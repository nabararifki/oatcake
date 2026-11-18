<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyLeaveTypeSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\AuditTrailService;
use App\Services\LeaveApprovalService;
use App\Services\LeaveLedgerService;
use App\Services\LeaveRequestService;
use App\Services\SmartDecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService $leaveRequestService,
        private readonly SmartDecisionService $smartDecisionService,
        private readonly LeaveApprovalService $leaveApprovalService,
        private readonly LeaveLedgerService $leaveLedgerService
    ) {}

    /**
     * Display the self-service leave request & history console with RBAC scoping.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', LeaveRequest::class);

        $user = $request->user();
        $user->loadMissing([
            'company',
            'employee' => fn ($q) => $q->withoutGlobalScopes()->with([
                'department' => fn ($dq) => $dq->withoutGlobalScopes(),
                'company',
            ]),
        ]);
        $employee = $user->employee;

        $company = $employee?->company
            ?? $user->company
            ?? ($user->isSuperAdmin() && session('active_company_id')
                ? Company::find(session('active_company_id'))
                : Company::first());

        $leaveTypes = LeaveType::withoutGlobalScopes()
            ->with(['companySettings' => function ($sq) use ($company): void {
                $sq->withoutGlobalScopes();
                if ($company !== null) {
                    $sq->where('company_id', $company->id);
                }
            }])
            ->where('is_active', true)
            ->where(function ($q) use ($company): void {
                $q->whereNull('company_id');
                if ($company !== null) {
                    $q->orWhere('company_id', $company->id);
                }
            })
            ->orderBy('id')
            ->get()
            ->unique('code')
            ->values();

        // Ensure standard system leave types are available if not yet seeded
        $existingCodes = $leaveTypes->pluck('code')->all();
        $provisionedNewType = false;
        foreach (array_keys(LeaveType::STANDARD_TYPES) as $stdCode) {
            if (! in_array($stdCode, $existingCodes, true)) {
                LeaveType::findByCode($stdCode, $company?->id);
                $provisionedNewType = true;
            }
        }
        if ($provisionedNewType) {
            $leaveTypes = LeaveType::withoutGlobalScopes()
                ->with(['companySettings' => function ($sq) use ($company): void {
                    $sq->withoutGlobalScopes();
                    if ($company !== null) {
                        $sq->where('company_id', $company->id);
                    }
                }])
                ->where('is_active', true)
                ->where(function ($q) use ($company): void {
                    $q->whereNull('company_id');
                    if ($company !== null) {
                        $q->orWhere('company_id', $company->id);
                    }
                })
                ->orderBy('id')
                ->get()
                ->unique('code')
                ->values();
        }

        $alLeaveType = $leaveTypes->firstWhere('code', 'AL') ?? 'AL';
        $dpLeaveType = $leaveTypes->firstWhere('code', 'DP') ?? 'DP';

        if ($employee !== null && $alLeaveType instanceof LeaveType && $dpLeaveType instanceof LeaveType) {
            $reservedByType = LeaveRequest::withoutGlobalScopes()
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)
                ->whereIn('leave_type_id', [$alLeaveType->id, $dpLeaveType->id])
                ->whereIn('status', [LeaveRequest::STATUS_PENDING_MANAGER, LeaveRequest::STATUS_PENDING_HR])
                ->selectRaw('leave_type_id, SUM(total_days) as reserved_sum')
                ->groupBy('leave_type_id')
                ->pluck('reserved_sum', 'leave_type_id');

            $alLedgerBal = (int) $this->leaveLedgerService->getBalance($employee, $alLeaveType);
            $alReserved = (int) round((float) ($reservedByType[$alLeaveType->id] ?? 0));
            $alQuota = [
                'ledger_balance' => $alLedgerBal,
                'reserved_days' => $alReserved,
                'available_balance' => max(0, $alLedgerBal - $alReserved),
            ];

            $dpLedgerBal = (int) $this->leaveLedgerService->getBalance($employee, $dpLeaveType);
            $dpReserved = (int) round((float) ($reservedByType[$dpLeaveType->id] ?? 0));
            $dpQuota = [
                'ledger_balance' => $dpLedgerBal,
                'reserved_days' => $dpReserved,
                'available_balance' => max(0, $dpLedgerBal - $dpReserved),
            ];
        } else {
            $alQuota = $employee !== null
                ? $this->leaveRequestService->getQuotaSummary($employee, $alLeaveType)
                : ['ledger_balance' => 0, 'reserved_days' => 0, 'available_balance' => 0];

            $dpQuota = $employee !== null
                ? $this->leaveRequestService->getQuotaSummary($employee, $dpLeaveType)
                : ['ledger_balance' => 0, 'reserved_days' => 0, 'available_balance' => 0];
        }

        $isAdminTier = $user->isAdminOrAbove();
        $isManagerTier = $user->isManager();
        $canFilterDepartment = $isAdminTier;
        $canSearchStaff = $isAdminTier || $isManagerTier;
        $showEmployeeColumn = $isAdminTier || $isManagerTier;
        $canManageSmartWeights = $isAdminTier || $isManagerTier;
        $canManageLeaveTypeSettings = $isAdminTier;

        $departments = collect();
        if ($canFilterDepartment && $company !== null) {
            $departments = Department::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->orderBy('code')
                ->get();
        } elseif ($isManagerTier && $employee?->department) {
            $departments = collect([$employee->department]);
        }
        $departmentsById = $departments->keyBy('id');

        $smartWeightsByDept = [];
        if ($canManageSmartWeights && $departments->isNotEmpty()) {
            $weightsByDeptId = $this->smartDecisionService->getOrCreateWeightsForDepartments($departments);
            foreach ($departments as $dept) {
                $weights = $weightsByDeptId[$dept->id] ?? collect();
                $smartWeightsByDept[$dept->id] = $weights->mapWithKeys(fn ($w) => [
                    $w->criteria_code => [
                        'raw_weight' => (int) $w->raw_weight,
                        'normalized_weight' => (float) $w->normalized_weight,
                    ],
                ])->all();
            }
        }

        // Scoped employees list for the Leave Ledger Viewer & HR Adjustment Console
        $ledgerEmployees = collect();
        if ($isAdminTier && $company !== null) {
            $ledgerEmployees = Employee::withoutGlobalScopes()
                ->with(['user' => fn ($uq) => $uq->withoutGlobalScopes()])
                ->where('company_id', $company->id)
                ->where('status', 'active')
                ->orderBy('department_id')
                ->orderBy('id')
                ->get()
                ->each(fn (Employee $emp) => $emp->setRelation('department', $departmentsById->get($emp->department_id)));
        } elseif ($isManagerTier && $employee !== null) {
            $ledgerEmployees = Employee::withoutGlobalScopes()
                ->with(['user' => fn ($uq) => $uq->withoutGlobalScopes(), 'department' => fn ($dq) => $dq->withoutGlobalScopes()])
                ->where('company_id', $employee->company_id)
                ->where('department_id', $employee->department_id)
                ->where('status', 'active')
                ->orderBy('id')
                ->get();
        } elseif ($employee !== null) {
            $employee->loadMissing(['user', 'department']);
            $ledgerEmployees = collect([$employee]);
        }

        $query = LeaveRequest::withoutGlobalScopes()
            ->with([
                'managerApprover',
                'hrApprover',
                'rejector',
            ]);

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
            $query->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id);
        } else {
            $query->whereRaw('1 = 0');
        }

        $filterStatus = (string) $request->input('status', 'all');
        if ($filterStatus !== 'all' && in_array($filterStatus, LeaveRequest::STATUSES, true)) {
            $query->where('status', $filterStatus);
        }

        $filterLeaveTypeId = (string) $request->input('leave_type_id', '');
        if ($filterLeaveTypeId !== '' && is_numeric($filterLeaveTypeId)) {
            $query->where('leave_type_id', (int) $filterLeaveTypeId);
        }

        $filterDateFrom = (string) $request->input('date_from', '');
        if ($filterDateFrom !== '') {
            $query->whereDate('start_date', '>=', $filterDateFrom);
        }

        $filterDateTo = (string) $request->input('date_to', '');
        if ($filterDateTo !== '') {
            $query->whereDate('end_date', '<=', $filterDateTo);
        }

        // Staff Name / Position Search (strict ZERO NIK)
        $filterSearch = trim((string) $request->input('search', ''));
        if ($canSearchStaff && $filterSearch !== '') {
            $query->whereHas('employee', function ($eq) use ($filterSearch): void {
                $eq->withoutGlobalScopes()->where(function ($sub) use ($filterSearch): void {
                    $sub->where('position', 'like', "%{$filterSearch}%")
                        ->orWhereHas('user', function ($uq) use ($filterSearch): void {
                            $uq->where('name', 'like', "%{$filterSearch}%")
                                ->orWhere('username', 'like', "%{$filterSearch}%");
                        });
                });
            });
        }

        $leaveRequests = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $leaveTypesById = $leaveTypes->keyBy('id');
        $ledgerEmployeesById = $ledgerEmployees->keyBy('id');

        $missingEmployeeIds = [];
        $missingLeaveTypeIds = [];
        foreach ($leaveRequests as $lr) {
            if ($leaveTypesById->has($lr->leave_type_id)) {
                $lr->setRelation('leaveType', $leaveTypesById->get($lr->leave_type_id));
            } else {
                $missingLeaveTypeIds[] = $lr->leave_type_id;
            }

            if ($ledgerEmployeesById->has($lr->employee_id)) {
                $lr->setRelation('employee', $ledgerEmployeesById->get($lr->employee_id));
            } else {
                $missingEmployeeIds[] = $lr->employee_id;
            }
        }

        if ($missingLeaveTypeIds !== []) {
            $extraTypes = LeaveType::withoutGlobalScopes()->whereIn('id', array_unique($missingLeaveTypeIds))->get()->keyBy('id');
            foreach ($leaveRequests as $lr) {
                if (! $lr->relationLoaded('leaveType')) {
                    $lr->setRelation('leaveType', $extraTypes->get($lr->leave_type_id));
                }
            }
        }

        if ($missingEmployeeIds !== []) {
            $extraEmps = Employee::withoutGlobalScopes()
                ->with(['user', 'department' => fn ($dq) => $dq->withoutGlobalScopes()])
                ->whereIn('id', array_unique($missingEmployeeIds))
                ->get()
                ->keyBy('id');
            foreach ($leaveRequests as $lr) {
                if (! $lr->relationLoaded('employee')) {
                    $lr->setRelation('employee', $extraEmps->get($lr->employee_id));
                }
            }
        }

        return view('leave.index', [
            'currentCompany' => $company,
            'employee' => $employee,
            'leaveTypes' => $leaveTypes,
            'quotaLeaveTypes' => $leaveTypes->where('requires_quota', true)->values(),
            'ledgerEmployees' => $ledgerEmployees,
            'alQuota' => $alQuota,
            'dpQuota' => $dpQuota,
            'leaveRequests' => $leaveRequests,
            'departments' => $departments,
            'canFilterDepartment' => $canFilterDepartment,
            'canSearchStaff' => $canSearchStaff,
            'showEmployeeColumn' => $showEmployeeColumn,
            'isManagerTier' => $isManagerTier,
            'isAdminTier' => $isAdminTier,
            'canManageSmartWeights' => $canManageSmartWeights,
            'canManageLeaveTypeSettings' => $canManageLeaveTypeSettings,
            'canDebugSmart' => $user->role === 'super_admin',
            'debugRequestsList' => $leaveRequests->map(fn (LeaveRequest $r): array => [
                'id' => $r->id,
                'employee_name' => $r->employee?->name ?? 'Karyawan',
                'department_name' => $r->employee?->department?->name ?? '-',
                'leave_type_code' => $r->leaveType?->code ?? '-',
                'start_date' => $r->start_date?->toDateString(),
                'total_days' => (int) $r->total_days,
                'status' => $r->status,
            ])->values()->all(),
            'smartWeightsByDept' => $smartWeightsByDept,
            'criteriaDefinitions' => SmartDecisionService::CRITERIA_DEFINITIONS,
            'filterStatus' => $filterStatus,
            'filterLeaveTypeId' => $filterLeaveTypeId,
            'filterDepartmentId' => $canFilterDepartment ? $request->input('department_id', '') : ($employee?->department_id ?? ''),
            'filterDateFrom' => $filterDateFrom,
            'filterDateTo' => $filterDateTo,
            'filterSearch' => $filterSearch,
        ]);
    }

    /**
     * Calculate effective working days between start_date and end_date based on the employee's published roster.
     */
    public function calculateDays(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;
        if ($employee === null) {
            return response()->json([
                'success' => false,
                'message' => __('leave.err_no_employee_profile'),
            ], 403);
        }

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $calculation = $this->leaveRequestService->calculateEffectiveDays(
            $employee,
            $validated['start_date'],
            $validated['end_date']
        );

        return response()->json(array_merge(
            [
                'success' => true,
                'data' => $calculation,
            ],
            $calculation
        ));
    }

    /**
     * Validate and store a new leave request.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('create', LeaveRequest::class);

        $user = $request->user();
        $employee = $user->employee;

        $validated = $request->validate([
            'leave_type_id' => ['nullable'],
            'leave_type_code' => ['nullable', 'string', 'max:20'],
            'leave_type' => ['nullable', 'string', 'max:20'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'attachment' => ['nullable', 'file', 'max:25600'],
        ]);

        $leaveRequest = $this->leaveRequestService->submitRequest(
            $employee,
            $validated,
            $request->file('attachment'),
            $user
        );

        $leaveRequest->loadMissing('leaveType');

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('leave.msg_submit_success'),
                'data' => [
                    'id' => $leaveRequest->id,
                    'uuid' => $leaveRequest->uuid,
                    'leave_type_id' => $leaveRequest->leave_type_id,
                    'leave_type_code' => $leaveRequest->leaveType?->code,
                    'start_date' => $leaveRequest->start_date?->toDateString(),
                    'end_date' => $leaveRequest->end_date?->toDateString(),
                    'total_days' => (int) $leaveRequest->total_days,
                    'status' => $leaveRequest->status,
                    'smart_score' => $leaveRequest->smart_score !== null ? (float) $leaveRequest->smart_score : null,
                    'smart_metrics' => $leaveRequest->smart_metrics,
                    'attachment_path' => $leaveRequest->attachment_path,
                ],
            ], 201);
        }

        return redirect()->route('leave.index')->with('success', __('leave.msg_submit_success'));
    }

    /**
     * Update an existing pending or approved leave request (applicant owner only).
     */
    public function update(Request $request, LeaveRequest $leaveRequest): JsonResponse|RedirectResponse
    {
        Gate::authorize('updateOwnership', $leaveRequest);

        $validated = $request->validate([
            'leave_type_id' => ['nullable'],
            'leave_type_code' => ['nullable', 'string', 'max:20'],
            'leave_type' => ['nullable', 'string', 'max:20'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'attachment' => ['nullable', 'file', 'max:25600'],
        ]);

        $updated = $this->leaveRequestService->updateRequest(
            $leaveRequest,
            $validated,
            $request->file('attachment'),
            $request->user()
        );

        $updated->loadMissing('leaveType');

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'ok',
                'success' => true,
                'message' => __('leave.msg_update_success'),
                'data' => [
                    'id' => $updated->id,
                    'uuid' => $updated->uuid,
                    'leave_type_id' => $updated->leave_type_id,
                    'leave_type_code' => $updated->leaveType?->code,
                    'start_date' => $updated->start_date?->toDateString(),
                    'end_date' => $updated->end_date?->toDateString(),
                    'total_days' => (int) $updated->total_days,
                    'reason' => $updated->reason,
                    'status' => $updated->status,
                    'ledger_entry_id' => $updated->ledger_entry_id,
                    'smart_score' => $updated->smart_score !== null ? (float) $updated->smart_score : null,
                    'smart_metrics' => $updated->smart_metrics,
                    'attachment_path' => $updated->attachment_path,
                ],
            ]);
        }

        return redirect()->route('leave.index')->with('success', __('leave.msg_update_success'));
    }

    /**
     * Cancel a pending or approved leave request, refunding debited quota and releasing roster lock if approved.
     */
    public function cancel(Request $request, LeaveRequest $leaveRequest): JsonResponse|RedirectResponse
    {
        Gate::authorize('cancelOwnership', $leaveRequest);

        $validated = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->leaveRequestService->cancelRequest(
            $leaveRequest,
            $validated['cancellation_reason'] ?? null,
            $request->user()
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'ok',
                'success' => true,
                'message' => __('leave.msg_cancel_success'),
                'data' => [
                    'id' => $updated->id,
                    'uuid' => $updated->uuid,
                    'status' => $updated->status,
                    'ledger_entry_id' => $updated->ledger_entry_id,
                    'cancelled_at' => $updated->cancelled_at?->timezone('Asia/Jakarta')->toIso8601String(),
                    'cancellation_reason' => $updated->cancellation_reason,
                ],
            ]);
        }

        return redirect()->route('leave.index')->with('success', __('leave.msg_cancel_success'));
    }

    /**
     * Tier 1 Approval (Manager -> pending_hr).
     */
    public function approveTier1(Request $request, LeaveRequest $leaveRequest): JsonResponse|RedirectResponse
    {
        $leaveRequest->loadMissing('employee');

        Gate::authorize('approveTier1', $leaveRequest);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->leaveApprovalService->approveTier1(
            $leaveRequest,
            $request->user(),
            $validated['notes'] ?? null
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'ok',
                'success' => true,
                'message' => __('leave.msg_tier1_approved'),
                'data' => [
                    'id' => $updated->id,
                    'uuid' => $updated->uuid,
                    'status' => $updated->status,
                    'manager_approved_by' => $updated->manager_approved_by,
                    'manager_approved_at' => $updated->manager_approved_at?->timezone('Asia/Jakarta')->toIso8601String(),
                ],
            ]);
        }

        return redirect()->route('leave.index')->with('success', __('leave.msg_tier1_approved'));
    }

    /**
     * Tier 2 Final Approval (HR/Admin -> approved + atomic FIFO ledger debit + roster sync).
     */
    public function approveTier2(Request $request, LeaveRequest $leaveRequest): JsonResponse|RedirectResponse
    {
        $leaveRequest->loadMissing('employee');

        Gate::authorize('approveTier2', $leaveRequest);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->leaveApprovalService->approveTier2(
            $leaveRequest,
            $request->user(),
            $validated['notes'] ?? null
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'ok',
                'success' => true,
                'message' => __('leave.msg_tier2_approved'),
                'data' => [
                    'id' => $updated->id,
                    'uuid' => $updated->uuid,
                    'status' => $updated->status,
                    'ledger_entry_id' => $updated->ledger_entry_id,
                    'hr_approved_by' => $updated->hr_approved_by,
                    'hr_approved_at' => $updated->hr_approved_at?->timezone('Asia/Jakarta')->toIso8601String(),
                ],
            ]);
        }

        return redirect()->route('leave.index')->with('success', __('leave.msg_tier2_approved'));
    }

    /**
     * Reject a pending leave request (Tier 1 or Tier 2) with mandatory reason.
     */
    public function reject(Request $request, LeaveRequest $leaveRequest): JsonResponse|RedirectResponse
    {
        $leaveRequest->loadMissing('employee');

        Gate::authorize('reject', $leaveRequest);

        if (! $request->filled('reason') && $request->filled('rejection_reason')) {
            $request->merge(['reason' => $request->input('rejection_reason')]);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $updated = $this->leaveApprovalService->reject(
            $leaveRequest,
            $request->user(),
            $validated['reason']
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'ok',
                'success' => true,
                'message' => __('leave.msg_rejected'),
                'data' => [
                    'id' => $updated->id,
                    'uuid' => $updated->uuid,
                    'status' => $updated->status,
                    'rejected_by' => $updated->rejected_by,
                    'rejected_at' => $updated->rejected_at?->timezone('Asia/Jakarta')->toIso8601String(),
                    'rejection_reason' => $updated->rejection_reason,
                ],
            ]);
        }

        return redirect()->route('leave.index')->with('success', __('leave.msg_rejected'));
    }

    /**
     * Return JSON leave ledger balances and latest 50 mutation entries for an employee.
     */
    public function ledgerHistory(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('viewLedger', [LeaveRequest::class, $employee]);

        $employee->loadMissing([
            'user' => fn ($uq) => $uq->withoutGlobalScopes(),
            'department' => fn ($dq) => $dq->withoutGlobalScopes(),
        ]);

        $alSummary = $this->leaveRequestService->getQuotaSummary($employee, 'AL');
        $dpSummary = $this->leaveRequestService->getQuotaSummary($employee, 'DP');

        $entries = LeaveLedgerEntry::withoutGlobalScopes()
            ->with([
                'leaveType' => fn ($lq) => $lq->withoutGlobalScopes(),
                'creator' => fn ($cq) => $cq->withoutGlobalScopes(),
            ])
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (LeaveLedgerEntry $entry): array => [
                'id' => $entry->id,
                'uuid' => $entry->uuid,
                'leave_type_code' => $entry->leaveType?->code ?? '—',
                'leave_type_name' => $entry->leaveType?->name ?? '—',
                'transaction_type' => $entry->transaction_type,
                'amount' => (int) $entry->amount,
                'remaining_amount' => (int) $entry->remaining_amount,
                'balance_after' => (int) $entry->balance_after,
                'expires_at' => $entry->expires_at?->toDateString(),
                'notes' => $entry->notes,
                'created_by_name' => $entry->creator?->name ?? 'System',
                'created_at' => $entry->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            ])
            ->values()
            ->all();

        $payload = [
            'employee' => [
                'id' => $employee->id,
                'uuid' => $employee->uuid,
                'name' => $employee->user?->name ?? '—',
                'position' => $employee->position ?? '—',
                'department' => $employee->department?->name ?? '—',
            ],
            'balances' => [
                'AL' => $alSummary,
                'DP' => $dpSummary,
            ],
            'entries' => $entries,
        ];

        return response()->json(array_merge(
            [
                'success' => true,
                'data' => $payload,
            ],
            $payload
        ));
    }

    /**
     * Execute a manual HR leave ledger adjustment (+ / -) for an employee in the active company (Admin+ only).
     */
    public function adjustLedger(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('adjustLedger', LeaveRequest::class);

        $user = $request->user();

        if (! $request->filled('notes') && $request->filled('reason')) {
            $request->merge(['notes' => $request->input('reason')]);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'leave_type_id' => ['nullable'],
            'leave_type_code' => ['nullable', 'string', 'max:20'],
            'direction' => ['nullable', 'string', 'in:credit,debit'],
            'amount' => ['required', 'integer', 'not_in:0', 'between:-365,365'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $targetEmployee = Employee::withoutGlobalScopes()->find((int) $validated['employee_id']);
        if ($targetEmployee === null) {
            throw ValidationException::withMessages([
                'employee_id' => [__('leave.err_no_employee_profile')],
            ]);
        }

        // Enforce strict tenant isolation on target employee
        Gate::authorize('viewLedger', [LeaveRequest::class, $targetEmployee]);

        $leaveType = null;
        if (! empty($validated['leave_type_id'])) {
            $ltId = $validated['leave_type_id'];
            $leaveType = is_numeric($ltId)
                ? LeaveType::withoutGlobalScopes()->find((int) $ltId)
                : LeaveType::findByCode((string) $ltId, (int) $targetEmployee->company_id);
        } elseif (! empty($validated['leave_type_code'])) {
            $leaveType = LeaveType::findByCode((string) $validated['leave_type_code'], (int) $targetEmployee->company_id);
        }

        if ($leaveType === null || ! $leaveType->requires_quota) {
            throw ValidationException::withMessages([
                'leave_type_id' => [__('leave.err_invalid_leave_type')],
            ]);
        }

        $meta = [
            'reference_type' => 'manual_adjustment',
            'notes' => trim($validated['notes']),
            'created_by' => $user->id,
        ];

        if (array_key_exists('expires_at', $validated) && $validated['expires_at'] !== null) {
            $meta['expires_at'] = $validated['expires_at'];
        }

        $signedAmount = (int) $validated['amount'];
        if (($validated['direction'] ?? null) === 'debit' && $signedAmount > 0) {
            $signedAmount = -$signedAmount;
        } elseif (($validated['direction'] ?? null) === 'credit' && $signedAmount < 0) {
            $signedAmount = abs($signedAmount);
        }

        try {
            $entry = $this->leaveLedgerService->recordAdjustment(
                $targetEmployee,
                $leaveType,
                $signedAmount,
                $meta
            );
        } catch (RuntimeException|InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'amount' => [$e->getMessage()],
            ]);
        }

        app(AuditTrailService::class)->record(
            'ledger_adjusted',
            $entry,
            null,
            [
                'employee_id' => $targetEmployee->id,
                'leave_type_code' => $leaveType->code,
                'transaction_type' => $entry->transaction_type,
                'amount' => (int) $entry->amount,
                'balance_after' => (int) $entry->balance_after,
                'notes' => $entry->notes,
            ],
            "Manual leave ledger adjustment ({$leaveType->code}: {$entry->amount})",
            ['employee_id' => $targetEmployee->id, 'leave_type_code' => $leaveType->code],
            $user,
            (int) $targetEmployee->company_id
        );

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'ok',
                'success' => true,
                'message' => __('leave.msg_ledger_adjusted'),
                'data' => [
                    'id' => $entry->id,
                    'uuid' => $entry->uuid,
                    'employee_id' => $entry->employee_id,
                    'leave_type_id' => $entry->leave_type_id,
                    'leave_type_code' => $leaveType->code,
                    'transaction_type' => $entry->transaction_type,
                    'amount' => (int) $entry->amount,
                    'remaining_amount' => (int) $entry->remaining_amount,
                    'balance_after' => (int) $entry->balance_after,
                    'expires_at' => $entry->expires_at?->toDateString(),
                    'notes' => $entry->notes,
                ],
            ]);
        }

        return redirect()->route('leave.index')->with('success', __('leave.msg_ledger_adjusted'));
    }

    /**
     * Update SPK SMART dynamic criteria weights for a department (Manager for own dept, Admin+ for any dept in tenant).
     */
    public function updateSmartWeights(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        if ($user === null || ! ($user->isManager() || $user->isAdminOrAbove())) {
            abort(403);
        }

        $departmentId = $request->filled('department_id')
            ? (int) $request->input('department_id')
            : ($user->employee?->department_id ? (int) $user->employee->department_id : null);

        if ($departmentId === null) {
            abort(403);
        }

        $department = Department::withoutGlobalScopes()->find($departmentId);
        if ($department === null) {
            abort(404);
        }

        Gate::authorize('manageDepartmentSmartWeights', [LeaveRequest::class, $department]);

        $weightsInput = is_array($request->input('weights'))
            ? $request->input('weights')
            : [];

        foreach (array_keys(SmartDecisionService::CRITERIA_DEFINITIONS) as $code) {
            if (! array_key_exists($code, $weightsInput)) {
                if ($request->has($code)) {
                    $weightsInput[$code] = $request->input($code);
                } elseif ($request->has(strtolower($code))) {
                    $weightsInput[$code] = $request->input(strtolower($code));
                }
            }
        }

        $updated = $this->smartDecisionService->updateDepartmentWeights($department, $weightsInput, $user);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('leave.msg_smart_weights_saved'),
                'department_id' => $department->id,
                'weights' => $updated->mapWithKeys(fn ($w) => [
                    $w->criteria_code => [
                        'raw_weight' => (int) $w->raw_weight,
                        'normalized_weight' => (float) $w->normalized_weight,
                    ],
                ])->all(),
            ]);
        }

        return redirect()->route('leave.index')->with('success', __('leave.msg_smart_weights_saved'));
    }

    /**
     * Update tenant-specific validity_months and default_annual_quota for a leave type (Admin+ only).
     */
    public function updateLeaveTypeSettings(Request $request, LeaveType $leaveType): JsonResponse|RedirectResponse
    {
        Gate::authorize('manageLeaveTypeSettings', [LeaveRequest::class, $leaveType]);

        $user = $request->user();
        $companyId = $user->company_id
            ?? ($user->isSuperAdmin() && session('active_company_id') ? (int) session('active_company_id') : null)
            ?? $leaveType->company_id
            ?? Company::first()?->id;

        if ($companyId === null) {
            abort(422);
        }

        $validated = $request->validate([
            'validity_months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'default_annual_quota' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $validityMonths = array_key_exists('validity_months', $validated) && $validated['validity_months'] !== null
            ? (int) $validated['validity_months']
            : null;

        $defaultAnnualQuota = array_key_exists('default_annual_quota', $validated) && $validated['default_annual_quota'] !== null
            ? (int) $validated['default_annual_quota']
            : $leaveType->getEffectiveAnnualQuota((int) $companyId);

        $setting = CompanyLeaveTypeSetting::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => (int) $companyId,
                'leave_type_id' => $leaveType->id,
            ],
            [
                'validity_months' => $validityMonths,
                'default_annual_quota' => $defaultAnnualQuota,
                'updated_by' => $user->id,
            ]
        );

        if ($leaveType->company_id !== null && (int) $leaveType->company_id === (int) $companyId) {
            $leaveType->update([
                'validity_months' => $validityMonths,
                'default_annual_quota' => $defaultAnnualQuota,
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('leave.msg_leave_type_settings_saved'),
                'data' => [
                    'leave_type_id' => $leaveType->id,
                    'leave_type_code' => $leaveType->code,
                    'company_id' => (int) $companyId,
                    'validity_months' => $setting->validity_months !== null ? (int) $setting->validity_months : null,
                    'default_annual_quota' => $setting->default_annual_quota !== null ? (int) $setting->default_annual_quota : null,
                ],
            ]);
        }

        return redirect()->route('leave.index')->with('success', __('leave.msg_leave_type_settings_saved'));
    }

    /**
     * Return full Mode A / Mode B SPK SMART telemetry analysis JSON for a leave request.
     */
    public function smartAnalysis(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $leaveRequest->loadMissing('employee');

        Gate::authorize('viewSmartAnalysis', $leaveRequest);

        $analysis = $this->smartDecisionService->evaluateComparativeForRequest($leaveRequest);

        return response()->json(array_merge(
            [
                'success' => true,
                'data' => $analysis,
            ],
            $analysis
        ));
    }

    /**
     * Super Admin only: execute interactive What-If SPK SMART simulation & DB telemetry inspection.
     */
    public function smartDebug(Request $request): JsonResponse
    {
        Gate::authorize('debugSmartEngine', LeaveRequest::class);

        $validated = $request->validate([
            'weights' => ['nullable', 'array'],
            'weights.C1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'weights.C2' => ['nullable', 'integer', 'min:0', 'max:100'],
            'weights.C3' => ['nullable', 'integer', 'min:0', 'max:100'],
            'weights.C4' => ['nullable', 'integer', 'min:0', 'max:100'],
            'weights.C5' => ['nullable', 'integer', 'min:0', 'max:100'],
            'min_staff_per_shift' => ['nullable', 'integer', 'min:1', 'max:500'],
            'total_department_staff' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'leave_request_id' => ['nullable'],
            'employee_id' => ['nullable', 'integer'],
            'candidates' => ['nullable', 'array', 'max:30'],
            'candidates.*.name' => ['nullable', 'string', 'max:120'],
            'candidates.*.C1' => ['required_with:candidates', 'numeric'],
            'candidates.*.C2' => ['required_with:candidates', 'numeric'],
            'candidates.*.C3' => ['required_with:candidates', 'numeric'],
            'candidates.*.C4' => ['required_with:candidates', 'numeric'],
            'candidates.*.C5' => ['required_with:candidates', 'numeric'],
        ]);

        $simulation = $this->smartDecisionService->simulateDebugScenario($validated);

        return response()->json(array_merge(
            [
                'success' => true,
                'data' => $simulation,
            ],
            $simulation
        ));
    }

    /**
     * Stream the private leave attachment file for authorized users.
     */
    public function streamAttachment(Request $request, LeaveRequest $leaveRequest): BinaryFileResponse
    {
        $leaveRequest->loadMissing('employee');

        Gate::authorize('viewAttachment', $leaveRequest);

        $path = $leaveRequest->attachment_path;
        if (empty($path) || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $fullPath = Storage::disk('local')->path($path);
        $lowerPath = strtolower($path);

        if (str_ends_with($lowerPath, '.webp')) {
            $mimeType = 'image/webp';
        } elseif (str_ends_with($lowerPath, '.pdf')) {
            $mimeType = 'application/pdf';
        } else {
            $mimeType = Storage::disk('local')->mimeType($path) ?: 'application/octet-stream';
        }

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
