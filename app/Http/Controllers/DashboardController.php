<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Resolve today's cross-department work schedules for the active tenant company.
     * Regular users (role: user) only see 'published' schedules; Managers/Admins also see 'draft'.
     *
     * @return array<string, mixed>
     */
    protected function resolveTodaySchedulesData(User $user, ?Company $activeCompany): array
    {
        $scheduleCompany = $activeCompany ?? ($user->isSuperAdmin() ? Company::orderBy('id')->first() : $user->company);
        $today = Carbon::today('Asia/Jakarta');
        $todayDate = $today->toDateString();
        $todayFormatted = $today->translatedFormat('d F Y');

        if (! $scheduleCompany) {
            return [
                'todayDate' => $todayDate,
                'todayFormatted' => $todayFormatted,
                'todaySchedules' => collect(),
                'todaySchedulesByDepartment' => collect(),
                'todayMods' => collect(),
                'todaySchedulesPayload' => [],
            ];
        }

        $todaySchedules = WorkSchedule::withoutGlobalScopes()
            ->where('company_id', $scheduleCompany->id)
            ->where('schedule_date', $todayDate)
            ->where('status', '!=', 'pending_delete')
            ->when($user->isUser(), fn ($q) => $q->published())
            ->with([
                'employee' => fn ($q) => $q->withoutGlobalScopes()->with([
                    'user',
                    'department' => fn ($dq) => $dq->withoutGlobalScopes(),
                    'tags' => fn ($tq) => $tq->withoutGlobalScopes(),
                ]),
                'shift' => fn ($q) => $q->withoutGlobalScopes(),
                'dutyTag' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->orderBy('id')
            ->get();

        $todaySchedulesByDepartment = $todaySchedules->groupBy(
            fn (WorkSchedule $ws) => $ws->employee?->department?->name ?? '-'
        );

        $todayMods = $todaySchedules->filter(function (WorkSchedule $ws): bool {
            if ($ws->isOff()) {
                return false;
            }

            return $ws->shift?->code === 'MOD'
                || $ws->dutyTag?->code === 'MOD_ELIGIBLE'
                || ($ws->employee?->tags?->contains('code', 'MOD_ELIGIBLE') ?? false);
        })->values();

        $todaySchedulesPayload = $todaySchedules->map(function (WorkSchedule $ws): array {
            $startStr = $ws->getEffectiveStartTime();
            $endStr = $ws->getEffectiveEndTime();
            $isMod = ! $ws->isOff() && (
                $ws->shift?->code === 'MOD'
                || $ws->dutyTag?->code === 'MOD_ELIGIBLE'
                || ($ws->employee?->tags?->contains('code', 'MOD_ELIGIBLE') ?? false)
            );

            return [
                'id' => $ws->id,
                'uuid' => $ws->uuid,
                'company_id' => $ws->company_id,
                'employee_id' => $ws->employee_id,
                'employee_name' => $ws->employee?->user?->name ?? '-',
                'position' => $ws->employee?->position ?? '-',
                'department_id' => $ws->employee?->department_id,
                'department_code' => $ws->employee?->department?->code ?? '-',
                'department_name' => $ws->employee?->department?->name ?? '-',
                'shift_id' => $ws->shift_id,
                'shift_code' => $ws->shift ? $ws->shift->code : 'OFF',
                'shift_name' => $ws->shift ? $ws->shift->name : __('roster.day_off_dp'),
                'color_badge' => $ws->shift ? $ws->shift->color_badge : '#71717A',
                'start_time' => $startStr !== '' ? substr($startStr, 0, 5) : '-',
                'end_time' => $endStr !== '' ? substr($endStr, 0, 5) : '-',
                'effective_start_time' => $startStr,
                'effective_end_time' => $endStr,
                'duration_hours' => $ws->getDurationInHours(),
                'is_off' => $ws->isOff(),
                'status' => $ws->status,
                'duty_tag_code' => $ws->dutyTag?->code,
                'is_mod' => $isMod,
            ];
        })->values()->all();

        return [
            'todayDate' => $todayDate,
            'todayFormatted' => $todayFormatted,
            'todaySchedules' => $todaySchedules,
            'todaySchedulesByDepartment' => $todaySchedulesByDepartment,
            'todayMods' => $todayMods,
            'todaySchedulesPayload' => $todaySchedulesPayload,
        ];
    }

    /**
     * Display the role-aware multi-tier dashboard.
     */
    public function index(Request $request): View|JsonResponse
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

        $todayData = $this->resolveTodaySchedulesData($user, $activeCompany);
        $todayDate = $todayData['todayDate'];
        $todayFormatted = $todayData['todayFormatted'];
        $todaySchedules = $todayData['todaySchedules'];
        $todaySchedulesByDepartment = $todayData['todaySchedulesByDepartment'];
        $todayMods = $todayData['todayMods'];
        $todaySchedulesPayload = $todayData['todaySchedulesPayload'];

        if ($request->wantsJson()) {
            return response()->json([
                'date' => $todayDate,
                'formatted_date' => $todayFormatted,
                'total_scheduled' => $todaySchedules->count(),
                'schedules' => $todaySchedulesPayload,
                'mods' => array_values(array_filter($todaySchedulesPayload, fn (array $item) => $item['is_mod'])),
            ]);
        }

        return view('dashboard', compact(
            'user',
            'activeCompany',
            'managerStats',
            'subordinates',
            'corporateStats',
            'departmentBreakdown',
            'platformStats',
            'tenants',
            'todayDate',
            'todayFormatted',
            'todaySchedules',
            'todaySchedulesByDepartment',
            'todayMods',
            'todaySchedulesPayload'
        ));
    }

    /**
     * Return today's cross-department work schedules as JSON.
     */
    public function todaySchedules(Request $request): JsonResponse
    {
        $user = User::with(['company', 'employee.department', 'employee.tags'])->findOrFail($request->user()->id);
        $activeCompany = $user->isSuperAdmin() && session('active_company_id')
            ? Company::find(session('active_company_id'))
            : $user->company;

        $todayData = $this->resolveTodaySchedulesData($user, $activeCompany);

        return response()->json([
            'date' => $todayData['todayDate'],
            'formatted_date' => $todayData['todayFormatted'],
            'total_scheduled' => $todayData['todaySchedules']->count(),
            'schedules' => $todayData['todaySchedulesPayload'],
            'mods' => array_values(array_filter($todayData['todaySchedulesPayload'], fn (array $item) => $item['is_mod'])),
        ]);
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
