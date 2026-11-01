<x-guest-layout>
    <x-slot name="logo">
        <a href="/" class="group inline-flex items-center justify-center rounded-2xl border border-line bg-surface p-2 shadow-md transition duration-200 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-accent">
            <img src="{{ asset('storage/oatcake_logo_full.jpg') }}"
                 alt="{{ config('app.name', 'OATCAKE') }}"
                 class="h-28 w-28 sm:h-32 sm:w-32 rounded-xl object-contain transition-transform duration-200 group-hover:scale-[1.02]" />
        </a>
    </x-slot>

    <div class="space-y-6" x-data="{ openManual: {{ old('login') ? 'true' : 'false' }} }">
        <!-- Header Title -->
        <div class="text-center space-y-1.5">
            <h1 class="text-xl font-bold tracking-tight text-ink">
                {{ __('common.login_portal_title') }}
            </h1>
            <p class="text-xs text-ink-muted leading-relaxed">
                {{ __('common.login_portal_subtitle') }}
            </p>
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="mb-2" :status="session('status')" />

        <!-- Flash Error / Validation Alert -->
        @if (session('error') || $errors->any())
            <div role="alert" class="alert alert-error bg-red-500/10 border border-red-500/30 text-red-700 dark:text-red-300 rounded-xl p-3.5 text-sm shadow-sm flex items-start gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0 text-red-600 dark:text-red-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <div class="space-y-1">
                    @if (session('error'))
                        <p class="font-medium">{{ session('error') }}</p>
                    @else
                        @foreach ($errors->all() as $error)
                            <p class="font-medium">{{ $error }}</p>
                        @endforeach
                    @endif
                </div>
            </div>
        @endif

        <!-- Opsi Utama (Atas): Google OAuth Login -->
        <div>
            <a href="{{ route('auth.google') }}"
               class="w-full h-12 rounded-xl border border-line bg-surface hover:bg-surface-muted text-ink font-semibold shadow-sm flex items-center justify-center gap-3 text-sm transition duration-150 focus:outline-none focus:ring-2 focus:ring-accent">
                <!-- Official Google Logo SVG -->
                <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                </svg>
                <span>{{ __('common.login_google_button') }}</span>
            </a>
        </div>

        <!-- Pemisah Visual -->
        <div class="relative flex py-1 items-center">
            <div class="grow border-t border-line"></div>
            <span class="shrink mx-3 text-xs text-ink-faint uppercase tracking-wider font-medium">
                {{ __('common.login_manual_divider') }}
            </span>
            <div class="grow border-t border-line"></div>
        </div>

        <!-- Opsi Sekunder (Bawah - Collapsible Hidden by Default) -->
        <div class="border border-line rounded-2xl bg-surface-muted/40 overflow-hidden">
            <button type="button"
                    @click="openManual = !openManual"
                    :aria-expanded="openManual.toString()"
                    class="w-full px-4 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-ink-muted hover:text-ink hover:bg-surface-muted flex items-center justify-between transition cursor-pointer focus:outline-none">
                <span>{{ __('common.login_manual_toggle') }}</span>
                <svg class="w-4 h-4 text-ink-muted transition-transform duration-200"
                     :class="{ 'rotate-180': openManual }"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            <div x-show="openManual"
                 x-collapse
                 style="display: none;"
                 class="px-4 pb-4 pt-3.5 border-t border-line bg-surface">
                <form method="POST" action="{{ route('login') }}" class="space-y-4">
                    @csrf

                    <!-- Login Identity (Username or Email) -->
                    <div>
                        <x-input-label for="login" :value="__('common.login_identity_label')" class="text-xs font-medium text-ink" />
                        <x-text-input id="login"
                                      class="block mt-1.5 w-full text-sm px-3.5 py-2.5 rounded-xl border-line-strong bg-canvas text-ink placeholder:text-ink-faint focus:border-accent focus:ring-2 focus:ring-accent"
                                      type="text"
                                      name="login"
                                      :value="old('login')"
                                      :placeholder="__('common.login_identity_placeholder')"
                                      required
                                      autocomplete="username" />
                        <x-input-error :messages="$errors->get('login')" class="mt-1.5" />
                    </div>

                    <!-- Password -->
                    <div>
                        <x-input-label for="password" :value="__('common.login_password_label')" class="text-xs font-medium text-ink" />
                        <x-text-input id="password"
                                      class="block mt-1.5 w-full text-sm px-3.5 py-2.5 rounded-xl border-line-strong bg-canvas text-ink placeholder:text-ink-faint focus:border-accent focus:ring-2 focus:ring-accent"
                                      type="password"
                                      name="password"
                                      placeholder="••••••••"
                                      required
                                      autocomplete="current-password" />
                        <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
                    </div>

                    <!-- Remember Me & Forgot Password -->
                    <div class="flex items-center justify-between pt-1">
                        <label for="remember_me" class="inline-flex items-center cursor-pointer">
                            <input id="remember_me"
                                   type="checkbox"
                                   class="rounded-md border-line-strong bg-canvas text-accent shadow-sm focus:ring-accent"
                                   name="remember">
                            <span class="ms-2 text-xs text-ink-muted">{{ __('common.login_remember_me') }}</span>
                        </label>

                        @if (Route::has('password.request'))
                            <a class="underline text-xs text-ink-muted hover:text-ink transition-colors"
                               href="{{ route('password.request') }}">
                                {{ __('common.login_forgot_password') }}
                            </a>
                        @endif
                    </div>

                    <!-- Submit Manual Login -->
                    <div class="pt-1">
                        <button type="submit"
                                class="w-full h-11 rounded-xl bg-accent text-accent-fg font-semibold shadow-md text-sm hover:opacity-95 transition cursor-pointer focus:outline-none focus:ring-2 focus:ring-accent">
                            {{ __('common.login_submit_button') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-guest-layout>
