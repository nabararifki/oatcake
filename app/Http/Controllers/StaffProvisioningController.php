<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeUserRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StaffProvisioningController extends Controller
{
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
}
