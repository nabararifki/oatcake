<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\SmartDepartmentWeight;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SmartDecisionService
{
    /**
     * Objective quantitative SPK SMART criteria definitions (C1..C5).
     *
     * @var array<string, array{
     *     code: string,
     *     type: string,
     *     min: float,
     *     max: float,
     *     unit: string,
     *     default_weight: int
     * }>
     */
    public const CRITERIA_DEFINITIONS = [
        'C1' => [
            'code' => 'C1',
            'type' => 'benefit',
            'min' => 0.0,
            'max' => 100.0,
            'unit' => '%',
            'default_weight' => 30,
        ],
        'C2' => [
            'code' => 'C2',
            'type' => 'cost',
            'min' => 0.0,
            'max' => 90.0,
            'unit' => 'days',
            'default_weight' => 20,
        ],
        'C3' => [
            'code' => 'C3',
            'type' => 'benefit',
            'min' => 0.0,
            'max' => 100.0,
            'unit' => '%',
            'default_weight' => 15,
        ],
        'C4' => [
            'code' => 'C4',
            'type' => 'cost',
            'min' => 0.0,
            'max' => 12.0,
            'unit' => 'days',
            'default_weight' => 15,
        ],
        'C5' => [
            'code' => 'C5',
            'type' => 'benefit',
            'min' => 0.0,
            'max' => 14.0,
            'unit' => 'days',
            'default_weight' => 20,
        ],
    ];

    public const REC_RECOMMENDED = 'recommended';
    public const REC_CAUTION = 'caution';
    public const REC_HIGH_RISK = 'high_risk';

    /**
     * Retrieve or automatically provision the 5 SPK SMART criteria weights for a department.
     *
     * @return Collection<int, SmartDepartmentWeight>
     */
    public function getOrCreateDepartmentWeights(Department $department): Collection
    {
        $existing = SmartDepartmentWeight::withoutGlobalScopes()
            ->where('department_id', $department->id)
            ->get()
            ->keyBy('criteria_code');

        if ($existing->count() < count(self::CRITERIA_DEFINITIONS)) {
            $totalDefault = array_sum(array_column(self::CRITERIA_DEFINITIONS, 'default_weight'));

            foreach (self::CRITERIA_DEFINITIONS as $code => $definition) {
                if (! $existing->has($code)) {
                    $rawWeight = (int) $definition['default_weight'];
                    $normalized = round($rawWeight / max(1, $totalDefault), 4);

                    $created = SmartDepartmentWeight::withoutGlobalScopes()->updateOrCreate(
                        [
                            'department_id' => $department->id,
                            'criteria_code' => $code,
                        ],
                        [
                            'company_id' => $department->company_id,
                            'raw_weight' => $rawWeight,
                            'normalized_weight' => $normalized,
                        ]
                    );

                    $existing->put($code, $created);
                }
            }
        }

        return collect(array_keys(self::CRITERIA_DEFINITIONS))
            ->map(fn (string $code): SmartDepartmentWeight => $existing->get($code))
            ->values();
    }

    /**
     * Validate, normalize, and persist dynamic department SMART weights,
     * then automatically recalculate all pending leave requests in that department.
     *
     * @param  array<string, int|float|string>  $weightsByCode
     * @return Collection<int, SmartDepartmentWeight>
     */
    public function updateDepartmentWeights(
        Department $department,
        array $weightsByCode,
        ?User $actor = null
    ): Collection {
        $rawWeights = [];
        $sumRaw = 0;

        foreach (self::CRITERIA_DEFINITIONS as $code => $definition) {
            if (! array_key_exists($code, $weightsByCode) || ! is_numeric($weightsByCode[$code])) {
                throw ValidationException::withMessages([
                    'weights' => [__('leave.err_smart_weight_range')],
                ]);
            }

            $numericVal = (float) $weightsByCode[$code];
            if (floor($numericVal) !== $numericVal) {
                throw ValidationException::withMessages([
                    'weights' => [__('leave.err_smart_weight_range')],
                ]);
            }

            $value = (int) $numericVal;
            if ($value < 0 || $value > 100) {
                throw ValidationException::withMessages([
                    'weights' => [__('leave.err_smart_weight_range')],
                ]);
            }

            $rawWeights[$code] = $value;
            $sumRaw += $value;
        }

        if ($sumRaw <= 0) {
            throw ValidationException::withMessages([
                'weights' => [__('leave.err_smart_weights_zero_sum')],
            ]);
        }

        if ($sumRaw !== 100) {
            throw ValidationException::withMessages([
                'weights' => [__('leave.err_smart_weights_must_equal_100')],
            ]);
        }

        // Calculate normalized weights w'_j = round(w_j / sum(w_k), 4)
        $normalizedWeights = [];
        $runningSum = 0.0;
        $lastNonZeroCode = null;

        foreach ($rawWeights as $code => $raw) {
            $norm = $raw > 0 ? round($raw / $sumRaw, 4) : 0.0;
            $normalizedWeights[$code] = $norm;
            $runningSum = round($runningSum + $norm, 4);
            if ($raw > 0) {
                $lastNonZeroCode = $code;
            }
        }

        // Ensure exact 1.0000 sum across normalized weights when rounding
        $drift = round(1.0000 - $runningSum, 4);
        if ($drift !== 0.0 && $lastNonZeroCode !== null) {
            $normalizedWeights[$lastNonZeroCode] = round($normalizedWeights[$lastNonZeroCode] + $drift, 4);
        }

        return DB::transaction(function () use ($department, $rawWeights, $normalizedWeights, $actor): Collection {
            $updatedCollection = collect();

            foreach (self::CRITERIA_DEFINITIONS as $code => $definition) {
                $record = SmartDepartmentWeight::withoutGlobalScopes()->updateOrCreate(
                    [
                        'department_id' => $department->id,
                        'criteria_code' => $code,
                    ],
                    [
                        'company_id' => $department->company_id,
                        'raw_weight' => $rawWeights[$code],
                        'normalized_weight' => $normalizedWeights[$code],
                        'updated_by' => $actor?->id ?? auth()->id(),
                    ]
                );

                $updatedCollection->push($record);
            }

            // Recalculate all pending leave requests in this department
            $pendingRequests = LeaveRequest::withoutGlobalScopes()
                ->with([
                    'employee' => fn ($q) => $q->withoutGlobalScopes()->with(['department' => fn ($dq) => $dq->withoutGlobalScopes()]),
                    'leaveType' => fn ($q) => $q->withoutGlobalScopes(),
                ])
                ->where('company_id', $department->company_id)
                ->whereIn('status', [
                    LeaveRequest::STATUS_PENDING_MANAGER,
                    LeaveRequest::STATUS_PENDING_HR,
                ])
                ->whereHas('employee', function ($q) use ($department): void {
                    $q->withoutGlobalScopes()->where('department_id', $department->id);
                })
                ->get();

            foreach ($pendingRequests as $pendingRequest) {
                $this->evaluateSingle($pendingRequest, true);
            }

            return $updatedCollection;
        });
    }

    /**
     * Mode B (Evaluasi Mandiri): Calculate C1..C5 telemetry, SMART utilities, weighted contributions,
     * final preference score U(a_i), and recommendation category for a single leave request.
     *
     * @return array<string, mixed>
     */
    public function evaluateSingle(LeaveRequest $leaveRequest, bool $persist = true): array
    {
        $leaveRequest->loadMissing([
            'employee' => fn ($q) => $q->withoutGlobalScopes()->with(['department' => fn ($dq) => $dq->withoutGlobalScopes()]),
            'leaveType' => fn ($q) => $q->withoutGlobalScopes(),
        ]);

        $employee = $leaveRequest->employee;
        $department = $employee?->department;
        $leaveType = $leaveRequest->leaveType;

        if ($employee === null || $department === null || $leaveType === null) {
            return [
                'mode' => 'B',
                'smart_score' => 0.0,
                'recommendation' => self::REC_HIGH_RISK,
                'criteria' => [],
            ];
        }

        $weights = $this->getOrCreateDepartmentWeights($department)->keyBy('criteria_code');

        $rawValues = $this->extractRawCriteriaValues($leaveRequest, $employee, $department, $leaveType);

        $criteriaBreakdown = [];
        $totalScore = 0.0;

        foreach (self::CRITERIA_DEFINITIONS as $code => $def) {
            $rawValue = (float) ($rawValues[$code] ?? 0.0);
            $utility = $this->calculateUtility($rawValue, $def['min'], $def['max'], $def['type']);

            /** @var SmartDepartmentWeight|null $weightRow */
            $weightRow = $weights->get($code);
            $rawWeight = $weightRow !== null ? (int) $weightRow->raw_weight : (int) $def['default_weight'];
            $normalizedWeight = $weightRow !== null ? round((float) $weightRow->normalized_weight, 4) : 0.2000;

            $weightedScore = round($normalizedWeight * $utility, 2);
            $totalScore += ($normalizedWeight * $utility);

            $criteriaBreakdown[$code] = [
                'code' => $code,
                'name' => $this->resolveCriterionLabel($code),
                'type' => $def['type'],
                'min' => $def['min'],
                'max' => $def['max'],
                'unit' => $def['unit'],
                'raw_value' => round($rawValue, 2),
                'utility' => round($utility, 2),
                'raw_weight' => $rawWeight,
                'normalized_weight' => $normalizedWeight,
                'weighted_score' => $weightedScore,
            ];
        }

        $finalScore = round($totalScore, 2);
        $recommendation = $this->resolveRecommendation($finalScore);

        $payload = [
            'mode' => 'B',
            'leave_request_id' => $leaveRequest->id,
            'leave_request_uuid' => $leaveRequest->uuid,
            'department_id' => $department->id,
            'department_name' => $department->name,
            'min_staff_per_shift' => max(1, (int) ($department->min_staff_per_shift ?? 1)),
            'smart_score' => $finalScore,
            'recommendation' => $recommendation,
            'recommendation_label' => $this->resolveRecommendationLabel($recommendation),
            'criteria' => $criteriaBreakdown,
            'evaluated_at' => Carbon::now('Asia/Jakarta')->toIso8601String(),
        ];

        if ($persist && $leaveRequest->exists) {
            $leaveRequest->update([
                'smart_score' => $finalScore,
                'smart_metrics' => $payload,
            ]);
        }

        return $payload;
    }

    /**
     * Mode A & Mode B Resolver: Evaluate a target leave request in its departmental context.
     * Detects overlapping pending leave requests in the same department:
     * - If 1 request: returns Mode B (Mandiri)
     * - If >= 2 overlapping requests: returns Mode A (Multi-Staf Comparative Ranking & Quota Simulation)
     *
     * @return array<string, mixed>
     */
    public function evaluateComparativeForRequest(LeaveRequest $targetRequest): array
    {
        $targetRequest->loadMissing([
            'employee' => fn ($q) => $q->withoutGlobalScopes()->with([
                'user' => fn ($uq) => $uq->withoutGlobalScopes(),
                'department' => fn ($dq) => $dq->withoutGlobalScopes(),
            ]),
            'leaveType' => fn ($q) => $q->withoutGlobalScopes(),
        ]);

        $targetEmployee = $targetRequest->employee;
        $department = $targetEmployee?->department;

        if ($targetEmployee === null || $department === null) {
            return $this->evaluateSingle($targetRequest, false);
        }

        $targetStart = $targetRequest->start_date instanceof CarbonInterface
            ? $targetRequest->start_date->toDateString()
            : Carbon::parse($targetRequest->start_date, 'Asia/Jakarta')->toDateString();

        $targetEnd = $targetRequest->end_date instanceof CarbonInterface
            ? $targetRequest->end_date->toDateString()
            : Carbon::parse($targetRequest->end_date, 'Asia/Jakarta')->toDateString();

        // Find all active pending requests in the same department that overlap with targetRequest's date range
        $overlappingRequests = LeaveRequest::withoutGlobalScopes()
            ->with([
                'employee' => fn ($q) => $q->withoutGlobalScopes()->with([
                    'user' => fn ($uq) => $uq->withoutGlobalScopes(),
                    'department' => fn ($dq) => $dq->withoutGlobalScopes(),
                ]),
                'leaveType' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->where('company_id', $targetRequest->company_id)
            ->whereIn('status', [
                LeaveRequest::STATUS_PENDING_MANAGER,
                LeaveRequest::STATUS_PENDING_HR,
            ])
            ->whereDate('start_date', '<=', $targetEnd)
            ->whereDate('end_date', '>=', $targetStart)
            ->whereHas('employee', function ($q) use ($department): void {
                $q->withoutGlobalScopes()->where('department_id', $department->id);
            })
            ->get();

        // Ensure targetRequest itself is in the collection even if its status is already approved/rejected/cancelled
        if (! $overlappingRequests->contains('id', $targetRequest->id)) {
            $overlappingRequests->push($targetRequest);
        }

        $mode = $overlappingRequests->count() >= 2 ? 'A' : 'B';

        // Evaluate each candidate with the department's current weights
        $evaluatedById = [];
        foreach ($overlappingRequests as $req) {
            $evaluatedById[$req->id] = $this->evaluateSingle($req, $req->isPending());
        }

        // Sort candidates by smart_score DESC, created_at ASC, id ASC
        $sortedCandidates = $overlappingRequests->sort(function (LeaveRequest $a, LeaveRequest $b) use ($evaluatedById): int {
            $scoreA = (float) ($evaluatedById[$a->id]['smart_score'] ?? 0.0);
            $scoreB = (float) ($evaluatedById[$b->id]['smart_score'] ?? 0.0);

            if (abs($scoreA - $scoreB) > 0.0001) {
                return $scoreB <=> $scoreA;
            }

            $timeA = $a->created_at ? $a->created_at->getTimestamp() : 0;
            $timeB = $b->created_at ? $b->created_at->getTimestamp() : 0;

            if ($timeA !== $timeB) {
                return $timeA <=> $timeB;
            }

            return $a->id <=> $b->id;
        })->values();

        // Simulate departmental formation quota across ranked candidates
        $requiredStaff = max(1, (int) ($department->min_staff_per_shift ?? 1));
        $activeDeptEmployeeIds = Employee::withoutGlobalScopes()
            ->where('company_id', $department->company_id)
            ->where('department_id', $department->id)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        $totalDeptStaff = count($activeDeptEmployeeIds);
        $availableByDateCache = [];
        $simulatedApprovedByDate = [];

        $candidatesList = [];
        $targetRank = 1;
        $targetCanApproveWithinQuota = true;

        foreach ($sortedCandidates as $index => $candidate) {
            $rank = $index + 1;
            $eval = $evaluatedById[$candidate->id];

            $candStart = Carbon::parse($candidate->start_date, 'Asia/Jakarta')->startOfDay();
            $candEnd = Carbon::parse($candidate->end_date, 'Asia/Jakarta')->startOfDay();

            $canApprove = true;
            $candidateDates = [];

            $cursor = $candStart->copy();
            while ($cursor->lessThanOrEqualTo($candEnd)) {
                $dStr = $cursor->toDateString();
                $candidateDates[] = $dStr;

                if (! array_key_exists($dStr, $availableByDateCache)) {
                    $availableByDateCache[$dStr] = $this->getAvailableDeptStaffBeforePendingOnDate(
                        $department,
                        $activeDeptEmployeeIds,
                        $totalDeptStaff,
                        $dStr
                    );
                }

                $alreadySimulated = $simulatedApprovedByDate[$dStr] ?? 0;
                $remainingIfApproved = $availableByDateCache[$dStr] - $alreadySimulated - 1;

                if ($remainingIfApproved < $requiredStaff) {
                    $canApprove = false;
                }

                $cursor->addDay();
            }

            if ($canApprove) {
                foreach ($candidateDates as $dStr) {
                    $simulatedApprovedByDate[$dStr] = ($simulatedApprovedByDate[$dStr] ?? 0) + 1;
                }
            }

            if ($candidate->id === $targetRequest->id) {
                $targetRank = $rank;
                $targetCanApproveWithinQuota = $canApprove;
            }

            $candidatesList[] = [
                'rank' => $rank,
                'leave_request_id' => $candidate->id,
                'leave_request_uuid' => $candidate->uuid,
                'is_target' => $candidate->id === $targetRequest->id,
                'employee_id' => $candidate->employee_id,
                'employee_name' => $candidate->employee?->user?->name ?? '—',
                'position' => $candidate->employee?->position ?? '—',
                'leave_type_code' => $candidate->leaveType?->code ?? '—',
                'leave_type_name' => $candidate->leaveType?->name ?? '—',
                'start_date' => $candStart->toDateString(),
                'end_date' => $candEnd->toDateString(),
                'total_days' => (float) $candidate->total_days,
                'smart_score' => (float) $eval['smart_score'],
                'recommendation' => $eval['recommendation'],
                'recommendation_label' => $eval['recommendation_label'],
                'can_approve_within_quota' => $canApprove,
                'criteria' => $eval['criteria'],
            ];
        }

        $targetEval = $evaluatedById[$targetRequest->id];

        return [
            'mode' => $mode,
            'mode_label' => $mode === 'A' ? __('leave.smart_mode_a_label') : __('leave.smart_mode_b_label'),
            'target_request_id' => $targetRequest->id,
            'target_request_uuid' => $targetRequest->uuid,
            'employee_name' => $targetEmployee->user?->name ?? '—',
            'position' => $targetEmployee->position ?? '—',
            'leave_type_code' => $targetRequest->leaveType?->code ?? '—',
            'leave_type_name' => $targetRequest->leaveType?->name ?? '—',
            'start_date' => $targetStart,
            'end_date' => $targetEnd,
            'total_days' => (float) $targetRequest->total_days,
            'department_id' => $department->id,
            'department_name' => $department->name,
            'min_staff_per_shift' => $requiredStaff,
            'total_department_staff' => $totalDeptStaff,
            'smart_score' => (float) $targetEval['smart_score'],
            'recommendation' => $targetEval['recommendation'],
            'recommendation_label' => $targetEval['recommendation_label'],
            'rank' => $targetRank,
            'total_candidates' => count($candidatesList),
            'can_approve_within_quota' => $targetCanApproveWithinQuota,
            'criteria' => $targetEval['criteria'],
            'candidates' => $candidatesList,
            'evaluated_at' => Carbon::now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    /**
     * Extract raw objective telemetry values C1..C5 from the database.
     *
     * @return array{C1: float, C2: float, C3: float, C4: float, C5: float}
     */
    public function extractRawCriteriaValues(
        LeaveRequest $leaveRequest,
        Employee $employee,
        Department $department,
        LeaveType $leaveType
    ): array {
        $refDateTime = $leaveRequest->created_at
            ? Carbon::parse($leaveRequest->created_at)->setTimezone('Asia/Jakarta')
            : Carbon::now('Asia/Jakarta');

        $refDate = $refDateTime->copy()->startOfDay();
        $startDate = Carbon::parse($leaveRequest->start_date, 'Asia/Jakarta')->startOfDay();
        $endDate = Carbon::parse($leaveRequest->end_date, 'Asia/Jakarta')->startOfDay();

        $c1 = $this->extractC1ManpowerCoverage($employee, $department, $startDate, $endDate, $leaveRequest->id);
        $c2 = $this->extractC2BalanceExpiryProximity($employee, $leaveType, $refDate);
        $c3 = $this->extractC3AttendanceDiscipline($employee, $refDateTime);
        $c4 = $this->extractC4RecentLeaveFrequency($employee, $refDate, $leaveRequest->id);
        $c5 = $this->extractC5SubmissionLeadTime($refDate, $startDate);

        return [
            'C1' => round($c1, 2),
            'C2' => round($c2, 2),
            'C3' => round($c3, 2),
            'C4' => round($c4, 2),
            'C5' => round($c5, 2),
        ];
    }

    /**
     * C1: Manpower Coverage (Benefit [0, 100]).
     */
    private function extractC1ManpowerCoverage(
        Employee $employee,
        Department $department,
        Carbon $startDate,
        Carbon $endDate,
        ?int $excludeRequestId = null
    ): float {
        $activeDeptEmployeeIds = Employee::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('department_id', $department->id)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        $totalDeptStaff = count($activeDeptEmployeeIds);
        $required = max(1, (int) ($department->min_staff_per_shift ?? 1));

        if ($totalDeptStaff === 0) {
            return 0.0;
        }

        $minDailyCoverage = 100.0;
        $cursor = $startDate->copy();

        while ($cursor->lessThanOrEqualTo($endDate)) {
            $dateStr = $cursor->toDateString();

            $availableBeforeApplicant = $this->getAvailableDeptStaffBeforePendingOnDate(
                $department,
                $activeDeptEmployeeIds,
                $totalDeptStaff,
                $dateStr,
                $employee->id,
                $excludeRequestId
            );

            // Subtract 1 for the applicant requesting leave on this date
            $remaining = max(0, $availableBeforeApplicant - 1);

            if ($remaining < $required) {
                $dailyRatio = ($remaining / $required) * 50.0;
            } else {
                $baselinePool = max($required, $totalDeptStaff - 1);
                $excessCapacity = max(1, $baselinePool - $required + 1);
                $actualExcess = min($excessCapacity, $remaining - $required + 1);
                $dailyRatio = 50.0 + (($actualExcess / $excessCapacity) * 50.0);
            }

            $dailyRatio = $this->clamp($dailyRatio, 0.0, 100.0);
            if ($dailyRatio < $minDailyCoverage) {
                $minDailyCoverage = $dailyRatio;
            }

            $cursor->addDay();
        }

        return $this->clamp($minDailyCoverage, 0.0, 100.0);
    }

    /**
     * Helper: Compute how many active department staff are scheduled/available on a specific date
     * after subtracting already-approved leave requests on that date.
     *
     * @param  list<int>  $activeDeptEmployeeIds
     */
    private function getAvailableDeptStaffBeforePendingOnDate(
        Department $department,
        array $activeDeptEmployeeIds,
        int $totalDeptStaff,
        string $dateStr,
        ?int $applicantEmployeeId = null,
        ?int $excludeRequestId = null
    ): int {
        if ($totalDeptStaff === 0) {
            return 0;
        }

        $publishedSchedules = WorkSchedule::withoutGlobalScopes()
            ->with(['shift' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('company_id', $department->company_id)
            ->whereIn('employee_id', $activeDeptEmployeeIds)
            ->where('status', 'published')
            ->where(function ($q): void {
                $q->whereNull('notes')
                    ->orWhere('notes', 'not like', 'LEAVE:%');
            })
            ->whereDate('schedule_date', $dateStr)
            ->get();

        if ($publishedSchedules->isEmpty()) {
            $scheduledWorkingEmployeeIds = $activeDeptEmployeeIds;
        } else {
            $workingIds = $publishedSchedules
                ->groupBy('employee_id')
                ->filter(function (Collection $empSchedules): bool {
                    return ! $empSchedules->every(function (WorkSchedule $ws): bool {
                        return $ws->isOff()
                            || (bool) $ws->getAttribute('is_day_off')
                            || ($ws->shift !== null && strtoupper((string) $ws->shift->code) === 'OFF');
                    });
                })
                ->keys()
                ->map(fn ($id): int => (int) $id)
                ->all();

            // Always ensure the applicant is part of the pool before subtracting 1 if they are applying for this date
            if ($applicantEmployeeId !== null && ! in_array($applicantEmployeeId, $workingIds, true)) {
                $workingIds[] = $applicantEmployeeId;
            }

            $scheduledWorkingEmployeeIds = $workingIds;
        }

        $approvedLeaveQuery = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $department->company_id)
            ->whereIn('employee_id', $scheduledWorkingEmployeeIds)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateStr)
            ->whereDate('end_date', '>=', $dateStr);

        if ($applicantEmployeeId !== null) {
            $approvedLeaveQuery->where('employee_id', '!=', $applicantEmployeeId);
        }

        if ($excludeRequestId !== null) {
            $approvedLeaveQuery->where('id', '!=', $excludeRequestId);
        }

        $approvedAbsentCount = $approvedLeaveQuery
            ->distinct('employee_id')
            ->count('employee_id');

        return max(0, count($scheduledWorkingEmployeeIds) - $approvedAbsentCount);
    }

    /**
     * C2: Balance Expiry Proximity (Cost [0, 90]).
     */
    private function extractC2BalanceExpiryProximity(
        Employee $employee,
        LeaveType $leaveType,
        Carbon $refDate
    ): float {
        if (! $leaveType->requires_quota) {
            return 45.0;
        }

        $today = Carbon::now('Asia/Jakarta')->startOfDay();
        $lookupFloor = $refDate->lessThan($today) ? $refDate->toDateString() : $today->toDateString();

        $nearestBucket = LeaveLedgerEntry::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('remaining_amount', '>', 0)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', $lookupFloor)
            ->orderBy('expires_at', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        if ($nearestBucket === null || $nearestBucket->expires_at === null) {
            return 90.0;
        }

        $expiryDate = Carbon::parse($nearestBucket->expires_at, 'Asia/Jakarta')->startOfDay();
        $daysUntilExpiry = (float) $refDate->diffInDays($expiryDate, false);

        return $this->clamp($daysUntilExpiry, 0.0, 90.0);
    }

    /**
     * C3: Attendance Discipline in the last 90 days (Benefit [0, 100]).
     */
    private function extractC3AttendanceDiscipline(
        Employee $employee,
        Carbon $refDateTime
    ): float {
        $windowStart = $refDateTime->copy()->subDays(90)->startOfDay();

        $attendances = Attendance::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['on_time', 'late', 'early_leave'])
            ->where('clock_in_at', '>=', $windowStart)
            ->get(['id', 'status', 'late_minutes']);

        $totalRecords = $attendances->count();
        if ($totalRecords === 0) {
            return 100.0;
        }

        $disciplinedCount = $attendances->filter(function (Attendance $attendance): bool {
            return $attendance->status === 'on_time' && (int) ($attendance->late_minutes ?? 0) === 0;
        })->count();

        $percentage = ($disciplinedCount / $totalRecords) * 100.0;

        return $this->clamp($percentage, 0.0, 100.0);
    }

    /**
     * C4: Recent Leave Frequency in the last 90 days (Cost [0, 12]).
     */
    private function extractC4RecentLeaveFrequency(
        Employee $employee,
        Carbon $refDate,
        ?int $excludeRequestId = null
    ): float {
        $windowStart = $refDate->copy()->subDays(90)->toDateString();

        $query = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('end_date', '>=', $windowStart);

        if ($excludeRequestId !== null) {
            $query->where('id', '!=', $excludeRequestId);
        }

        $sumApprovedDays = (float) $query->sum('total_days');

        return $this->clamp($sumApprovedDays, 0.0, 12.0);
    }

    /**
     * C5: Submission Lead Time (Benefit [0, 14]).
     */
    private function extractC5SubmissionLeadTime(
        Carbon $refDate,
        Carbon $startDate
    ): float {
        $leadDays = (float) $refDate->diffInDays($startDate, false);

        return $this->clamp($leadDays, 0.0, 14.0);
    }

    /**
     * Calculate SMART utility u_j(a_i) in [0, 100] for a criterion.
     */
    public function calculateUtility(float $rawValue, float $min, float $max, string $type): float
    {
        if ($max <= $min) {
            return 100.0;
        }

        $clamped = $this->clamp($rawValue, $min, $max);

        if ($type === 'cost') {
            $utility = (($max - $clamped) / ($max - $min)) * 100.0;
        } else {
            $utility = (($clamped - $min) / ($max - $min)) * 100.0;
        }

        return round($this->clamp($utility, 0.0, 100.0), 2);
    }

    /**
     * Resolve recommendation category from final preference score U(a_i).
     */
    public function resolveRecommendation(float $score): string
    {
        if ($score >= 70.00) {
            return self::REC_RECOMMENDED;
        }

        if ($score >= 50.00) {
            return self::REC_CAUTION;
        }

        return self::REC_HIGH_RISK;
    }

    /**
     * Resolve translated label for a criterion code.
     */
    private function resolveCriterionLabel(string $code): string
    {
        return match ($code) {
            'C1' => __('leave.smart_criteria_c1'),
            'C2' => __('leave.smart_criteria_c2'),
            'C3' => __('leave.smart_criteria_c3'),
            'C4' => __('leave.smart_criteria_c4'),
            'C5' => __('leave.smart_criteria_c5'),
            default => $code,
        };
    }

    /**
     * Resolve translated label for a recommendation category.
     */
    private function resolveRecommendationLabel(string $recommendation): string
    {
        return match ($recommendation) {
            self::REC_RECOMMENDED => __('leave.smart_rec_recommended'),
            self::REC_CAUTION => __('leave.smart_rec_caution'),
            default => __('leave.smart_rec_high_risk'),
        };
    }

    /**
     * Clamp a float value between min and max.
     */
    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
