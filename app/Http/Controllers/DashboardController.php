<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\AttendanceGeofenceService;
use App\Services\LeaveLedgerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly AttendanceGeofenceService $geofenceService,
        private readonly LeaveLedgerService $leaveLedgerService
    ) {}
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
            'employee.company',
            'employee.department',
            'employee.tags',
            'employee.manager.user',
        ])->findOrFail($request->user()->id);

        $isSuperAdmin = $user->isSuperAdmin();
        $employee = $user->employee;

        $activeCompany = $isSuperAdmin && session('active_company_id')
            ? Company::find(session('active_company_id'))
            : $user->company;

        $company = $activeCompany ?? $employee?->company ?? $user->company;
        $companyId = $company?->id;

        $todayWib = today('Asia/Jakarta');
        $nowWib = Carbon::now('Asia/Jakarta');
        $isHoliday = PublicHoliday::isHoliday($todayWib, $companyId);

        $todayAttendance = null;
        $todaySchedule = null;
        $todaySessionsState = null;
        $annualLeaveBalance = 0;
        $dpBalance = 0;

        if ($employee) {
            $annualLeaveBalance = (int) $this->leaveLedgerService->getBalance($employee, 'AL');
            $dpBalance = (int) $this->leaveLedgerService->getBalance($employee, 'DP');
        }

        if (! $isSuperAdmin && $employee) {
            $todayAttendance = Attendance::withoutGlobalScopes()
                ->with(['workSchedule.shift'])
                ->where('employee_id', $employee->id)
                ->where('status', '!=', 'rejected')
                ->whereNotNull('clock_in_at')
                ->whereNull('clock_out_at')
                ->latest('clock_in_at')
                ->first();

            if (! $todayAttendance) {
                [$dayStart, $dayEnd] = $this->resolveDayClockInBounds($todayWib->toDateString(), $company);

                $todayAttendance = Attendance::withoutGlobalScopes()
                    ->with(['workSchedule.shift'])
                    ->where('employee_id', $employee->id)
                    ->where('status', '!=', 'rejected')
                    ->whereBetween('clock_in_at', [$dayStart, $dayEnd])
                    ->latest('clock_in_at')
                    ->first();
            }

            $todaySchedule = $this->geofenceService->resolveTodaySchedule($employee, $nowWib, false);
            $todaySessionsState = $this->geofenceService->resolveTodaySessionsState($employee, $nowWib);
        }

        $hasActiveScheduleToday = $employee !== null
            && (
                ($todaySessionsState['working_schedules'] ?? collect())->isNotEmpty()
                || ($todaySchedule !== null && ! $todaySchedule->isOff())
            )
            && ! $this->geofenceService->isEmployeeOnApprovedLeave($employee, $nowWib);
        $autoAssignShiftEnabled = (bool) ($company?->auto_assign_shift_on_clock_in ?? true);
        $isClockInBlockedByUnscheduledPolicy = ! $isSuperAdmin
            && $employee !== null
            && ! $hasActiveScheduleToday
            && ! $autoAssignShiftEnabled
            && ! $todayAttendance;

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
            'isSuperAdmin',
            'employee',
            'company',
            'activeCompany',
            'todayAttendance',
            'todaySchedule',
            'todaySessionsState',
            'hasActiveScheduleToday',
            'autoAssignShiftEnabled',
            'isClockInBlockedByUnscheduledPolicy',
            'annualLeaveBalance',
            'dpBalance',
            'isHoliday',
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
            ->with('status', __('company.context_switched_successfully'));
    }

    /**
     * Resolve timezone-safe clock_in_at boundaries for a calendar date in the active company's timezone.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDayClockInBounds(string $dateString, ?Company $company): array
    {
        $timezone = $company?->timezone ?: 'Asia/Jakarta';
        $startUtc = Carbon::parse($dateString, $timezone)->startOfDay()->utc();
        $endUtc = Carbon::parse($dateString, $timezone)->endOfDay()->utc();

        if (DB::connection()->getDriverName() === 'pgsql') {
            return [$startUtc, $endUtc];
        }

        $appTimezone = (string) config('app.timezone', 'Asia/Jakarta');

        return [
            $startUtc->copy()->timezone($appTimezone),
            $endUtc->copy()->timezone($appTimezone),
        ];
    }
}
