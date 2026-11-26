@php
    $authUser = auth()->user();
    $currentCompany = $currentCompany ?? ($authUser?->isSuperAdmin() && session('active_company_id')
        ? \App\Models\Company::find(session('active_company_id'))
        : $authUser?->company);
    $accentPalette = $accentPalette ?? \App\Support\Accent::palette($currentCompany?->accent_color ?? null);
    $accentColor = $accentPalette['hex'];

    $deptCode = $authUser?->employee?->department?->code;
    $roleBadgeLabel = match ($authUser?->role) {
        'super_admin' => __('staff.role_super_admin'),
        'super_user' => __('staff.role_super_user'),
        'admin_hr' => __('staff.role_admin_hr'),
        'manager' => $deptCode ? __('staff.role_manager') . " - {$deptCode}" : __('staff.role_manager'),
        'admin_staff' => $deptCode ? __('staff.role_admin_staff') . " - {$deptCode}" : __('staff.role_admin_staff'),
        default => __('staff.role_user'),
    };

    $sidebarCollapsed = request()->cookie('sidebar') === 'collapsed';

    $allCompanies = $authUser?->isSuperAdmin()
        ? \App\Models\Company::orderBy('name')->get()
        : collect();

    $autoLogoutMinutes = $currentCompany ? $currentCompany->getEffectiveAutoLogoutMinutes() : 30;
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
        @auth
            <meta name="session-timeout-minutes" content="{{ $autoLogoutMinutes }}">
            <meta name="session-logout-url" content="{{ route('logout') }}">
            <meta name="session-login-url" content="{{ route('login', ['timeout' => 1]) }}">
        @endauth
        @include('layouts.partials.pwa-head')

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
        <div x-data="{ mobileMenuOpen: false, ...pwaManager() }"
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
                            <img src="{{ asset('storage/oatcake_logo_min.png') }}"
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
                            <img src="{{ $currentCompany->logo_url ?: asset('storage/oatcake_logo_min.png') }}"
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
                            <img src="{{ asset('storage/oatcake_logo_min.png') }}"
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

                    @if ($authUser)
                        <!-- Lonceng Pusat Notifikasi Real-Time & Web Push -->
                        <button type="button"
                                @click="$store.pwa.openNotificationsModal()"
                                title="{{ __('pwa.notifications_bell_label') }}"
                                aria-label="{{ __('pwa.notifications_bell_label') }}"
                                class="relative inline-flex items-center justify-center w-9 h-9 rounded-xl bg-surface-muted text-ink-muted hover:text-ink ring-1 ring-inset ring-line transition-colors cursor-pointer shrink-0">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                            </svg>
                            <span x-show="$store.pwa && $store.pwa.unreadCount > 0"
                                  x-cloak
                                  x-text="$store.pwa.unreadCount > 99 ? '99+' : $store.pwa.unreadCount"
                                  class="absolute -top-1 -right-1 min-w-[1.1rem] h-[1.1rem] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold leading-none flex items-center justify-center ring-2 ring-surface"></span>
                        </button>
                    @endif

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

                                <form method="POST" action="{{ route('logout') }}" data-pwa-logout-form>
                                    @csrf

                                    <x-dropdown-link :href="route('logout')"
                                            onclick="event.preventDefault();
                                                        if (window.oatcakeLogoutSubmit) { window.oatcakeLogoutSubmit(this.closest('form')); } else { this.closest('form').submit(); }">
                                        {{ __('nav.logout') }}
                                    </x-dropdown-link>
                                </form>
                            </x-slot>
                        </x-dropdown>
                    @endif
                </div>
            </header>

            <!-- Banner Mode Offline Baca-Saja (Muncul Otomatis Saat Koneksi Terputus) -->
            <div x-show="$store.pwa && !$store.pwa.isOnline"
                 x-cloak
                 x-transition
                 role="status"
                 aria-live="polite"
                 class="shrink-0 mx-2 sm:mx-4 mb-1 rounded-xl border border-amber-500/40 bg-amber-500/10 px-3.5 py-2 text-xs text-amber-900 dark:text-amber-200 flex flex-wrap items-center justify-between gap-2.5">
                <div class="flex items-center gap-2.5 min-w-0 flex-1">
                    <svg class="w-4 h-4 shrink-0 text-amber-600 dark:text-amber-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c1.547-1.547 3.504-2.463 5.565-2.747m3.68.389a9.723 9.723 0 0 1 4.543 2.358M12 18.75h.008v.008H12v-.008ZM1.924 8.674a14.22 14.22 0 0 1 4.86-3.192m3.84-.894a14.23 14.23 0 0 1 11.452 4.086" />
                    </svg>
                    <div class="min-w-0">
                        <span class="font-bold">{{ __('pwa.offline_banner_title') }}:</span>
                        <span class="opacity-90">{{ __('pwa.offline_banner_desc') }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <button type="button"
                            @click="$store.pwa.openSnapshotModal()"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-surface border border-line px-2.5 py-1 text-2xs font-bold text-ink hover:bg-surface-muted transition cursor-pointer">
                        <svg class="w-3.5 h-3.5 text-accent" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                        </svg>
                        <span>{{ __('pwa.btn_view_saved_schedule') }}</span>
                    </button>
                    <a href="{{ route('pwa.offline') }}"
                       class="inline-flex items-center rounded-lg bg-amber-600/20 px-2.5 py-1 text-2xs font-bold text-amber-900 dark:text-amber-200 hover:bg-amber-600/30 transition">
                        {{ __('pwa.btn_open_offline_page') }}
                    </a>
                </div>
            </div>

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

        <!-- Modal Panduan Instalasi PWA (Fallback HTTP Non-HTTPS, Android Chrome & iOS Safari) -->
        <x-ui.modal name="pwa-install-guide-modal" :title="__('pwa.install_guide_title')" maxWidth="lg">
            <div x-data class="space-y-4">
                <!-- Bagian 1: Deteksi Koneksi HTTP Lokal / Non-HTTPS -->
                <div x-show="$store.pwa && !$store.pwa.isSecureContext"
                     x-cloak
                     class="space-y-3">
                    <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-3.5 space-y-2">
                        <div class="flex items-start gap-2.5">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-amber-500/20 text-amber-700 dark:text-amber-300 shrink-0">
                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                                </svg>
                            </span>
                            <div class="min-w-0 flex-1 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-md bg-amber-500/20 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-900 dark:text-amber-200">
                                        {{ __('pwa.http_fallback_badge') }}
                                    </span>
                                    <code class="text-[11px] font-mono px-1.5 py-0.5 rounded bg-surface border border-line text-ink break-all"
                                          x-text="$store.pwa.currentOrigin"></code>
                                </div>
                                <p class="text-xs font-bold text-amber-950 dark:text-amber-100">
                                    {{ __('pwa.http_fallback_title') }}
                                </p>
                                <p class="text-2xs text-amber-900/90 dark:text-amber-200/90 leading-relaxed">
                                    {{ __('pwa.http_fallback_desc') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Metode 1: Aktifkan PWA Penuh & Kamera via Chrome Flags -->
                    <div class="rounded-xl border border-accent/30 bg-accent/5 p-3.5 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-xs font-bold text-ink">
                                {{ __('pwa.http_method_1_title') }}
                            </h3>
                            <span class="inline-flex items-center rounded-md bg-accent/15 px-2 py-0.5 text-[10px] font-semibold text-accent-text">
                                {{ __('pwa.http_method_1_badge') }}
                            </span>
                        </div>

                        <ol class="space-y-2 text-2xs text-ink-muted list-decimal list-inside leading-relaxed">
                            <li>{{ __('pwa.http_method_1_step_1') }}</li>
                            <li>{{ __('pwa.http_method_1_step_2') }}</li>
                            <li>{{ __('pwa.http_method_1_step_3') }}</li>
                        </ol>

                        <div class="space-y-2 pt-1">
                            <!-- Salin Alamat Chrome Flags -->
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 rounded-lg border border-line bg-surface p-2">
                                <code class="text-[11px] font-mono text-ink break-all select-all">chrome://flags/#unsafely-treat-insecure-origin-as-secure</code>
                                <button type="button"
                                        @click="$store.pwa.copyToClipboard($store.pwa.chromeFlagsUrl, 'flags')"
                                        class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-accent px-3 py-1.5 text-2xs font-bold text-accent-fg shadow-2xs hover:opacity-95 transition cursor-pointer shrink-0">
                                    <svg x-show="$store.pwa.copiedTarget !== 'flags'" class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" />
                                    </svg>
                                    <svg x-cloak x-show="$store.pwa.copiedTarget === 'flags'" class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                    <span x-text="$store.pwa.copiedTarget === 'flags' ? '{{ __('pwa.copied_feedback') }}' : '{{ __('pwa.btn_copy_chrome_flags') }}'"></span>
                                </button>
                            </div>

                            <!-- Salin Origin Server -->
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 rounded-lg border border-line bg-surface p-2">
                                <code class="text-[11px] font-mono text-ink break-all select-all" x-text="$store.pwa.currentOrigin"></code>
                                <button type="button"
                                        @click="$store.pwa.copyToClipboard($store.pwa.currentOrigin, 'origin')"
                                        class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-line bg-surface-muted px-3 py-1.5 text-2xs font-bold text-ink hover:bg-surface-muted/80 transition cursor-pointer shrink-0">
                                    <svg x-show="$store.pwa.copiedTarget !== 'origin'" class="w-3.5 h-3.5 text-accent" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" />
                                    </svg>
                                    <svg x-cloak x-show="$store.pwa.copiedTarget === 'origin'" class="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                    <span x-text="$store.pwa.copiedTarget === 'origin' ? '{{ __('pwa.copied_feedback') }}' : '{{ __('pwa.btn_copy_server_origin') }}'"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Metode 2: Tambahkan Pintasan ke Layar Utama Tanpa Mengubah Flag -->
                    <div class="rounded-xl border border-line bg-surface-muted/40 p-3.5 space-y-2">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-surface border border-line text-ink-muted shrink-0">
                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 12.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 18.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z" />
                                </svg>
                            </span>
                            <h3 class="text-xs font-bold text-ink">
                                {{ __('pwa.http_method_2_title') }}
                            </h3>
                        </div>
                        <ol class="space-y-1.5 text-2xs text-ink-muted list-decimal list-inside leading-relaxed">
                            <li>{{ __('pwa.http_method_2_step_1') }}</li>
                            <li>{{ __('pwa.http_method_2_step_2') }}</li>
                        </ol>
                    </div>
                </div>

                <!-- Bagian 2: Panduan Manual Android Chrome & iOS Safari -->
                <div class="grid grid-cols-1 gap-3">
                    <!-- Panduan Manual Google Chrome Android -->
                    <div class="rounded-xl border border-line bg-surface-muted/50 p-3.5 space-y-2.5">
                        <div class="flex items-start gap-2.5">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-accent/10 text-accent shrink-0">
                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 12.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 18.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z" />
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <h3 class="text-xs font-bold text-ink">{{ __('pwa.install_guide_android_title') }}</h3>
                                <p class="text-2xs text-ink-muted leading-relaxed">{{ __('pwa.install_guide_android_desc') }}</p>
                            </div>
                        </div>
                        <ol class="space-y-1.5 text-2xs text-ink-muted list-decimal list-inside leading-relaxed">
                            <li>{{ __('pwa.install_guide_android_step_1') }}</li>
                            <li>{{ __('pwa.install_guide_android_step_2') }}</li>
                        </ol>
                    </div>

                    <!-- Panduan Safari iOS / iPadOS -->
                    <div class="rounded-xl border border-line bg-surface-muted/50 p-3.5 space-y-2.5">
                        <div class="flex items-start gap-2.5">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-accent/10 text-accent shrink-0">
                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 8.25H7.5a2.25 2.25 0 0 0-2.25 2.25v9a2.25 2.25 0 0 0 2.25 2.25h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25H15m0-3-3-3m0 0-3 3m3-3V15" />
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <h3 class="text-xs font-bold text-ink">{{ __('pwa.install_guide_ios_title') }}</h3>
                                <p class="text-2xs text-ink-muted leading-relaxed">{{ __('pwa.install_guide_ios_desc') }}</p>
                            </div>
                        </div>
                        <ol class="space-y-1.5 text-2xs text-ink-muted list-decimal list-inside leading-relaxed">
                            <li>{{ __('pwa.install_guide_step_1') }}</li>
                            <li>{{ __('pwa.install_guide_step_2') }}</li>
                            <li>{{ __('pwa.install_guide_step_3') }}</li>
                        </ol>
                    </div>
                </div>

                <p class="text-2xs text-ink-muted border-t border-line pt-3">
                    {{ __('pwa.install_guide_desktop_tip') }}
                </p>

                <div class="flex justify-end pt-1">
                    <button type="button"
                            @click="$dispatch('close-modal', 'pwa-install-guide-modal')"
                            class="rounded-xl bg-accent px-4 py-2 text-xs font-bold text-accent-fg shadow-sm hover:opacity-95 transition cursor-pointer">
                        {{ __('pwa.btn_close') }}
                    </button>
                </div>
            </div>
        </x-ui.modal>

        <!-- Modal Jadwal Kerja Tersimpan (Offline Snapshot) -->
        <x-ui.modal name="offline-snapshot-modal" :title="__('pwa.snapshot_card_title')" maxWidth="2xl">
            <x-pwa.snapshot-viewer />
        </x-ui.modal>

        <!-- Modal Pusat Notifikasi Real-Time & Kontrol Web Push -->
        <x-ui.modal name="notifications-modal" :title="__('pwa.notifications_title')" maxWidth="lg">
            <div x-data class="space-y-4">
                <!-- Baris Status Web Push Perangkat -->
                <div class="rounded-xl border border-line bg-surface-muted/50 p-3.5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="flex items-start gap-2.5 min-w-0">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-accent/10 text-accent shrink-0">
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-ink">{{ __('pwa.device_push_label') }}</p>
                            <p class="text-2xs text-ink-muted"
                               x-text="!$store.pwa.pushSupported
                                   ? '{{ __('pwa.device_push_unsupported') }}'
                                   : ($store.pwa.pushPermission === 'denied'
                                       ? '{{ __('pwa.device_push_denied') }}'
                                       : ($store.pwa.isPushSubscribed ? '{{ __('pwa.device_push_active') }}' : '{{ __('pwa.device_push_inactive') }}'))"></p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 shrink-0">
                        <button type="button"
                                x-show="$store.pwa.pushSupported && $store.pwa.pushPermission !== 'denied'"
                                @click="$store.pwa.togglePushSubscription()"
                                :disabled="$store.pwa.pushBusy || !$store.pwa.isOnline"
                                :class="$store.pwa.isPushSubscribed
                                    ? 'border border-line bg-surface text-ink hover:bg-surface-muted'
                                    : 'bg-accent text-accent-fg hover:opacity-95'"
                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-2xs font-bold shadow-2xs transition cursor-pointer disabled:opacity-50">
                            <span x-text="$store.pwa.isPushSubscribed ? '{{ __('pwa.btn_disable_push') }}' : '{{ __('pwa.btn_enable_push') }}'"></span>
                        </button>

                        <button type="button"
                                @click="$store.pwa.sendTestNotification()"
                                :disabled="$store.pwa.pushBusy || !$store.pwa.isOnline"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface px-3 py-1.5 text-2xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer disabled:opacity-50">
                            <svg class="w-3.5 h-3.5 text-accent" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                            </svg>
                            <span>{{ __('pwa.btn_test_push') }}</span>
                        </button>
                    </div>
                </div>

                <!-- Header Riwayat Notifikasi & Tandai Semua Dibaca -->
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-ink-muted">
                            {{ __('pwa.notifications_bell_label') }}
                        </span>
                        <span x-show="$store.pwa.unreadCount > 0"
                              x-cloak
                              class="o-badge o-badge-accent">
                            <span x-text="$store.pwa.unreadCount"></span>
                            <span>{{ __('pwa.notification_unread_badge') }}</span>
                        </span>
                    </div>

                    <button type="button"
                            x-show="$store.pwa.unreadCount > 0"
                            x-cloak
                            @click="$store.pwa.markAllNotificationsRead()"
                            class="text-2xs font-semibold text-accent-text hover:underline cursor-pointer">
                        {{ __('pwa.btn_mark_all_read') }}
                    </button>
                </div>

                <!-- Daftar Notifikasi -->
                <template x-if="$store.pwa.notifications.length === 0">
                    <div class="rounded-xl border border-dashed border-line bg-surface-muted/30 p-6 text-center text-xs text-ink-muted">
                        {{ __('pwa.notifications_empty') }}
                    </div>
                </template>

                <template x-if="$store.pwa.notifications.length > 0">
                    <div class="space-y-2 max-h-[45vh] overflow-y-auto pr-1 o-inset-stage">
                        <template x-for="item in $store.pwa.notifications" :key="item.uuid">
                            <button type="button"
                                    @click="$store.pwa.markNotificationRead(item)"
                                    :class="item.read_at ? 'border-line bg-surface hover:bg-surface-muted/50' : 'border-accent/40 bg-accent/5 hover:bg-accent/10'"
                                    class="w-full text-left rounded-xl border p-3 transition flex items-start gap-3 cursor-pointer">
                                <span class="mt-1.5 w-2 h-2 rounded-full shrink-0"
                                      :class="item.read_at ? 'bg-line-strong' : 'bg-accent'"></span>
                                <div class="min-w-0 flex-1 space-y-1">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-xs font-bold text-ink truncate" x-text="item.title"></p>
                                        <span class="text-[10px] font-mono text-ink-muted shrink-0"
                                              x-text="item.created_at_formatted || ''"></span>
                                    </div>
                                    <p class="text-xs text-ink-muted leading-relaxed" x-text="item.body"></p>
                                </div>
                            </button>
                        </template>
                    </div>
                </template>
            </div>
        </x-ui.modal>

        <x-ui.toast />
    </body>
</html>
