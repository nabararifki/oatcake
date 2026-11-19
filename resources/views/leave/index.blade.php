<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="text-lg sm:text-xl font-bold text-ink tracking-tight">
                    {{ __('leave.title') }}
                </h1>
                <p class="text-xs text-ink-muted mt-0.5">
                    {{ __('leave.subtitle') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2 shrink-0">
                @if (($ledgerEmployees ?? collect())->isNotEmpty())
                    <button type="button"
                            @click="$dispatch('open-modal', 'leave-ledger-modal'); window.dispatchEvent(new CustomEvent('load-leave-ledger'))"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-line bg-surface px-3.5 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                        <span>{{ __('leave.btn_ledger_console') }}</span>
                    </button>
                @endif

                @if ($canManageLeaveTypeSettings ?? false)
                    <button type="button"
                            @click="$dispatch('open-modal', 'leave-type-settings-modal')"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-line bg-surface px-3.5 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                        <span>{{ __('leave.btn_leave_type_settings') }}</span>
                    </button>
                @endif

                @if ($canDebugSmart ?? false)
                    <button type="button"
                            @click="$dispatch('open-modal', 'smart-debug-modal')"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-amber-500/40 bg-amber-500/10 px-3.5 py-2 text-xs font-semibold text-amber-800 dark:text-amber-300 hover:bg-amber-500/20 transition cursor-pointer">
                        <svg class="w-4 h-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" />
                        </svg>
                        <span>{{ __('leave.btn_smart_debug') }}</span>
                    </button>
                @endif

                @if ($canManageSmartWeights ?? false)
                    <button type="button"
                            @click="$dispatch('open-modal', 'smart-weights-modal')"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-line bg-surface px-3.5 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                        <span>{{ __('leave.btn_smart_weights') }}</span>
                    </button>
                @endif

                @if ($employee !== null)
                    <button type="button"
                            @click="$dispatch('open-modal', 'create-leave-request-modal')"
                            class="inline-flex items-center gap-2 rounded-xl bg-accent px-4 py-2 text-xs font-bold text-accent-fg shadow-sm hover:opacity-95 transition cursor-pointer">
                        <svg class="w-4 h-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        <span>{{ __('leave.btn_create_request') }}</span>
                    </button>
                @endif
            </div>
        </div>
    </x-slot>

    @php
        $leaveTypesPayload = $leaveTypes->map(function ($lt) use ($alQuota, $dpQuota) {
            $avail = null;
            if ($lt->code === 'AL') {
                $avail = $alQuota['available_balance'];
            } elseif ($lt->code === 'DP') {
                $avail = $dpQuota['available_balance'];
            }

            return [
                'id' => $lt->id,
                'code' => $lt->code,
                'name' => $lt->name,
                'requires_quota' => (bool) $lt->requires_quota,
                'requires_attachment' => (bool) $lt->requires_attachment,
                'available_balance' => $avail,
            ];
        })->values()->all();

        $defaultTypeId = $leaveTypes->first()?->id ?? '';
        $defaultWeightDeptId = $departments->first()?->id ?? ($employee?->department_id ?? '');

        $ledgerEmployeesList = ($ledgerEmployees ?? collect())->map(fn ($emp) => [
            'id' => $emp->id,
            'uuid' => $emp->uuid,
            'name' => $emp->user?->name ?? '—',
            'position' => $emp->position ?? '—',
            'department' => $emp->department?->code ?? '—',
            'history_url' => route('leave.ledger.history', $emp),
        ])->values()->all();

        $defaultLedgerEmployeeId = $employee?->id ?? ($ledgerEmployeesList[0]['id'] ?? '');
    @endphp

    <div class="space-y-6"
         @load-leave-ledger.window="fetchLedgerHistory()"
         x-data="leaveRequestConsole({
             leaveTypes: {{ Js::from($leaveTypesPayload) }},
             defaultTypeId: {{ Js::from($defaultTypeId) }},
             calculateDaysUrl: {{ Js::from(route('leave.calculateDays')) }},
             oldLeaveTypeId: {{ Js::from(old('leave_type_id', $defaultTypeId)) }},
             oldStartDate: {{ Js::from(old('start_date', '')) }},
             oldEndDate: {{ Js::from(old('end_date', '')) }},
             smartWeightsByDept: {{ Js::from($smartWeightsByDept ?? []) }},
             defaultWeightDeptId: {{ Js::from($defaultWeightDeptId) }},
             ledgerEmployees: {{ Js::from($ledgerEmployeesList) }},
             defaultLedgerEmployeeId: {{ Js::from($defaultLedgerEmployeeId) }},
             smartDebugUrl: {{ Js::from(route('leave.smartDebug')) }},
             csrfToken: {{ Js::from(csrf_token()) }},
             debugDepartments: {{ Js::from($debugDepartmentsList ?? []) }},
             debugRequests: {{ Js::from($debugRequestsList ?? []) }},
         })">

        @if (session('success'))
            <div class="bg-emerald-500/10 border border-emerald-500/20 text-emerald-700 dark:text-emerald-300 rounded-2xl p-3.5 text-sm font-medium">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="bg-red-500/10 border border-red-500/30 text-red-700 dark:text-red-300 rounded-2xl p-4 text-xs space-y-1">
                @foreach ($errors->all() as $error)
                    <div>&bull; {{ $error }}</div>
                @endforeach
            </div>
        @endif

        <!-- Baris Kartu Ringkasan Kuota Karyawan (AL & DP) -->
        @if ($employee !== null)
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Kartu Cuti Tahunan (AL) -->
                <div class="rounded-2xl border border-line bg-surface p-5 shadow-2xs space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-ink-muted">
                            {{ __('leave.card_al_title') }}
                        </span>
                        <span class="inline-flex items-center rounded-md bg-accent/10 px-2.5 py-0.5 text-2xs font-mono font-bold text-accent border border-accent/20">
                            AL
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-3 pt-1">
                        <div class="rounded-xl bg-surface-muted/60 border border-line p-3">
                            <div class="text-2xs text-ink-muted">{{ __('leave.label_ledger_balance') }}</div>
                            <div class="mt-1 text-lg font-mono font-bold text-ink">
                                {{ (int) $alQuota['ledger_balance'] }}
                                <span class="text-2xs font-sans font-normal text-ink-muted">{{ __('leave.days_unit') }}</span>
                            </div>
                        </div>

                        <div class="rounded-xl bg-amber-500/10 border border-amber-500/20 p-3">
                            <div class="text-2xs text-amber-700 dark:text-amber-300">{{ __('leave.label_reserved_balance') }}</div>
                            <div class="mt-1 text-lg font-mono font-bold text-amber-700 dark:text-amber-300">
                                {{ (int) $alQuota['reserved_days'] }}
                                <span class="text-2xs font-sans font-normal">{{ __('leave.days_unit') }}</span>
                            </div>
                        </div>

                        <div class="rounded-xl bg-emerald-500/10 border border-emerald-500/20 p-3">
                            <div class="text-2xs text-emerald-700 dark:text-emerald-300">{{ __('leave.label_available_balance') }}</div>
                            <div class="mt-1 text-lg font-mono font-bold text-emerald-700 dark:text-emerald-300">
                                {{ (int) $alQuota['available_balance'] }}
                                <span class="text-2xs font-sans font-normal">{{ __('leave.days_unit') }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kartu Day of Payment (DP) -->
                <div class="rounded-2xl border border-line bg-surface p-5 shadow-2xs space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-ink-muted">
                            {{ __('leave.card_dp_title') }}
                        </span>
                        <span class="inline-flex items-center rounded-md bg-purple-500/10 px-2.5 py-0.5 text-2xs font-mono font-bold text-purple-700 dark:text-purple-300 border border-purple-500/20">
                            DP
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-3 pt-1">
                        <div class="rounded-xl bg-surface-muted/60 border border-line p-3">
                            <div class="text-2xs text-ink-muted">{{ __('leave.label_ledger_balance') }}</div>
                            <div class="mt-1 text-lg font-mono font-bold text-ink">
                                {{ (int) $dpQuota['ledger_balance'] }}
                                <span class="text-2xs font-sans font-normal text-ink-muted">{{ __('leave.days_unit') }}</span>
                            </div>
                        </div>

                        <div class="rounded-xl bg-amber-500/10 border border-amber-500/20 p-3">
                            <div class="text-2xs text-amber-700 dark:text-amber-300">{{ __('leave.label_reserved_balance') }}</div>
                            <div class="mt-1 text-lg font-mono font-bold text-amber-700 dark:text-amber-300">
                                {{ (int) $dpQuota['reserved_days'] }}
                                <span class="text-2xs font-sans font-normal">{{ __('leave.days_unit') }}</span>
                            </div>
                        </div>

                        <div class="rounded-xl bg-emerald-500/10 border border-emerald-500/20 p-3">
                            <div class="text-2xs text-emerald-700 dark:text-emerald-300">{{ __('leave.label_available_balance') }}</div>
                            <div class="mt-1 text-lg font-mono font-bold text-emerald-700 dark:text-emerald-300">
                                {{ (int) $dpQuota['available_balance'] }}
                                <span class="text-2xs font-sans font-normal">{{ __('leave.days_unit') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- Bilah Filter Riwayat Pengajuan Cuti -->
        <div class="rounded-2xl border border-line bg-surface p-4 sm:p-5 shadow-2xs">
            <form method="GET" action="{{ route('leave.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3.5 items-end">
                <div class="lg:col-span-2 space-y-1">
                    <label for="filter_date_from" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                        {{ __('leave.filter_date_from') }}
                    </label>
                    <input type="date"
                           id="filter_date_from"
                           name="date_from"
                           value="{{ $filterDateFrom }}"
                           class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                </div>

                <div class="lg:col-span-2 space-y-1">
                    <label for="filter_date_to" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                        {{ __('leave.filter_date_to') }}
                    </label>
                    <input type="date"
                           id="filter_date_to"
                           name="date_to"
                           value="{{ $filterDateTo }}"
                           class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                </div>

                @if ($canFilterDepartment)
                    <div class="lg:col-span-2 space-y-1">
                        <label for="filter_department_id" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                            {{ __('leave.filter_department') }}
                        </label>
                        <select id="filter_department_id"
                                name="department_id"
                                class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                            <option value="">{{ __('leave.filter_all_departments') }}</option>
                            @foreach ($departments as $dept)
                                <option value="{{ $dept->id }}" @selected((string) $filterDepartmentId === (string) $dept->id)>
                                    {{ $dept->code }} — {{ $dept->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="lg:col-span-2 space-y-1">
                    <label for="filter_leave_type_id" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                        {{ __('leave.filter_leave_type') }}
                    </label>
                    <select id="filter_leave_type_id"
                            name="leave_type_id"
                            class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                        <option value="">{{ __('leave.filter_all_types') }}</option>
                        @foreach ($leaveTypes as $lt)
                            <option value="{{ $lt->id }}" @selected((string) $filterLeaveTypeId === (string) $lt->id)>
                                {{ $lt->code }} — {{ $lt->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="lg:col-span-2 space-y-1">
                    <label for="filter_status" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                        {{ __('leave.filter_status') }}
                    </label>
                    <select id="filter_status"
                            name="status"
                            class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                        <option value="all" @selected($filterStatus === 'all')>{{ __('leave.filter_all_statuses') }}</option>
                        <option value="pending_manager" @selected($filterStatus === 'pending_manager')>{{ __('leave.status_pending_manager') }}</option>
                        <option value="pending_hr" @selected($filterStatus === 'pending_hr')>{{ __('leave.status_pending_hr') }}</option>
                        <option value="approved" @selected($filterStatus === 'approved')>{{ __('leave.status_approved') }}</option>
                        <option value="rejected" @selected($filterStatus === 'rejected')>{{ __('leave.status_rejected') }}</option>
                        <option value="cancelled" @selected($filterStatus === 'cancelled')>{{ __('leave.status_cancelled') }}</option>
                    </select>
                </div>

                @if ($canSearchStaff)
                    <div class="lg:col-span-2 space-y-1">
                        <label for="filter_search" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                            {{ __('leave.filter_search_staff') }}
                        </label>
                        <input type="text"
                               id="filter_search"
                               name="search"
                               value="{{ $filterSearch }}"
                               placeholder="{{ __('leave.filter_search_placeholder') }}"
                               class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink placeholder:text-ink-muted/70 focus:outline-none focus:ring-2 focus:ring-accent" />
                    </div>
                @endif

                <div class="lg:col-span-2 flex items-center gap-2">
                    <button type="submit"
                            class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-xl bg-accent px-3.5 py-2 text-xs font-bold text-accent-fg shadow-2xs hover:opacity-90 transition cursor-pointer">
                        <span>{{ __('leave.btn_filter') }}</span>
                    </button>
                    <a href="{{ route('leave.index') }}"
                       class="inline-flex items-center justify-center rounded-xl border border-line bg-surface px-3 py-2 text-xs font-semibold text-ink-muted hover:text-ink hover:bg-surface-muted transition">
                        {{ __('leave.btn_reset_filter') }}
                    </a>
                </div>
            </form>
        </div>

        <!-- Tabel Riwayat & Log Pengajuan Cuti (.o-table) -->
        <div class="rounded-2xl border border-line bg-surface p-4 sm:p-5 shadow-2xs space-y-4">
            <x-ui.table :striped="true">
                <x-slot name="header">
                    <th>{{ __('leave.col_submitted_at') }}</th>
                    @if ($showEmployeeColumn)
                        <th>{{ __('leave.col_employee') }}</th>
                        <th>{{ __('leave.col_department') }}</th>
                    @endif
                    <th>{{ __('leave.col_leave_type') }}</th>
                    <th>{{ __('leave.col_date_range') }}</th>
                    <th>{{ __('leave.col_effective_days') }}</th>
                    @if ($showEmployeeColumn)
                        <th>{{ __('leave.col_smart_score') }}</th>
                    @endif
                    <th>{{ __('leave.col_reason') }}</th>
                    <th>{{ __('leave.col_status') }}</th>
                    <th>{{ __('leave.col_attachment') }}</th>
                    <th>{{ __('leave.col_actions') }}</th>
                </x-slot>

                @forelse ($leaveRequests as $req)
                    @php
                        $canTier1 = $req->status === 'pending_manager' && auth()->user()->can('approveTier1', $req);
                        $canTier2 = $req->status === 'pending_hr' && auth()->user()->can('approveTier2', $req);
                        $canRejectReq = $req->isPending() && auth()->user()->can('reject', $req);
                        $canEditReq = $req->canBeEdited() && auth()->user()->can('update', $req);
                        $canCancelReq = $req->canBeCancelled() && auth()->user()->can('cancel', $req);
                    @endphp
                    <tr>
                        <td class="font-mono text-xs text-ink whitespace-nowrap">
                            {{ $req->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i') ?? '—' }}
                        </td>

                        @if ($showEmployeeColumn)
                            <td>
                                <div class="font-semibold text-xs text-ink">
                                    {{ $req->employee?->user?->name ?? '—' }}
                                </div>
                                <div class="text-2xs text-ink-muted">
                                    {{ $req->employee?->position ?? '—' }}
                                </div>
                            </td>
                            <td class="text-xs text-ink whitespace-nowrap">
                                @if ($req->employee?->department)
                                    <span class="inline-flex items-center rounded-md bg-surface-muted px-2 py-0.5 text-2xs font-mono font-bold text-ink border border-line">
                                        {{ $req->employee->department->code }}
                                    </span>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                        @endif

                        <td class="whitespace-nowrap">
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-surface-muted px-2.5 py-0.5 text-xs font-semibold text-ink border border-line">
                                <span class="font-mono font-bold">{{ $req->leaveType?->code ?? '—' }}</span>
                                <span class="text-ink-muted hidden sm:inline">{{ $req->leaveType?->name ?? '' }}</span>
                            </span>
                        </td>

                        <td class="font-mono text-xs text-ink whitespace-nowrap">
                            {{ $req->start_date?->format('Y-m-d') }} &mdash; {{ $req->end_date?->format('Y-m-d') }}
                        </td>

                        <td class="font-mono text-xs font-bold text-ink whitespace-nowrap">
                            {{ (int) $req->total_days }} {{ __('leave.days_unit') }}
                        </td>

                        @if ($showEmployeeColumn)
                            <td class="whitespace-nowrap">
                                <div class="flex items-center gap-2">
                                    @php
                                        $scoreVal = $req->smart_score !== null ? (float) $req->smart_score : null;
                                    @endphp

                                    @if ($scoreVal !== null)
                                        @if ($scoreVal >= 70.0)
                                            <span class="inline-flex items-center rounded-md bg-emerald-500/10 px-2 py-0.5 text-2xs font-mono font-bold text-emerald-700 dark:text-emerald-300 border border-emerald-500/30">
                                                {{ number_format($scoreVal, 2) }} &bull; {{ __('leave.smart_rec_recommended') }}
                                            </span>
                                        @elseif ($scoreVal >= 50.0)
                                            <span class="inline-flex items-center rounded-md bg-amber-500/10 px-2 py-0.5 text-2xs font-mono font-bold text-amber-700 dark:text-amber-300 border border-amber-500/30">
                                                {{ number_format($scoreVal, 2) }} &bull; {{ __('leave.smart_rec_caution') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-md bg-red-500/10 px-2 py-0.5 text-2xs font-mono font-bold text-red-700 dark:text-red-300 border border-red-500/30">
                                                {{ number_format($scoreVal, 2) }} &bull; {{ __('leave.smart_rec_high_risk') }}
                                            </span>
                                        @endif
                                    @endif

                                    @if (auth()->user()->can('viewSmartAnalysis', $req))
                                        <button type="button"
                                                @click="openSmartAnalysisModal({
                                                    analysisUrl: {{ Js::from(route('leave.smartAnalysis', $req)) }},
                                                    canApproveTier1: {{ Js::from($canTier1) }},
                                                    approveTier1Url: {{ Js::from(route('leave.approveTier1', $req)) }},
                                                    canApproveTier2: {{ Js::from($canTier2) }},
                                                    approveTier2Url: {{ Js::from(route('leave.approveTier2', $req)) }},
                                                    canReject: {{ Js::from($canRejectReq) }},
                                                    rejectUrl: {{ Js::from(route('leave.reject', $req)) }},
                                                })"
                                                class="inline-flex items-center gap-1 rounded-lg border border-line bg-surface-muted px-2 py-0.5 text-2xs font-semibold text-ink hover:bg-surface transition cursor-pointer">
                                            <span>{{ __('leave.btn_smart_analysis') }}</span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        @endif

                        <td class="text-xs text-ink max-w-xs truncate" title="{{ $req->reason }}">
                            {{ $req->reason }}
                        </td>

                        <td class="whitespace-nowrap">
                            @if ($req->status === 'pending_manager')
                                <span class="inline-flex items-center rounded-md bg-amber-500/10 px-2.5 py-0.5 text-2xs font-semibold text-amber-700 dark:text-amber-300 border border-amber-500/30">
                                    {{ __('leave.status_pending_manager') }}
                                </span>
                            @elseif ($req->status === 'pending_hr')
                                <span class="inline-flex items-center rounded-md bg-sky-500/10 px-2.5 py-0.5 text-2xs font-semibold text-sky-700 dark:text-sky-300 border border-sky-500/30">
                                    {{ __('leave.status_pending_hr') }}
                                </span>
                            @elseif ($req->status === 'approved')
                                <span class="inline-flex items-center rounded-md bg-emerald-500/10 px-2.5 py-0.5 text-2xs font-semibold text-emerald-700 dark:text-emerald-300 border border-emerald-500/30">
                                    {{ __('leave.status_approved') }}
                                </span>
                            @elseif ($req->status === 'rejected')
                                <span class="inline-flex items-center rounded-md bg-red-500/10 px-2.5 py-0.5 text-2xs font-semibold text-red-700 dark:text-red-300 border border-red-500/30">
                                    {{ __('leave.status_rejected') }}
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-md bg-surface-muted px-2.5 py-0.5 text-2xs font-semibold text-ink-muted border border-line">
                                    {{ __('leave.status_cancelled') }}
                                </span>
                            @endif
                        </td>

                        <td class="whitespace-nowrap">
                            @if ($req->hasAttachment() && auth()->user()->can('viewAttachment', $req))
                                <a href="{{ route('leave.attachment', $req) }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="inline-flex items-center gap-1 rounded-lg border border-line bg-surface-muted px-2.5 py-1 text-2xs font-semibold text-ink hover:bg-surface transition">
                                    <span>{{ __('leave.btn_view_attachment') }}</span>
                                </a>
                            @else
                                <span class="text-xs text-ink-muted">—</span>
                            @endif
                        </td>

                        <td class="whitespace-nowrap">
                            <div class="flex flex-wrap items-center gap-1.5">
                                @if ($canTier1)
                                    <form method="POST" action="{{ route('leave.approveTier1', $req) }}" class="inline">
                                        @csrf
                                        <button type="submit"
                                                class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1 text-2xs font-semibold text-emerald-700 dark:text-emerald-300 hover:bg-emerald-500/20 transition cursor-pointer">
                                            {{ __('leave.btn_approve_tier1') }}
                                        </button>
                                    </form>
                                @endif

                                @if ($canTier2)
                                    <form method="POST" action="{{ route('leave.approveTier2', $req) }}" class="inline">
                                        @csrf
                                        <button type="submit"
                                                class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1 text-2xs font-semibold text-emerald-700 dark:text-emerald-300 hover:bg-emerald-500/20 transition cursor-pointer">
                                            {{ __('leave.btn_approve_tier2') }}
                                        </button>
                                    </form>
                                @endif

                                @if ($canRejectReq)
                                    <button type="button"
                                            @click="openRejectModal({{ Js::from(route('leave.reject', $req)) }})"
                                            class="rounded-lg border border-red-500/30 bg-red-500/10 px-2.5 py-1 text-2xs font-semibold text-red-600 dark:text-red-400 hover:bg-red-500/20 transition cursor-pointer">
                                        {{ __('leave.btn_reject') }}
                                    </button>
                                @endif

                                @if ($canEditReq)
                                    <button type="button"
                                            @click="openEditModal({
                                                actionUrl: {{ Js::from(route('leave.update', $req)) }},
                                                leaveTypeId: {{ Js::from($req->leave_type_id) }},
                                                startDate: {{ Js::from($req->start_date?->format('Y-m-d')) }},
                                                endDate: {{ Js::from($req->end_date?->format('Y-m-d')) }},
                                                reason: {{ Js::from($req->reason) }},
                                                status: {{ Js::from($req->status) }},
                                                hasAttachment: {{ Js::from($req->hasAttachment()) }},
                                            })"
                                            class="rounded-lg border border-accent/30 bg-accent/10 px-2.5 py-1 text-2xs font-semibold text-accent hover:bg-accent/20 transition cursor-pointer">
                                        {{ __('leave.btn_edit_request') }}
                                    </button>
                                @endif

                                @if ($canCancelReq)
                                    <button type="button"
                                            @click="openCancelModal({{ Js::from(route('leave.cancel', $req)) }})"
                                            class="rounded-lg border border-line bg-surface-muted px-2.5 py-1 text-2xs font-semibold text-ink hover:bg-surface transition cursor-pointer">
                                        {{ __('leave.btn_cancel_action') }}
                                    </button>
                                @endif

                                @if (! $canTier1 && ! $canTier2 && ! $canRejectReq && ! $canEditReq && ! $canCancelReq)
                                    <span class="text-xs text-ink-muted">—</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showEmployeeColumn ? 11 : 8 }}" class="py-8 text-center text-xs text-ink-muted">
                            {{ __('leave.history_empty') }}
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>

            @if ($leaveRequests->hasPages())
                <div class="pt-3 border-t border-line">
                    {{ $leaveRequests->links() }}
                </div>
            @endif
        </div>

        <!-- Modal Pengajuan Cuti Baru -->
        <x-ui.modal name="create-leave-request-modal" :title="__('leave.modal_create_title')" maxWidth="lg">
            <form method="POST"
                  action="{{ route('leave.store') }}"
                  enctype="multipart/form-data"
                  class="space-y-4">
                @csrf

                <!-- Pilihan Tipe Cuti -->
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <label for="leave_type_id" class="block text-xs font-semibold text-ink">
                            {{ __('leave.field_leave_type') }} <span class="text-red-500">*</span>
                        </label>
                        <template x-if="selectedType && selectedType.requires_quota && selectedType.available_balance !== null">
                            <span class="inline-flex items-center rounded-md bg-emerald-500/10 px-2 py-0.5 text-2xs font-semibold text-emerald-700 dark:text-emerald-300 border border-emerald-500/20"
                                  x-text="`${Math.round(Number(selectedType.available_balance || 0))} {{ __('leave.days_unit') }}`"></span>
                        </template>
                        <template x-if="selectedType && !selectedType.requires_quota">
                            <span class="inline-flex items-center rounded-md bg-surface-muted px-2 py-0.5 text-2xs font-medium text-ink-muted border border-line">
                                {{ __('leave.non_quota_badge') }}
                            </span>
                        </template>
                    </div>

                    <select id="leave_type_id"
                            name="leave_type_id"
                            x-model="selectedTypeId"
                            required
                            class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                        @foreach ($leaveTypes as $lt)
                            <option value="{{ $lt->id }}">
                                {{ $lt->code }} — {{ $lt->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Input Rentang Tanggal Mulai & Selesai -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div class="space-y-1.5">
                        <label for="start_date" class="block text-xs font-semibold text-ink">
                            {{ __('leave.field_start_date') }} <span class="text-red-500">*</span>
                        </label>
                        <input type="date"
                               id="start_date"
                               name="start_date"
                               x-model="startDate"
                               @change="fetchEffectiveDays()"
                               required
                               class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                    </div>

                    <div class="space-y-1.5">
                        <label for="end_date" class="block text-xs font-semibold text-ink">
                            {{ __('leave.field_end_date') }} <span class="text-red-500">*</span>
                        </label>
                        <input type="date"
                               id="end_date"
                               name="end_date"
                               x-model="endDate"
                               @change="fetchEffectiveDays()"
                               required
                               class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                    </div>
                </div>

                <!-- Panel Pratinjau Kalkulasi Hari Kerja Efektif Berbasis Roster -->
                <div class="rounded-2xl border border-line bg-surface-muted/60 p-3.5 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-2xs font-bold uppercase tracking-wider text-ink-muted">
                            {{ __('leave.preview_title') }}
                        </span>
                        <span x-show="calculating" x-cloak class="text-2xs text-accent font-medium">
                            {{ __('leave.preview_calculating') }}
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-2.5 text-center">
                        <div class="rounded-xl bg-surface border border-line p-2.5">
                            <div class="text-2xs text-ink-muted">{{ __('leave.preview_calendar_days') }}</div>
                            <div class="mt-0.5 text-sm font-mono font-bold text-ink" x-text="calendarDays">0</div>
                        </div>

                        <div class="rounded-xl bg-surface border border-line p-2.5">
                            <div class="text-2xs text-ink-muted">{{ __('leave.preview_excluded_off_days') }}</div>
                            <div class="mt-0.5 text-sm font-mono font-bold text-amber-600 dark:text-amber-400" x-text="excludedOffDays">0</div>
                        </div>

                        <div class="rounded-xl bg-surface border border-line p-2.5">
                            <div class="text-2xs text-ink-muted">{{ __('leave.preview_effective_days') }}</div>
                            <div class="mt-0.5 text-sm font-mono font-bold text-emerald-600 dark:text-emerald-400" x-text="Math.round(Number(effectiveDays || 0))">0</div>
                        </div>
                    </div>
                </div>

                <!-- Input Alasan Pengajuan -->
                <div class="space-y-1.5">
                    <label for="reason" class="block text-xs font-semibold text-ink">
                        {{ __('leave.field_reason') }} <span class="text-red-500">*</span>
                    </label>
                    <textarea id="reason"
                              name="reason"
                              rows="3"
                              minlength="3"
                              maxlength="1000"
                              required
                              placeholder="{{ __('leave.field_reason_placeholder') }}"
                              class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink placeholder:text-ink-muted/70 focus:outline-none focus:ring-2 focus:ring-accent">{{ old('reason') }}</textarea>
                </div>

                <!-- Input Lampiran Bukti (Gambar / PDF) -->
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <label for="attachment" class="block text-xs font-semibold text-ink">
                            {{ __('leave.field_attachment') }}
                            <span x-show="selectedType && selectedType.requires_attachment" class="text-red-500">*</span>
                        </label>
                        <span x-show="selectedType && selectedType.requires_attachment"
                              class="inline-flex items-center rounded-md bg-amber-500/10 px-2 py-0.5 text-2xs font-semibold text-amber-700 dark:text-amber-300 border border-amber-500/20">
                            {{ __('leave.attachment_required_badge') }}
                        </span>
                        <span x-show="!selectedType || !selectedType.requires_attachment"
                              class="inline-flex items-center rounded-md bg-surface-muted px-2 py-0.5 text-2xs font-medium text-ink-muted border border-line">
                            {{ __('leave.attachment_optional_badge') }}
                        </span>
                    </div>

                    <input type="file"
                           id="attachment"
                           name="attachment"
                           accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
                           :required="selectedType && selectedType.requires_attachment"
                           class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink file:mr-3 file:rounded-lg file:border-0 file:bg-accent file:px-3 file:py-1 file:text-xs file:font-semibold file:text-accent-fg cursor-pointer" />

                    <p class="text-2xs text-ink-muted">
                        {{ __('leave.attachment_hint') }}
                    </p>
                </div>

                <div class="pt-2 flex items-center justify-end gap-2">
                    <button type="button"
                            @click="$dispatch('close-modal', 'create-leave-request-modal')"
                            class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                        {{ __('common.cancel') }}
                    </button>
                    <button type="submit"
                            class="rounded-xl bg-accent px-4 py-2 text-xs font-bold text-accent-fg shadow-sm hover:opacity-95 transition cursor-pointer">
                        {{ __('leave.btn_submit_request') }}
                    </button>
                </div>
            </form>
        </x-ui.modal>

        <!-- Modal Ubah (Edit) Pengajuan Cuti -->
        <x-ui.modal name="edit-leave-request-modal" :title="__('leave.modal_edit_title')" maxWidth="lg">
            <form method="POST"
                  :action="editForm.actionUrl"
                  enctype="multipart/form-data"
                  class="space-y-4">
                @csrf
                @method('PUT')

                <template x-if="editForm.status === 'approved'">
                    <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-200">
                        {{ __('leave.modal_edit_approved_warning') }}
                    </div>
                </template>

                <!-- Pilihan Tipe Cuti -->
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <label for="edit_leave_type_id" class="block text-xs font-semibold text-ink">
                            {{ __('leave.field_leave_type') }} <span class="text-red-500">*</span>
                        </label>
                    </div>

                    <select id="edit_leave_type_id"
                            name="leave_type_id"
                            x-model="editForm.leaveTypeId"
                            required
                            class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                        @foreach ($leaveTypes as $lt)
                            <option value="{{ $lt->id }}">
                                {{ $lt->code }} — {{ $lt->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Input Rentang Tanggal Mulai & Selesai -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div class="space-y-1.5">
                        <label for="edit_start_date" class="block text-xs font-semibold text-ink">
                            {{ __('leave.field_start_date') }} <span class="text-red-500">*</span>
                        </label>
                        <input type="date"
                               id="edit_start_date"
                               name="start_date"
                               x-model="editForm.startDate"
                               @change="fetchEditEffectiveDays()"
                               required
                               class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                    </div>

                    <div class="space-y-1.5">
                        <label for="edit_end_date" class="block text-xs font-semibold text-ink">
                            {{ __('leave.field_end_date') }} <span class="text-red-500">*</span>
                        </label>
                        <input type="date"
                               id="edit_end_date"
                               name="end_date"
                               x-model="editForm.endDate"
                               @change="fetchEditEffectiveDays()"
                               required
                               class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                    </div>
                </div>

                <!-- Panel Pratinjau Kalkulasi Hari Kerja Efektif Berbasis Roster -->
                <div class="rounded-2xl border border-line bg-surface-muted/60 p-3.5 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-2xs font-bold uppercase tracking-wider text-ink-muted">
                            {{ __('leave.preview_title') }}
                        </span>
                        <span x-show="editForm.calculating" x-cloak class="text-2xs text-accent font-medium">
                            {{ __('leave.preview_calculating') }}
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-2.5 text-center">
                        <div class="rounded-xl bg-surface border border-line p-2.5">
                            <div class="text-2xs text-ink-muted">{{ __('leave.preview_calendar_days') }}</div>
                            <div class="mt-0.5 text-sm font-mono font-bold text-ink" x-text="editForm.calendarDays">0</div>
                        </div>

                        <div class="rounded-xl bg-surface border border-line p-2.5">
                            <div class="text-2xs text-ink-muted">{{ __('leave.preview_excluded_off_days') }}</div>
                            <div class="mt-0.5 text-sm font-mono font-bold text-amber-600 dark:text-amber-400" x-text="editForm.excludedOffDays">0</div>
                        </div>

                        <div class="rounded-xl bg-surface border border-line p-2.5">
                            <div class="text-2xs text-ink-muted">{{ __('leave.preview_effective_days') }}</div>
                            <div class="mt-0.5 text-sm font-mono font-bold text-emerald-600 dark:text-emerald-400" x-text="Math.round(Number(editForm.effectiveDays || 0))">0</div>
                        </div>
                    </div>
                </div>

                <!-- Input Alasan Pengajuan -->
                <div class="space-y-1.5">
                    <label for="edit_reason" class="block text-xs font-semibold text-ink">
                        {{ __('leave.field_reason') }} <span class="text-red-500">*</span>
                    </label>
                    <textarea id="edit_reason"
                              name="reason"
                              rows="3"
                              minlength="3"
                              maxlength="1000"
                              required
                              x-model="editForm.reason"
                              placeholder="{{ __('leave.field_reason_placeholder') }}"
                              class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink placeholder:text-ink-muted/70 focus:outline-none focus:ring-2 focus:ring-accent"></textarea>
                </div>

                <!-- Input Lampiran Bukti (Gambar / PDF) -->
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <label for="edit_attachment" class="block text-xs font-semibold text-ink">
                            {{ __('leave.field_attachment') }}
                            <span x-show="selectedEditType && selectedEditType.requires_attachment && !editForm.hasAttachment" class="text-red-500">*</span>
                        </label>
                    </div>

                    <input type="file"
                           id="edit_attachment"
                           name="attachment"
                           accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
                           :required="selectedEditType && selectedEditType.requires_attachment && !editForm.hasAttachment"
                           class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink file:mr-3 file:rounded-lg file:border-0 file:bg-accent file:px-3 file:py-1 file:text-xs file:font-semibold file:text-accent-fg cursor-pointer" />

                    <p class="text-2xs text-ink-muted">
                        {{ __('leave.attachment_hint') }}
                    </p>
                </div>

                <div class="pt-2 flex items-center justify-end gap-2">
                    <button type="button"
                            @click="$dispatch('close-modal', 'edit-leave-request-modal')"
                            class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                        {{ __('common.cancel') }}
                    </button>
                    <button type="submit"
                            class="rounded-xl bg-accent px-4 py-2 text-xs font-bold text-accent-fg shadow-sm hover:opacity-95 transition cursor-pointer">
                        {{ __('leave.btn_save_changes') }}
                    </button>
                </div>
            </form>
        </x-ui.modal>

        <!-- Modal Konfirmasi Pembatalan Cuti -->
        <x-ui.modal name="cancel-leave-request-modal" :title="__('leave.modal_cancel_title')" maxWidth="md">
            <form method="POST" :action="cancelActionUrl" class="space-y-4">
                @csrf
                <p class="text-xs text-ink-muted">
                    {{ __('leave.modal_cancel_desc') }}
                </p>

                <div class="space-y-1.5">
                    <label for="cancellation_reason" class="block text-xs font-semibold text-ink">
                        {{ __('leave.field_cancellation_reason') }}
                    </label>
                    <textarea id="cancellation_reason"
                              name="cancellation_reason"
                              rows="2"
                              maxlength="500"
                              placeholder="{{ __('leave.field_cancellation_placeholder') }}"
                              class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink placeholder:text-ink-muted/70 focus:outline-none focus:ring-2 focus:ring-accent"></textarea>
                </div>

                <div class="pt-2 flex items-center justify-end gap-2">
                    <button type="button"
                            @click="$dispatch('close-modal', 'cancel-leave-request-modal')"
                            class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                        {{ __('common.cancel') }}
                    </button>
                    <button type="submit"
                            class="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-red-700 transition cursor-pointer">
                        {{ __('leave.btn_confirm_cancel') }}
                    </button>
                </div>
            </form>
        </x-ui.modal>

        <!-- Modal Penolakan Pengajuan Cuti (Manager / HR) -->
        <x-ui.modal name="reject-leave-request-modal" :title="__('leave.modal_reject_title')" maxWidth="md">
            <form method="POST" :action="rejectActionUrl" class="space-y-4">
                @csrf
                <p class="text-xs text-ink-muted">
                    {{ __('leave.modal_reject_desc') }}
                </p>

                <div class="space-y-1.5">
                    <label for="reject_reason_input" class="block text-xs font-semibold text-ink">
                        {{ __('leave.field_rejection_reason') }} <span class="text-red-500">*</span>
                    </label>
                    <textarea id="reject_reason_input"
                              name="reason"
                              rows="3"
                              minlength="5"
                              maxlength="500"
                              required
                              placeholder="{{ __('leave.field_rejection_placeholder') }}"
                              class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink placeholder:text-ink-muted/70 focus:outline-none focus:ring-2 focus:ring-accent"></textarea>
                </div>

                <div class="pt-2 flex items-center justify-end gap-2">
                    <button type="button"
                            @click="$dispatch('close-modal', 'reject-leave-request-modal')"
                            class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                        {{ __('common.cancel') }}
                    </button>
                    <button type="submit"
                            class="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-red-700 transition cursor-pointer">
                        {{ __('leave.btn_confirm_reject') }}
                    </button>
                </div>
            </form>
        </x-ui.modal>

        <!-- Modal Detail Analisis Objektif SPK SMART (Mode A & Mode B) -->
        @if ($showEmployeeColumn)
            <x-ui.modal name="smart-analysis-modal" :title="__('leave.modal_smart_analysis_title')" maxWidth="2xl">
                <div class="space-y-4">
                    <div x-show="loadingAnalysis" class="py-8 text-center text-xs text-ink-muted">
                        {{ __('leave.smart_loading') }}
                    </div>

                    <template x-if="!loadingAnalysis && smartAnalysisData">
                        <div class="space-y-4">
                            <!-- Ringkasan Mode & Skor Akhir -->
                            <div class="rounded-2xl border border-line bg-surface-muted/60 p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center rounded-md bg-accent/10 px-2.5 py-0.5 text-2xs font-mono font-bold text-accent border border-accent/20"
                                              x-text="smartAnalysisData.mode_label"></span>
                                        <span class="text-xs font-semibold text-ink" x-text="smartAnalysisData.department_name"></span>
                                    </div>
                                    <div class="text-xs text-ink-muted">
                                        <span class="font-semibold text-ink" x-text="smartAnalysisData.employee_name"></span>
                                        &bull;
                                        <span class="font-mono" x-text="`${smartAnalysisData.start_date} — ${smartAnalysisData.end_date}`"></span>
                                    </div>
                                </div>

                                <div class="text-left sm:text-right">
                                    <div class="text-2xs uppercase tracking-wider text-ink-muted">{{ __('leave.col_smart_score') }}</div>
                                    <div class="text-xl font-mono font-bold text-ink"
                                         x-text="`${Number(smartAnalysisData.smart_score || 0).toFixed(2)} — ${smartAnalysisData.recommendation_label || ''}`"></div>
                                </div>
                            </div>

                            <!-- Tabel Rincian 5 Kriteria (C1..C5) -->
                            <div class="overflow-x-auto rounded-xl border border-line">
                                <table class="w-full text-left text-xs">
                                    <thead class="bg-surface-muted border-b border-line text-2xs uppercase text-ink-muted">
                                        <tr>
                                            <th class="px-3 py-2">{{ __('leave.smart_col_criteria') }}</th>
                                            <th class="px-3 py-2 font-mono">{{ __('leave.smart_col_raw_value') }}</th>
                                            <th class="px-3 py-2 font-mono">{{ __('leave.smart_col_utility') }}</th>
                                            <th class="px-3 py-2 font-mono">{{ __('leave.smart_col_norm_weight') }}</th>
                                            <th class="px-3 py-2 font-mono text-right">{{ __('leave.smart_col_weighted_score') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-line">
                                        <template x-for="(crit, code) in smartAnalysisData.criteria" :key="code">
                                            <tr>
                                                <td class="px-3 py-2">
                                                    <div class="font-semibold text-ink" x-text="crit.name"></div>
                                                    <div class="text-2xs text-ink-muted uppercase" x-text="crit.type"></div>
                                                </td>
                                                <td class="px-3 py-2 font-mono text-ink" x-text="`${Number(crit.raw_value).toFixed(2)} ${crit.unit}`"></td>
                                                <td class="px-3 py-2 font-mono text-ink" x-text="Number(crit.utility).toFixed(2)"></td>
                                                <td class="px-3 py-2 font-mono text-ink" x-text="Number(crit.normalized_weight).toFixed(4)"></td>
                                                <td class="px-3 py-2 font-mono font-bold text-ink text-right" x-text="Number(crit.weighted_score).toFixed(2)"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Tabel Peringkat Komparasi Multi-Staf (Tampil jika Mode A) -->
                            <template x-if="smartAnalysisData.mode === 'A' && smartAnalysisData.candidates && smartAnalysisData.candidates.length > 1">
                                <div class="space-y-2 pt-2">
                                    <h4 class="text-xs font-bold uppercase tracking-wider text-ink-muted">
                                        {{ __('leave.smart_comparative_title') }}
                                    </h4>
                                    <div class="overflow-x-auto rounded-xl border border-line">
                                        <table class="w-full text-left text-xs">
                                            <thead class="bg-surface-muted border-b border-line text-2xs uppercase text-ink-muted">
                                                <tr>
                                                    <th class="px-3 py-2">{{ __('leave.smart_col_rank') }}</th>
                                                    <th class="px-3 py-2">{{ __('leave.col_employee') }}</th>
                                                    <th class="px-3 py-2">{{ __('leave.col_date_range') }}</th>
                                                    <th class="px-3 py-2 font-mono">{{ __('leave.col_smart_score') }}</th>
                                                    <th class="px-3 py-2">{{ __('leave.smart_col_quota_status') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-line">
                                                <template x-for="cand in smartAnalysisData.candidates" :key="cand.leave_request_id">
                                                    <tr :class="cand.is_target ? 'bg-accent/5' : ''">
                                                        <td class="px-3 py-2 font-mono font-bold text-ink" x-text="`#${cand.rank}`"></td>
                                                        <td class="px-3 py-2">
                                                            <div class="font-semibold text-ink" x-text="cand.employee_name"></div>
                                                            <div class="text-2xs text-ink-muted" x-text="cand.position"></div>
                                                        </td>
                                                        <td class="px-3 py-2 font-mono text-ink" x-text="`${cand.start_date} — ${cand.end_date}`"></td>
                                                        <td class="px-3 py-2 font-mono font-bold text-ink" x-text="`${Number(cand.smart_score).toFixed(2)} (${cand.recommendation_label})`"></td>
                                                        <td class="px-3 py-2">
                                                            <span x-show="cand.can_approve_within_quota"
                                                                  class="inline-flex items-center rounded-md bg-emerald-500/10 px-2 py-0.5 text-2xs font-semibold text-emerald-700 dark:text-emerald-300 border border-emerald-500/30">
                                                                {{ __('leave.smart_quota_safe') }}
                                                            </span>
                                                            <span x-show="!cand.can_approve_within_quota"
                                                                  class="inline-flex items-center rounded-md bg-red-500/10 px-2 py-0.5 text-2xs font-semibold text-red-700 dark:text-red-300 border border-red-500/30">
                                                                {{ __('leave.smart_quota_breach') }}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <div class="pt-2 flex flex-wrap items-center justify-between gap-2 border-t border-line">
                        <div class="flex items-center gap-2">
                            <template x-if="smartAnalysisActions.canApproveTier1">
                                <form method="POST" :action="smartAnalysisActions.approveTier1Url" class="inline">
                                    @csrf
                                    <button type="submit"
                                            class="rounded-xl bg-emerald-600 px-3.5 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 transition cursor-pointer">
                                        {{ __('leave.btn_approve_tier1') }}
                                    </button>
                                </form>
                            </template>

                            <template x-if="smartAnalysisActions.canApproveTier2">
                                <form method="POST" :action="smartAnalysisActions.approveTier2Url" class="inline">
                                    @csrf
                                    <button type="submit"
                                            class="rounded-xl bg-emerald-600 px-3.5 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 transition cursor-pointer">
                                        {{ __('leave.btn_approve_tier2') }}
                                    </button>
                                </form>
                            </template>

                            <template x-if="smartAnalysisActions.canReject">
                                <button type="button"
                                        @click="$dispatch('close-modal', 'smart-analysis-modal'); openRejectModal(smartAnalysisActions.rejectUrl)"
                                        class="rounded-xl bg-red-600 px-3.5 py-2 text-xs font-bold text-white shadow-sm hover:bg-red-700 transition cursor-pointer">
                                    {{ __('leave.btn_reject') }}
                                </button>
                            </template>
                        </div>

                        <button type="button"
                                @click="$dispatch('close-modal', 'smart-analysis-modal')"
                                class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                            {{ __('common.close') }}
                        </button>
                    </div>
                </div>
            </x-ui.modal>
        @endif

        <!-- Modal Kartu Buku Besar & Rekonsiliasi Saldo Cuti (Leave Ledger & HR Adjustment Console) -->
        @if (($ledgerEmployees ?? collect())->isNotEmpty())
            <x-ui.modal name="leave-ledger-modal" :title="__('leave.modal_ledger_title')" maxWidth="2xl">
                <div class="space-y-5">
                    <p class="text-xs text-ink-muted">
                        {{ __('leave.modal_ledger_desc') }}
                    </p>

                    <!-- Pemilih Karyawan -->
                    <div class="space-y-1.5">
                        <label for="ledger_employee_select" class="block text-xs font-semibold text-ink">
                            {{ __('leave.ledger_select_employee') }}
                        </label>
                        <select id="ledger_employee_select"
                                x-model="selectedLedgerEmployeeId"
                                @change="fetchLedgerHistory()"
                                class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                            <template x-for="emp in ledgerEmployees" :key="emp.id">
                                <option :value="String(emp.id)" x-text="`${emp.name} (${emp.department} — ${emp.position})`"></option>
                            </template>
                        </select>
                    </div>

                    <!-- Ringkasan Saldo Real-Time Karyawan Terpilih -->
                    <template x-if="ledgerData && ledgerData.balances">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="rounded-xl border border-line bg-surface-muted/60 p-3 flex items-center justify-between">
                                <div>
                                    <div class="text-2xs uppercase text-ink-muted font-semibold">{{ __('leave.card_al_title') }}</div>
                                    <div class="text-xs text-ink-muted">
                                        {{ __('leave.label_ledger_balance') }}:
                                        <span class="font-mono font-bold text-ink" x-text="Math.round(Number(ledgerData.balances.AL?.ledger_balance || 0))"></span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-2xs text-emerald-700 dark:text-emerald-300">{{ __('leave.label_available_balance') }}</div>
                                    <div class="text-base font-mono font-bold text-emerald-700 dark:text-emerald-300"
                                         x-text="`${Math.round(Number(ledgerData.balances.AL?.available_balance || 0))} {{ __('leave.days_unit') }}`"></div>
                                </div>
                            </div>

                            <div class="rounded-xl border border-line bg-surface-muted/60 p-3 flex items-center justify-between">
                                <div>
                                    <div class="text-2xs uppercase text-ink-muted font-semibold">{{ __('leave.card_dp_title') }}</div>
                                    <div class="text-xs text-ink-muted">
                                        {{ __('leave.label_ledger_balance') }}:
                                        <span class="font-mono font-bold text-ink" x-text="Math.round(Number(ledgerData.balances.DP?.ledger_balance || 0))"></span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-2xs text-emerald-700 dark:text-emerald-300">{{ __('leave.label_available_balance') }}</div>
                                    <div class="text-base font-mono font-bold text-emerald-700 dark:text-emerald-300"
                                         x-text="`${Math.round(Number(ledgerData.balances.DP?.available_balance || 0))} {{ __('leave.days_unit') }}`"></div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Form Penyesuaian Saldo Manual (Khusus Admin, Super User, Super Admin) -->
                    @if ($isAdminTier ?? false)
                        <form method="POST"
                              action="{{ route('leave.ledger.adjust') }}"
                              class="rounded-2xl border border-line bg-surface-muted/50 p-4 space-y-3">
                            @csrf
                            <input type="hidden" name="employee_id" :value="selectedLedgerEmployeeId" />

                            <div class="text-xs font-bold uppercase tracking-wider text-ink">
                                {{ __('leave.ledger_adjust_title') }}
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div class="space-y-1">
                                    <label class="block text-2xs font-semibold text-ink-muted">{{ __('leave.field_leave_type') }}</label>
                                    <select name="leave_type_id"
                                            required
                                            class="w-full rounded-xl border border-line bg-surface px-3 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                                        @foreach ($quotaLeaveTypes ?? [] as $qlt)
                                            <option value="{{ $qlt->id }}">{{ $qlt->code }} — {{ $qlt->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="space-y-1">
                                    <label class="block text-2xs font-semibold text-ink-muted">{{ __('leave.field_adjust_amount') }}</label>
                                    <input type="number"
                                           name="amount"
                                           step="1"
                                           min="-365"
                                           max="365"
                                           required
                                           placeholder="+1 / -1"
                                           class="w-full rounded-xl border border-line bg-surface px-3 py-1.5 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                                </div>

                                <div class="space-y-1">
                                    <label class="block text-2xs font-semibold text-ink-muted">{{ __('leave.field_adjust_expires_at') }}</label>
                                    <input type="date"
                                           name="expires_at"
                                           class="w-full rounded-xl border border-line bg-surface px-3 py-1.5 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                                </div>
                            </div>

                            <div class="space-y-1">
                                <label class="block text-2xs font-semibold text-ink-muted">{{ __('leave.field_adjust_notes') }}</label>
                                <input type="text"
                                       name="notes"
                                       minlength="5"
                                       maxlength="500"
                                       required
                                       placeholder="{{ __('leave.field_adjust_notes_placeholder') }}"
                                       class="w-full rounded-xl border border-line bg-surface px-3 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                            </div>

                            <div class="flex justify-end">
                                <button type="submit"
                                        class="rounded-xl bg-accent px-4 py-1.5 text-xs font-bold text-accent-fg shadow-2xs hover:opacity-95 transition cursor-pointer">
                                    {{ __('leave.btn_submit_adjustment') }}
                                </button>
                            </div>
                        </form>
                    @endif

                    <!-- Tabel 50 Riwayat Mutasi Buku Besar Terakhir -->
                    <div class="space-y-2">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-ink-muted">
                            {{ __('leave.ledger_history_title') }}
                        </h4>

                        <div class="overflow-x-auto max-h-72 rounded-xl border border-line">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-surface-muted border-b border-line text-2xs uppercase text-ink-muted sticky top-0">
                                    <tr>
                                        <th class="px-3 py-2">{{ __('leave.ledger_col_date') }}</th>
                                        <th class="px-3 py-2">{{ __('leave.ledger_col_type') }}</th>
                                        <th class="px-3 py-2">{{ __('leave.ledger_col_tx') }}</th>
                                        <th class="px-3 py-2 font-mono">{{ __('leave.ledger_col_amount') }}</th>
                                        <th class="px-3 py-2 font-mono">{{ __('leave.ledger_col_remaining') }}</th>
                                        <th class="px-3 py-2 font-mono">{{ __('leave.ledger_col_balance_after') }}</th>
                                        <th class="px-3 py-2 font-mono">{{ __('leave.ledger_col_expires_at') }}</th>
                                        <th class="px-3 py-2">{{ __('leave.ledger_col_notes') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    <template x-if="!ledgerData || !ledgerData.entries || ledgerData.entries.length === 0">
                                        <tr>
                                            <td colspan="8" class="px-3 py-6 text-center text-xs text-ink-muted">
                                                {{ __('leave.ledger_empty') }}
                                            </td>
                                        </tr>
                                    </template>

                                    <template x-if="ledgerData && ledgerData.entries">
                                        <template x-for="entry in ledgerData.entries" :key="entry.id">
                                            <tr>
                                                <td class="px-3 py-2 font-mono text-2xs text-ink whitespace-nowrap" x-text="entry.created_at"></td>
                                                <td class="px-3 py-2 font-mono font-bold text-ink" x-text="entry.leave_type_code"></td>
                                                <td class="px-3 py-2 uppercase text-2xs font-mono text-ink-muted" x-text="entry.transaction_type"></td>
                                                <td class="px-3 py-2 font-mono font-bold"
                                                    :class="Number(entry.amount) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'"
                                                    x-text="`${Number(entry.amount) > 0 ? '+' : ''}${Math.round(Number(entry.amount || 0))}`"></td>
                                                <td class="px-3 py-2 font-mono text-ink" x-text="Math.round(Number(entry.remaining_amount || 0))"></td>
                                                <td class="px-3 py-2 font-mono font-bold text-ink" x-text="Math.round(Number(entry.balance_after || 0))"></td>
                                                <td class="px-3 py-2 font-mono text-2xs text-ink-muted" x-text="entry.expires_at || '—'"></td>
                                                <td class="px-3 py-2 text-2xs text-ink">
                                                    <div x-text="entry.notes || '—'"></div>
                                                    <div class="text-ink-muted" x-text="entry.created_by_name"></div>
                                                </td>
                                            </tr>
                                        </template>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button type="button"
                                @click="$dispatch('close-modal', 'leave-ledger-modal')"
                                class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                            {{ __('common.close') }}
                        </button>
                    </div>
                </div>
            </x-ui.modal>
        @endif

        <!-- Modal Konfigurasi Bobot SPK SMART Departemen -->
        @if ($canManageSmartWeights ?? false)
            <x-ui.modal name="smart-weights-modal" :title="__('leave.modal_smart_weights_title')" maxWidth="lg">
                <form method="POST" action="{{ route('leave.smartWeights.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <p class="text-xs text-ink-muted">
                        {{ __('leave.modal_smart_weights_desc') }}
                    </p>

                    <div class="space-y-1.5">
                        <label for="weight_department_id" class="block text-xs font-semibold text-ink">
                            {{ __('leave.filter_department') }}
                        </label>
                        <select id="weight_department_id"
                                name="department_id"
                                x-model="selectedWeightDeptId"
                                @change="loadDeptWeights()"
                                @if (! ($isAdminTier ?? false)) disabled @endif
                                class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                            @foreach ($departments as $dept)
                                <option value="{{ $dept->id }}">{{ $dept->code }} — {{ $dept->name }}</option>
                            @endforeach
                        </select>
                        @if (! ($isAdminTier ?? false) && $departments->isNotEmpty())
                            <input type="hidden" name="department_id" value="{{ $departments->first()->id }}" />
                        @endif
                    </div>

                    @php
                        $criteriaLabels = [
                            'C1' => __('leave.smart_criteria_c1'),
                            'C2' => __('leave.smart_criteria_c2'),
                            'C3' => __('leave.smart_criteria_c3'),
                            'C4' => __('leave.smart_criteria_c4'),
                            'C5' => __('leave.smart_criteria_c5'),
                        ];
                    @endphp
                    <div class="space-y-3 rounded-2xl border border-line bg-surface-muted/50 p-4">
                        @foreach ($criteriaLabels as $cCode => $cLabel)
                            <div class="space-y-1.5 rounded-xl border border-line bg-surface p-3"
                                 :class="weightLocks['{{ $cCode }}'] ? 'border-amber-500/40 bg-amber-500/5' : ''">
                                <div class="flex items-center justify-between gap-2 text-xs">
                                    <label for="weight_slider_{{ $cCode }}" class="font-semibold text-ink">
                                        {{ $cLabel }}
                                    </label>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <span class="font-mono text-xs font-bold text-ink">
                                            <span x-text="weightForm['{{ $cCode }}']"></span>%
                                            <span class="text-2xs font-normal text-ink-muted">(<span x-text="normalizedPreview('{{ $cCode }}')"></span>)</span>
                                        </span>
                                        <button type="button"
                                                @click="toggleWeightLock('{{ $cCode }}')"
                                                :title="weightLocks['{{ $cCode }}'] ? '{{ __('leave.smart_unlock_criterion') }}' : '{{ __('leave.smart_lock_criterion') }}'"
                                                class="inline-flex items-center gap-1 rounded-lg border px-2 py-0.5 text-2xs font-semibold transition cursor-pointer"
                                                :class="weightLocks['{{ $cCode }}']
                                                    ? 'border-amber-500/40 bg-amber-500/15 text-amber-700 dark:text-amber-300'
                                                    : 'border-line bg-surface-muted text-ink-muted hover:text-ink'">
                                            <svg x-show="weightLocks['{{ $cCode }}']" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                            </svg>
                                            <svg x-show="!weightLocks['{{ $cCode }}']" x-cloak class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                            </svg>
                                            <span x-text="weightLocks['{{ $cCode }}'] ? '{{ __('leave.smart_locked_badge') }}' : '{{ __('leave.smart_unlocked_badge') }}'"></span>
                                        </button>
                                    </div>
                                </div>

                                <input type="range"
                                       id="weight_slider_{{ $cCode }}"
                                       min="0"
                                       max="100"
                                       step="1"
                                       :value="weightForm['{{ $cCode }}']"
                                       :disabled="weightLocks['{{ $cCode }}']"
                                       @input="onSliderChange('{{ $cCode }}', $event.target.value, $event.target)"
                                       class="w-full h-2 rounded-lg accent-accent cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed" />

                                <input type="hidden"
                                       id="weight_{{ $cCode }}"
                                       name="weights[{{ $cCode }}]"
                                       :value="weightForm['{{ $cCode }}']" />
                            </div>
                        @endforeach

                        <div class="flex items-center justify-between pt-2 border-t border-line text-xs">
                            <span class="font-semibold text-ink-muted">{{ __('leave.smart_weight_total_required') }}</span>
                            <span class="font-mono font-bold px-2.5 py-0.5 rounded-md border"
                                  :class="totalWeightSum === 100
                                      ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/30'
                                      : 'bg-red-500/10 text-red-700 dark:text-red-300 border-red-500/30'"
                                  x-text="`${totalWeightSum}%`"></span>
                        </div>
                    </div>

                    <div class="pt-2 flex items-center justify-end gap-2">
                        <button type="button"
                                @click="$dispatch('close-modal', 'smart-weights-modal')"
                                class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                            {{ __('common.cancel') }}
                        </button>
                        <button type="submit"
                                class="rounded-xl bg-accent px-4 py-2 text-xs font-bold text-accent-fg shadow-sm hover:opacity-95 transition cursor-pointer">
                            {{ __('leave.btn_save_weights') }}
                        </button>
                    </div>
                </form>
            </x-ui.modal>
        @endif

        <!-- Modal Pengaturan Tipe Cuti & Masa Kedaluwarsa (Admin+ Only) -->
        @if ($canManageLeaveTypeSettings ?? false)
            <x-ui.modal name="leave-type-settings-modal" :title="__('leave.modal_type_settings_title')" maxWidth="2xl">
                <div class="space-y-4">
                    <p class="text-xs text-ink-muted">
                        {{ __('leave.modal_type_settings_desc') }}
                    </p>

                    <div class="space-y-3">
                        @foreach ($leaveTypes as $lt)
                            <form method="POST"
                                  action="{{ route('leave.types.updateSettings', $lt) }}"
                                  class="rounded-2xl border border-line bg-surface-muted/50 p-3.5 grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
                                @csrf
                                @method('PUT')

                                <div class="sm:col-span-4">
                                    <div class="text-xs font-bold text-ink">
                                        <span class="font-mono">{{ $lt->code }}</span> &mdash; {{ $lt->name }}
                                    </div>
                                    <div class="text-2xs text-ink-muted">
                                        {{ $lt->requires_quota ? __('leave.label_ledger_balance') : __('leave.non_quota_badge') }}
                                    </div>
                                </div>

                                <div class="sm:col-span-3 space-y-1">
                                    <label class="block text-2xs font-semibold text-ink-muted">
                                        {{ __('leave.field_validity_months') }}
                                    </label>
                                    <input type="number"
                                           name="validity_months"
                                           min="1"
                                           max="36"
                                           value="{{ $lt->getEffectiveValidityMonths($currentCompany?->id) }}"
                                           placeholder="{{ __('leave.field_validity_never') }}"
                                           class="w-full rounded-xl border border-line bg-surface px-3 py-1.5 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                                </div>

                                <div class="sm:col-span-3 space-y-1">
                                    <label class="block text-2xs font-semibold text-ink-muted">
                                        {{ __('leave.field_default_annual_quota') }}
                                    </label>
                                    <input type="number"
                                           name="default_annual_quota"
                                           min="0"
                                           max="365"
                                           step="1"
                                           value="{{ $lt->getEffectiveAnnualQuota($currentCompany?->id) }}"
                                           class="w-full rounded-xl border border-line bg-surface px-3 py-1.5 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                                </div>

                                <div class="sm:col-span-2 flex justify-end">
                                    <button type="submit"
                                            class="w-full rounded-xl bg-accent px-3 py-1.5 text-xs font-bold text-accent-fg shadow-2xs hover:opacity-95 transition cursor-pointer">
                                        {{ __('leave.btn_save_settings') }}
                                    </button>
                                </div>
                            </form>
                        @endforeach
                    </div>
                </div>
            </x-ui.modal>
        @endif

        @if ($canDebugSmart ?? false)
            @php
                $debugCriteriaMeta = [
                    'C1' => [
                        'title' => __('leave.debug_criteria_c1_title'),
                        'short' => __('leave.debug_criteria_c1_short'),
                        'nature' => 'benefit',
                        'nature_label' => __('leave.debug_badge_benefit'),
                        'scale' => __('leave.debug_criteria_c1_scale'),
                        'desc' => __('leave.debug_criteria_c1_desc'),
                    ],
                    'C2' => [
                        'title' => __('leave.debug_criteria_c2_title'),
                        'short' => __('leave.debug_criteria_c2_short'),
                        'nature' => 'cost',
                        'nature_label' => __('leave.debug_badge_cost'),
                        'scale' => __('leave.debug_criteria_c2_scale'),
                        'desc' => __('leave.debug_criteria_c2_desc'),
                    ],
                    'C3' => [
                        'title' => __('leave.debug_criteria_c3_title'),
                        'short' => __('leave.debug_criteria_c3_short'),
                        'nature' => 'benefit',
                        'nature_label' => __('leave.debug_badge_benefit'),
                        'scale' => __('leave.debug_criteria_c3_scale'),
                        'desc' => __('leave.debug_criteria_c3_desc'),
                    ],
                    'C4' => [
                        'title' => __('leave.debug_criteria_c4_title'),
                        'short' => __('leave.debug_criteria_c4_short'),
                        'nature' => 'cost',
                        'nature_label' => __('leave.debug_badge_cost'),
                        'scale' => __('leave.debug_criteria_c4_scale'),
                        'desc' => __('leave.debug_criteria_c4_desc'),
                    ],
                    'C5' => [
                        'title' => __('leave.debug_criteria_c5_title'),
                        'short' => __('leave.debug_criteria_c5_short'),
                        'nature' => 'benefit',
                        'nature_label' => __('leave.debug_badge_benefit'),
                        'scale' => __('leave.debug_criteria_c5_scale'),
                        'desc' => __('leave.debug_criteria_c5_desc'),
                    ],
                ];
            @endphp

            <x-ui.modal name="smart-debug-modal" :title="__('leave.modal_smart_debug_title')" maxWidth="5xl">
                <div class="space-y-5">
                    <p class="text-xs text-ink-muted leading-relaxed">
                        {{ __('leave.modal_smart_debug_desc') }}
                    </p>

                    <!-- Quick Scenario Presets -->
                    <div class="rounded-2xl border border-line bg-surface-muted/40 p-3.5 space-y-2">
                        <div class="text-2xs font-bold uppercase tracking-wider text-ink-muted">
                            {{ __('leave.debug_preset_label') }}
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button"
                                    @click="applyDebugPreset('mode_a')"
                                    class="rounded-xl border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                                {{ __('leave.debug_preset_mode_a') }}
                            </button>
                            <button type="button"
                                    @click="applyDebugPreset('mode_b')"
                                    class="rounded-xl border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                                {{ __('leave.debug_preset_mode_b') }}
                            </button>
                            <button type="button"
                                    @click="applyDebugPreset('critical_staff')"
                                    class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-800 dark:text-amber-300 hover:bg-amber-500/20 transition cursor-pointer">
                                {{ __('leave.debug_preset_critical_staff') }}
                            </button>
                        </div>
                    </div>

                    <!-- Bobot C1..C5 (Coupled Lockable Sliders 100%) & Parameter Formasi Shift -->
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
                        <div class="lg:col-span-8 rounded-2xl border border-line bg-surface-muted/40 p-4 space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-xs font-bold text-ink">{{ __('leave.debug_weights_section') }}</span>
                                <div class="flex items-center gap-2">
                                    <button type="button"
                                            @click="resetDebugWeights()"
                                            class="inline-flex items-center rounded-lg border border-line bg-surface px-2.5 py-1 text-2xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                                        {{ __('leave.debug_btn_reset_weights') }}
                                    </button>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-2xs font-mono font-bold border"
                                          :class="debugWeightSum === 100 ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/30' : 'bg-red-500/10 text-red-700 dark:text-red-300 border-red-500/30'"
                                          x-text="`{{ __('leave.debug_weight_sum') }}: ${debugWeightSum}%`"></span>
                                </div>
                            </div>

                            <div class="space-y-2.5">
                                @foreach ($debugCriteriaMeta as $cCode => $meta)
                                    <div class="space-y-2 rounded-xl border border-line bg-surface p-3 transition-colors"
                                         :class="debugWeightLocks['{{ $cCode }}'] ? 'border-amber-500/40 bg-amber-500/5' : ''">
                                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                            <div class="flex flex-wrap items-center gap-1.5 min-w-0">
                                                <label for="debug_weight_slider_{{ $cCode }}" class="font-semibold text-ink">
                                                    {{ $meta['title'] }}
                                                </label>
                                                <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-semibold {{ $meta['nature'] === 'benefit' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300' }}">
                                                    {{ $meta['nature_label'] }} &middot; {{ $meta['scale'] }}
                                                </span>
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <span class="font-mono text-xs font-bold text-ink">
                                                    <span x-text="debugForm.weights['{{ $cCode }}']"></span>%
                                                    <span class="text-2xs font-normal text-ink-muted">(w'=<span x-text="debugNormalizedPreview('{{ $cCode }}')"></span>)</span>
                                                </span>
                                                <button type="button"
                                                        id="debug_weight_lock_{{ $cCode }}"
                                                        @click="toggleDebugWeightLock('{{ $cCode }}')"
                                                        :aria-pressed="debugWeightLocks['{{ $cCode }}'] ? 'true' : 'false'"
                                                        :title="debugWeightLocks['{{ $cCode }}'] ? '{{ __('leave.smart_unlock_criterion') }}' : '{{ __('leave.smart_lock_criterion') }}'"
                                                        class="inline-flex items-center gap-1 rounded-lg border px-2 py-0.5 text-2xs font-semibold transition cursor-pointer"
                                                        :class="debugWeightLocks['{{ $cCode }}']
                                                            ? 'border-amber-500/40 bg-amber-500/15 text-amber-700 dark:text-amber-300'
                                                            : 'border-line bg-surface-muted text-ink-muted hover:text-ink'">
                                                    <svg x-show="debugWeightLocks['{{ $cCode }}']" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                                    </svg>
                                                    <svg x-show="!debugWeightLocks['{{ $cCode }}']" x-cloak class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                                    </svg>
                                                    <span x-text="debugWeightLocks['{{ $cCode }}'] ? '{{ __('leave.smart_locked_badge') }}' : '{{ __('leave.smart_unlocked_badge') }}'"></span>
                                                </button>
                                            </div>
                                        </div>

                                        <p class="text-2xs text-ink-muted leading-relaxed">
                                            {{ $meta['desc'] }}
                                        </p>

                                        <input type="range"
                                               id="debug_weight_slider_{{ $cCode }}"
                                               min="0"
                                               max="100"
                                               step="1"
                                               :value="debugForm.weights['{{ $cCode }}']"
                                               :disabled="debugWeightLocks['{{ $cCode }}']"
                                               @input="onDebugSliderChange('{{ $cCode }}', $event.target.value, $event.target)"
                                               class="w-full h-2 rounded-lg accent-accent cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed" />
                                    </div>
                                @endforeach
                            </div>

                            <div class="flex items-center justify-between pt-2 border-t border-line text-xs">
                                <span class="font-semibold text-ink-muted">{{ __('leave.smart_weight_total_required') }}</span>
                                <span class="font-mono font-bold px-2.5 py-0.5 rounded-md border"
                                      :class="debugWeightSum === 100
                                          ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/30'
                                          : 'bg-red-500/10 text-red-700 dark:text-red-300 border-red-500/30'"
                                      x-text="`${debugWeightSum}%`"></span>
                            </div>
                        </div>

                        <div class="lg:col-span-4 space-y-4">
                            <div class="rounded-2xl border border-line bg-surface p-4 space-y-3.5">
                                <template x-if="debugDepartments.length > 0">
                                    <div class="space-y-1">
                                        <label for="debug_department_select" class="block text-2xs font-semibold text-ink-muted">
                                            {{ __('leave.debug_load_dept_weights_label') }}
                                        </label>
                                        <select id="debug_department_select"
                                                x-model="debugSelectedDeptId"
                                                @change="loadDebugDeptWeights()"
                                                class="w-full rounded-xl border border-line bg-surface-muted px-2.5 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                                            <option value="">{{ __('leave.debug_select_department') }}</option>
                                            <template x-for="dept in debugDepartments" :key="dept.id">
                                                <option :value="String(dept.id)" x-text="`${dept.code} — ${dept.name}`"></option>
                                            </template>
                                        </select>
                                        <p class="text-2xs text-ink-muted leading-snug">
                                            {{ __('leave.debug_load_dept_weights_hint') }}
                                        </p>
                                    </div>
                                </template>

                                <div class="grid grid-cols-2 gap-3">
                                    <div class="space-y-1">
                                        <label class="block text-2xs font-semibold text-ink-muted">
                                            {{ __('leave.debug_min_staff_label') }}
                                        </label>
                                        <input type="number"
                                               min="1"
                                               max="500"
                                               step="1"
                                               x-model.number="debugForm.min_staff_per_shift"
                                               class="w-full rounded-xl border border-line bg-surface-muted px-2.5 py-1.5 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                                    </div>
                                    <div class="space-y-1">
                                        <label class="block text-2xs font-semibold text-ink-muted">
                                            {{ __('leave.debug_total_staff_label') }}
                                        </label>
                                        <input type="number"
                                               min="1"
                                               max="2000"
                                               step="1"
                                               x-model.number="debugForm.total_department_staff"
                                               class="w-full rounded-xl border border-line bg-surface-muted px-2.5 py-1.5 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                                    </div>
                                </div>

                                <div class="space-y-1">
                                    <label for="debug_request_select" class="block text-2xs font-semibold text-ink-muted">
                                        {{ __('leave.debug_telemetry_source') }}
                                    </label>
                                    <select id="debug_request_select"
                                            x-model="debugForm.leave_request_id"
                                            @change="onDebugRequestChange()"
                                            class="w-full rounded-xl border border-line bg-surface-muted px-2.5 py-1.5 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                                        <option value="">{{ __('leave.debug_select_request') }}</option>
                                        <template x-for="reqItem in debugRequests" :key="reqItem.id">
                                            <option :value="reqItem.id"
                                                    x-text="`#${reqItem.id} - ${reqItem.employee_name} (${reqItem.department_code} · ${reqItem.leave_type_code}, ${reqItem.total_days} {{ __('leave.days_unit') }}, ${reqItem.start_date})`"></option>
                                        </template>
                                    </select>
                                    <p class="text-2xs text-ink-muted leading-snug">
                                        {{ __('leave.debug_telemetry_sync_hint') }}
                                    </p>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-line bg-surface-muted/40 p-4 space-y-2.5">
                                <div class="text-2xs font-bold uppercase tracking-wider text-ink-muted">
                                    {{ __('leave.debug_criteria_legend_title') }}
                                </div>
                                <div class="space-y-1.5 text-2xs text-ink">
                                    <div class="flex items-start gap-2">
                                        <span class="shrink-0 inline-flex items-center rounded-md border border-emerald-500/30 bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300">
                                            {{ __('leave.debug_badge_benefit') }}
                                        </span>
                                        <span class="text-ink-muted leading-snug">{{ __('leave.smart_type_benefit') }}</span>
                                    </div>
                                    <div class="flex items-start gap-2">
                                        <span class="shrink-0 inline-flex items-center rounded-md border border-amber-500/30 bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:text-amber-300">
                                            {{ __('leave.debug_badge_cost') }}
                                        </span>
                                        <span class="text-ink-muted leading-snug">{{ __('leave.smart_type_cost') }}</span>
                                    </div>
                                </div>
                                <p class="text-2xs text-ink-muted leading-relaxed pt-2 border-t border-line">
                                    {{ __('leave.debug_slider_coupling_hint') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Matriks Kandidat What-If -->
                    <div class="rounded-2xl border border-line bg-surface p-4 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-xs font-bold text-ink">{{ __('leave.debug_candidates_section') }}</span>
                            <button type="button"
                                    @click="addDebugCandidate()"
                                    class="inline-flex items-center gap-1 rounded-xl border border-line bg-surface-muted px-3 py-1.5 text-2xs font-semibold text-ink hover:bg-surface transition cursor-pointer">
                                <svg class="w-3.5 h-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                                <span>{{ __('leave.debug_btn_add_candidate') }}</span>
                            </button>
                        </div>

                        <div class="w-full overflow-x-auto rounded-xl border border-line">
                            <table class="w-full text-left text-xs border-collapse">
                                <thead class="bg-surface-muted/70 text-2xs uppercase text-ink-muted">
                                    <tr>
                                        <th class="px-3 py-2 align-top">{{ __('leave.debug_candidate_name') }}</th>
                                        @foreach ($debugCriteriaMeta as $cCode => $meta)
                                            <th class="px-2 py-2 align-top" title="{{ $meta['desc'] }}">
                                                <div class="flex items-center gap-1 font-mono font-bold text-ink">
                                                    <span>{{ $cCode }}</span>
                                                    <span class="rounded px-1 py-0.5 text-[10px] font-sans font-semibold border {{ $meta['nature'] === 'benefit' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300' }}">
                                                        {{ $meta['nature_label'] }}
                                                    </span>
                                                </div>
                                                <div class="text-[10px] font-normal normal-case text-ink-muted leading-tight mt-0.5">
                                                    {{ $meta['short'] }} ({{ $meta['scale'] }})
                                                </div>
                                            </th>
                                        @endforeach
                                        <th class="px-2 py-2 align-top text-right">{{ __('leave.col_actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    <template x-for="(cand, idx) in debugForm.candidates" :key="idx">
                                        <tr>
                                            <td class="px-2.5 py-2 min-w-[10rem]">
                                                <input type="text"
                                                       x-model="cand.name"
                                                       aria-label="{{ __('leave.debug_candidate_name') }}"
                                                       class="w-full rounded-lg border border-line bg-surface-muted px-2.5 py-1 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                                            </td>
                                            @foreach ($debugCriteriaMeta as $cCode => $meta)
                                                <td class="px-1.5 py-2 w-24">
                                                    <input type="number"
                                                           step="1"
                                                           x-model.number="cand.{{ $cCode }}"
                                                           aria-label="{{ $meta['title'] }}"
                                                           title="{{ $meta['title'] }} ({{ $meta['nature_label'] }} · {{ $meta['scale'] }})"
                                                           class="w-full rounded-lg border border-line bg-surface-muted px-2 py-1 text-xs font-mono text-ink" />
                                                </td>
                                            @endforeach
                                            <td class="px-2.5 py-2 text-right">
                                                <button type="button"
                                                        @click="removeDebugCandidate(idx)"
                                                        class="rounded-lg border border-red-500/30 bg-red-500/10 px-2.5 py-1 text-2xs font-semibold text-red-700 dark:text-red-300 hover:bg-red-500/20 transition cursor-pointer">
                                                    {{ __('leave.debug_btn_remove_candidate') }}
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div x-show="debugError !== ''" x-cloak class="rounded-xl border border-red-500/30 bg-red-500/10 px-3.5 py-2.5 text-xs font-medium text-red-700 dark:text-red-300" x-text="debugError"></div>

                    <div class="flex items-center justify-end gap-3">
                        <button type="button"
                                @click="runSmartDebug()"
                                :disabled="debugLoading || debugWeightSum !== 100"
                                class="inline-flex items-center gap-2 rounded-xl bg-accent px-4 py-2 text-xs font-bold text-accent-fg shadow-sm hover:opacity-95 transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                            <span x-show="!debugLoading">{{ __('leave.debug_btn_run_simulation') }}</span>
                            <span x-show="debugLoading" x-cloak>{{ __('leave.debug_running_simulation') }}</span>
                        </button>
                    </div>

                    <!-- Output Peringkat, Trace Rumus, & Telemetri Mentah -->
                    <template x-if="debugResult">
                        <div class="space-y-4 pt-2 border-t border-line">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-xs font-bold text-ink">{{ __('leave.debug_results_section') }}</span>
                                <div class="flex flex-wrap items-center gap-2 text-2xs font-mono">
                                    <span class="rounded-full border border-accent/30 bg-accent/10 px-2.5 py-0.5 font-bold text-accent"
                                          x-text="debugResult.mode_label"></span>
                                    <span class="rounded-full border border-line bg-surface-muted px-2.5 py-0.5 text-ink-muted"
                                          x-text="`${debugResult.formation?.min_staff_per_shift} / ${debugResult.formation?.total_department_staff}`"></span>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <template x-for="res in (debugResult.results || [])" :key="res.rank">
                                    <div class="rounded-2xl border border-line bg-surface-muted/30 p-3.5 space-y-2.5">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex items-center gap-2">
                                                <span class="inline-flex items-center rounded-lg bg-accent px-2 py-0.5 text-2xs font-mono font-bold text-accent-fg"
                                                      x-text="`#${res.rank}`"></span>
                                                <span class="text-sm font-bold text-ink" x-text="res.name"></span>
                                            </div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="inline-flex items-center rounded-md px-2.5 py-0.5 text-2xs font-mono font-bold border"
                                                      :class="res.can_approve_within_quota ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/30' : 'bg-red-500/10 text-red-700 dark:text-red-300 border-red-500/30'"
                                                      x-text="res.can_approve_within_quota
                                                          ? `OK (${res.remaining_staff_if_approved} >= ${debugResult.formation?.min_staff_per_shift})`
                                                          : `BREACH (${res.remaining_staff_if_approved} < ${debugResult.formation?.min_staff_per_shift})`"></span>
                                                <span class="inline-flex items-center rounded-md border border-line bg-surface px-2.5 py-0.5 text-xs font-mono font-bold text-ink"
                                                      x-text="`${Number(res.smart_score || 0).toFixed(2)} - ${res.recommendation_label}`"></span>
                                            </div>
                                        </div>

                                        <div class="w-full overflow-x-auto rounded-xl border border-line bg-surface">
                                            <table class="w-full text-left text-2xs border-collapse">
                                                <thead class="bg-surface-muted/60 uppercase text-ink-muted">
                                                    <tr>
                                                        <th class="px-2.5 py-1.5 font-mono">Crit</th>
                                                        <th class="px-2.5 py-1.5">Raw / Clamped</th>
                                                        <th class="px-2.5 py-1.5 font-mono">Formula</th>
                                                        <th class="px-2.5 py-1.5 font-mono text-right">u_j</th>
                                                        <th class="px-2.5 py-1.5 font-mono text-right">w'_j</th>
                                                        <th class="px-2.5 py-1.5 font-mono text-right">Score</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-line">
                                                    <template x-for="cCode in ['C1', 'C2', 'C3', 'C4', 'C5']" :key="cCode">
                                                        <tr x-show="res.criteria && res.criteria[cCode]">
                                                            <td class="px-2.5 py-1.5 font-mono font-bold text-ink" :title="res.criteria[cCode]?.name || cCode">
                                                                <div class="flex items-center gap-1.5">
                                                                    <span x-text="cCode"></span>
                                                                    <span class="rounded px-1 py-0.5 text-[10px] font-sans font-semibold border"
                                                                          :class="res.criteria[cCode]?.type === 'cost'
                                                                              ? 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300'
                                                                              : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'"
                                                                          x-text="res.criteria[cCode]?.type === 'cost' ? '{{ __('leave.debug_badge_cost') }}' : '{{ __('leave.debug_badge_benefit') }}'"></span>
                                                                </div>
                                                            </td>
                                                            <td class="px-2.5 py-1.5 font-mono text-ink-muted" x-text="`${res.criteria[cCode]?.raw_value} / ${res.criteria[cCode]?.clamped_value}`"></td>
                                                            <td class="px-2.5 py-1.5 font-mono text-ink" x-text="res.criteria[cCode]?.formula"></td>
                                                            <td class="px-2.5 py-1.5 font-mono text-right text-ink" x-text="Number(res.criteria[cCode]?.utility || 0).toFixed(2)"></td>
                                                            <td class="px-2.5 py-1.5 font-mono text-right text-ink-muted" x-text="Number(res.criteria[cCode]?.normalized_weight || 0).toFixed(4)"></td>
                                                            <td class="px-2.5 py-1.5 font-mono font-bold text-right text-ink" x-text="Number(res.criteria[cCode]?.weighted_score || 0).toFixed(2)"></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <div class="rounded-2xl border border-line bg-surface p-3.5 space-y-2">
                                <div class="text-2xs font-bold uppercase tracking-wider text-ink-muted">
                                    {{ __('leave.debug_raw_telemetry_title') }}
                                </div>
                                <pre class="max-h-56 overflow-auto rounded-xl border border-line bg-surface-muted p-3 text-2xs font-mono text-ink leading-relaxed"
                                     x-text="JSON.stringify(debugResult.telemetry || debugResult, null, 2)"></pre>
                            </div>
                        </div>
                    </template>
                </div>
            </x-ui.modal>
        @endif
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('leaveRequestConsole', (config) => ({
                leaveTypes: config.leaveTypes || [],
                selectedTypeId: String(config.oldLeaveTypeId || config.defaultTypeId || ''),
                calculateDaysUrl: config.calculateDaysUrl,
                startDate: config.oldStartDate || '',
                endDate: config.oldEndDate || '',
                calculating: false,
                calendarDays: 0,
                excludedOffDays: 0,
                effectiveDays: 0,
                cancelActionUrl: '',
                rejectActionUrl: '',
                loadingAnalysis: false,
                smartAnalysisData: null,
                smartAnalysisActions: {
                    canApproveTier1: false,
                    approveTier1Url: '',
                    canApproveTier2: false,
                    approveTier2Url: '',
                    canReject: false,
                    rejectUrl: '',
                },
                smartWeightsByDept: config.smartWeightsByDept || {},
                selectedWeightDeptId: String(config.defaultWeightDeptId || ''),
                weightForm: { C1: 30, C2: 20, C3: 15, C4: 15, C5: 20 },
                weightLocks: { C1: false, C2: false, C3: false, C4: false, C5: false },
                editForm: {
                    actionUrl: '',
                    leaveTypeId: String(config.defaultTypeId || ''),
                    startDate: '',
                    endDate: '',
                    reason: '',
                    status: 'pending_manager',
                    hasAttachment: false,
                    calculating: false,
                    calendarDays: 0,
                    excludedOffDays: 0,
                    effectiveDays: 0,
                },
                ledgerEmployees: config.ledgerEmployees || [],
                selectedLedgerEmployeeId: String(config.defaultLedgerEmployeeId || ''),
                ledgerData: null,
                smartDebugUrl: config.smartDebugUrl || '',
                csrfToken: config.csrfToken || '',
                debugDepartments: config.debugDepartments || [],
                debugSelectedDeptId: '',
                debugRequests: config.debugRequests || [],
                debugWeightLocks: { C1: false, C2: false, C3: false, C4: false, C5: false },
                debugForm: {
                    weights: { C1: 30, C2: 20, C3: 15, C4: 15, C5: 20 },
                    min_staff_per_shift: 2,
                    total_department_staff: 5,
                    leave_request_id: '',
                    candidates: [
                        { name: 'Kandidat A', C1: 85, C2: 30, C3: 98, C4: 1, C5: 0 },
                        { name: 'Kandidat B', C1: 45, C2: 5, C3: 90, C4: 0, C5: 3 },
                        { name: 'Kandidat C', C1: 65, C2: 120, C3: 75, C4: 4, C5: 1 },
                    ],
                },
                debugLoading: false,
                debugError: '',
                debugResult: null,

                get selectedType() {
                    return this.leaveTypes.find(t => String(t.id) === String(this.selectedTypeId)) || null;
                },

                get selectedEditType() {
                    return this.leaveTypes.find(t => String(t.id) === String(this.editForm.leaveTypeId)) || null;
                },

                get totalWeightSum() {
                    return ['C1', 'C2', 'C3', 'C4', 'C5'].reduce(
                        (acc, k) => acc + Math.round(Number(this.weightForm[k] || 0)),
                        0
                    );
                },

                get debugWeightSum() {
                    return ['C1', 'C2', 'C3', 'C4', 'C5'].reduce(
                        (acc, k) => acc + Math.round(Number(this.debugForm.weights[k] || 0)),
                        0
                    );
                },

                init() {
                    if (this.startDate && this.endDate) {
                        this.fetchEffectiveDays();
                    }
                    this.loadDeptWeights();
                    this.debugForm.weights = this.normalizeWeightsTo100(this.debugForm.weights);
                },

                normalizeWeightsTo100(rawSource) {
                    const defaultWeights = { C1: 30, C2: 20, C3: 15, C4: 15, C5: 20 };
                    if (!rawSource || typeof rawSource !== 'object') {
                        return { ...defaultWeights };
                    }

                    const codes = ['C1', 'C2', 'C3', 'C4', 'C5'];
                    const rawVals = {};
                    codes.forEach(k => {
                        const entry = rawSource[k];
                        const val = entry && typeof entry === 'object' ? entry.raw_weight : entry;
                        const num = Number(val);
                        rawVals[k] = Number.isFinite(num) ? Math.max(0, Math.round(num)) : defaultWeights[k];
                    });

                    const sum = codes.reduce((acc, k) => acc + rawVals[k], 0);
                    if (sum === 100) {
                        return rawVals;
                    }
                    if (sum <= 0) {
                        return { ...defaultWeights };
                    }

                    const exacts = codes.map(k => ({
                        code: k,
                        exact: (rawVals[k] / sum) * 100,
                    }));
                    let floorSum = 0;
                    const floored = {};
                    exacts.forEach(item => {
                        floored[item.code] = Math.floor(item.exact);
                        floorSum += floored[item.code];
                        item.rem = item.exact - floored[item.code];
                    });

                    let remainderPoints = 100 - floorSum;
                    exacts.sort((a, b) => b.rem - a.rem);
                    for (let i = 0; i < exacts.length && remainderPoints > 0; i++) {
                        floored[exacts[i].code] += 1;
                        remainderPoints -= 1;
                    }

                    return floored;
                },

                rebalanceCoupledWeights(weightsObj, locksObj, changedCode, rawValue, inputEl = null) {
                    if (locksObj[changedCode]) {
                        if (inputEl) {
                            inputEl.value = String(weightsObj[changedCode]);
                        }
                        return;
                    }

                    const codes = ['C1', 'C2', 'C3', 'C4', 'C5'];
                    const lockedOthers = codes.filter(k => k !== changedCode && locksObj[k]);
                    const unlockedOthers = codes.filter(k => k !== changedCode && !locksObj[k]);

                    const lockedSum = lockedOthers.reduce(
                        (acc, k) => acc + Math.max(0, Math.round(Number(weightsObj[k] || 0))),
                        0
                    );
                    const maxAvailable = Math.max(0, 100 - lockedSum);

                    if (unlockedOthers.length === 0) {
                        weightsObj[changedCode] = maxAvailable;
                        if (inputEl) {
                            inputEl.value = String(maxAvailable);
                        }
                        return;
                    }

                    const clampedNewVal = Math.max(0, Math.min(maxAvailable, Math.round(Number(rawValue) || 0)));
                    weightsObj[changedCode] = clampedNewVal;
                    if (inputEl) {
                        inputEl.value = String(clampedNewVal);
                    }

                    const remainingPool = maxAvailable - clampedNewVal;
                    const currentUnlockedSum = unlockedOthers.reduce(
                        (acc, k) => acc + Math.max(0, Math.round(Number(weightsObj[k] || 0))),
                        0
                    );

                    const allocations = unlockedOthers.map(k => {
                        const currentVal = Math.max(0, Math.round(Number(weightsObj[k] || 0)));
                        const exact = currentUnlockedSum > 0
                            ? (currentVal / currentUnlockedSum) * remainingPool
                            : remainingPool / unlockedOthers.length;
                        const floorVal = Math.floor(exact);
                        return {
                            code: k,
                            floorVal,
                            rem: exact - floorVal,
                        };
                    });

                    const distributed = allocations.reduce((acc, item) => acc + item.floorVal, 0);
                    let leftover = remainingPool - distributed;

                    allocations.sort((a, b) => b.rem - a.rem);
                    for (let i = 0; i < allocations.length && leftover > 0; i++) {
                        allocations[i].floorVal += 1;
                        leftover -= 1;
                    }

                    allocations.forEach(item => {
                        weightsObj[item.code] = item.floorVal;
                    });
                },

                loadDeptWeights() {
                    const deptWeights = this.smartWeightsByDept[this.selectedWeightDeptId];
                    this.weightLocks = { C1: false, C2: false, C3: false, C4: false, C5: false };
                    if (deptWeights) {
                        this.weightForm = this.normalizeWeightsTo100(deptWeights);
                    }
                },

                toggleWeightLock(code) {
                    this.weightLocks[code] = !this.weightLocks[code];
                },

                onSliderChange(changedCode, rawValue, inputEl = null) {
                    this.rebalanceCoupledWeights(this.weightForm, this.weightLocks, changedCode, rawValue, inputEl);
                },

                normalizedPreview(code) {
                    const sum = ['C1', 'C2', 'C3', 'C4', 'C5'].reduce(
                        (acc, k) => acc + Math.max(0, Number(this.weightForm[k] || 0)),
                        0
                    );
                    if (sum <= 0) {
                        return '0.0000';
                    }
                    const val = Math.max(0, Number(this.weightForm[code] || 0));
                    return (val / sum).toFixed(4);
                },

                toggleDebugWeightLock(code) {
                    this.debugWeightLocks[code] = !this.debugWeightLocks[code];
                },

                onDebugSliderChange(changedCode, rawValue, inputEl = null) {
                    this.rebalanceCoupledWeights(this.debugForm.weights, this.debugWeightLocks, changedCode, rawValue, inputEl);
                },

                debugNormalizedPreview(code) {
                    const sum = ['C1', 'C2', 'C3', 'C4', 'C5'].reduce(
                        (acc, k) => acc + Math.max(0, Number(this.debugForm.weights[k] || 0)),
                        0
                    );
                    if (sum <= 0) {
                        return '0.0000';
                    }
                    const val = Math.max(0, Number(this.debugForm.weights[code] || 0));
                    return (val / sum).toFixed(4);
                },

                resetDebugWeights() {
                    this.debugWeightLocks = { C1: false, C2: false, C3: false, C4: false, C5: false };
                    this.debugSelectedDeptId = '';
                    this.debugForm.weights = { C1: 30, C2: 20, C3: 15, C4: 15, C5: 20 };
                },

                loadDebugDeptWeights(deptId = null) {
                    const targetId = String(deptId ?? this.debugSelectedDeptId ?? '');
                    if (!targetId) {
                        return;
                    }
                    this.debugWeightLocks = { C1: false, C2: false, C3: false, C4: false, C5: false };
                    const deptWeights = this.smartWeightsByDept[targetId];
                    this.debugForm.weights = deptWeights
                        ? this.normalizeWeightsTo100(deptWeights)
                        : { C1: 30, C2: 20, C3: 15, C4: 15, C5: 20 };

                    const dept = this.debugDepartments.find(d => String(d.id) === targetId);
                    if (dept) {
                        this.debugForm.min_staff_per_shift = Number(dept.min_staff_per_shift || 1);
                        this.debugForm.total_department_staff = Number(dept.total_department_staff || 1);
                    }
                },

                onDebugRequestChange() {
                    const req = this.debugRequests.find(r => String(r.id) === String(this.debugForm.leave_request_id));
                    if (!req) {
                        return;
                    }
                    if (req.department_id) {
                        this.debugSelectedDeptId = String(req.department_id);
                        this.debugWeightLocks = { C1: false, C2: false, C3: false, C4: false, C5: false };
                        const deptWeights = this.smartWeightsByDept[req.department_id];
                        this.debugForm.weights = deptWeights
                            ? this.normalizeWeightsTo100(deptWeights)
                            : { C1: 30, C2: 20, C3: 15, C4: 15, C5: 20 };
                    }
                    this.debugForm.min_staff_per_shift = Number(req.min_staff_per_shift || 2);
                    this.debugForm.total_department_staff = Number(req.total_department_staff || 5);
                },

                async fetchEffectiveDays() {
                    if (!this.startDate || !this.endDate || this.startDate > this.endDate) {
                        this.calendarDays = 0;
                        this.excludedOffDays = 0;
                        this.effectiveDays = 0.0;
                        return;
                    }

                    this.calculating = true;
                    try {
                        const url = new URL(this.calculateDaysUrl, window.location.origin);
                        url.searchParams.set('start_date', this.startDate);
                        url.searchParams.set('end_date', this.endDate);

                        const response = await fetch(url.toString(), {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });

                        if (response.ok) {
                            const json = await response.json();
                            const payload = json.data || json;
                            this.calendarDays = Number(payload.calendar_days || 0);
                            this.excludedOffDays = Number(payload.excluded_off_days || 0);
                            this.effectiveDays = Number(payload.effective_days || 0);
                        }
                    } catch (e) {
                        console.error('Failed to calculate effective leave days:', e);
                    } finally {
                        this.calculating = false;
                    }
                },

                openCancelModal(actionUrl) {
                    this.cancelActionUrl = actionUrl;
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'cancel-leave-request-modal' }));
                },

                openEditModal(payload) {
                    this.editForm.actionUrl = payload.actionUrl || '';
                    this.editForm.leaveTypeId = String(payload.leaveTypeId || this.defaultTypeId || '');
                    this.editForm.startDate = payload.startDate || '';
                    this.editForm.endDate = payload.endDate || '';
                    this.editForm.reason = payload.reason || '';
                    this.editForm.status = payload.status || 'pending_manager';
                    this.editForm.hasAttachment = Boolean(payload.hasAttachment);
                    this.fetchEditEffectiveDays();
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'edit-leave-request-modal' }));
                },

                async fetchEditEffectiveDays() {
                    if (!this.editForm.startDate || !this.editForm.endDate || this.editForm.startDate > this.editForm.endDate) {
                        this.editForm.calendarDays = 0;
                        this.editForm.excludedOffDays = 0;
                        this.editForm.effectiveDays = 0.0;
                        return;
                    }

                    this.editForm.calculating = true;
                    try {
                        const url = new URL(this.calculateDaysUrl, window.location.origin);
                        url.searchParams.set('start_date', this.editForm.startDate);
                        url.searchParams.set('end_date', this.editForm.endDate);

                        const response = await fetch(url.toString(), {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });

                        if (response.ok) {
                            const json = await response.json();
                            const payload = json.data || json;
                            this.editForm.calendarDays = Number(payload.calendar_days || 0);
                            this.editForm.excludedOffDays = Number(payload.excluded_off_days || 0);
                            this.editForm.effectiveDays = Number(payload.effective_days || 0);
                        }
                    } catch (e) {
                        console.error('Failed to calculate effective leave days for edit:', e);
                    } finally {
                        this.editForm.calculating = false;
                    }
                },

                openRejectModal(actionUrl) {
                    this.rejectActionUrl = actionUrl;
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'reject-leave-request-modal' }));
                },

                async openSmartAnalysisModal(options) {
                    const analysisUrl = typeof options === 'string' ? options : options.analysisUrl;
                    this.smartAnalysisActions = typeof options === 'object' && options !== null
                        ? {
                            canApproveTier1: Boolean(options.canApproveTier1),
                            approveTier1Url: options.approveTier1Url || '',
                            canApproveTier2: Boolean(options.canApproveTier2),
                            approveTier2Url: options.approveTier2Url || '',
                            canReject: Boolean(options.canReject),
                            rejectUrl: options.rejectUrl || '',
                        }
                        : {
                            canApproveTier1: false,
                            approveTier1Url: '',
                            canApproveTier2: false,
                            approveTier2Url: '',
                            canReject: false,
                            rejectUrl: '',
                        };

                    this.loadingAnalysis = true;
                    this.smartAnalysisData = null;
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'smart-analysis-modal' }));

                    try {
                        const response = await fetch(analysisUrl, {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        if (response.ok) {
                            const json = await response.json();
                            this.smartAnalysisData = json.data || json;
                        }
                    } catch (e) {
                        console.error('Failed to load SPK SMART analysis:', e);
                    } finally {
                        this.loadingAnalysis = false;
                    }
                },

                async fetchLedgerHistory() {
                    const emp = this.ledgerEmployees.find(e => String(e.id) === String(this.selectedLedgerEmployeeId));
                    if (!emp || !emp.history_url) {
                        return;
                    }

                    try {
                        const response = await fetch(emp.history_url, {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        if (response.ok) {
                            const json = await response.json();
                            this.ledgerData = json.data || json;
                        }
                    } catch (e) {
                        console.error('Failed to load leave ledger history:', e);
                    }
                },

                applyDebugPreset(preset) {
                    this.debugWeightLocks = { C1: false, C2: false, C3: false, C4: false, C5: false };
                    this.debugForm.leave_request_id = '';
                    this.debugSelectedDeptId = '';

                    if (preset === 'mode_b') {
                        this.debugForm.weights = this.normalizeWeightsTo100({ C1: 30, C2: 20, C3: 15, C4: 15, C5: 20 });
                        this.debugForm.min_staff_per_shift = 2;
                        this.debugForm.total_department_staff = 6;
                        this.debugForm.candidates = [
                            { name: 'Staf Mandiri', C1: 80, C2: 45, C3: 95, C4: 1, C5: 0 },
                        ];
                        return;
                    }

                    if (preset === 'critical_staff') {
                        this.debugForm.weights = this.normalizeWeightsTo100({ C1: 50, C2: 15, C3: 15, C4: 10, C5: 10 });
                        this.debugForm.min_staff_per_shift = 3;
                        this.debugForm.total_department_staff = 4;
                        this.debugForm.candidates = [
                            { name: 'Kandidat #1 (Disiplin)', C1: 80, C2: 20, C3: 98, C4: 0, C5: 0 },
                            { name: 'Kandidat #2 (Cadangan)', C1: 70, C2: 35, C3: 88, C4: 2, C5: 1 },
                            { name: 'Kandidat #3 (Risiko Formasi)', C1: 50, C2: 90, C3: 75, C4: 5, C5: 3 },
                        ];
                        return;
                    }

                    this.debugForm.weights = this.normalizeWeightsTo100({ C1: 30, C2: 20, C3: 15, C4: 15, C5: 20 });
                    this.debugForm.min_staff_per_shift = 2;
                    this.debugForm.total_department_staff = 5;
                    this.debugForm.candidates = [
                        { name: 'Kandidat A', C1: 85, C2: 30, C3: 98, C4: 1, C5: 0 },
                        { name: 'Kandidat B', C1: 45, C2: 5, C3: 90, C4: 0, C5: 3 },
                        { name: 'Kandidat C', C1: 65, C2: 120, C3: 75, C4: 4, C5: 1 },
                    ];
                },

                addDebugCandidate() {
                    const num = (this.debugForm.candidates?.length || 0) + 1;
                    this.debugForm.candidates.push({
                        name: `Kandidat #${num}`,
                        C1: 75,
                        C2: 30,
                        C3: 90,
                        C4: 1,
                        C5: 0,
                    });
                },

                removeDebugCandidate(idx) {
                    this.debugForm.candidates.splice(idx, 1);
                },

                async runSmartDebug() {
                    this.debugError = '';
                    this.debugLoading = true;
                    try {
                        const normalizedPayloadWeights = {
                            C1: Math.round(Number(this.debugForm.weights.C1 || 0)),
                            C2: Math.round(Number(this.debugForm.weights.C2 || 0)),
                            C3: Math.round(Number(this.debugForm.weights.C3 || 0)),
                            C4: Math.round(Number(this.debugForm.weights.C4 || 0)),
                            C5: Math.round(Number(this.debugForm.weights.C5 || 0)),
                        };

                        const response = await fetch(this.smartDebugUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': this.csrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({
                                weights: normalizedPayloadWeights,
                                min_staff_per_shift: Number(this.debugForm.min_staff_per_shift || 1),
                                total_department_staff: Number(this.debugForm.total_department_staff || 1),
                                leave_request_id: this.debugForm.leave_request_id || null,
                                candidates: this.debugForm.candidates,
                            }),
                        });

                        const json = await response.json();
                        if (!response.ok) {
                            const firstErr = json.errors ? Object.values(json.errors).flat()[0] : null;
                            this.debugError = firstErr || json.message || 'Simulation error';
                            return;
                        }

                        this.debugResult = json.data || json;
                    } catch (e) {
                        this.debugError = e?.message || 'Network error';
                    } finally {
                        this.debugLoading = false;
                    }
                },
            }));
        });
    </script>
</x-app-layout>
