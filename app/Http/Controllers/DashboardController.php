<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the role-aware multi-tier dashboard.
     */
    public function index(Request $request): View
    {
        $user = User::with([
            'company',
            'employee.department',
            'employee.tags',
            'employee.manager.user',
        ])->findOrFail($request->user()->id);

        $activeCompany = $user->isSuperAdmin() && session('active_company_id')
            ? Company::find(session('active_company_id'))
            : $user->company;

        $managerStats = null;
        $subordinates = collect();
        $corporateStats = null;
        $departmentBreakdown = collect();
        $platformStats = null;
        $tenants = collect();

        // Tier: Manager (Department-level metrics & subordinates)
        if ($user->isManager()) {
            $department = $user->employee?->department;
            $deptId = $department?->id ?? 0;

            $departmentTeam = Employee::with(['user', 'department', 'tags'])
                ->active()
                ->where('department_id', $deptId)
                ->orderBy('id')
                ->get();

            $directSubordinates = $user->employee
                ? Employee::with(['user', 'department', 'tags'])
                    ->active()
                    ->where('department_id', $deptId)
                    ->where('manager_id', $user->employee->id)
                    ->orderBy('id')
                    ->get()
                : collect();

            $subordinates = $directSubordinates->isNotEmpty()
                ? $directSubordinates
                : $departmentTeam->where('id', '!=', $user->employee?->id)->values();

            $managerStats = [
                'department_name' => $department?->name ?? '-',
                'department_code' => $department?->code ?? '-',
                'total_team_members' => $departmentTeam->count(),
                'is_operational' => (bool) ($department?->is_operational ?? false),
                'operational_label' => ($department?->is_operational ?? false)
                    ? __('common.operational_24h')
                    : __('common.regular_office_hours'),
                'min_staff_per_shift' => $department?->min_staff_per_shift ?? 1,
            ];
        }

        // Tier: Admin & Super User (Corporate-wide metrics & geofence)
        if ($user->isAdmin() || $user->isSuperUser() || ($user->isSuperAdmin() && $activeCompany)) {
            $departmentBreakdown = Department::withCount([
                'employees as active_employees_count' => fn ($query) => $query->active(),
            ])
                ->orderBy('code')
                ->get();

            $operationalCount = $departmentBreakdown->where('is_operational', true)->count();
            $backOfficeCount = $departmentBreakdown->where('is_operational', false)->count();

            $corporateStats = [
                'total_active_employees' => Employee::active()->count(),
                'total_departments' => $departmentBreakdown->count(),
                'operational_departments' => $operationalCount,
                'back_office_departments' => $backOfficeCount,
                'ratio_label' => __('common.ratio_summary', [
                    'operational' => $operationalCount,
                    'back_office' => $backOfficeCount,
                ]),
                'geofence_lat' => $activeCompany?->latitude,
                'geofence_lng' => $activeCompany?->longitude,
                'geofence_radius' => $activeCompany?->geofence_radius_meters,
            ];
        }

        // Tier: Super Admin (Platform-wide overview & context switcher)
        if ($user->isSuperAdmin()) {
            $tenants = Company::orderBy('name')->get();

            $platformStats = [
                'total_companies' => $tenants->count(),
                'total_users_global' => User::count(),
                'active_company' => $activeCompany,
            ];
        }

        return view('dashboard', compact(
            'user',
            'activeCompany',
            'managerStats',
            'subordinates',
            'corporateStats',
            'departmentBreakdown',
            'platformStats',
            'tenants'
        ));
    }

    /**
     * Switch the active tenant context for Super Admin.
     */
    public function switchCompany(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Company::class);

        $companyId = $request->input('company_id');

        if (empty($companyId)) {
            session()->forget('active_company_id');
        } else {
            $company = Company::findOrFail($companyId);
            session(['active_company_id' => $company->id]);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', 'Konteks perusahaan berhasil diperbarui.');
    }

    /**
     * Display the department management overview (Admin, Super User, Super Admin).
     */
    public function departments(): View
    {
        Gate::authorize('create', Department::class);

        $departments = Department::withCount([
            'employees as active_employees_count' => fn ($query) => $query->active(),
        ])->orderBy('code')->get();

        return view('departments.index', compact('departments'));
    }

    /**
     * Display the company settings and geofence parameters (Super User, Super Admin).
     */
    public function companySettings(Request $request): View
    {
        $user = $request->user();
        $company = $user->isSuperAdmin() && session('active_company_id')
            ? Company::find(session('active_company_id'))
            : ($user->company ?? Company::first());

        if ($company) {
            Gate::authorize('update', $company);
        } elseif (! $user->isSuperAdmin()) {
            abort(403);
        }

        return view('company.settings', compact('company'));
    }
}
