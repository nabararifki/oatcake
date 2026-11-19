@php
    $user = Auth::user();
    $currentCompany = $currentCompany ?? ($user?->isSuperAdmin() && session('active_company_id')
        ? \App\Models\Company::find(session('active_company_id'))
        : $user?->company);
    $allCompanies = $allCompanies ?? ($user?->isSuperAdmin()
        ? \App\Models\Company::orderBy('name')->get()
        : collect());
@endphp

@include('layouts.partials.mobile-drawer', [
    'currentCompany' => $currentCompany,
    'allCompanies' => $allCompanies,
])

<!-- Sisi Kiri Desktop: Sidebar Terintegrasi Tanpa Garis Pemisah (Monolithic Seamless Frame) -->
{{-- Lebar & label dirender via atribut html[data-sidebar] (server-side + inline script), bukan Alpine, agar tidak ada layout shift. --}}
<aside
    x-data
    style="view-transition-name: app-sidebar;"
    class="o-sidebar hidden lg:flex flex-col shrink-0 h-full bg-surface select-none relative"
    aria-label="Sidebar Navigation"
>
    <!-- Menu Navigasi Vertikal -->
    <nav class="flex-1 overflow-y-auto px-3 py-3 space-y-1.5">
        <!-- Dashboard -->
        <a href="{{ route('dashboard') }}"
           :title="$store.sidebar.collapsed ? '{{ __('nav.dashboard') }}' : ''"
           class="o-sidebar-item flex items-center gap-3 py-2.5 text-xs transition-colors duration-150 {{ request()->routeIs('dashboard') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
            <svg class="w-5 h-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
            </svg>
            <span class="o-sidebar-label truncate">{{ __('nav.dashboard') }}</span>
        </a>

        <!-- Schedule Roster (Gantt Chart 24h) -->
        <a href="{{ route('roster.index') }}"
           :title="$store.sidebar.collapsed ? '{{ __('nav.schedule_roster') }}' : ''"
           class="o-sidebar-item flex items-center gap-3 py-2.5 text-xs transition-colors duration-150 {{ request()->routeIs('roster.*') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
            <svg class="w-5 h-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H16.5v-.008Zm0 2.25h.008v.008H16.5V15Z" />
            </svg>
            <span class="o-sidebar-label truncate">{{ __('nav.schedule_roster') }}</span>
        </a>

        <!-- GPS Geofencing Attendance (Presensi) -->
        <a href="{{ route('attendance.index') }}"
           :title="$store.sidebar.collapsed ? '{{ __('nav.attendance') }}' : ''"
           class="o-sidebar-item flex items-center gap-3 py-2.5 text-xs transition-colors duration-150 {{ request()->routeIs('attendance.index') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
            <svg class="w-5 h-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <span class="o-sidebar-label truncate">{{ __('nav.attendance') }}</span>
        </a>

        <!-- Outside Duty Attendance Verification Console (Manager, Admin, Super User, Super Admin) -->
        @if ($user && $user->isManagerOrAbove())
            <a href="{{ route('attendance.verifications.index') }}"
               :title="$store.sidebar.collapsed ? '{{ __('nav.attendance_verifications') }}' : ''"
               class="o-sidebar-item flex items-center gap-3 py-2.5 text-xs transition-colors duration-150 {{ request()->routeIs('attendance.verifications.*') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
                <svg class="w-5 h-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                </svg>
                <span class="o-sidebar-label truncate">{{ __('nav.attendance_verifications') }}</span>
            </a>
        @endif

        <!-- Leave & Permit Management (Cuti & Izin) -->
        <a href="{{ route('leave.index') }}"
           :title="$store.sidebar.collapsed ? '{{ __('nav.leave_requests') }}' : ''"
           class="o-sidebar-item flex items-center gap-3 py-2.5 text-xs transition-colors duration-150 {{ request()->routeIs('leave.*') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
            <svg class="w-5 h-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
            </svg>
            <span class="o-sidebar-label truncate">{{ __('nav.leave_requests') }}</span>
        </a>

        <!-- Staff Directory (Manager, Admin, Super User, Super Admin) -->
        @if ($user && ($user->isSuperAdmin() || $user->can('viewAny', \App\Models\Employee::class)))
            <a href="{{ route('staff.index') }}"
               :title="$store.sidebar.collapsed ? '{{ __('nav.staff_directory') }}' : ''"
               class="o-sidebar-item flex items-center gap-3 py-2.5 text-xs transition-colors duration-150 {{ request()->routeIs('staff.*') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
                <svg class="w-5 h-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                </svg>
                <span class="o-sidebar-label truncate">{{ __('nav.staff_directory') }}</span>
            </a>
        @endif

        <!-- Departments (Manager, Admin, Super User, Super Admin) -->
        @if ($user && ($user->isSuperAdmin() || $user->can('viewAny', \App\Models\Department::class)))
            <a href="{{ route('departments.index') }}"
               :title="$store.sidebar.collapsed ? '{{ __('nav.departments') }}' : ''"
               class="o-sidebar-item flex items-center gap-3 py-2.5 text-xs transition-colors duration-150 {{ request()->routeIs('departments.*') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
                <svg class="w-5 h-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
                </svg>
                <span class="o-sidebar-label truncate">{{ __('nav.departments') }}</span>
            </a>
        @endif

        <!-- Company Settings (Admin, Super User, Super Admin) -->
        @if ($user && ($user->isSuperAdmin() || $user->isSuperUser() || $user->isAdmin()))
            <a href="{{ route('settings.company') }}"
               :title="$store.sidebar.collapsed ? '{{ __('nav.company_settings') }}' : ''"
               class="o-sidebar-item flex items-center gap-3 py-2.5 text-xs transition-colors duration-150 {{ request()->routeIs('settings.company', 'settings.company.*', 'settings.holidays.*') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
                <svg class="w-5 h-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                </svg>
                <span class="o-sidebar-label truncate">{{ __('nav.company_settings') }}</span>
            </a>
        @endif

        <!-- Audit Trail Console (Admin, Super User, Super Admin) -->
        @if ($user && $user->isAdminOrAbove())
            <a href="{{ route('audit-logs.index') }}"
               :title="$store.sidebar.collapsed ? '{{ __('nav.audit_logs') }}' : ''"
               class="o-sidebar-item flex items-center gap-3 py-2.5 text-xs transition-colors duration-150 {{ request()->routeIs('audit-logs.*') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
                <svg class="w-5 h-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m5.231 13.481L15 17.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v16.5c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Zm3.75 11.625a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                </svg>
                <span class="o-sidebar-label truncate">{{ __('nav.audit_logs') }}</span>
            </a>
        @endif
    </nav>

    <!-- Bagian Bawah: Pusat Notifikasi, Tombol Instal PWA, Identitas Brand & Tombol Collapse Sidebar -->
    <div class="p-3 shrink-0 space-y-2">
        <button
            type="button"
            @click="$store.pwa.openNotificationsModal()"
            :title="$store.sidebar.collapsed ? '{{ __('pwa.notifications_bell_label') }}' : ''"
            aria-label="{{ __('pwa.notifications_bell_label') }}"
            class="o-sidebar-item w-full flex items-center justify-between gap-2.5 py-2 rounded-xl text-xs font-medium text-ink-muted hover:bg-surface-muted hover:text-ink transition-colors cursor-pointer"
        >
            <span class="flex items-center gap-2.5 min-w-0">
                <svg class="w-5 h-5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                </svg>
                <span class="o-sidebar-label truncate">{{ __('pwa.notifications_bell_label') }}</span>
            </span>
            <span x-show="$store.pwa && $store.pwa.unreadCount > 0"
                  x-cloak
                  x-text="$store.pwa.unreadCount"
                  class="o-sidebar-label px-1.5 py-0.5 rounded-full bg-red-600 text-white text-[10px] font-bold"></span>
        </button>

        <button
            type="button"
            x-show="$store.pwa && !$store.pwa.isStandalone"
            x-cloak
            @click="$store.pwa.installApp()"
            :title="$store.sidebar.collapsed ? '{{ __('pwa.install_app') }}' : ''"
            aria-label="{{ __('pwa.install_app') }}"
            class="o-sidebar-item w-full flex items-center gap-2.5 py-2.5 rounded-xl border border-accent/30 bg-accent/10 text-xs font-semibold text-accent-text hover:bg-accent/15 transition-colors cursor-pointer"
        >
            <svg class="w-5 h-5 shrink-0 text-accent" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
            </svg>
            <span class="o-sidebar-label truncate">{{ __('pwa.install_app') }}</span>
        </button>

        <div class="o-sidebar-brand flex items-center gap-2.5 py-1.5 rounded-xl bg-surface-muted/50 border border-line/60">
            <img src="{{ $currentCompany?->logo_url ?: asset('storage/oatcake_logo_min.jpg') }}"
                 alt="{{ $currentCompany?->name ?? config('app.name', 'OATCAKE') }}"
                 class="h-7 w-7 shrink-0 rounded-lg object-cover border border-line bg-surface" />
            <div class="o-sidebar-label min-w-0 flex-1">
                <p class="text-[11px] font-semibold text-ink truncate">
                    {{ $currentCompany?->name ?? 'OATCAKE Platform' }}
                </p>
                <p class="text-[10px] font-mono text-ink-muted truncate">
                    {{ $currentCompany?->code ?? 'CORE ENGINE' }}
                </p>
            </div>
        </div>

        <button
            type="button"
            @click="$store.sidebar.toggle()"
            :title="$store.sidebar.collapsed ? '{{ __('nav.expand_sidebar') }}' : '{{ __('nav.collapse_sidebar') }}'"
            :aria-label="$store.sidebar.collapsed ? '{{ __('nav.expand_sidebar') }}' : '{{ __('nav.collapse_sidebar') }}'"
             class="o-sidebar-item w-full flex items-center gap-2.5 py-2 rounded-xl text-xs font-medium text-ink-muted hover:bg-surface-muted hover:text-ink transition-colors cursor-pointer"
        >
            <svg class="o-sidebar-chevron h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
            </svg>
            <span class="o-sidebar-label truncate">{{ __('nav.collapse_sidebar') }}</span>
        </button>
    </div>
</aside>
