@php
    $accentPalette = $accentPalette ?? \App\Support\Accent::palette(null);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="{{ ($userTheme ?? 'system') === 'dark' ? 'dark' : '' }}"
      data-theme="{{ $userTheme ?? 'system' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="light dark">

        @include('layouts.partials.pwa-head')

        <title>{{ __('pwa.offline_page_title') }} — {{ config('app.name', 'OATCAKE') }}</title>

        <script>
            (function() {
                try {
                    var match = document.cookie.match(/(?:^|; )theme=(light|dark|system)/);
                    var theme = match ? match[1] : '{{ $userTheme ?? "system" }}';
                    var isDark = theme === 'dark' || (theme !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', isDark);
                    document.documentElement.dataset.theme = theme;
                } catch (e) {}
            })();
        </script>

        <style>
            :root {
                --primary-accent: {{ $accentPalette['hex'] }};
                --primary-accent-rgb: {{ $accentPalette['rgb'] }};
                --primary-accent-fg: {{ $accentPalette['fg'] }};
                --primary-accent-text-light: {{ $accentPalette['text_light'] }};
                --primary-accent-text-dark: {{ $accentPalette['text_dark'] }};
            }
        </style>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans h-dvh w-screen overflow-hidden bg-surface text-ink antialiased" x-data="pwaManager()">
        <div class="h-dvh w-screen overflow-hidden flex flex-col bg-surface text-ink">
            <!-- Top Accent Hairline -->
            <div class="h-0.5 w-full shrink-0" style="background-color: var(--primary-accent);"></div>

            <!-- Topbar -->
            <header class="h-14 px-4 sm:px-6 flex items-center justify-between gap-3 shrink-0 bg-surface">
                <div class="flex items-center gap-2.5 min-w-0">
                    <img src="/icons/icon-192x192.png"
                         alt="OATCAKE"
                         class="h-8 w-8 shrink-0 rounded-lg object-cover border border-line bg-surface shadow-2xs" />
                    <div class="min-w-0">
                        <p class="text-xs sm:text-sm font-bold tracking-tight text-ink truncate">
                            {{ __('pwa.offline_page_title') }}
                        </p>
                        <p class="text-2xs text-ink-muted truncate hidden sm:block">
                            {{ __('pwa.offline_page_subtitle') }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <!-- Reactive Connection Pill -->
                    <span x-show="$store.pwa.isOnline"
                          x-cloak
                          class="o-badge o-badge-success">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        <span>{{ __('pwa.status_online') }}</span>
                    </span>
                    <span x-show="!$store.pwa.isOnline"
                          class="o-badge o-badge-warning">
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                        <span>{{ __('pwa.status_offline') }}</span>
                    </span>

                    <!-- Dark Mode Toggle -->
                    <button type="button"
                            @click="$store.theme.toggle()"
                            :title="$store.theme.dark ? '{{ __('nav.theme_light') }}' : '{{ __('nav.theme_dark') }}'"
                            aria-label="{{ __('nav.theme') }}"
                            class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-surface-muted text-ink-muted hover:text-ink ring-1 ring-inset ring-line transition-colors cursor-pointer shrink-0">
                        <svg x-show="!$store.theme.dark" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                        </svg>
                        <svg x-cloak x-show="$store.theme.dark" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                        </svg>
                    </button>
                </div>
            </header>

            <!-- Inset Content Stage -->
            <div class="flex-1 min-h-0 p-2 sm:p-4 bg-surface flex flex-col">
                <div class="flex-1 min-h-0 rounded-xl sm:rounded-2xl border border-line bg-canvas overflow-y-auto shadow-sm o-inset-stage">
                    <main class="p-4 sm:p-6 max-w-5xl mx-auto space-y-5">
                        <!-- Reactive Connection Status Card -->
                        <section class="rounded-2xl border p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4"
                                 :class="$store.pwa.isOnline
                                     ? 'border-emerald-500/30 bg-emerald-500/10'
                                     : 'border-amber-500/30 bg-amber-500/10'">
                            <div class="flex items-start gap-3 min-w-0">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
                                     :class="$store.pwa.isOnline ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' : 'bg-amber-500/15 text-amber-800 dark:text-amber-300'">
                                    <svg x-show="!$store.pwa.isOnline" class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c1.547-1.547 3.504-2.463 5.565-2.747m3.68.389a9.723 9.723 0 0 1 4.543 2.358M12 18.75h.008v.008H12v-.008ZM1.924 8.674a14.22 14.22 0 0 1 4.86-3.192m3.84-.894a14.23 14.23 0 0 1 11.452 4.086" />
                                    </svg>
                                    <svg x-show="$store.pwa.isOnline" x-cloak class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z" />
                                    </svg>
                                </div>
                                <div class="space-y-1">
                                    <h1 class="text-sm sm:text-base font-bold text-ink"
                                        x-text="$store.pwa.isOnline ? '{{ __('pwa.status_online') }}' : '{{ __('pwa.status_offline') }}'"></h1>
                                    <p class="text-xs text-ink-muted"
                                       x-text="$store.pwa.isOnline ? '{{ __('pwa.status_online_hint') }}' : '{{ __('pwa.status_offline_hint') }}'"></p>
                                </div>
                            </div>

                            <button type="button"
                                    @click="$store.pwa.reloadToDashboard()"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-accent px-4 py-2.5 text-xs font-bold text-accent-fg shadow-sm hover:opacity-95 transition cursor-pointer shrink-0">
                                <svg class="w-4 h-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                                <span>{{ __('pwa.btn_reload_dashboard') }}</span>
                            </button>
                        </section>

                        <!-- Offline Snapshot Viewer Card -->
                        <x-pwa.snapshot-viewer />
                    </main>
                </div>
            </div>
        </div>
    </body>
</html>
