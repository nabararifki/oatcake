<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeUserRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tag;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StaffProvisioningController extends Controller
{
    use AuthorizesRequests;

    /**
     * Ensure the current authenticated actor is authorized to access staff provisioning.
     */
    protected function ensureAuthorized(?User $user): void
    {
        if (! $user || ! $user->is_active || ! in_array($user->role, ['manager', 'admin', 'super_user', 'super_admin'], true)) {
            abort(403, 'Anda tidak memiliki akses ke modul provisioning staf.');
        }
    }

    /**
     * Display a listing of active company staff.
     */
    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAuthorized($actor);

        $actor->loadMissing(['company', 'employee.department']);

        $query = Employee::with(['user', 'department', 'manager.user', 'tags'])
            ->active()
            ->orderBy('id');

        if ($actor->isManager()) {
            $managerDeptId = $actor->employee?->department_id;
            $query->where('department_id', $managerDeptId ?? 0);
        }

        $employees = $query->get();

        $company = $actor->company;

        return view('staff.index', compact('employees', 'company', 'actor'));
    }

    /**
     * Show the form for provisioning a new employee and user account.
     */
    public function create(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAuthorized($actor);

        $actor->loadMissing(['company', 'employee.department']);

        $departments = Department::orderBy('name')->get();
        $tags = Tag::orderBy('name')->get();
        $managers = Employee::with(['user', 'department'])
            ->active()
            ->orderBy('id')
            ->get();

        $allowedRoles = StoreEmployeeUserRequest::allowedRolesFor($actor);
        $isManager = $actor->isManager();
        $lockedDepartmentId = $isManager ? $actor->employee?->department_id : null;

        return view('staff.create', compact(
            'actor',
            'departments',
            'tags',
            'managers',
            'allowedRoles',
            'isManager',
            'lockedDepartmentId'
        ));
    }

    /**
     * Store a newly provisioned user and employee profile in storage.
     */
    public function store(StoreEmployeeUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $companyId = $request->resolveCompanyId();

        DB::transaction(function () use ($request, $validated, $companyId): void {
            $rawPassword = filled($validated['password'] ?? null)
                ? $validated['password']
                : Str::random(16);

            $user = User::create([
                'company_id' => $companyId,
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => Hash::make($rawPassword),
                'role' => $validated['role'],
                'is_active' => true,
            ]);

            $employee = Employee::create([
                'company_id' => $companyId,
                'user_id' => $user->id,
                'department_id' => $validated['department_id'],
                'manager_id' => $validated['manager_id'] ?? null,
                'position' => $validated['position'],
                'join_date' => $validated['join_date'],
                'phone' => $validated['phone'] ?? null,
                'status' => 'active',
            ]);

            if (! empty($validated['tags'])) {
                $employee->tags()->sync($request->tags);
            }
        });

        return redirect()
            ->route('staff.index')
            ->with('success', "Staf baru {$validated['name']} berhasil didaftarkan.");
    }

    /**
     * Show the form for editing an existing employee and associated user account.
     */
    public function edit(Request $request, Employee $employee): View
    {
        $actor = $request->user();
        $this->ensureAuthorized($actor);
        $this->authorize('update', $employee);

        $actor->loadMissing(['company', 'employee.department']);
        $employee->load([
            'user',
            'department' => fn ($q) => $q->withoutGlobalScopes(),
            'tags' => fn ($q) => $q->withoutGlobalScopes(),
        ]);

        $departments = Department::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->orderBy('name')
            ->get();

        $tags = Tag::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->orderBy('name')
            ->get();

        $managers = Employee::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('id', '!=', $employee->id)
            ->with(['user', 'department' => fn ($q) => $q->withoutGlobalScopes()])
            ->active()
            ->orderBy('id')
            ->get();

        $allowedRoles = StoreEmployeeUserRequest::allowedRolesFor($actor);
        $isManager = $actor->isManager();
        $lockedDepartmentId = $isManager ? $actor->employee?->department_id : null;

        return view('staff.edit', compact(
            'actor',
            'employee',
            'departments',
            'tags',
            'managers',
            'allowedRoles',
            'isManager',
            'lockedDepartmentId'
        ));
    }

    /**
     * Update the specified employee and user account in storage.
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $this->authorize('update', $employee);
        $validated = $request->validated();

        DB::transaction(function () use ($employee, $validated): void {
            $status = $validated['status'] ?? $employee->status ?? 'active';

            if ($employee->user) {
                $userUpdates = [
                    'name' => $validated['name'],
                    'username' => $validated['username'],
                    'email' => $validated['email'],
                    'role' => $validated['role'],
                    'is_active' => $status === 'active',
                ];

                if (filled($validated['password'] ?? null)) {
                    $userUpdates['password'] = Hash::make($validated['password']);
                }

                $employee->user->update($userUpdates);
            }

            $employee->update([
                'department_id' => $validated['department_id'],
                'manager_id' => $validated['manager_id'] ?? null,
                'position' => $validated['position'],
                'join_date' => $validated['join_date'] ?? $employee->join_date?->format('Y-m-d'),
                'phone' => array_key_exists('phone', $validated) ? $validated['phone'] : $employee->phone,
                'status' => $status,
            ]);

            $employee->tags()->sync($validated['tags'] ?? []);
        });

        return redirect()
            ->route('staff.index')
            ->with('success', __('staff.updated_successfully', ['name' => $validated['name']]));
    }

    /**
     * Remove the specified employee and associated user account from storage.
     */
    public function destroy(Request $request, Employee $employee): RedirectResponse|JsonResponse
    {
        $actor = $request->user();
        $this->ensureAuthorized($actor);
        $this->authorize('delete', $employee);

        if ((int) $employee->user_id === (int) auth()->id()) {
            abort(422, __('staff.delete_self_error'));
        }

        if (! $actor->isSuperAdmin() && (int) $employee->company_id !== (int) $actor->company_id) {
            abort(403);
        }

        DB::transaction(function () use ($employee): void {
            $employee->tags()->detach();

            Employee::withoutGlobalScopes()
                ->where('company_id', $employee->company_id)
                ->where('manager_id', $employee->id)
                ->update(['manager_id' => null]);

            WorkSchedule::withoutGlobalScopes()
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)
                ->delete();

            $user = $employee->user;
            $employee->delete();

            if ($user) {
                WorkSchedule::withoutGlobalScopes()
                    ->where('created_by', $user->id)
                    ->update(['created_by' => null]);
                $user->delete();
            }
        });

        if ($request->wantsJson()) {
            return response()->json([
                'message' => __('staff.delete_success'),
            ]);
        }

        return redirect()
            ->route('staff.index')
            ->with('success', __('staff.delete_success'));
    }
}
