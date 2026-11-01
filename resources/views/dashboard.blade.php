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
                            <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.today_shift_status') }}</div>
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
    </div>
</x-app-layout>
