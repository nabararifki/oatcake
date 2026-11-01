@php
    $user = Auth::user();
    $currentCompany = $currentCompany ?? ($user?->isSuperAdmin() && session('active_company_id')
        ? \App\Models\Company::find(session('active_company_id'))
        : $user?->company);

    $deptCode = $user?->employee?->department?->code;

    $roleBadgeLabel = match ($user?->role) {
        'super_admin' => 'Super Admin',
        'super_user' => 'Super User',
        'admin' => 'HR Admin',
        'manager' => $deptCode ? "Manager - {$deptCode}" : 'Manager',
        default => 'Staff',
    };

    $allCompanies = $user?->isSuperAdmin()
        ? \App\Models\Company::orderBy('name')->get()
        : collect();
@endphp

<nav x-data="{ open: false }" class="bg-surface border-b border-line">
    <div class="h-1 w-full" style="background-color: var(--primary-accent);"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-14">
            <div class="flex items-center gap-6">
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3 group">
                        @if ($user?->isSuperAdmin() && ! $currentCompany)
                            <img src="{{ asset('storage/oatcake_logo_min.jpg') }}"
                                 alt="OATCAKE Platform Administration"
                                 class="w-9 h-9 rounded-lg object-cover border border-line bg-surface shadow-xs" />
                            <div class="flex flex-col">
                                <span class="text-sm font-bold tracking-tight text-ink">
                                    OATCAKE Platform Administration
                                </span>
                                <span class="text-[11px] text-ink-muted font-mono">{{ __('common.global_root_control') }}</span>
                            </div>
                        @elseif ($currentCompany)
                            <img src="{{ $currentCompany->logo_url ?: asset('storage/oatcake_logo_min.jpg') }}"
                                 alt="{{ $currentCompany->name }}"
                                 class="h-9 w-9 rounded-lg object-cover border border-line bg-surface shadow-xs" />
                            <div class="flex flex-col">
                                <span class="text-sm font-bold tracking-tight text-ink truncate max-w-[220px] sm:max-w-[280px]">
                                    {{ $currentCompany->name }}
                                </span>
                                <span class="text-[11px] text-ink-muted font-mono">{{ $currentCompany->code }}</span>
                            </div>
                        @else
                            <img src="{{ asset('storage/oatcake_logo_min.jpg') }}"
                                 alt="{{ config('app.name', 'OATCAKE') }}"
                                 class="h-9 w-9 rounded-lg object-cover border border-line bg-surface shadow-xs" />
                        @endif
                    </a>
                </div>

                <div class="hidden space-x-6 sm:-my-px sm:ms-4 sm:flex h-full items-center">
                    <a href="{{ route('dashboard') }}"
                       class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium h-full transition duration-150 {{ request()->routeIs('dashboard') ? 'text-ink border-[var(--primary-accent)] font-semibold' : 'border-transparent text-ink-muted hover:text-ink hover:border-line-strong' }}">
                        {{ __('nav.dashboard') }}
                    </a>

                    @if ($user && ($user->isSuperAdmin() || $user->can('viewAny', \App\Models\Employee::class)))
                        <a href="{{ route('staff.index') }}"
                           class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium h-full transition duration-150 {{ request()->routeIs('staff.*') ? 'text-ink border-[var(--primary-accent)] font-semibold' : 'border-transparent text-ink-muted hover:text-ink hover:border-line-strong' }}">
                            {{ __('nav.staff_directory') }}
                        </a>
                    @endif

                    @if ($user && ($user->isSuperAdmin() || $user->can('create', \App\Models\Department::class)))
                        <a href="{{ route('departments.index') }}"
                           class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium h-full transition duration-150 {{ request()->routeIs('departments.*') ? 'text-ink border-[var(--primary-accent)] font-semibold' : 'border-transparent text-ink-muted hover:text-ink hover:border-line-strong' }}">
                            {{ __('nav.departments') }}
                        </a>
                    @endif

                    @if ($user && ($user->isSuperAdmin() || $user->isSuperUser()))
                        <a href="{{ route('company.settings') }}"
                           class="inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium h-full transition duration-150 {{ request()->routeIs('company.*') ? 'text-ink border-[var(--primary-accent)] font-semibold' : 'border-transparent text-ink-muted hover:text-ink hover:border-line-strong' }}">
                            {{ __('nav.company_settings') }}
                        </a>
                    @endif
                </div>
            </div>

            <div class="hidden sm:flex sm:items-center sm:ms-6 gap-3">
                @if ($user?->isSuperAdmin())
                    <form method="POST" action="{{ route('company.switch') }}" class="flex items-center">
                        @csrf
                        <select name="company_id"
                                onchange="this.form.submit()"
                                aria-label="Company Context Switcher"
                                class="bg-surface-muted border border-line text-ink text-xs font-medium rounded-lg px-2.5 py-1.5 focus:border-[var(--primary-accent)]">
                            <option value="">{{ __('common.all_tenants_global') }}</option>
                            @foreach ($allCompanies as $tenant)
                                <option value="{{ $tenant->id }}" @selected((int) session('active_company_id') === (int) $tenant->id)>
                                    {{ $tenant->code }} — {{ \Illuminate\Support\Str::limit($tenant->name, 24) }}
                                </option>
                            @endforeach
                        </select>
                    </form>
                @endif

                <x-ui.locale-switcher />

                <button type="button"
                        x-data
                        @click="$store.theme.toggle()"
                        :title="$store.theme.dark ? '{{ __('nav.theme_light') }}' : '{{ __('nav.theme_dark') }}'"
                        aria-label="{{ __('nav.theme') }}"
                        class="inline-flex items-center justify-center p-1.5 rounded-lg bg-surface-muted text-ink-muted hover:text-ink ring-1 ring-inset ring-line transition-colors">
                    <svg x-show="!$store.theme.dark" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                    </svg>
                    <svg x-cloak x-show="$store.theme.dark" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                    </svg>
                </button>

                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center gap-2.5 px-3 py-1.5 border border-line text-sm leading-4 font-medium rounded-lg text-ink bg-surface hover:bg-surface-muted focus:outline-none transition ease-in-out duration-150">
                            <span>{{ $user?->name }}</span>

                            <span class="px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide rounded-md text-accent-fg"
                                  style="background-color: var(--primary-accent);">
                                {{ $roleBadgeLabel }}
                            </span>

                            <svg class="fill-current h-4 w-4 text-ink-muted" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
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
            </div>
        </div>
    </div>
</nav>
