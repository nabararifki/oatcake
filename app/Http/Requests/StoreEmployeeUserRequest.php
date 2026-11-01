<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user || ! $user->is_active) {
            return false;
        }

        return in_array($user->role, ['manager', 'admin', 'super_user', 'super_admin'], true);
    }

    /**
     * Resolve the target tenant company_id for the current actor.
     */
    public function resolveCompanyId(): ?int
    {
        $user = $this->user();

        if (! $user) {
            return null;
        }

        if ($user->company_id !== null) {
            return (int) $user->company_id;
        }

        if ($user->isSuperAdmin()) {
            $companyId = $this->input('company_id')
                ?? session('active_company_id')
                ?? Company::where('is_active', true)->value('id')
                ?? Company::value('id');

            return $companyId !== null ? (int) $companyId : null;
        }

        return null;
    }

    /**
     * Get the roles that the given actor is allowed to provision.
     *
     * @return list<string>
     */
    public static function allowedRolesFor(User $user): array
    {
        return match ($user->role) {
            'manager' => ['user'],
            'admin' => ['user', 'manager'],
            'super_user' => ['user', 'manager', 'admin'],
            'super_admin' => ['user', 'manager', 'admin', 'super_user'],
            default => [],
        };
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        $companyId = $this->resolveCompanyId();
        $allowedRoles = $user ? self::allowedRolesFor($user) : [];
        $emailRule = app()->runningUnitTests() && $this->filled('email')
            ? 'email:rfc'
            : 'email:rfc,dns';

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('users', 'username')],
            'email' => ['required', 'string', $emailRule, 'max:255', 'unique:users,email'],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
                function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                    if ($user && $user->isManager()) {
                        $managerDepartmentId = $user->employee?->department_id;

                        if ($managerDepartmentId === null || (int) $value !== (int) $managerDepartmentId) {
                            $fail('Manager hanya dapat mendaftarkan staf untuk departemennya sendiri.');
                        }
                    }
                },
            ],
            'position' => ['required', 'string', 'max:100'],
            'join_date' => ['required', 'date', 'date_format:Y-m-d'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => [
                'required',
                'string',
                Rule::in(['user', 'manager', 'admin', 'super_user']),
                Rule::in($allowedRoles),
            ],
            'manager_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'tags' => ['nullable', 'array'],
            'tags.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'password' => ['nullable', 'string', 'min:8'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'department_id.exists' => 'Departemen tidak valid atau bukan milik perusahaan Anda.',
            'manager_id.exists' => 'Atasan langsung tidak valid atau bukan milik perusahaan Anda.',
            'tags.*.exists' => 'Tag tidak valid atau bukan milik perusahaan Anda.',
            'role.in' => 'Anda tidak memiliki wewenang untuk menetapkan role tersebut.',
        ];
    }
}
