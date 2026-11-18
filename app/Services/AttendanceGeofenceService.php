<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\WorkSchedule;
use Carbon\Carbon;

class AttendanceGeofenceService
{
    /**
     * Mean Earth radius in meters for Haversine formula.
     */
    public const EARTH_RADIUS_METERS = 6371000.0;

    /**
     * Default Novotel YIA coordinates fallback.
     */
    public const DEFAULT_LATITUDE = -7.9048250;
    public const DEFAULT_LONGITUDE = 110.0572110;

    /**
     * Calculate exact geodesic distance in meters between two WGS84 coordinates using the Haversine formula.
     */
    public function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lon2 - $lon1);

        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);

        $a = sin($deltaPhi / 2) ** 2
            + cos($phi1) * cos($phi2) * (sin($deltaLambda / 2) ** 2);

        $a = min(1.0, max(0.0, $a));
        $c = 2 * atan2(sqrt($a), sqrt(1.0 - $a));

        return round(self::EARTH_RADIUS_METERS * $c, 2);
    }

    /**
     * Validate whether the user's coordinates fall within the company's operational geofence radius.
     *
     * @return array{is_inside: bool, distance_meters: int, radius_limit: int}
     */
    public function validateGeofence(Company $company, float $userLat, float $userLon): array
    {
        $targetLat = $company->latitude !== null ? (float) $company->latitude : self::DEFAULT_LATITUDE;
        $targetLon = $company->longitude !== null ? (float) $company->longitude : self::DEFAULT_LONGITUDE;
        $radiusLimit = (int) ($company->geofence_radius ?? 100);

        $distanceMeters = (int) round($this->calculateDistance($targetLat, $targetLon, $userLat, $userLon));

        return [
            'is_inside' => $distanceMeters <= $radiusLimit,
            'distance_meters' => $distanceMeters,
            'radius_limit' => $radiusLimit,
        ];
    }

    /**
     * Check whether the employee is on approved leave (or has a LEAVE:-locked schedule) on the given date.
     */
    public function isEmployeeOnApprovedLeave(Employee $employee, ?Carbon $referenceTime = null): bool
    {
        $nowWib = ($referenceTime ?? Carbon::now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');
        $todayDate = $nowWib->toDateString();

        $hasLockedSchedule = WorkSchedule::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereDate('schedule_date', $todayDate)
            ->where('status', '!=', 'pending_delete')
            ->where('notes', 'like', 'LEAVE:%')
            ->exists();

        if ($hasLockedSchedule) {
            return true;
        }

        return LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $todayDate)
            ->whereDate('end_date', '>=', $todayDate)
            ->exists();
    }

    /**
     * Find the active operational shift whose start_time is closest to the clock-in time
     * using circular 24-hour distance (in minutes).
     */
    public function findNearestShiftForClockIn(Employee $employee, Carbon $clockInAt): ?Shift
    {
        $clockInWib = $clockInAt->copy()->timezone('Asia/Jakarta');
        $mIn = ((int) $clockInWib->format('G') * 60) + (int) $clockInWib->format('i');

        $allCandidates = Shift::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('is_active', true)
            ->where('code', '!=', 'OFF')
            ->whereNotNull('start_time')
            ->orderBy('id')
            ->get();

        if ($allCandidates->isEmpty()) {
            return null;
        }

        $isModEligible = (bool) ($employee->getAttribute('is_mod_eligible') ?? false)
            || $employee->tags()->withoutGlobalScopes()->where('code', 'MOD_ELIGIBLE')->exists();

        $candidates = $allCandidates->filter(function (Shift $shift) use ($isModEligible): bool {
            $isModShift = strtoupper((string) $shift->code) === 'MOD'
                || (bool) ($shift->getAttribute('is_mod') ?? false);

            if ($isModShift && ! $isModEligible) {
                return false;
            }

            return true;
        })->values();

        if ($candidates->isEmpty()) {
            $candidates = $allCandidates;
        }

        $bestShift = null;
        $bestDistance = PHP_INT_MAX;
        $bestStartsAtOrAfter = false;

        foreach ($candidates as $shift) {
            $startParsed = Carbon::parse($shift->start_time, 'Asia/Jakarta');
            $mShift = ((int) $startParsed->format('G') * 60) + (int) $startParsed->format('i');

            $delta = abs($mIn - $mShift);
            $distance = min($delta, 1440 - $delta);
            $startsAtOrAfter = $mShift >= $mIn;

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestShift = $shift;
                $bestStartsAtOrAfter = $startsAtOrAfter;
            } elseif ($distance === $bestDistance && $bestShift !== null) {
                if ($startsAtOrAfter && ! $bestStartsAtOrAfter) {
                    $bestShift = $shift;
                    $bestStartsAtOrAfter = true;
                } elseif ($startsAtOrAfter === $bestStartsAtOrAfter && $shift->id < $bestShift->id) {
                    $bestShift = $shift;
                }
            }
        }

        return $bestShift;
    }

    /**
     * Automatically create or convert an OFF WorkSchedule to the nearest operational shift on clock-in.
     */
    public function autoAssignNearestShift(
        Employee $employee,
        Carbon $clockInAt,
        ?WorkSchedule $existingOffSchedule = null
    ): ?WorkSchedule {
        if ($this->isEmployeeOnApprovedLeave($employee, $clockInAt)) {
            return $existingOffSchedule;
        }

        $nearestShift = $this->findNearestShiftForClockIn($employee, $clockInAt);
        if ($nearestShift === null) {
            return $existingOffSchedule;
        }

        $todayDate = $clockInAt->copy()->timezone('Asia/Jakarta')->toDateString();

        $targetSchedule = $existingOffSchedule ?? WorkSchedule::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereDate('schedule_date', $todayDate)
            ->where('status', '!=', 'pending_delete')
            ->first();

        if ($targetSchedule !== null) {
            if ($targetSchedule->isLockedLeave()) {
                return $targetSchedule;
            }

            if ($targetSchedule->isOff()) {
                $targetSchedule->update([
                    'shift_id' => $nearestShift->id,
                    'status' => 'published',
                    'custom_start_time' => null,
                    'custom_end_time' => null,
                    'notes' => 'Auto-assigned on clock-in',
                ]);

                return WorkSchedule::withoutGlobalScopes()
                    ->with([
                        'shift' => fn ($q) => $q->withoutGlobalScopes(),
                        'dutyTag' => fn ($q) => $q->withoutGlobalScopes(),
                    ])
                    ->find($targetSchedule->id);
            }

            return $targetSchedule;
        }

        $created = WorkSchedule::withoutGlobalScopes()->create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'shift_id' => $nearestShift->id,
            'schedule_date' => $todayDate,
            'status' => 'published',
            'notes' => 'Auto-assigned on clock-in',
        ]);

        return WorkSchedule::withoutGlobalScopes()
            ->with([
                'shift' => fn ($q) => $q->withoutGlobalScopes(),
                'dutyTag' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->find($created->id);
    }

    /**
     * Resolve the active published work schedule for the employee on the given Asia/Jakarta date.
     * Supports multi-session (split shift) schedules on the same day and auto-assignment on unscheduled clock-in.
     */
    public function resolveTodaySchedule(
        Employee $employee,
        ?Carbon $referenceTime = null,
        bool $autoAssignOnUnscheduled = true
    ): ?WorkSchedule {
        $nowWib = ($referenceTime ?? Carbon::now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');
        $todayDate = $nowWib->toDateString();

        $schedules = WorkSchedule::withoutGlobalScopes()
            ->with([
                'shift' => fn ($q) => $q->withoutGlobalScopes(),
                'dutyTag' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereDate('schedule_date', $todayDate)
            ->where('status', 'published')
            ->get()
            ->sortBy(fn (WorkSchedule $ws) => ($ws->getEffectiveStartTime() ?: '99:99:99') . '_' . str_pad((string) $ws->id, 10, '0', STR_PAD_LEFT))
            ->values();

        $company = $employee->relationLoaded('company')
            ? $employee->company
            : Company::withoutGlobalScopes()->find($employee->company_id);

        $allowAutoAssign = $autoAssignOnUnscheduled
            && (bool) ($company?->auto_assign_shift_on_clock_in ?? true);

        if ($schedules->isEmpty()) {
            if ($allowAutoAssign) {
                return $this->autoAssignNearestShift($employee, $nowWib);
            }

            return null;
        }

        $openAttendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereNotNull('work_schedule_id')
            ->whereNotNull('clock_in_at')
            ->whereNull('clock_out_at')
            ->latest('clock_in_at')
            ->first();

        if ($openAttendance !== null) {
            $activeSchedule = $schedules->firstWhere('id', $openAttendance->work_schedule_id);
            if ($activeSchedule !== null) {
                return $activeSchedule;
            }
        }

        $completedScheduleIds = Attendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereNotNull('work_schedule_id')
            ->whereNotNull('clock_out_at')
            ->pluck('work_schedule_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $workingSchedules = $schedules->filter(fn (WorkSchedule $ws) => ! $ws->isOff())->values();

        if ($workingSchedules->isEmpty()) {
            $offSchedule = $schedules->first();
            if ($allowAutoAssign) {
                return $this->autoAssignNearestShift($employee, $nowWib, $offSchedule);
            }

            return $offSchedule;
        }

        $uncompleted = $workingSchedules->first(
            fn (WorkSchedule $ws) => ! in_array((int) $ws->id, $completedScheduleIds, true)
        );

        return $uncompleted ?? $workingSchedules->last();
    }

    /**
     * Resolve multi-session (split shift) state for the Dashboard attendance widget.
     *
     * @return array{
     *     working_schedules: \Illuminate\Support\Collection<int, WorkSchedule>,
     *     completed_count: int,
     *     next_pending_schedule: ?WorkSchedule,
     *     is_waiting_next_session: bool,
     *     next_session_index: int,
     *     next_session_start_time: string
     * }
     */
    public function resolveTodaySessionsState(Employee $employee, ?Carbon $referenceTime = null): array
    {
        $nowWib = ($referenceTime ?? Carbon::now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');
        $todayDate = $nowWib->toDateString();

        $workingSchedules = WorkSchedule::withoutGlobalScopes()
            ->with([
                'shift' => fn ($q) => $q->withoutGlobalScopes(),
                'dutyTag' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereDate('schedule_date', $todayDate)
            ->where('status', 'published')
            ->get()
            ->filter(fn (WorkSchedule $ws) => ! $ws->isOff())
            ->sortBy(fn (WorkSchedule $ws) => ($ws->getEffectiveStartTime() ?: '99:99:99') . '_' . str_pad((string) $ws->id, 10, '0', STR_PAD_LEFT))
            ->values();

        $openAttendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereNotNull('clock_in_at')
            ->whereNull('clock_out_at')
            ->latest('clock_in_at')
            ->first();

        $completedScheduleIds = Attendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereNotNull('work_schedule_id')
            ->whereNotNull('clock_out_at')
            ->pluck('work_schedule_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $completedCount = $workingSchedules
            ->filter(fn (WorkSchedule $ws) => in_array((int) $ws->id, $completedScheduleIds, true))
            ->count();

        $nextPendingSchedule = $workingSchedules->first(
            fn (WorkSchedule $ws) => ! in_array((int) $ws->id, $completedScheduleIds, true)
        );

        $isWaitingNextSession = $openAttendance === null
            && $completedCount > 0
            && $nextPendingSchedule !== null;

        $nextSessionIndex = $completedCount + 1;
        $nextSessionStartTime = $nextPendingSchedule
            ? substr($nextPendingSchedule->getEffectiveStartTime(), 0, 5)
            : '';

        return [
            'working_schedules' => $workingSchedules,
            'completed_count' => $completedCount,
            'next_pending_schedule' => $nextPendingSchedule,
            'is_waiting_next_session' => $isWaitingNextSession,
            'next_session_index' => $nextSessionIndex,
            'next_session_start_time' => $nextSessionStartTime,
        ];
    }

    /**
     * Evaluate clock-in punctuality against the shift's effective start time and grace period.
     *
     * @return array{status: string, late_minutes: int}
     */
    public function evaluateClockIn(?WorkSchedule $schedule, Carbon $clockInAt): array
    {
        if ($schedule === null || $schedule->isOff()) {
            return [
                'status' => 'on_time',
                'late_minutes' => 0,
            ];
        }

        $startDt = $schedule->getEffectiveStartDateTime();
        if ($startDt === null) {
            return [
                'status' => 'on_time',
                'late_minutes' => 0,
            ];
        }

        $clockInWib = $clockInAt->copy()->timezone('Asia/Jakarta');

        // Align cross-midnight startDt when circular difference exceeds 12 hours (720m)
        $rawSignedDiff = $startDt->diffInMinutes($clockInWib, false);
        if ($rawSignedDiff > 720) {
            $startDt = $startDt->copy()->addDay();
        } elseif ($rawSignedDiff < -720) {
            $startDt = $startDt->copy()->subDay();
        }

        $graceMinutes = (int) ($schedule->shift?->grace_period_minutes ?? 15);
        $thresholdDt = $startDt->copy()->addMinutes($graceMinutes);

        if ($clockInWib->lessThanOrEqualTo($thresholdDt)) {
            $withinGraceLateMinutes = 0;
            if ($schedule->notes === 'Auto-assigned on clock-in' && $clockInWib->greaterThan($startDt)) {
                $withinGraceLateMinutes = (int) floor($startDt->diffInMinutes($clockInWib));
            }

            return [
                'status' => 'on_time',
                'late_minutes' => $withinGraceLateMinutes,
            ];
        }

        $lateMinutes = (int) floor($startDt->diffInMinutes($clockInWib));

        return [
            'status' => 'late',
            'late_minutes' => max(1, $lateMinutes),
        ];
    }

    /**
     * Evaluate clock-out against the shift's effective end time to calculate early leave minutes.
     *
     * @return array{early_leave_minutes: int, is_early_leave: bool}
     */
    public function evaluateClockOut(?WorkSchedule $schedule, Carbon $clockOutAt): array
    {
        if ($schedule === null || $schedule->isOff()) {
            return [
                'early_leave_minutes' => 0,
                'is_early_leave' => false,
            ];
        }

        $endDt = $schedule->getEffectiveEndDateTime();
        if ($endDt === null) {
            return [
                'early_leave_minutes' => 0,
                'is_early_leave' => false,
            ];
        }

        $clockOutWib = $clockOutAt->copy()->timezone('Asia/Jakarta');

        if ($clockOutWib->lessThan($endDt)) {
            $earlyMinutes = (int) floor($clockOutWib->diffInMinutes($endDt));

            return [
                'early_leave_minutes' => max(0, $earlyMinutes),
                'is_early_leave' => $earlyMinutes > 0,
            ];
        }

        return [
            'early_leave_minutes' => 0,
            'is_early_leave' => false,
        ];
    }
}
