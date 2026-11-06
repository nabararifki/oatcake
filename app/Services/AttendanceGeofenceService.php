<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
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
     * Resolve the active published work schedule for the employee on the given Asia/Jakarta date.
     * Supports multi-session (split shift) schedules on the same day:
     * - Session 1 is returned when no attendance has been completed today.
     * - Once Session 1 has both clock_in_at and clock_out_at, the next uncompleted session (Session 2) is returned.
     */
    public function resolveTodaySchedule(Employee $employee, ?Carbon $referenceTime = null): ?WorkSchedule
    {
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

        if ($schedules->isEmpty()) {
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
            return $schedules->first();
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

        $graceMinutes = (int) ($schedule->shift?->grace_period_minutes ?? 15);
        $thresholdDt = $startDt->copy()->addMinutes($graceMinutes);
        $clockInWib = $clockInAt->copy()->timezone('Asia/Jakarta');

        if ($clockInWib->lessThanOrEqualTo($thresholdDt)) {
            return [
                'status' => 'on_time',
                'late_minutes' => 0,
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
