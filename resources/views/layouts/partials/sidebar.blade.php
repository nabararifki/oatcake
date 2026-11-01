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
            <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
            </svg>
            <span class="o-sidebar-label truncate">{{ __('nav.dashboard') }}</span>
        </a>

        <!-- Schedule Roster (Gantt Chart 24h) -->
        <a href="{{ route('roster.index') }}"
           :title="$store.sidebar.collapsed ? '{{ __('nav.schedule_roster') }}' : ''"
           class="o-sidebar-item flex items-center gap-3 py-2.5 text-xs transition-colors duration-150 {{ request()->routeIs('roster.*') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
            <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.253 3.75h18M4.5 7.5h15a2.25 2.25 0 0 1 2.25 2.25v10.5A2.25 2.25 0 0 1 19.5 22.5H4.5A2.25 2.25 0 0 1 2.25 20.25V9.75A2.25 2.25 0 0 1 4.5 7.5Z" />
            </svg>
            <span class="o-sidebar-label truncate">{{ __('nav.schedule_roster') }}</span>
        </a>

        <!-- Staff Directory (Manager, Admin, Super User, Super Admin) -->
        @if ($user && ($user->isSuperAdmin() || $user->can('viewAny', \App\Models\Employee::class)))
            <a href="{{ route('staff.index') }}"
               :title="$store.sidebar.collapsed ? '{{ __('nav.staff_directory') }}' : ''"
               class="o-sidebar-item flex items-center gap-3 py-2.5 text-xs transition-colors duration-150 {{ request()->routeIs('staff.*') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
                <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                </svg>
                <span class="o-sidebar-label truncate">{{ __('nav.staff_directory') }}</span>
            </a>
        @endif

        <!-- Departments (Admin, Super User, Super Admin) -->
        @if ($user && ($user->isSuperAdmin() || $user->can('create', \App\Models\Department::class)))
            <a href="{{ route('departments.index') }}"
               :title="$store.sidebar.collapsed ? '{{ __('nav.departments') }}' : ''"
               class="o-sidebar-item flex items-center gap-3 py-2.5 text-xs transition-colors duration-150 {{ request()->routeIs('departments.*') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
                <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
                </svg>
                <span class="o-sidebar-label truncate">{{ __('nav.departments') }}</span>
            </a>
        @endif

        <!-- Company Settings (Super User, Super Admin) -->
        @if ($user && ($user->isSuperAdmin() || $user->isSuperUser()))
            <a href="{{ route('company.settings') }}"
               :title="$store.sidebar.collapsed ? '{{ __('nav.company_settings') }}' : ''"
               class="o-sidebar-item flex items-center gap-3 py-2.5 text-xs transition-colors duration-150 {{ request()->routeIs('company.*') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
                <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                </svg>
                <span class="o-sidebar-label truncate">{{ __('nav.company_settings') }}</span>
            </a>
        @endif
    </nav>

    <!-- Bagian Bawah: Identitas Brand & Tombol Collapse Sidebar -->
    <div class="p-3 shrink-0 space-y-2">
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
