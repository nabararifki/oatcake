@php
    $accentPalette = $accentPalette ?? \App\Support\Accent::palette($currentCompany?->accent_color ?? null);
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

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

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

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-ink bg-canvas antialiased">
        <div class="relative min-h-screen h-dvh overflow-y-auto flex flex-col justify-between items-center bg-canvas px-4 py-6 sm:py-10">
            <!-- Top Accent Hairline -->
            <div class="fixed top-0 inset-x-0 h-0.5 z-40" style="background-color: var(--primary-accent);"></div>

            <!-- Utilitas Navigasi Tamu (Sudut Kanan Atas: Pemilih Bahasa & Toggle Dark/Light Mode) -->
            <header class="w-full max-w-6xl flex items-center justify-end z-30 shrink-0">
                <div class="inline-flex items-center gap-2 rounded-2xl border border-line bg-surface/90 p-1.5 shadow-sm backdrop-blur-md">
                    <x-ui.locale-switcher />

                    <button type="button"
                            x-data
                            @click="$store.theme.toggle()"
                            :title="$store.theme.dark ? '{{ __('nav.theme_light') }}' : '{{ __('nav.theme_dark') }}'"
                            :aria-label="$store.theme.dark ? '{{ __('nav.theme_light') }}' : '{{ __('nav.theme_dark') }}'"
                            :aria-pressed="$store.theme.dark.toString()"
                            class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-surface-muted text-ink-muted hover:text-ink ring-1 ring-inset ring-line transition-colors cursor-pointer shrink-0 focus:outline-none focus:ring-2 focus:ring-accent">
                        <svg x-show="!$store.theme.dark" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                        </svg>
                        <svg x-cloak x-show="$store.theme.dark" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                        </svg>
                    </button>
                </div>
            </header>

            <!-- Kontainer Utama Kartu Autentikasi -->
            <main class="w-full flex-1 flex flex-col justify-center items-center my-6">
                @isset($logo)
                    <div class="mb-5">
                        {{ $logo }}
                    </div>
                @else
                    <div class="mb-5">
                        <a href="/" class="group inline-flex items-center justify-center rounded-2xl border border-line bg-surface p-2 shadow-md transition duration-200 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-accent">
                            <img src="{{ asset('storage/oatcake_logo_full.jpg') }}"
                                 alt="{{ config('app.name', 'OATCAKE') }}"
                                 class="h-24 w-24 sm:h-28 sm:w-28 rounded-xl object-contain" />
                        </a>
                    </div>
                @endisset

                <div class="w-full sm:max-w-md rounded-2xl border border-line bg-surface/95 shadow-xl backdrop-blur-md p-6 sm:p-8 overflow-hidden">
                    {{ $slot }}
                </div>
            </main>

            <footer class="text-center text-[11px] text-ink-faint shrink-0">
                &copy; {{ date('Y') }} {{ config('app.name', 'OATCAKE') }} &mdash; {{ __('common.brand_tagline') }}
            </footer>
        </div>
    </body>
</html>
