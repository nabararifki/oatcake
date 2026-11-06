@php
    $authUser = auth()->user();
    $currentCompany = $currentCompany ?? ($authUser?->isSuperAdmin() && session('active_company_id')
        ? \App\Models\Company::find(session('active_company_id'))
        : $authUser?->company);
    $accentPalette = $accentPalette ?? \App\Support\Accent::palette($currentCompany?->accent_color ?? null);
    $accentColor = $accentPalette['hex'];

    $deptCode = $authUser?->employee?->department?->code;
    $roleBadgeLabel = match ($authUser?->role) {
        'super_admin' => 'Super Admin',
        'super_user' => 'Super User',
        'admin' => 'HR Admin',
        'manager' => $deptCode ? "Manager - {$deptCode}" : 'Manager',
        default => 'Staff',
    };

    $sidebarCollapsed = request()->cookie('sidebar') === 'collapsed';

    $allCompanies = $authUser?->isSuperAdmin()
        ? \App\Models\Company::orderBy('name')->get()
        : collect();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="{{ ($userTheme ?? 'system') === 'dark' ? 'dark' : '' }}"
      data-theme="{{ $userTheme ?? 'system' }}"
      data-sidebar="{{ $sidebarCollapsed ? 'collapsed' : 'expanded' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="light dark">

        <title>{{ $currentCompany?->name ? $currentCompany->name . ' — ' . config('app.name', 'OATCAKE') : config('app.name', 'OATCAKE') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <script>
            (function() {
                try {
                    var match = document.cookie.match(/(?:^|; )theme=(light|dark|system)/);
                    var theme = match ? match[1] : '{{ $userTheme ?? "system" }}';
                    var isDark = theme === 'dark' || (theme !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', isDark);
                    document.documentElement.dataset.theme = theme;
                    var sb = document.cookie.match(/(?:^|; )sidebar=(collapsed|expanded)/);
                    if (sb) document.documentElement.dataset.sidebar = sb[1];
                } catch (e) {}
                // Aktifkan transisi hanya setelah frame pertama dirender (cegah animasi saat mounting).
                window.addEventListener('load', function () {
                    requestAnimationFrame(function () {
                        requestAnimationFrame(function () {
                            document.documentElement.classList.add('o-ready');
                        });
                    });
                });
            })();
        </script>

        <!-- Dynamic Tenant Accent Color Injection -->
        <style>
            :root {
                --primary-accent: {{ $currentCompany?->accent_color ?? $accentPalette['hex'] }};
                --primary-accent-rgb: {{ $accentPalette['rgb'] }};
                --primary-accent-fg: {{ $accentPalette['fg'] }};
                --primary-accent-text-light: {{ $accentPalette['text_light'] }};
                --primary-accent-text-dark: {{ $accentPalette['text_dark'] }};
            }
        </style>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans h-dvh w-screen w-full max-w-full overflow-hidden bg-surface text-ink antialiased">
        <!-- Unified Monolithic Outer Chrome Frame -->
        <div x-data="{ mobileMenuOpen: false }"
             x-init="$watch('mobileMenuOpen', val => { if ($store.ui) $store.ui.mobileDrawerOpen = val }); $watch('$store.ui.mobileDrawerOpen', val => { mobileMenuOpen = val })"
             @mobile-drawer-change.window="mobileMenuOpen = $event.detail.open"
             @keydown.escape.window="mobileMenuOpen = false; if ($store.ui) $store.ui.mobileDrawerOpen = false"
             class="h-dvh w-screen overflow-hidden flex flex-col bg-surface text-ink antialiased w-full max-w-full">
            <!-- Top Accent Hairline -->
            <div class="h-0.5 w-full shrink-0" style="background-color: var(--primary-accent);"></div>

            <!-- Baris 1: Topbar Terintegrasi Tanpa Garis Pemisah Bawah (Monolithic Seamless Frame) -->
            <header style="view-transition-name: app-topbar;" class="relative z-50 h-14 px-3 sm:px-5 flex items-center justify-between gap-2 shrink-0 bg-surface">
                <!-- Sisi Kiri: Hamburger Mobile + Brand Tenant (Logo / Inisial + Nama Perusahaan + Badge Kode Properti) -->
                <div class="flex items-center gap-1.5 sm:gap-3 min-w-0">
                    <button @click="mobileMenuOpen = true" type="button" aria-label="Open Mobile Menu"
                            class="lg:hidden p-2 -ml-1.5 mr-2 rounded-lg text-ink-muted hover:text-ink hover:bg-surface-muted focus:outline-none focus:ring-2 focus:ring-accent">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2 sm:gap-2.5 min-w-0 group">
                        @if ($authUser?->isSuperAdmin() && ! $currentCompany)
                            <img src="{{ asset('storage/oatcake_logo_min.jpg') }}"
                                 alt="OATCAKE Platform Administration"
                                 class="h-8 w-8 shrink-0 rounded-lg object-cover border border-line bg-surface shadow-2xs" />
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="text-xs sm:text-sm font-bold tracking-tight text-ink truncate max-w-[110px] sm:max-w-none">
                                    OATCAKE Platform Administration
                                </span>
                                <span class="hidden md:inline-flex items-center rounded-md bg-surface-muted px-2 py-0.5 text-[10px] font-mono font-semibold text-ink-muted ring-1 ring-inset ring-line shrink-0">
                                    {{ __('common.global_root_control') }}
                                </span>
                            </div>
                        @elseif ($currentCompany)
                            <img src="{{ $currentCompany->logo_url ?: asset('storage/oatcake_logo_min.jpg') }}"
                                 alt="{{ $currentCompany->name }}"
                                 class="h-8 w-8 shrink-0 rounded-lg object-cover border border-line bg-surface shadow-2xs" />
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="text-xs sm:text-sm font-bold tracking-tight text-ink truncate max-w-[100px] sm:max-w-[260px] md:max-w-[320px]">
                                    {{ $currentCompany->name }}
                                </span>
                                <span class="hidden sm:inline-flex items-center rounded-md bg-surface-muted px-2 py-0.5 text-[10px] font-mono font-semibold text-ink-muted ring-1 ring-inset ring-line shrink-0">
                                    {{ $currentCompany->code }}
                                </span>
                            </div>
                        @else
                            <img src="{{ asset('storage/oatcake_logo_min.jpg') }}"
                                 alt="{{ config('app.name', 'OATCAKE') }}"
                                 class="h-8 w-8 shrink-0 rounded-lg object-cover border border-line bg-surface shadow-2xs" />
                        @endif
                    </a>
                </div>

                <!-- Sisi Kanan: Quick Tenant Context Switcher, Locale Switcher, Dark Mode Toggle & User Dropdown -->
                <div class="flex items-center gap-1.5 sm:gap-3 shrink-0">
                    @if ($authUser?->isSuperAdmin())
                        <form method="POST" action="{{ route('company.switch') }}" class="inline-flex items-center m-0">
                            @csrf
                            <select name="company_id"
                                    onchange="this.form.submit()"
                                    aria-label="Company Context Switcher"
                                    class="max-w-[110px] truncate sm:max-w-xs bg-surface-muted border border-line text-ink text-xs font-medium rounded-lg px-2 py-1.5 sm:px-2.5 focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">{{ __('common.all_tenants_global') }}</option>
                                @foreach ($allCompanies as $tenant)
                                    <option value="{{ $tenant->id }}" @selected((int) session('active_company_id') === (int) $tenant->id)>
                                        {{ $tenant->code }} — {{ \Illuminate\Support\Str::limit($tenant->name, 22) }}
                                    </option>
                                @endforeach
                            </select>
                        </form>
                    @endif

                    <!-- Segmented Pill Language Switcher (ID | EN) -->
                    <x-ui.locale-switcher />

                    <!-- Dark Mode Toggle -->
                    <button type="button"
                            x-data
                            @click="$store.theme.toggle()"
                            :title="$store.theme.dark ? '{{ __('nav.theme_light') }}' : '{{ __('nav.theme_dark') }}'"
                            :aria-pressed="$store.theme.dark.toString()"
                            aria-label="{{ __('nav.theme') }}"
                            class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-surface-muted text-ink-muted hover:text-ink ring-1 ring-inset ring-line transition-colors cursor-pointer shrink-0">
                        <svg x-show="!$store.theme.dark" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                        </svg>
                        <svg x-cloak x-show="$store.theme.dark" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                        </svg>
                    </button>

                    @if ($authUser)
                        <x-dropdown align="right" width="48">
                            <x-slot name="trigger">
                                <button type="button" class="inline-flex items-center gap-1.5 sm:gap-2 px-1.5 sm:px-2.5 py-1.5 border border-line text-xs font-medium rounded-lg text-ink bg-surface hover:bg-surface-muted focus:outline-none transition ease-in-out duration-150">
                                    <span class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold text-accent-fg shrink-0 sm:hidden"
                                          style="background-color: var(--primary-accent);">
                                        {{ strtoupper(substr($authUser->name ?? 'U', 0, 1)) }}
                                    </span>

                                    <span class="hidden sm:inline truncate max-w-[130px]">{{ $authUser->name }}</span>

                                    <span class="hidden sm:inline-flex px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide rounded-md text-accent-fg"
                                          style="background-color: var(--primary-accent);">
                                        {{ $roleBadgeLabel }}
                                    </span>

                                    <svg class="fill-current h-3.5 w-3.5 text-ink-muted shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <x-dropdown-link :href="route('profile.edit')">
                                    {{ __('nav.profile') }}
                                </x-dropdown-link>

                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf

                                    <x-dropdown-link :href="route('logout')"
                                            onclick="event.preventDefault();
                                                        this.closest('form').submit();">
                                        {{ __('nav.logout') }}
                                    </x-dropdown-link>
                                </form>
                            </x-slot>
                        </x-dropdown>
                    @endif
                </div>
            </header>

            <!-- Baris 2: Badan Bingkai (Sidebar Kiri + Panggung Konten Kanan) -->
            <div class="flex-1 min-h-0 flex overflow-hidden">
                <!-- Sisi Kiri: Sidebar Desktop & Mobile Slide-Over Drawer -->
                @include('layouts.partials.sidebar', [
                    'currentCompany' => $currentCompany,
                    'accentColor' => $accentColor,
                    'allCompanies' => $allCompanies,
                ])

                <!-- Sisi Kanan: Panggung Inset Konten (Jendela Scroll Mandiri) -->
                <div class="flex-1 min-w-0 h-full p-2 sm:p-4 bg-surface flex flex-col">
                    @isset($fullbleed)
                        <div class="flex-1 min-h-0 rounded-xl sm:rounded-2xl border border-line bg-canvas p-0 overflow-hidden h-full relative shadow-sm flex flex-col">
                            <main class="flex-1 min-h-0 flex flex-col overflow-hidden">{{ $fullbleed }}</main>
                        </div>
                    @else
                        <div class="flex-1 min-h-0 rounded-xl sm:rounded-2xl border border-line bg-canvas overflow-y-auto relative shadow-sm o-inset-stage" style="view-transition-name: app-stage;">
                            @isset($header)
                                <div class="border-b border-line bg-surface/60 px-4 py-3 sm:px-6 sm:py-4">
                                    <div class="max-w-7xl mx-auto">
                                        {{ $header }}
                                    </div>
                                </div>
                            @endisset

                            <main class="p-4 sm:p-6 max-w-7xl mx-auto">{{ $slot }}</main>
                        </div>
                    @endisset
                </div>
            </div>
        </div>

        <x-ui.toast />
    </body>
</html>
