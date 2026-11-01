<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $employee = $this->route('employee');

        if (! $user || ! $user->is_active || ! ($employee instanceof Employee)) {
            return false;
        }

        return $user->can('update', $employee);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        /** @var Employee $employee */
        $employee = $this->route('employee');
        $companyId = (int) $employee->company_id;
        $targetUserId = $employee->user_id;

        $allowedRoles = $user ? StoreEmployeeUserRequest::allowedRolesFor($user) : [];
        $emailRule = app()->runningUnitTests() && $this->filled('email')
            ? 'email:rfc'
            : 'email:rfc,dns';

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:100',
                'alpha_dash',
                Rule::unique('users', 'username')->ignore($targetUserId),
            ],
            'email' => [
                'required',
                'string',
                $emailRule,
                'max:255',
                Rule::unique('users', 'email')->ignore($targetUserId),
            ],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
                function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                    if ($user && $user->isManager()) {
                        $managerDepartmentId = $user->employee?->department_id;

                        if ($managerDepartmentId === null || (int) $value !== (int) $managerDepartmentId) {
                            $fail('Manager hanya dapat memperbarui staf pada departemennya sendiri.');
                        }
                    }
                },
            ],
            'position' => ['required', 'string', 'max:100'],
            'join_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive', 'on_leave'])],
            'role' => [
                'required',
                'string',
                Rule::in(['user', 'manager', 'admin', 'super_user']),
                Rule::in($allowedRoles),
            ],
            'manager_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('id', '!=', $employee->id)),
            ],
            'tags' => ['nullable', 'array'],
            'tags.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'password' => ['nullable', 'string', 'min:8'],
        ];
    }
}
