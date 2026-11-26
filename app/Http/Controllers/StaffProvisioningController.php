<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeUserRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Company;
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
        if (! $user || ! $user->is_active || ! in_array($user->role, ['manager', 'admin_hr', 'super_user', 'super_admin'], true)) {
            abort(403, 'Anda tidak memiliki akses ke modul provisioning staf.');
        }
    }

    /**
     * Enforce strict hierarchical rank dominance before mutating/deleting a target staff member.
     */
    protected function ensureActorOutranksTarget(User $actor, Employee $employee): void
    {
        $isSelf = $employee->user_id !== null && (int) $actor->id === (int) $employee->user_id;
        $targetUser = $employee->relationLoaded('user') ? $employee->user : $employee->user()->first();
        $targetRank = User::rankForRole($targetUser?->role ?? 'user');
        $actorRank = $actor->roleRank();

        $allowed = $isSelf ? ($actorRank >= $targetRank) : ($actorRank > $targetRank);

        if (! $allowed) {
            abort(403, 'Anda tidak memiliki wewenang untuk mengubah atau menghapus pengguna dengan peran setara atau lebih tinggi.');
        }
    }

    /**
     * Display a listing of company staff with advanced filtering (department, role, status, tag, and zero-NIK search).
     */
    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->ensureAuthorized($actor);

        $actor->loadMissing(['company', 'employee.department']);

        $company = $actor->isSuperAdmin() && session('active_company_id')
            ? Company::find(session('active_company_id'))
            : $actor->company;

        $canFilterDepartment = $actor->isAdminOrAbove();
        $filterSearch = trim((string) $request->query('search', ''));
        $filterDepartmentId = $request->filled('department_id') ? (int) $request->query('department_id') : null;
        $filterRole = trim((string) $request->query('role', ''));
        $filterStatus = trim((string) $request->query('status', ''));
        $filterTagId = $request->filled('tag_id') ? (int) $request->query('tag_id') : null;

        $effectiveDepartmentId = $actor->isManager()
            ? ($actor->employee?->department_id ?? 0)
            : $filterDepartmentId;

        $validRoles = array_keys(User::ROLE_RANKS);
        $validStatuses = ['active', 'inactive', 'on_leave', 'resigned', 'suspended'];
        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $employees = Employee::with(['user', 'department', 'manager.user', 'tags'])
            ->when($effectiveDepartmentId !== null, fn ($q) => $q->where('department_id', $effectiveDepartmentId))
            ->when(
                $filterRole !== '' && in_array($filterRole, $validRoles, true),
                fn ($q) => $q->whereHas('user', fn ($uq) => $uq->where('role', $filterRole))
            )
            ->when(
                $filterStatus !== '' && $filterStatus !== 'all' && in_array($filterStatus, $validStatuses, true),
                fn ($q) => $q->where('status', $filterStatus)
            )
            ->when(
                $filterTagId !== null,
                fn ($q) => $q->whereHas('tags', fn ($tq) => $tq->withoutGlobalScopes()->where('tags.id', $filterTagId))
            )
            ->when($filterSearch !== '', function ($q) use ($filterSearch, $likeOperator): void {
                $pattern = "%{$filterSearch}%";

                // Strict Zero NIK: search only across position, name, email, and username.
                $q->where(function ($sub) use ($pattern, $likeOperator): void {
                    $sub->where('position', $likeOperator, $pattern)
                        ->orWhereHas('user', function ($uq) use ($pattern, $likeOperator): void {
                            $uq->where('name', $likeOperator, $pattern)
                                ->orWhere('email', $likeOperator, $pattern)
                                ->orWhere('username', $likeOperator, $pattern);
                        });
                });
            })
            ->orderBy('id')
            ->get();

        $departments = $canFilterDepartment
            ? Department::orderBy('name')->get()
            : ($actor->employee?->department ? collect([$actor->employee->department]) : collect());

        $tags = Tag::orderBy('name')->get();

        $availableRoles = $actor->isSuperAdmin()
            ? ['super_admin', 'super_user', 'admin_hr', 'manager', 'admin_staff', 'user']
            : ['super_user', 'admin_hr', 'manager', 'admin_staff', 'user'];

        $filters = [
            'search' => $filterSearch,
            'department_id' => $canFilterDepartment ? ($filterDepartmentId ?? '') : ($actor->employee?->department_id ?? ''),
            'role' => $filterRole,
            'status' => $filterStatus,
            'tag_id' => $filterTagId ?? '',
        ];

        return view('staff.index', compact(
            'employees',
            'company',
            'actor',
            'departments',
            'tags',
            'availableRoles',
            'canFilterDepartment',
            'filters'
        ));
    }

    /**
     * Build the dynamic role hierarchy guide filtered to roles at or below the actor's rank.
     *
     * @return list<array{key: string, rank: int, label: string, description: string}>
     */
    protected function buildRoleHierarchyGuide(User $actor): array
    {
        $actorRank = $actor->roleRank();
        $ranks = User::ROLE_RANKS;
        arsort($ranks);

        $guide = [];
        foreach ($ranks as $roleKey => $rank) {
            if ($roleKey === 'super_admin' || $rank > $actorRank) {
                continue;
            }

            $labelKey = 'staff.role_' . $roleKey;
            $resolvedLabel = __($labelKey) !== $labelKey
                ? __($labelKey)
                : strtoupper(str_replace('_', ' ', $roleKey));

            $descKey = 'staff.role_guide_desc_' . $roleKey;
            $resolvedDesc = __($descKey) !== $descKey ? __($descKey) : '';

            $guide[] = [
                'key' => $roleKey,
                'rank' => $rank,
                'label' => $resolvedLabel,
                'description' => $resolvedDesc,
            ];
        }

        return $guide;
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
        $roleHierarchyGuide = $this->buildRoleHierarchyGuide($actor);
        $isManager = $actor->isManager();
        $lockedDepartmentId = $isManager ? $actor->employee?->department_id : null;

        return view('staff.create', compact(
            'actor',
            'departments',
            'tags',
            'managers',
            'allowedRoles',
            'roleHierarchyGuide',
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

            $employee->tags()->sync($validated['tags'] ?? []);
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
        $this->ensureActorOutranksTarget($actor, $employee);

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
        $roleHierarchyGuide = $this->buildRoleHierarchyGuide($actor);
        $isManager = $actor->isManager();
        $lockedDepartmentId = $isManager ? $actor->employee?->department_id : null;

        return view('staff.edit', compact(
            'actor',
            'employee',
            'departments',
            'tags',
            'managers',
            'allowedRoles',
            'roleHierarchyGuide',
            'isManager',
            'lockedDepartmentId'
        ));
    }

    /**
     * Update the specified employee and user account in storage.
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $actor = $request->user();
        $this->authorize('update', $employee);
        $this->ensureActorOutranksTarget($actor, $employee);
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
        $this->ensureActorOutranksTarget($actor, $employee);

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
