<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h2 class="font-bold text-xl text-ink leading-tight">
                    {{ __('nav.dashboard') }}
                </h2>
                <p class="text-xs text-ink-muted mt-0.5">
                    @if ($user->isSuperAdmin() && ! $activeCompany)
                        {{ __('common.platform_admin_subtitle') }}
                    @else
                        {{ $activeCompany?->name ?? __('common.tenant_workspace') }} &mdash; {{ __('common.active_role') }}: <span class="font-semibold uppercase">{{ str_replace('_', ' ', $user->role) }}</span>
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button"
                        @click="$dispatch('open-modal', 'today-schedules-modal')"
                        class="rounded-xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-semibold px-3.5 py-2 shadow-sm flex items-center gap-2 cursor-pointer transition-colors">
                    <svg class="h-4 w-4 text-accent shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                    </svg>
                    <span>{{ __('dashboard.btn_view_today_schedule') }}</span>
                </button>

                @if ($user->isManager())
                    <a href="{{ route('staff.create') }}"
                       class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold text-accent-fg shadow-xs transition-opacity hover:opacity-90"
                       style="background-color: var(--primary-accent);">
                        {{ __('common.add_team_member') }}
                    </a>
                @elseif ($user->isAdmin() || $user->isSuperUser())
                    <a href="{{ route('staff.create') }}"
                       class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold text-accent-fg shadow-xs transition-opacity hover:opacity-90"
                       style="background-color: var(--primary-accent);">
                        {{ __('common.onboard_new_staff') }}
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="space-y-8">
        @if (session('status'))
            <div class="bg-emerald-500/10 border border-emerald-500/20 text-emerald-700 dark:text-emerald-300 rounded-xl p-3.5 text-sm">
                <span>{{ session('status') }}</span>
            </div>
        @endif

        {{-- =====================================================================
             WIDGET 4: SUPER ADMIN (PLATFORM OWNER)
             ===================================================================== --}}
        @if ($user->isSuperAdmin() && $platformStats)
            <section aria-label="Super Admin Platform Overview" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-ink-muted flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" style="background-color: var(--primary-accent);"></span>
                        {{ __('common.platform_global_overview') }}
                    </h3>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.total_tenant_companies') }}</div>
                        <div class="mt-2 text-3xl font-bold text-ink">
                            {{ $platformStats['total_companies'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-faint">{{ __('common.registered_active_tenants') }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.total_registered_accounts') }}</div>
                        <div class="mt-2 text-3xl font-bold text-ink">
                            {{ $platformStats['total_users_global'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-faint">{{ __('common.all_platform_users') }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.current_tenant_context') }}</div>
                        <div class="mt-2 text-lg font-bold text-ink truncate">
                            {{ $activeCompany?->name ?? __('common.global_root_all_tenants') }}
                        </div>
                        <div class="mt-1 text-xs font-mono text-ink-faint">
                            {{ $activeCompany?->code ?? 'ROOT-GLOBAL' }}
                        </div>
                    </div>
                </div>

                <!-- Quick-Switch Context Panel -->
                <div class="rounded-xl border border-line bg-surface p-6 shadow-sm">
                    <h4 class="text-sm font-semibold text-ink mb-3">
                        {{ __('common.quick_switch_tenant_panel') }}
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach ($tenants as $tenant)
                            <form method="POST" action="{{ route('company.switch') }}">
                                @csrf
                                <input type="hidden" name="company_id" value="{{ $tenant->id }}" />
                                <button type="submit"
                                        class="w-full text-left p-3.5 rounded-xl border transition flex items-center justify-between {{ (int) session('active_company_id') === (int) $tenant->id ? 'border-[var(--primary-accent)] bg-surface-muted font-semibold' : 'border-line hover:bg-surface-muted/60' }}">
                                    <div>
                                        <div class="text-sm font-bold text-ink">{{ $tenant->code }}</div>
                                        <div class="text-xs text-ink-muted truncate max-w-[200px]">{{ $tenant->name }}</div>
                                    </div>
                                    <span class="w-3 h-3 rounded-full shrink-0" style="background-color: {{ $tenant->accent_color }};"></span>
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        {{-- =====================================================================
             WIDGET 3: ADMIN (HRD) & SUPER USER (OWNER / GM)
             ===================================================================== --}}
        @if (($user->isAdmin() || $user->isSuperUser() || ($user->isSuperAdmin() && $activeCompany)) && $corporateStats)
            <section aria-label="Corporate Metrics and Department Breakdown" class="space-y-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-ink-muted flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" style="background-color: var(--primary-accent);"></span>
                        {{ __('common.corporate_stats_heading', ['code' => $activeCompany?->code]) }}
                    </h3>
                </div>

                <!-- Top Corporate Metrics -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.total_active_employees') }}</div>
                        <div class="mt-2 text-3xl font-bold text-ink">
                            {{ $corporateStats['total_active_employees'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-faint">{{ __('common.active_status_staff') }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.total_departments') }}</div>
                        <div class="mt-2 text-3xl font-bold text-ink">
                            {{ $corporateStats['total_departments'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-faint">{{ __('common.registered_org_units') }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.ratio_24h_vs_backoffice') }}</div>
                        <div class="mt-2 text-lg font-bold text-ink">
                            {{ $corporateStats['operational_departments'] }} : {{ $corporateStats['back_office_departments'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-muted">{{ $corporateStats['ratio_label'] }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.active_geofence_params') }}</div>
                        <div class="mt-2 text-sm font-mono font-semibold text-ink">
                            {{ $corporateStats['geofence_lat'] }}, {{ $corporateStats['geofence_lng'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-muted">
                            {{ __('common.attendance_radius') }}: <span class="font-bold">{{ $corporateStats['geofence_radius'] }} {{ __('common.meters') }}</span>
                        </div>
                    </div>
                </div>

                <!-- Breakdown Staf per Departemen -->
                <div class="rounded-xl border border-line bg-surface p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-sm font-semibold text-ink">
                            {{ __('common.dept_staff_breakdown') }}
                        </h4>
                        <span class="text-xs text-ink-muted">{{ __('common.total_dept_count', ['count' => $departmentBreakdown->count()]) }}</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                        @foreach ($departmentBreakdown as $dept)
                            <div class="p-4 rounded-xl border border-line bg-surface-muted/40 flex flex-col justify-between gap-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <span class="inline-flex items-center text-xs font-mono font-bold px-2.5 py-0.5 rounded-md bg-ink text-canvas">
                                            {{ $dept->code }}
                                        </span>
                                        <h5 class="text-sm font-semibold text-ink mt-1.5">
                                            {{ $dept->name }}
                                        </h5>
                                    </div>
                                    @if ($dept->is_operational)
                                        <span class="o-badge o-badge-warning">{{ __('common.hours_24') }}</span>
                                    @else
                                        <span class="o-badge o-badge-neutral">{{ __('common.back_office') }}</span>
                                    @endif
                                </div>

                                <div class="pt-2 border-t border-line flex items-center justify-between text-xs">
                                    <span class="text-ink-muted">{{ __('common.active_staff_label') }}</span>
                                    <span class="font-bold text-sm text-ink">{{ __('common.people_count', ['count' => $dept->active_employees_count]) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        {{-- =====================================================================
             WIDGET 2: MANAGER (KEPALA DEPARTEMEN / SUPERVISOR)
             ===================================================================== --}}
        @if ($user->isManager() && $managerStats)
            <section aria-label="Manager Department Overview" class="space-y-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-ink-muted flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" style="background-color: var(--primary-accent);"></span>
                        {{ __('common.dept_stats_heading', ['name' => $managerStats['department_name'], 'code' => $managerStats['department_code']]) }}
                    </h3>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.total_team_members') }}</div>
                        <div class="mt-2 text-3xl font-bold text-ink">
                            {{ $managerStats['total_team_members'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-faint">{{ __('common.department_prefix', ['name' => $managerStats['department_name']]) }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.dept_operational_status') }}</div>
                        <div class="mt-2 text-lg font-bold text-ink">
                            {{ $managerStats['operational_label'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-faint">{{ __('common.unit_service_type') }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.min_staff_per_shift_quota') }}</div>
                        <div class="mt-2 text-3xl font-bold text-ink">
                            {{ $managerStats['min_staff_per_shift'] }} <span class="text-sm font-normal text-ink-muted">{{ __('common.staff_per_shift_unit') }}</span>
                        </div>
                        <div class="mt-1 text-xs text-ink-faint">{{ __('common.roster_threshold') }}</div>
                    </div>
                </div>

                <!-- Tabel Ringkas Bawahan Langsung -->
                <div class="rounded-xl border border-line bg-surface overflow-hidden shadow-sm">
                    <div class="px-6 py-4 border-b border-line flex items-center justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-ink">
                                {{ __('common.direct_subordinates_heading', ['code' => $managerStats['department_code']]) }}
                            </h4>
                            <p class="text-xs text-ink-muted">{{ __('common.showing_only_dept_staff', ['name' => $managerStats['department_name']]) }}</p>
                        </div>
                        <a href="{{ route('staff.create') }}"
                           class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold text-accent-fg shadow-xs"
                           style="background-color: var(--primary-accent);">
                            {{ __('common.add_team_member') }}
                        </a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="o-table">
                            <thead>
                                <tr>
                                    <th>{{ __('common.staff_name') }}</th>
                                    <th>{{ __('common.position') }}</th>
                                    <th>{{ __('common.join_date') }}</th>
                                    <th>{{ __('common.tags') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($subordinates as $sub)
                                    <tr>
                                        <td>
                                            <div class="font-semibold text-ink">{{ $sub->user?->name }}</div>
                                            <div class="text-xs text-ink-muted">{{ $sub->user?->email }}</div>
                                        </td>
                                        <td>{{ $sub->position }}</td>
                                        <td class="text-xs text-ink-muted">{{ $sub->join_date?->format('Y-m-d') }}</td>
                                        <td>
                                            <div class="flex flex-wrap gap-1">
                                                @forelse ($sub->tags as $tag)
                                                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-2xs font-semibold text-white"
                                                          style="background-color: {{ $tag->badge_color }};">
                                                        {{ $tag->code }}
                                                    </span>
                                                @empty
                                                    <span class="text-xs text-ink-faint">&mdash;</span>
                                                @endforelse
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="py-6 text-center text-ink-muted text-xs">
                                            {{ __('common.no_direct_subordinates') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        @endif

        {{-- =====================================================================
             WIDGET 1: USER (KARYAWAN BIASA) / PROFIL MANDIRI KEPEGAWAIAN
             ===================================================================== --}}
        @if ($user->isUser() || $user->employee)
            <section aria-label="Employee Self-Service Profile" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-ink-muted flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" style="background-color: var(--primary-accent);"></span>
                        {{ __('common.self_service_profile_heading') }}
                    </h3>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Kartu Profil Kepegawaian Mandiri -->
                    <div class="lg:col-span-2 rounded-xl border border-line bg-surface p-6 shadow-sm">
                        <div class="border-b border-line pb-4 mb-4">
                            <h4 class="text-lg font-bold text-ink">{{ $user->name }}</h4>
                            <p class="text-xs text-ink-muted">{{ $user->email }} &bull; @<span>{{ $user->username }}</span></p>
                        </div>

                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt class="text-xs text-ink-muted uppercase">{{ __('nav.departments') }}</dt>
                                <dd class="font-semibold text-ink mt-0.5">
                                    {{ $user->employee?->department?->name ?? '-' }}
                                    @if ($user->employee?->department?->code)
                                        ({{ $user->employee->department->code }})
                                    @endif
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs text-ink-muted uppercase">{{ __('common.position_title') }}</dt>
                                <dd class="font-semibold text-ink mt-0.5">
                                    {{ $user->employee?->position ?? '-' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs text-ink-muted uppercase">{{ __('common.join_date') }}</dt>
                                <dd class="font-semibold text-ink mt-0.5">
                                    {{ $user->employee?->join_date?->format('Y-m-d') ?? '-' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs text-ink-muted uppercase">{{ __('common.direct_manager') }}</dt>
                                <dd class="font-semibold text-ink mt-0.5">
                                    {{ $user->employee?->manager?->user?->name ?? __('common.no_direct_manager') }}
                                </dd>
                            </div>
                        </dl>

                        @if ($user->employee && $user->employee->tags->isNotEmpty())
                            <div class="mt-4 pt-4 border-t border-line flex items-center gap-2 flex-wrap">
                                <span class="text-xs text-ink-muted">{{ __('common.functional_attributes') }}</span>
                                @foreach ($user->employee->tags as $tag)
                                    <span class="inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-semibold text-white"
                                          style="background-color: {{ $tag->badge_color }};">
                                        {{ $tag->code }} — {{ $tag->name }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <!-- Placeholder Sprint 2 (Shift Hari Ini) & Sprint 4 (Saldo Cuti & DP) -->
                    <div class="space-y-4">
                        <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.today_shift_status') }}</div>
                                <button type="button"
                                        @click="$dispatch('open-modal', 'today-schedules-modal')"
                                        class="rounded-xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-semibold px-3.5 py-2 shadow-sm flex items-center gap-2 cursor-pointer transition-colors">
                                    <span>{{ __('dashboard.btn_view_today_schedule') }}</span>
                                </button>
                            </div>
                            <div class="mt-2 text-base font-bold text-ink">
                                {{ __('common.not_scheduled_sprint2') }}
                            </div>
                            <p class="mt-1 text-xs text-ink-faint">
                                {{ __('common.sprint2_roster_desc') }}
                            </p>
                        </div>

                        <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                            <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.annual_leave_balance_est') }}</div>
                            <div class="mt-2 text-2xl font-bold text-ink">
                                12 <span class="text-xs font-normal text-ink-muted">{{ __('common.working_days_sprint4') }}</span>
                            </div>
                            <p class="mt-1 text-xs text-ink-faint">
                                {{ __('common.sprint4_leave_desc') }}
                            </p>
                        </div>

                        <!-- Placeholder DP (Day of Payment / Hak Libur Pengganti) -->
                        <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.dp_balance_est') }}</div>
                                <span class="o-badge o-badge-accent cursor-help" title="{{ __('common.dp_tooltip') }}">
                                    {{ __('common.dp_badge') }}
                                </span>
                            </div>
                            <div class="mt-2 text-2xl font-bold text-ink">
                                0 <span class="text-xs font-normal text-ink-muted">{{ __('common.days_unit') }}</span>
                            </div>
                            <p class="mt-1 text-xs text-ink-faint">
                                {{ __('common.dp_desc') }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        {{-- =====================================================================
             MODAL NATIVE: JADWAL HARI INI LINTAS DEPARTEMEN (PURE 24-HOUR GANTT)
             ===================================================================== --}}
        <x-ui.modal name="today-schedules-modal" :title="__('dashboard.modal_today_title', ['date' => $todayFormatted ?? ''])" maxWidth="4xl">
            <div class="overflow-x-auto rounded-xl border border-line bg-surface">
                <div class="min-w-max w-full flex flex-col">
                    <!-- Header Sumbu Waktu 24 Jam (Sticky Top) -->
                    <div class="sticky top-0 z-20 flex items-center bg-surface-muted border-b border-line">
                        <div class="w-44 sm:w-52 shrink-0 border-r border-line px-3 py-2 text-2xs font-bold uppercase tracking-wider text-ink-muted sticky left-0 z-30 bg-surface-muted">
                            {{ __('dashboard.today_col_staff') }}
                        </div>
                        <div class="w-full min-w-[650px] relative h-8 flex items-center justify-between px-2 text-[10px] font-mono font-semibold text-ink-muted select-none">
                            <span>00:00</span>
                            <span>02:00</span>
                            <span>04:00</span>
                            <span>06:00</span>
                            <span>08:00</span>
                            <span>10:00</span>
                            <span>12:00</span>
                            <span>14:00</span>
                            <span>16:00</span>
                            <span>18:00</span>
                            <span>20:00</span>
                            <span>22:00</span>
                            <span>24:00</span>
                        </div>
                    </div>

                    @if (($todaySchedulesByDepartment ?? collect())->isNotEmpty())
                        @foreach (($todaySchedulesByDepartment ?? collect()) as $deptName => $deptSchedules)
                            <!-- Baris Header Pemisah Departemen -->
                            <div class="bg-surface-muted px-3 py-1.5 text-2xs font-bold text-ink-muted uppercase tracking-wider border-b border-line">
                                {{ $deptName }} ({{ $deptSchedules->first()?->employee?->department?->code ?? '-' }})
                            </div>

                            <!-- Baris Staf & Balok Shift 24 Jam -->
                            @foreach ($deptSchedules->groupBy('employee_id') as $empId => $empSchedules)
                                @php
                                    $emp = $empSchedules->first()?->employee;
                                    $staffName = $emp?->user?->name ?? '-';
                                    $staffPos = $emp?->position ?? '-';
                                    $hasMod = $empSchedules->contains(fn ($s) => ! $s->isOff() && (
                                        $s->shift?->code === 'MOD'
                                        || $s->dutyTag?->code === 'MOD_ELIGIBLE'
                                        || ($s->employee?->tags?->contains('code', 'MOD_ELIGIBLE') ?? false)
                                    ));
                                @endphp
                                <div class="flex items-center hover:bg-surface-muted/20 transition-colors">
                                    <!-- Kolom Kiri Staf (Sticky Left) -->
                                    <div class="w-44 sm:w-52 shrink-0 border-r border-line sticky left-0 z-10 bg-surface px-3 h-10 flex items-center gap-2 border-b border-line/40">
                                        <div class="h-6 w-6 rounded-full flex items-center justify-center text-[10px] font-bold text-accent-fg shrink-0 shadow-2xs"
                                             style="background-color: var(--primary-accent);">
                                            {{ strtoupper(substr($staffName, 0, 1)) }}
                                        </div>
                                        <div class="min-w-0 flex-1 truncate">
                                            <div class="flex items-center gap-1">
                                                <span class="text-xs font-semibold text-ink truncate" title="{{ $staffName }}">{{ $staffName }}</span>
                                                @if ($hasMod)
                                                    <span class="px-1 rounded bg-amber-500/15 text-amber-700 dark:text-amber-300 text-[9px] font-bold shrink-0 ring-1 ring-inset ring-amber-500/30">
                                                        {{ __('roster.duty_mod_badge') }}
                                                    </span>
                                                @endif
                                            </div>
                                            <p class="text-[10px] text-ink-muted truncate">{{ $staffPos }}</p>
                                        </div>
                                    </div>

                                    <!-- Kolom Kanan Linimasa 24 Jam -->
                                    <div class="w-full min-w-[650px] relative h-10 border-b border-line/40 overflow-hidden">
                                        <!-- Garis Grid Vertikal Penanda Waktu per 2 Jam (12 Segmen) -->
                                        <div class="pointer-events-none absolute inset-0 grid grid-cols-12 divide-x divide-line/40 z-0">
                                            <div></div><div></div><div></div><div></div><div></div><div></div>
                                            <div></div><div></div><div></div><div></div><div></div><div></div>
                                        </div>

                                        @foreach ($empSchedules as $sched)
                                            @php
                                                $startStr = $sched->getEffectiveStartTime();
                                                $endStr = $sched->getEffectiveEndTime();
                                                $startShort = $startStr !== '' ? substr($startStr, 0, 5) : '00:00';
                                                $endShort = $endStr !== '' ? substr($endStr, 0, 5) : '24:00';
                                                $startParts = $startStr !== '' ? explode(':', $startStr) : [0, 0];
                                                $effectiveStartHour = ((int) ($startParts[0] ?? 0)) + (((int) ($startParts[1] ?? 0)) / 60.0);
                                                $durationHours = $sched->isOff() ? 24.0 : $sched->getDurationInHours();
                                                $leftPercent = $sched->isOff() ? 0 : round(($effectiveStartHour / 24.0) * 100, 2);
                                                $widthPercent = $sched->isOff() ? 100 : round(($durationHours / 24.0) * 100, 2);
                                                $isCrossDay = ! $sched->isOff() && $startStr !== '' && $endStr !== '' && $endStr <= $startStr;
                                                $shiftCode = $sched->shift ? $sched->shift->code : 'OFF';
                                                $shiftColor = $sched->shift ? $sched->shift->color_badge : '#71717A';
                                            @endphp
                                            <div class="absolute top-1 bottom-1 rounded-md px-2 py-0.5 text-white flex items-center justify-between overflow-hidden shadow-2xs z-10 {{ $sched->status === 'draft' ? 'opacity-75 border border-dashed border-amber-400' : 'border border-line-strong/40' }} {{ $isCrossDay ? 'border-r-4 border-r-amber-300 bg-[repeating-linear-gradient(135deg,transparent,transparent_6px,rgba(255,255,255,0.16)_6px,rgba(255,255,255,0.16)_12px)]' : '' }}"
                                                 style="background-color: {{ $shiftColor }}; left: {{ $leftPercent }}%; width: {{ $widthPercent }}%;"
                                                 title="{{ $staffName }} — {{ $shiftCode }} ({{ $startShort }} - {{ $endShort }})">
                                                <div class="flex items-center gap-1.5 min-w-0 pr-5">
                                                    @if ($sched->status === 'draft')
                                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-300 shrink-0" title="{{ __('roster.status_draft') }}"></span>
                                                    @endif
                                                    <span class="text-[11px] font-bold leading-none shrink-0">{{ $shiftCode }}</span>
                                                    @if (! $sched->isOff())
                                                        <span class="text-[10px] font-mono opacity-90 truncate">{{ $startShort }} - {{ $endShort }}</span>
                                                    @endif
                                                    @if ($isCrossDay)
                                                        <span class="text-[8px] font-mono px-1 rounded bg-black/30 text-amber-200 shrink-0">{{ __('roster.cross_day_badge') }}</span>
                                                    @endif
                                                </div>
                                                @if (! $sched->isOff())
                                                    <span class="absolute top-0.5 right-1 text-3xs font-bold opacity-75 dark:opacity-90 leading-none pointer-events-none select-none">
                                                        {{ $sched->getDurationInHours() }}h
                                                    </span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        @endforeach
                    @else
                        <div class="py-10 text-center text-xs text-ink-muted font-medium">
                            {{ __('dashboard.today_empty_schedules') }}
                        </div>
                    @endif
                </div>
            </div>
        </x-ui.modal>
    </div>
</x-app-layout>
