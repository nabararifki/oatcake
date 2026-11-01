@php
    $user = Auth::user();
    $currentCompany = $currentCompany ?? ($user?->isSuperAdmin() && session('active_company_id')
        ? \App\Models\Company::find(session('active_company_id'))
        : $user?->company);
    $allCompanies = $allCompanies ?? ($user?->isSuperAdmin()
        ? \App\Models\Company::orderBy('name')->get()
        : collect());
@endphp

<!-- Mobile Backdrop Overlay (< 1024px) -->
<div x-show="mobileMenuOpen"
     x-transition:enter="transition-opacity ease-linear duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition-opacity ease-linear duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @click="mobileMenuOpen = false"
     x-cloak
     class="fixed inset-0 bg-zinc-950/60 backdrop-blur-sm z-50 lg:hidden"
     aria-hidden="true"></div>

<!-- Panel Drawer Meluncur (< 1024px) -->
<div x-show="mobileMenuOpen"
     x-transition:enter="transition ease-in-out duration-300 transform"
     x-transition:enter-start="-translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in-out duration-300 transform"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="-translate-x-full"
     @keydown.escape.window="mobileMenuOpen = false"
     x-cloak
     role="dialog"
     aria-modal="true"
     aria-label="Mobile Sidebar Navigation"
     class="fixed inset-y-0 left-0 w-72 max-w-[80vw] bg-surface border-r border-line shadow-2xl z-50 flex flex-col lg:hidden">
    <!-- Header Drawer: Brand & Tombol Close 'X' -->
    <div class="h-14 border-b border-line px-4 flex items-center justify-between gap-3 shrink-0">
        <div class="flex items-center gap-2.5 min-w-0">
            @if ($user?->isSuperAdmin() && ! $currentCompany)
                <img src="{{ asset('storage/oatcake_logo_min.jpg') }}"
                     alt="OATCAKE Platform"
                     class="w-8 h-8 shrink-0 rounded-lg object-cover border border-line bg-surface" />
                <div class="flex flex-col min-w-0">
                    <span class="text-xs font-bold text-ink truncate">OATCAKE Platform</span>
                    <span class="text-[10px] font-mono text-ink-muted">{{ __('common.global_root_control') }}</span>
                </div>
            @elseif ($currentCompany)
                <img src="{{ $currentCompany->logo_url ?: asset('storage/oatcake_logo_min.jpg') }}"
                     alt="{{ $currentCompany->name }}"
                     class="w-8 h-8 shrink-0 rounded-lg object-cover border border-line bg-surface" />
                <div class="flex flex-col min-w-0">
                    <span class="text-xs font-bold text-ink truncate">{{ $currentCompany->name }}</span>
                    <span class="text-[10px] font-mono text-ink-muted">{{ $currentCompany->code }}</span>
                </div>
            @else
                <img src="{{ asset('storage/oatcake_logo_min.jpg') }}"
                     alt="{{ config('app.name', 'OATCAKE') }}"
                     class="w-8 h-8 shrink-0 rounded-lg object-cover border border-line bg-surface" />
            @endif
        </div>

        <button type="button"
                @click="mobileMenuOpen = false"
                aria-label="{{ __('common.cancel') }}"
                class="p-2 rounded-lg hover:bg-surface-muted text-ink-muted hover:text-ink">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <!-- Mobile Tenant Switcher (Super Admin) -->
    @if ($user?->isSuperAdmin())
        <div class="px-4 py-3 border-b border-line sm:hidden">
            <form method="POST" action="{{ route('company.switch') }}" class="m-0">
                @csrf
                <select name="company_id"
                        onchange="this.form.submit()"
                        aria-label="Mobile Company Context Switcher"
                        class="w-full bg-surface-muted border border-line text-ink text-xs font-medium rounded-lg px-2.5 py-2 focus:outline-none focus:ring-1 focus:ring-accent">
                    <option value="">{{ __('common.all_tenants_global') }}</option>
                    @foreach ($allCompanies as $tenant)
                        <option value="{{ $tenant->id }}" @selected((int) session('active_company_id') === (int) $tenant->id)>
                            {{ $tenant->code }} — {{ \Illuminate\Support\Str::limit($tenant->name, 22) }}
                        </option>
                    @endforeach
                </select>
            </form>
        </div>
    @endif

    <!-- Daftar Menu Navigasi Lengkap -->
    <div class="flex-1 overflow-y-auto p-4 space-y-1">
        <a href="{{ route('dashboard') }}"
           class="flex items-center gap-3 px-3.5 py-2.5 text-xs transition-all duration-150 {{ request()->routeIs('dashboard') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
            <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
            </svg>
            <span class="truncate">{{ __('nav.dashboard') }}</span>
        </a>

        @if ($user && ($user->isSuperAdmin() || $user->can('viewAny', \App\Models\Employee::class)))
            <a href="{{ route('staff.index') }}"
               class="flex items-center gap-3 px-3.5 py-2.5 text-xs transition-all duration-150 {{ request()->routeIs('staff.*') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
                <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                </svg>
                <span class="truncate">{{ __('nav.staff_directory') }}</span>
            </a>
        @endif

        @if ($user && ($user->isSuperAdmin() || $user->can('create', \App\Models\Department::class)))
            <a href="{{ route('departments.index') }}"
               class="flex items-center gap-3 px-3.5 py-2.5 text-xs transition-all duration-150 {{ request()->routeIs('departments.*') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
                <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
                </svg>
                <span class="truncate">{{ __('nav.departments') }}</span>
            </a>
        @endif

        @if ($user && ($user->isSuperAdmin() || $user->isSuperUser()))
            <a href="{{ route('company.settings') }}"
               class="flex items-center gap-3 px-3.5 py-2.5 text-xs transition-all duration-150 {{ request()->routeIs('company.*') ? 'bg-accent text-accent-fg font-semibold rounded-xl shadow-md' : 'text-ink-muted hover:bg-surface-muted hover:text-ink rounded-xl font-medium' }}">
                <svg class="h-4 w-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                </svg>
                <span class="truncate">{{ __('nav.company_settings') }}</span>
            </a>
        @endif
    </div>
</div>
