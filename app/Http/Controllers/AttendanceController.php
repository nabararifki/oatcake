<?php

namespace App\Http\Controllers;

use App\Jobs\SendWebPushNotificationJob;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\AttendanceGeofenceService;
use App\Services\AttendancePhotoService;
use App\Services\DpCommitService;
use App\Services\WebPushService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceGeofenceService $geofenceService,
        private readonly AttendancePhotoService $photoService,
        private readonly DpCommitService $dpCommitService,
        private readonly ?WebPushService $webPushService = null
    ) {}

    /**
     * Display the centralized RBAC-scoped attendance history and audit log.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $employee = $user->employee?->load(['department', 'company']);
        $company = $this->resolveAttendanceCompany($user, $employee);

        $nowWib = Carbon::now('Asia/Jakarta');
        $todayDate = $nowWib->toDateString();

        $todaySchedule = $employee
            ? $this->geofenceService->resolveTodaySchedule($employee, $nowWib, false)
            : null;

        $todayHoliday = PublicHoliday::findHolidayForDate($todayDate, $company?->id);

        $isAdminTier = $user->isSuperAdmin() || $user->isSuperUser() || $user->isAdmin();
        $isManagerTier = $user->isSupervisorOrManager();
        $canFilterDepartment = $isAdminTier;
        $canSearchStaff = $isAdminTier || $isManagerTier;
        $showEmployeeColumn = $isAdminTier || $isManagerTier;

        $departments = collect();
        if ($canFilterDepartment && $company !== null) {
            $departments = Department::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->orderBy('code')
                ->get();
        } elseif ($isManagerTier && $employee?->department) {
            $departments = collect([$employee->department]);
        }

        $query = $this->buildScopedAttendanceQuery($request, $user, $employee, $company, true);

        $filterDateFrom = (string) $request->input('date_from', $request->input('start_date', $nowWib->copy()->subDays(30)->toDateString()));
        $filterDateTo = (string) $request->input('date_to', $request->input('end_date', $todayDate));
        $filterStatus = (string) $request->input('status', 'all');
        $filterSearch = trim((string) $request->input('search', ''));

        $attendances = $query
            ->orderByDesc('clock_in_at')
            ->paginate(25)
            ->withQueryString();

        return view('attendance.index', [
            'currentCompany' => $company,
            'employee' => $employee,
            'todaySchedule' => $todaySchedule,
            'todayHoliday' => $todayHoliday,
            'attendances' => $attendances,
            'recentAttendances' => $attendances,
            'departments' => $departments,
            'canFilterDepartment' => $canFilterDepartment,
            'canSearchStaff' => $canSearchStaff,
            'showEmployeeColumn' => $showEmployeeColumn,
            'canManageAttendance' => $user->isAdminOrAbove(),
            'isManagerTier' => $isManagerTier,
            'filterDateFrom' => $filterDateFrom,
            'filterDateTo' => $filterDateTo,
            'filterDepartmentId' => $canFilterDepartment ? $request->input('department_id', '') : ($employee?->department_id ?? ''),
            'filterStatus' => $filterStatus,
            'filterSearch' => $filterSearch,
        ]);
    }

    /**
     * Stream filtered attendance records as a payroll-ready CSV with O(1) memory usage.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $employee = $user->employee?->load(['department', 'company']);
        $company = $this->resolveAttendanceCompany($user, $employee);
        $timezone = $company?->timezone ?: 'Asia/Jakarta';

        $query = $this->buildScopedAttendanceQuery($request, $user, $employee, $company, false)
            ->orderByDesc('clock_in_at')
            ->orderByDesc('id');

        $filename = 'attendance-' . Carbon::now($timezone)->format('Y-m-d_His') . '.csv';

        return new StreamedResponse(function () use ($query, $timezone): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // Emit UTF-8 BOM so Excel and external payroll tools render Indonesian characters properly.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('attendance.csv_header_payroll_code'),
                __('attendance.csv_header_full_name'),
                __('attendance.csv_header_department'),
                __('attendance.csv_header_attendance_date'),
                __('attendance.csv_header_shift_code'),
                __('attendance.csv_header_clock_in'),
                __('attendance.csv_header_clock_out'),
                __('attendance.csv_header_late_minutes'),
                __('attendance.csv_header_early_leave_minutes'),
                __('attendance.csv_header_status'),
            ]);

            $query->chunk(500, function ($attendances) use ($handle, $timezone): void {
                foreach ($attendances as $attendance) {
                    $emp = $attendance->employee;
                    $payrollCode = (string) ($emp?->getAttribute('payroll_code') ?: ($emp?->id ?? $attendance->employee_id ?? ''));
                    $fullName = (string) ($emp?->user?->name ?? '-');
                    $department = (string) ($emp?->department?->name ?? '-');

                    $clockInLocal = $attendance->clock_in_at?->copy()->timezone($timezone);
                    $clockOutLocal = $attendance->clock_out_at?->copy()->timezone($timezone);

                    $attendanceDate = $clockInLocal?->toDateString()
                        ?? ($attendance->workSchedule?->schedule_date?->toDateString() ?? '-');
                    $shiftCode = (string) ($attendance->workSchedule?->shift?->code ?? '-');
                    $clockInTime = $clockInLocal?->format('H:i:s') ?? '-';
                    $clockOutTime = $clockOutLocal?->format('H:i:s') ?? '-';

                    $statusKey = 'attendance.status_' . $attendance->status;
                    $statusLabel = __($statusKey) !== $statusKey ? __($statusKey) : (string) $attendance->status;

                    fputcsv($handle, [
                        $payrollCode,
                        $fullName,
                        $department,
                        $attendanceDate,
                        $shiftCode,
                        $clockInTime,
                        $clockOutTime,
                        (int) ($attendance->late_minutes ?? 0),
                        (int) ($attendance->early_leave_minutes ?? 0),
                        $statusLabel,
                    ]);
                }

                if (ob_get_level() === 0) {
                    flush();
                }
            });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * Resolve the active company context for attendance viewing and exports.
     */
    private function resolveAttendanceCompany(User $user, ?Employee $employee): ?Company
    {
        return $employee?->company
            ?? $user->company
            ?? ($user->isSuperAdmin() && session('active_company_id')
                ? Company::find(session('active_company_id'))
                : Company::first());
    }

    /**
     * Build the RBAC-scoped and filter-aware attendance query shared by UI index and CSV export.
     */
    private function buildScopedAttendanceQuery(
        Request $request,
        User $user,
        ?Employee $employee,
        ?Company $company,
        bool $includeVerifier = true
    ): Builder {
        $isAdminTier = $user->isSuperAdmin() || $user->isSuperUser() || $user->isAdmin();
        $isManagerTier = $user->isSupervisorOrManager();
        $canSearchStaff = $isAdminTier || $isManagerTier;

        $relations = [
            'employee' => fn ($q) => $q->withoutGlobalScopes()->with([
                'user',
                'department' => fn ($dq) => $dq->withoutGlobalScopes(),
            ]),
            'workSchedule' => fn ($wq) => $wq->withoutGlobalScopes()->with([
                'shift' => fn ($sq) => $sq->withoutGlobalScopes()->withTrashed(),
            ]),
        ];

        if ($includeVerifier) {
            $relations[] = 'verifier';
        }

        $query = Attendance::withoutGlobalScopes()->with($relations);

        // Hierarchical RBAC Scoping
        if ($isAdminTier) {
            if ($company !== null) {
                $query->where('company_id', $company->id);
            }

            if ($request->filled('department_id')) {
                $deptId = (int) $request->input('department_id');
                $query->whereHas('employee', fn ($eq) => $eq->withoutGlobalScopes()->where('department_id', $deptId));
            }
        } elseif ($isManagerTier && $employee !== null) {
            $query->where('company_id', $employee->company_id)
                ->whereHas('employee', fn ($eq) => $eq->withoutGlobalScopes()->where('department_id', $employee->department_id));
        } elseif ($employee !== null) {
            // Regular User: strictly isolated to self
            $query->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id);
        } else {
            $query->whereRaw('1 = 0');
        }

        // Date Range Filter (supports UI date_from/date_to and start_date/end_date aliases)
        $dateFrom = trim((string) $request->input('date_from', $request->input('start_date', '')));
        $dateTo = trim((string) $request->input('date_to', $request->input('end_date', '')));

        if ($dateFrom !== '') {
            $query->whereDate('clock_in_at', '>=', $dateFrom);
        }

        if ($dateTo !== '') {
            $query->whereDate('clock_in_at', '<=', $dateTo);
        }

        // Status Filter
        $validStatuses = ['on_time', 'late', 'early_leave', 'pending_verification', 'rejected'];
        $filterStatus = (string) $request->input('status', 'all');
        if ($filterStatus !== 'all' && in_array($filterStatus, $validStatuses, true)) {
            $query->where('status', $filterStatus);
        }

        // Staff Name / Position Search (Manager & Admin Tier only, strict ZERO NIK)
        $filterSearch = trim((string) $request->input('search', ''));
        if ($canSearchStaff && $filterSearch !== '') {
            $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $pattern = "%{$filterSearch}%";

            $query->whereHas('employee', function ($eq) use ($likeOperator, $pattern): void {
                $eq->withoutGlobalScopes()->where(function ($sub) use ($likeOperator, $pattern): void {
                    $sub->where('position', $likeOperator, $pattern)
                        ->orWhereHas('user', function ($uq) use ($likeOperator, $pattern): void {
                            $uq->where('name', $likeOperator, $pattern)
                                ->orWhere('username', $likeOperator, $pattern);
                        });
                });
            });
        }

        return $query;
    }

    /**
     * Record a GPS geofence-validated Clock-In for the authenticated employee.
     */
    public function clockIn(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user?->employee;

        if ($this->isAttemptingCrossEmployeeAction($request, $user?->id, $employee?->id, $employee?->uuid)) {
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_unauthorized_self_only'),
            ], 403);
        }

        if ($employee === null) {
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_no_employee_profile'),
            ], 403);
        }

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'photo' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:25600'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $company = $employee->company ?? $user->company;

        $geofence = $this->geofenceService->validateGeofence(
            $company,
            (float) $validated['latitude'],
            (float) $validated['longitude']
        );

        if (! $geofence['is_inside']) {
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_outside_radius', [
                    'distance' => $geofence['distance_meters'],
                    'radius' => $geofence['radius_limit'],
                ]),
                'distance' => $geofence['distance_meters'],
                'radius_limit' => $geofence['radius_limit'],
                'is_outside' => true,
            ], 422);
        }

        $openAttendance = Attendance::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('employee_id', $employee->id)
            ->where('status', '!=', 'rejected')
            ->whereNotNull('clock_in_at')
            ->whereNull('clock_out_at')
            ->latest('clock_in_at')
            ->first();

        if ($openAttendance !== null) {
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_already_clocked_in'),
            ], 422);
        }

        $nowWib = Carbon::now('Asia/Jakarta');

        if ($this->geofenceService->isEmployeeOnApprovedLeave($employee, $nowWib)) {
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_on_approved_leave'),
            ], 422);
        }

        $autoAssignEnabled = (bool) ($company->auto_assign_shift_on_clock_in ?? true);
        $preResolvedSchedule = $this->geofenceService->resolveTodaySchedule($employee, $nowWib, false);
        if (! $autoAssignEnabled && ($preResolvedSchedule === null || $preResolvedSchedule->isOff())) {
            return response()->json([
                'success' => false,
                'message' => __('attendance.unscheduled_clock_in_disabled'),
            ], 422);
        }

        try {
            $clockInPhotoPath = $this->photoService->processAndStore(
                $request->file('photo'),
                $company,
                (string) $employee->uuid
            );
        } catch (ValidationException $ve) {
            throw $ve;
        } catch (Throwable $e) {
            Log::error('Clock-in photo processing failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_photo_processing_failed'),
            ], 500);
        }

        $schedule = ($preResolvedSchedule !== null && ! $preResolvedSchedule->isOff())
            ? $preResolvedSchedule
            : $this->geofenceService->resolveTodaySchedule($employee, $nowWib, $autoAssignEnabled);
        $evaluation = $this->geofenceService->evaluateClockIn($schedule, $nowWib);
        $isProvisionalDp = PublicHoliday::isHoliday($nowWib, (int) $company->id);

        $attendance = Attendance::create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'work_schedule_id' => ($schedule !== null && ! $schedule->isOff()) ? $schedule->id : null,
            'clock_in_at' => $nowWib,
            'clock_in_latitude' => $validated['latitude'],
            'clock_in_longitude' => $validated['longitude'],
            'clock_in_distance_meters' => $geofence['distance_meters'],
            'clock_in_photo_path' => $clockInPhotoPath,
            'proof_photo_path' => $clockInPhotoPath,
            'status' => $evaluation['status'],
            'late_minutes' => $evaluation['late_minutes'],
            'early_leave_minutes' => 0,
            'is_outside_geofence' => false,
            'is_provisional_dp' => $isProvisionalDp,
            'device_info' => substr((string) $request->userAgent(), 0, 255) ?: null,
            'ip_address' => $request->ip(),
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('attendance.msg_clock_in_success'),
            'distance' => $geofence['distance_meters'],
            'is_outside' => false,
            'data' => [
                'id' => $attendance->id,
                'uuid' => $attendance->uuid,
                'work_schedule_id' => $attendance->work_schedule_id,
                'status' => $attendance->status,
                'is_provisional_dp' => $attendance->is_provisional_dp,
                'late_minutes' => $attendance->late_minutes,
                'early_leave_minutes' => $attendance->early_leave_minutes,
                'clock_in_photo_path' => $attendance->clock_in_photo_path,
                'clock_in_at' => $attendance->clock_in_at?->timezone('Asia/Jakarta')->toIso8601String(),
                'clock_in_time' => $attendance->clock_in_at?->timezone('Asia/Jakarta')->format('H:i:s'),
                'clock_in_distance_meters' => $attendance->clock_in_distance_meters,
            ],
        ]);
    }

    /**
     * Record an Outside Duty Clock-In with direct selfie capture and server-side photo compression.
     */
    public function clockInOutside(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user?->employee;

        if ($this->isAttemptingCrossEmployeeAction($request, $user?->id, $employee?->id, $employee?->uuid)) {
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_unauthorized_self_only'),
            ], 403);
        }

        if ($employee === null) {
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_no_employee_profile'),
            ], 403);
        }

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'outside_category' => ['required', 'string', Rule::in(Attendance::OUTSIDE_CATEGORIES)],
            'outside_location_name' => ['required', 'string', 'max:150'],
            'notes' => ['required', 'string', 'min:5', 'max:500'],
            'photo' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:25600'],
        ]);

        $company = $employee->company ?? $user->company;

        $openAttendance = Attendance::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('employee_id', $employee->id)
            ->where('status', '!=', 'rejected')
            ->whereNotNull('clock_in_at')
            ->whereNull('clock_out_at')
            ->latest('clock_in_at')
            ->first();

        if ($openAttendance !== null) {
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_already_clocked_in'),
            ], 422);
        }

        $nowWib = Carbon::now('Asia/Jakarta');

        if ($this->geofenceService->isEmployeeOnApprovedLeave($employee, $nowWib)) {
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_on_approved_leave'),
            ], 422);
        }

        $autoAssignEnabled = (bool) ($company->auto_assign_shift_on_clock_in ?? true);
        $preResolvedSchedule = $this->geofenceService->resolveTodaySchedule($employee, $nowWib, false);
        if (! $autoAssignEnabled && ($preResolvedSchedule === null || $preResolvedSchedule->isOff())) {
            return response()->json([
                'success' => false,
                'message' => __('attendance.unscheduled_clock_in_disabled'),
            ], 422);
        }

        $geofence = $this->geofenceService->validateGeofence(
            $company,
            (float) $validated['latitude'],
            (float) $validated['longitude']
        );

        try {
            $proofPhotoPath = $this->photoService->processAndStore(
                $request->file('photo'),
                (int) $company->id,
                (string) $employee->uuid
            );
        } catch (ValidationException $ve) {
            throw $ve;
        } catch (Throwable $e) {
            Log::error('Outside clock-in photo processing failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_photo_processing_failed'),
            ], 500);
        }

        $schedule = ($preResolvedSchedule !== null && ! $preResolvedSchedule->isOff())
            ? $preResolvedSchedule
            : $this->geofenceService->resolveTodaySchedule($employee, $nowWib, $autoAssignEnabled);
        $evaluation = $this->geofenceService->evaluateClockIn($schedule, $nowWib);
        $isProvisionalDp = PublicHoliday::isHoliday($nowWib, (int) $company->id);

        $attendance = Attendance::create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'work_schedule_id' => ($schedule !== null && ! $schedule->isOff()) ? $schedule->id : null,
            'clock_in_at' => $nowWib,
            'clock_in_latitude' => $validated['latitude'],
            'clock_in_longitude' => $validated['longitude'],
            'clock_in_distance_meters' => $geofence['distance_meters'],
            'status' => 'pending_verification',
            'late_minutes' => $evaluation['late_minutes'],
            'early_leave_minutes' => 0,
            'is_outside_geofence' => true,
            'is_provisional_dp' => $isProvisionalDp,
            'proof_photo_path' => $proofPhotoPath,
            'clock_in_photo_path' => $proofPhotoPath,
            'outside_category' => $validated['outside_category'],
            'outside_location_name' => $validated['outside_location_name'],
            'device_info' => substr((string) $request->userAgent(), 0, 255) ?: null,
            'ip_address' => $request->ip(),
            'notes' => $validated['notes'],
        ]);

        try {
            $push = $this->webPushService ?? app(WebPushService::class);
            $recipientIds = $push->resolveDepartmentManagersOrCompanyAdmins(
                (int) $company->id,
                $employee->department_id,
                $user->id
            )->pluck('id')->all();

            if ($recipientIds !== []) {
                SendWebPushNotificationJob::dispatch(
                    $recipientIds,
                    'attendance_outside_pending',
                    __('pwa.notif_outside_pending_title'),
                    __('pwa.notif_outside_pending_body', [
                        'name' => $user->name,
                        'location' => $validated['outside_location_name'],
                    ]),
                    route('attendance.verifications.index', [], false),
                    [
                        'attendance_id' => $attendance->id,
                        'attendance_uuid' => $attendance->uuid,
                        'employee_id' => $employee->id,
                    ]
                );
            }
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch outside attendance notification: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => __('attendance.outside_submit_success'),
            'status' => 'pending_verification',
            'distance' => $geofence['distance_meters'],
            'is_outside' => true,
            'data' => [
                'id' => $attendance->id,
                'uuid' => $attendance->uuid,
                'status' => $attendance->status,
                'is_outside_geofence' => $attendance->is_outside_geofence,
                'is_provisional_dp' => $attendance->is_provisional_dp,
                'outside_category' => $attendance->outside_category,
                'outside_location_name' => $attendance->outside_location_name,
                'proof_photo_path' => $attendance->proof_photo_path,
                'clock_in_photo_path' => $attendance->clock_in_photo_path,
                'late_minutes' => $attendance->late_minutes,
                'clock_in_at' => $attendance->clock_in_at?->timezone('Asia/Jakarta')->toIso8601String(),
                'clock_in_distance_meters' => $attendance->clock_in_distance_meters,
            ],
        ], 201);
    }

    /**
     * Record a GPS geofence-validated Clock-Out for the authenticated employee.
     */
    public function clockOut(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user?->employee;

        if ($this->isAttemptingCrossEmployeeAction($request, $user?->id, $employee?->id, $employee?->uuid)) {
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_unauthorized_self_only'),
            ], 403);
        }

        if ($employee === null) {
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_no_employee_profile'),
            ], 403);
        }

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'photo' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:25600'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $company = $employee->company ?? $user->company;

        $attendance = Attendance::withoutGlobalScopes()
            ->with('workSchedule.shift')
            ->where('company_id', $company->id)
            ->where('employee_id', $employee->id)
            ->where('status', '!=', 'rejected')
            ->whereNotNull('clock_in_at')
            ->whereNull('clock_out_at')
            ->latest('clock_in_at')
            ->first();

        if ($attendance === null) {
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_no_active_clock_in'),
            ], 422);
        }

        $geofence = $this->geofenceService->validateGeofence(
            $company,
            (float) $validated['latitude'],
            (float) $validated['longitude']
        );

        try {
            $clockOutPhotoPath = $this->photoService->processAndStore(
                $request->file('photo'),
                $company,
                (string) $employee->uuid
            );
        } catch (ValidationException $ve) {
            throw $ve;
        } catch (Throwable $e) {
            Log::error('Clock-out photo processing failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'success' => false,
                'message' => __('attendance.err_photo_processing_failed'),
            ], 500);
        }

        $nowWib = Carbon::now('Asia/Jakarta');
        $clockOutEval = $this->geofenceService->evaluateClockOut($attendance->workSchedule, $nowWib);

        $incomingNotes = trim((string) ($validated['notes'] ?? ''));
        $existingNotes = trim((string) $attendance->notes);
        $mergedNotes = $incomingNotes !== ''
            ? ($existingNotes !== '' ? $existingNotes."\n".$incomingNotes : $incomingNotes)
            : $attendance->notes;

        $attendance->update([
            'clock_out_at' => $nowWib,
            'clock_out_latitude' => $validated['latitude'],
            'clock_out_longitude' => $validated['longitude'],
            'clock_out_distance_meters' => $geofence['distance_meters'],
            'clock_out_photo_path' => $clockOutPhotoPath,
            'early_leave_minutes' => $clockOutEval['early_leave_minutes'],
            'notes' => $mergedNotes,
        ]);

        $attendance->refresh();
        $this->dpCommitService->attemptCommitFromAttendance($attendance, $user);
        $attendance->refresh();

        return response()->json([
            'success' => true,
            'message' => __('attendance.msg_clock_out_success'),
            'distance' => $geofence['distance_meters'],
            'is_outside' => ! $geofence['is_inside'],
            'data' => [
                'id' => $attendance->id,
                'uuid' => $attendance->uuid,
                'work_schedule_id' => $attendance->work_schedule_id,
                'status' => $attendance->status,
                'is_provisional_dp' => $attendance->is_provisional_dp,
                'dp_committed_at' => $attendance->dp_committed_at?->timezone('Asia/Jakarta')->toIso8601String(),
                'dp_ledger_entry_id' => $attendance->dp_ledger_entry_id,
                'late_minutes' => $attendance->late_minutes,
                'early_leave_minutes' => $attendance->early_leave_minutes,
                'clock_in_photo_path' => $attendance->clock_in_photo_path,
                'clock_out_photo_path' => $attendance->clock_out_photo_path,
                'clock_in_at' => $attendance->clock_in_at?->timezone('Asia/Jakarta')->toIso8601String(),
                'clock_out_at' => $attendance->clock_out_at?->timezone('Asia/Jakarta')->toIso8601String(),
                'clock_out_time' => $attendance->clock_out_at?->timezone('Asia/Jakarta')->format('H:i:s'),
                'clock_out_distance_meters' => $attendance->clock_out_distance_meters,
                'work_duration_hours' => $attendance->getWorkDurationInHours(),
            ],
        ]);
    }

    /**
     * Administrative correction of an attendance record (admin, super_user, super_admin).
     */
    public function update(Request $request, Attendance $attendance): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $attendance);

        $validated = $request->validate([
            'clock_in_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'clock_out_at' => ['nullable', 'date_format:Y-m-d H:i:s', 'after:clock_in_at'],
            'status' => ['required', Rule::in(['on_time', 'late', 'early_leave', 'pending_verification', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $clockIn = Carbon::createFromFormat('Y-m-d H:i:s', $validated['clock_in_at'], 'Asia/Jakarta');
        $clockOut = ! empty($validated['clock_out_at'])
            ? Carbon::createFromFormat('Y-m-d H:i:s', $validated['clock_out_at'], 'Asia/Jakarta')
            : null;

        $attendance->loadMissing('workSchedule.shift');
        $schedule = $attendance->workSchedule;

        $lateMinutes = 0;
        $earlyLeaveMinutes = 0;
        if ($schedule !== null && ! $schedule->isOff()) {
            $lateMinutes = $this->geofenceService->evaluateClockIn($schedule, $clockIn)['late_minutes'];
            if ($clockOut !== null) {
                $earlyLeaveMinutes = $this->geofenceService->evaluateClockOut($schedule, $clockOut)['early_leave_minutes'];
            }
        }

        if ($validated['status'] !== 'late') {
            $lateMinutes = $validated['status'] === 'on_time' ? 0 : $lateMinutes;
        } else {
            $lateMinutes = max(1, $lateMinutes);
        }

        $admin = $request->user();
        $auditLine = sprintf(
            'Dikoreksi oleh Admin %s pada %s',
            $admin->name,
            Carbon::now('Asia/Jakarta')->format('Y-m-d H:i:s').' WIB'
        );
        $baseNotes = trim((string) ($validated['notes'] ?? ''));
        $correction = $baseNotes !== '' ? $baseNotes.' | '.$auditLine : $auditLine;
        $existingNotes = trim((string) $attendance->notes);
        $notes = $existingNotes !== '' ? $existingNotes."\n".$correction : $correction;

        DB::transaction(function () use ($attendance, $validated, $clockIn, $clockOut, $lateMinutes, $earlyLeaveMinutes, $notes): void {
            $attendance->update([
                'clock_in_at' => $clockIn,
                'clock_out_at' => $clockOut,
                'status' => $validated['status'],
                'late_minutes' => $lateMinutes,
                'early_leave_minutes' => $earlyLeaveMinutes,
                'notes' => $notes,
            ]);
        });

        $attendance->refresh();
        $this->dpCommitService->attemptCommitFromAttendance($attendance, $admin);
        $attendance->refresh();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('attendance.edit_success'),
                'data' => [
                    'id' => $attendance->id,
                    'uuid' => $attendance->uuid,
                    'status' => $attendance->status,
                    'late_minutes' => $attendance->late_minutes,
                    'early_leave_minutes' => $attendance->early_leave_minutes,
                    'work_duration_hours' => $attendance->getWorkDurationInHours(),
                ],
            ]);
        }

        return redirect()->back()->with('success', __('attendance.edit_success'));
    }

    /**
     * Permanently delete an attendance record and its private selfie proof file.
     */
    public function destroy(Request $request, Attendance $attendance): JsonResponse|RedirectResponse
    {
        Gate::authorize('delete', $attendance);

        $photos = array_unique(array_filter([
            $attendance->clock_in_photo_path,
            $attendance->clock_out_photo_path,
            $attendance->proof_photo_path,
        ]));

        DB::transaction(function () use ($attendance, $photos): void {
            $attendance->delete();

            foreach ($photos as $path) {
                Storage::disk('local')->delete($path);
            }
        });

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('attendance.delete_success'),
            ]);
        }

        return redirect()->back()->with('success', __('attendance.delete_success'));
    }

    /**
     * Detect any attempt to spoof or pass another employee's identifier in the request.
     */
    private function isAttemptingCrossEmployeeAction(
        Request $request,
        ?int $selfUserId,
        ?int $selfEmployeeId,
        ?string $selfEmployeeUuid
    ): bool {
        if ($request->filled('employee_id') && (int) $request->input('employee_id') !== (int) $selfEmployeeId) {
            return true;
        }

        if ($request->filled('user_id') && (int) $request->input('user_id') !== (int) $selfUserId) {
            return true;
        }

        if ($request->filled('employee_uuid') && (string) $request->input('employee_uuid') !== (string) $selfEmployeeUuid) {
            return true;
        }

        if ($request->filled('work_schedule_id')) {
            $schedule = WorkSchedule::withoutGlobalScopes()->find($request->input('work_schedule_id'));
            if ($schedule !== null && (int) $schedule->employee_id !== (int) $selfEmployeeId) {
                return true;
            }
        }

        if ($request->filled('attendance_id')) {
            $targetAttendance = Attendance::withoutGlobalScopes()->find($request->input('attendance_id'));
            if ($targetAttendance !== null && (int) $targetAttendance->employee_id !== (int) $selfEmployeeId) {
                return true;
            }
        }

        return false;
    }
}
