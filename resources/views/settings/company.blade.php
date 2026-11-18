<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="font-bold text-xl text-ink leading-tight">
                    {{ __('company.title') }}
                </h2>
                <p class="text-xs text-ink-muted mt-0.5">
                    {{ $company?->name ?? __('common.global_root_all_tenants') }} &mdash; {{ __('company.subtitle') }}
                </p>
            </div>

            @if ($isSuperAdmin ?? auth()->user()?->isSuperAdmin())
                <button type="button"
                        @click="$dispatch('open-modal', 'create-tenant-modal')"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-accent text-accent-fg font-semibold shadow-md shadow-accent/20 hover:opacity-95 transition-all text-xs cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    <span>{{ __('company.btn_register_tenant') }}</span>
                </button>
            @endif
        </div>
    </x-slot>

    <div class="space-y-6"
         x-data="{
             lat: '{{ old('latitude', $company?->latitude ?? -6.2000000) }}',
             lng: '{{ old('longitude', $company?->longitude ?? 106.8166667) }}',
             radius: {{ (int) old('geofence_radius', $company?->geofence_radius ?? $company?->geofence_radius_meters ?? 100) }},
             autoAssignShiftOnClockIn: {{ Js::from((bool) old('auto_assign_shift_on_clock_in', $company?->auto_assign_shift_on_clock_in ?? true)) }},
             activeCategory: 'all',
             searchQuery: '',
             sectionKeywords: {
                 profile: @js(strtolower(__('company.section_profile') . ' ' . __('company.section_profile_desc') . ' ' . __('company.name') . ' ' . __('company.code') . ' ' . __('company.address') . ' ' . __('company.timezone') . ' profil properti tenant kode alamat zona waktu')),
                 attendance: @js(strtolower(__('company.section_geofence') . ' ' . __('company.section_geofence_desc') . ' ' . __('company.geofence_preview_title') . ' ' . __('company.auto_assign_shift_label') . ' ' . __('company.auto_assign_shift_desc') . ' ' . __('company.latitude') . ' ' . __('company.longitude') . ' ' . __('company.geofence_radius') . ' presensi geofence gps radar auto-schedule shift clock-in')),
                 storage: @js(strtolower(__('company.storage_usage_heading') . ' ' . __('company.storage_usage_desc') . ' ' . __('company.photo_retention_label') . ' ' . __('company.storage_limit_label') . ' penyimpanan retensi foto storage quota')),
                 holidays: @js(strtolower(__('company.holiday_section_title') . ' ' . __('company.holiday_section_desc') . ' hari libur nasional event dp calendar holiday'))
             },
             matchesSection(cat) {
                 if (this.activeCategory !== 'all' && this.activeCategory !== cat) {
                     return false;
                 }
                 const q = (this.searchQuery || '').trim().toLowerCase();
                 if (q === '') {
                     return true;
                 }
                 return (this.sectionKeywords[cat] || '').includes(q);
             },
             get visibleSectionCount() {
                 return ['profile', 'attendance', 'storage', 'holidays'].filter((c) => this.matchesSection(c)).length;
             },
             resetFilters() {
                 this.activeCategory = 'all';
                 this.searchQuery = '';
             },
             detectingGps: false,
             gpsNotice: '',
             gpsError: false,
             detectCurrentLocation() {
                 if (!navigator.geolocation) {
                     this.gpsError = true;
                     this.gpsNotice = @js(__('company.auto_detect_failed'));
                     return;
                 }
                 this.detectingGps = true;
                 this.gpsNotice = @js(__('company.auto_detecting'));
                 this.gpsError = false;
                 navigator.geolocation.getCurrentPosition(
                     (pos) => {
                         this.lat = pos.coords.latitude.toFixed(7);
                         this.lng = pos.coords.longitude.toFixed(7);
                         this.detectingGps = false;
                         this.gpsError = false;
                         this.gpsNotice = @js(__('company.auto_detect_success'));
                     },
                     () => {
                         this.detectingGps = false;
                         this.gpsError = true;
                         this.gpsNotice = @js(__('company.auto_detect_failed'));
                     },
                     { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                 );
             }
         }">

        @if (session('success'))
            <div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 p-4 text-sm flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                    <span class="font-medium">{{ session('success') }}</span>
                </div>
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl border border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300 p-4 text-xs space-y-1 shadow-sm">
                @foreach ($errors->all() as $err)
                    <div class="font-medium">&bull; {{ $err }}</div>
                @endforeach
            </div>
        @endif

        @if ($company)
            <!-- Bilah Pencarian & Tab Kategori Pengaturan Perusahaan -->
            <div class="bg-surface border border-line rounded-2xl p-4 shadow-sm space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="relative flex-1">
                        <span class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-ink-muted">
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                            </svg>
                        </span>
                        <input id="company-settings-search"
                               type="text"
                               x-model.debounce.150ms="searchQuery"
                               placeholder="{{ __('company.search_placeholder') }}"
                               class="w-full rounded-2xl border border-line bg-surface-muted pl-10 pr-10 py-2 text-xs sm:text-sm text-ink placeholder:text-ink-faint focus:outline-none focus:ring-2 focus:ring-accent" />
                        <button type="button"
                                x-show="searchQuery !== ''"
                                x-cloak
                                @click="searchQuery = ''"
                                title="{{ __('company.search_clear') }}"
                                aria-label="{{ __('company.search_clear') }}"
                                class="absolute inset-y-0 right-2.5 flex items-center text-ink-muted hover:text-ink cursor-pointer">
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="text-2xs font-mono font-semibold text-ink-muted shrink-0 px-3 py-1.5 rounded-xl bg-surface-muted border border-line text-center">
                        <span x-text="visibleSectionCount">4</span> / 4
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button"
                            @click="activeCategory = 'all'"
                            :class="activeCategory === 'all' ? 'bg-accent text-accent-fg border-accent shadow-xs' : 'bg-surface-muted text-ink hover:bg-surface border-line'"
                            class="px-3.5 py-1.5 rounded-xl border text-xs font-semibold transition-colors cursor-pointer">
                        {{ __('company.cat_all') }}
                    </button>
                    <button type="button"
                            @click="activeCategory = 'profile'"
                            :class="activeCategory === 'profile' ? 'bg-accent text-accent-fg border-accent shadow-xs' : 'bg-surface-muted text-ink hover:bg-surface border-line'"
                            class="px-3.5 py-1.5 rounded-xl border text-xs font-semibold transition-colors cursor-pointer">
                        {{ __('company.cat_profile') }}
                    </button>
                    <button type="button"
                            @click="activeCategory = 'attendance'"
                            :class="activeCategory === 'attendance' ? 'bg-accent text-accent-fg border-accent shadow-xs' : 'bg-surface-muted text-ink hover:bg-surface border-line'"
                            class="px-3.5 py-1.5 rounded-xl border text-xs font-semibold transition-colors cursor-pointer">
                        {{ __('company.cat_attendance') }}
                    </button>
                    <button type="button"
                            @click="activeCategory = 'storage'"
                            :class="activeCategory === 'storage' ? 'bg-accent text-accent-fg border-accent shadow-xs' : 'bg-surface-muted text-ink hover:bg-surface border-line'"
                            class="px-3.5 py-1.5 rounded-xl border text-xs font-semibold transition-colors cursor-pointer">
                        {{ __('company.cat_storage') }}
                    </button>
                    <button type="button"
                            @click="activeCategory = 'holidays'"
                            :class="activeCategory === 'holidays' ? 'bg-accent text-accent-fg border-accent shadow-xs' : 'bg-surface-muted text-ink hover:bg-surface border-line'"
                            class="px-3.5 py-1.5 rounded-xl border text-xs font-semibold transition-colors cursor-pointer">
                        {{ __('company.cat_holidays') }}
                    </button>
                </div>
            </div>

            <!-- Empty Search State -->
            <div x-show="visibleSectionCount === 0"
                 x-cloak
                 class="bg-surface border border-dashed border-line-strong rounded-2xl p-8 text-center space-y-3">
                <div class="mx-auto w-10 h-10 rounded-full bg-surface-muted border border-line flex items-center justify-center text-ink-muted">
                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                </div>
                <div class="space-y-1">
                    <h3 class="text-sm font-bold text-ink">{{ __('company.search_empty_title') }}</h3>
                    <p class="text-xs text-ink-muted max-w-md mx-auto">{{ __('company.search_empty_desc') }}</p>
                </div>
                <button type="button"
                        @click="resetFilters()"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl border border-line bg-surface-muted hover:bg-surface text-xs font-semibold text-ink transition cursor-pointer">
                    {{ __('company.search_reset_btn') }}
                </button>
            </div>

            <form method="POST"
                  action="{{ route('settings.company.update') }}"
                  x-show="matchesSection('profile') || matchesSection('attendance') || matchesSection('storage')"
                  class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                @csrf
                @method('PUT')

                <!-- Kolom Kiri: Profil Properti, Presensi & Geofence, Penyimpanan -->
                <div :class="matchesSection('attendance') ? 'lg:col-span-2' : 'lg:col-span-3'" class="lg:col-span-2 space-y-6">
                    <!-- Kategori 1: Profil Properti -->
                    <div x-show="matchesSection('profile')"
                         class="bg-surface border border-line rounded-2xl p-6 shadow-sm space-y-4">
                        <div class="border-b border-line pb-3">
                            <h3 class="text-sm font-bold text-ink">{{ __('company.section_profile') }}</h3>
                            <p class="text-xs text-ink-muted mt-0.5">{{ __('company.section_profile_desc') }}</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="sm:col-span-2">
                                <label for="company-name" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.name') }} <span class="text-red-500">*</span>
                                </label>
                                <input id="company-name"
                                       type="text"
                                       name="name"
                                       value="{{ old('name', $company->name) }}"
                                       required
                                       maxlength="150"
                                       class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                            </div>

                            <div>
                                <label for="company-code" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.code') }} <span class="text-red-500">*</span>
                                </label>
                                <input id="company-code"
                                       type="text"
                                       name="code"
                                       value="{{ old('code', $company->code) }}"
                                       required
                                       maxlength="20"
                                       class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono uppercase text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="sm:col-span-2">
                                <label for="company-address" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.address') }}
                                </label>
                                <input id="company-address"
                                       type="text"
                                       name="address"
                                       value="{{ old('address', $company->address) }}"
                                       maxlength="255"
                                       placeholder="{{ __('company.address_placeholder') }}"
                                       class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                            </div>

                            <div>
                                <label for="company-timezone" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.timezone') }} <span class="text-red-500">*</span>
                                </label>
                                @php
                                    $selectedTz = old('timezone', $company->timezone ?? 'Asia/Jakarta');
                                @endphp
                                <select id="company-timezone"
                                        name="timezone"
                                        required
                                        class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                                    <option value="Asia/Jakarta" @selected($selectedTz === 'Asia/Jakarta')>{{ __('company.tz_wib') }}</option>
                                    <option value="Asia/Makassar" @selected($selectedTz === 'Asia/Makassar')>{{ __('company.tz_wita') }}</option>
                                    <option value="Asia/Jayapura" @selected($selectedTz === 'Asia/Jayapura')>{{ __('company.tz_wit') }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Kategori 3: Penyimpanan Bukti Presensi & Kebijakan Retensi -->
                    <div x-show="matchesSection('storage')"
                         class="bg-surface border border-line rounded-2xl p-6 shadow-sm space-y-4">
                        <div class="border-b border-line pb-3">
                            <h3 class="text-sm font-bold text-ink">{{ __('company.storage_usage_heading') }}</h3>
                            <p class="text-xs text-ink-muted mt-0.5">{{ __('company.storage_usage_desc') }}</p>
                        </div>

                        @php
                            $usedMb = $company->getStorageUsageMb();
                            $limitMb = (int) ($company->storage_limit_mb ?? 1024);
                            $usagePercent = $limitMb > 0 ? min(100, round(($usedMb / $limitMb) * 100, 1)) : 0;
                            $isSuperAdminUser = $isSuperAdmin ?? auth()->user()?->isSuperAdmin();
                        @endphp

                        <!-- Visual Progress Bar Penggunaan Penyimpanan -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between text-xs font-semibold text-ink">
                                <span>{{ __('company.storage_usage_label', ['used' => $usedMb, 'limit' => $limitMb, 'percent' => $usagePercent]) }}</span>
                                <span class="font-mono text-2xs {{ $usagePercent >= 90 ? 'text-red-600' : 'text-emerald-600 dark:text-emerald-400' }}">{{ $usagePercent }}%</span>
                            </div>
                            <div class="w-full h-3 rounded-full bg-surface-muted border border-line overflow-hidden p-0.5">
                                <div class="h-full rounded-full transition-all duration-300 {{ $usagePercent >= 90 ? 'bg-red-500' : ($usagePercent >= 75 ? 'bg-amber-500' : 'bg-accent') }}"
                                     style="width: {{ $usagePercent }}%;"></div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                            <div>
                                <label for="company-photo-retention" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.photo_retention_label') }} <span class="text-red-500">*</span>
                                </label>
                                @php
                                    $retention = (int) old('photo_retention_months', $company->photo_retention_months ?? 3);
                                @endphp
                                <select id="company-photo-retention"
                                        name="photo_retention_months"
                                        required
                                        class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                                    <option value="1" @selected($retention === 1)>{{ __('company.retention_1_month') }}</option>
                                    <option value="3" @selected($retention === 3)>{{ __('company.retention_3_months') }}</option>
                                    <option value="6" @selected($retention === 6)>{{ __('company.retention_6_months') }}</option>
                                    <option value="12" @selected($retention === 12)>{{ __('company.retention_12_months') }}</option>
                                </select>
                            </div>

                            <div>
                                <label for="company-storage-limit" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.storage_limit_label') }}
                                    @if ($isSuperAdminUser)
                                        <span class="text-red-500">*</span>
                                    @endif
                                </label>
                                @if ($isSuperAdminUser)
                                    <input id="company-storage-limit"
                                           type="number"
                                           name="storage_limit_mb"
                                           min="10"
                                           max="1048576"
                                           value="{{ old('storage_limit_mb', $company->storage_limit_mb ?? 1024) }}"
                                           required
                                           class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                                    <p class="text-2xs text-ink-muted mt-1">{{ __('company.storage_limit_hint') }}</p>
                                @else
                                    <input id="company-storage-limit"
                                           type="number"
                                           disabled
                                           value="{{ $company->storage_limit_mb ?? 1024 }}"
                                           class="w-full rounded-2xl border border-line bg-surface-muted px-3.5 py-2 text-sm font-mono text-ink-muted cursor-not-allowed" />
                                    <p class="text-2xs text-ink-muted mt-1">{{ __('company.storage_limit_hint') }}</p>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Kategori 2: Presensi, Geofence GPS, & Kebijakan Penjadwalan -->
                    <div x-show="matchesSection('attendance')"
                         class="bg-surface border border-line rounded-2xl p-6 shadow-sm space-y-4">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-line pb-3">
                            <div>
                                <h3 class="text-sm font-bold text-ink">{{ __('company.section_geofence') }}</h3>
                                <p class="text-xs text-ink-muted mt-0.5">{{ __('company.section_geofence_desc') }}</p>
                            </div>

                            <button type="button"
                                    @click="detectCurrentLocation()"
                                    :disabled="detectingGps"
                                    class="inline-flex items-center gap-2 px-3.5 py-2 rounded-2xl border border-line bg-surface-muted hover:bg-surface text-ink text-xs font-semibold transition-colors shadow-2xs cursor-pointer shrink-0">
                                <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                                </svg>
                                <span x-text="detectingGps ? @js(__('company.auto_detecting')) : @js(__('company.auto_detect_gps'))">
                                    {{ __('company.auto_detect_gps') }}
                                </span>
                            </button>
                        </div>

                        <div x-show="gpsNotice"
                             x-cloak
                             class="rounded-2xl border px-3.5 py-2.5 text-xs font-medium"
                             :class="gpsError ? 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'"
                             x-text="gpsNotice"></div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="company-latitude" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.latitude') }} <span class="text-red-500">*</span>
                                </label>
                                <input id="company-latitude"
                                       type="number"
                                       step="any"
                                       min="-90"
                                       max="90"
                                       name="latitude"
                                       x-model="lat"
                                       required
                                       class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                            </div>

                            <div>
                                <label for="company-longitude" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.longitude') }} <span class="text-red-500">*</span>
                                </label>
                                <input id="company-longitude"
                                       type="number"
                                       step="any"
                                       min="-180"
                                       max="180"
                                       name="longitude"
                                       x-model="lng"
                                       required
                                       class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                            </div>
                        </div>

                        <div class="space-y-2 pt-2">
                            <div class="flex items-center justify-between">
                                <label for="company-geofence-radius" class="block text-xs font-semibold text-ink">
                                    {{ __('company.geofence_radius') }} <span class="text-red-500">*</span>
                                </label>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-lg bg-surface-muted border border-line font-mono text-xs font-bold text-ink">
                                    <span x-text="radius"></span>&nbsp;m
                                </span>
                            </div>

                            <div class="flex items-center gap-3">
                                <input type="range"
                                       min="20"
                                       max="1000"
                                       step="5"
                                       x-model="radius"
                                       aria-label="{{ __('company.geofence_radius') }} Slider"
                                       class="flex-1 accent-accent cursor-pointer" />
                                <input id="company-geofence-radius"
                                       type="number"
                                       name="geofence_radius"
                                       min="20"
                                       max="1000"
                                       x-model="radius"
                                       required
                                       class="w-24 rounded-2xl border border-line bg-surface px-3 py-1.5 text-sm font-mono text-ink text-right focus:outline-none focus:ring-2 focus:ring-accent" />
                            </div>
                            <p class="text-2xs text-ink-muted">{{ __('company.geofence_radius_hint') }}</p>
                        </div>

                        <!-- Toggle Penjadwalan Otomatis saat Presensi (Auto-Assign Shift on Clock-In) -->
                        <div class="pt-4 border-t border-line flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <label for="company-auto-assign-shift" class="text-xs font-bold text-ink cursor-pointer">
                                        {{ __('company.auto_assign_shift_label') }}
                                    </label>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-2xs font-semibold border"
                                          :class="autoAssignShiftOnClockIn ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/30' : 'bg-amber-500/10 text-amber-800 dark:text-amber-300 border-amber-500/30'"
                                          x-text="autoAssignShiftOnClockIn ? @js(__('company.toggle_active_badge')) : @js(__('company.toggle_inactive_badge'))"></span>
                                </div>
                                <p class="text-xs text-ink-muted leading-relaxed">
                                    {{ __('company.auto_assign_shift_desc') }}
                                </p>
                            </div>

                            <div class="shrink-0 flex items-center">
                                <input type="hidden" name="auto_assign_shift_on_clock_in" value="0" />
                                <label for="company-auto-assign-shift" class="relative inline-flex items-center cursor-pointer">
                                    <input id="company-auto-assign-shift"
                                           type="checkbox"
                                           name="auto_assign_shift_on_clock_in"
                                           value="1"
                                           x-model="autoAssignShiftOnClockIn"
                                           class="sr-only peer" />
                                    <div class="w-11 h-6 bg-surface-muted border border-line-strong peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-accent rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[3px] after:start-[3px] after:bg-white after:border after:border-line after:rounded-full after:h-4.5 after:w-4.5 after:transition-all peer-checked:bg-accent"></div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end bg-surface border border-line rounded-2xl px-6 py-4 shadow-sm">
                        <button type="submit"
                                class="px-5 py-2.5 rounded-2xl bg-accent text-accent-fg text-xs font-semibold shadow-md shadow-accent/20 hover:opacity-95 transition-all cursor-pointer">
                            {{ __('company.btn_save') }}
                        </button>
                    </div>
                </div>

                <!-- Kolom Kanan: Visualisasi Radar Radius Geofence -->
                <div x-show="matchesSection('attendance')" class="space-y-6">
                    <div class="bg-surface border border-line rounded-2xl p-6 shadow-sm flex flex-col justify-between space-y-4">
                        <div>
                            <h3 class="text-sm font-bold text-ink">{{ __('company.geofence_preview_title') }}</h3>
                            <p class="text-xs text-ink-muted mt-0.5">{{ __('company.geofence_preview_desc') }}</p>
                        </div>

                        <!-- Radar Geofence Interactive Preview -->
                        <div class="relative w-full aspect-square max-w-[260px] mx-auto rounded-2xl bg-surface-muted border border-line flex items-center justify-center overflow-hidden">
                            <!-- Grid Lines -->
                            <div class="absolute inset-0 flex items-center justify-center pointer-events-none opacity-30">
                                <div class="w-full h-px bg-line-strong"></div>
                            </div>
                            <div class="absolute inset-0 flex items-center justify-center pointer-events-none opacity-30">
                                <div class="h-full w-px bg-line-strong"></div>
                            </div>

                            <!-- Outer Reference Rings -->
                            <div class="w-52 h-52 rounded-full border border-dashed border-line pointer-events-none absolute"></div>
                            <div class="w-32 h-32 rounded-full border border-dashed border-line pointer-events-none absolute"></div>

                            <!-- Dynamic Geofence Circle -->
                            <div class="rounded-full bg-emerald-500/15 border-2 border-emerald-500 transition-all duration-200 flex items-center justify-center"
                                 :style="`width: ${Math.max(24, Math.min(92, Math.round((radius / 1000) * 90)))}%; height: ${Math.max(24, Math.min(92, Math.round((radius / 1000) * 90)))}%;`">
                                <div class="w-3.5 h-3.5 rounded-full bg-emerald-500 ring-4 ring-emerald-500/30"></div>
                            </div>

                            <!-- Radius Badge -->
                            <div class="absolute bottom-2.5 inset-x-2.5 flex items-center justify-between px-2.5 py-1.5 rounded-xl bg-surface/90 backdrop-blur-xs border border-line text-[11px] font-mono text-ink">
                                <span x-text="`${lat}, ${lng}`" class="truncate"></span>
                                <span class="font-bold text-emerald-600 dark:text-emerald-400 shrink-0 ml-2" x-text="`${radius}m`"></span>
                            </div>
                        </div>

                        <div class="rounded-2xl bg-surface-muted/60 border border-line p-3.5 text-xs space-y-1.5">
                            <div class="flex items-center justify-between">
                                <span class="text-ink-muted">{{ __('company.code') }}</span>
                                <span class="font-mono font-bold text-ink">{{ $company->code }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-ink-muted">{{ __('company.timezone') }}</span>
                                <span class="font-semibold text-ink">{{ $company->timezone ?? 'Asia/Jakarta' }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Kalender Hari Libur & Event Khusus Per Perusahaan -->
            @php
                $canManageHolidays = auth()->check() && auth()->user()->isAdminOrAbove();
                $companyHolidays = $holidays ?? \App\Models\PublicHoliday::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->orderBy('holiday_date')
                    ->get();
            @endphp
            <div x-show="matchesSection('holidays')"
                 x-data="{
                    editHolidayId: null,
                    editHolidayDate: '',
                    editHolidayName: '',
                    editHolidayIsNational: false,
                    deleteHolidayId: null,
                    deleteHolidayName: '',
                    deleteHolidayDate: '',
                    openEditHoliday(id, date, name, isNational) {
                        this.editHolidayId = id;
                        this.editHolidayDate = date;
                        this.editHolidayName = name;
                        this.editHolidayIsNational = Boolean(isNational);
                        this.$dispatch('open-modal', 'edit-holiday-modal');
                    },
                    openDeleteHoliday(id, date, name) {
                        this.deleteHolidayId = id;
                        this.deleteHolidayDate = date;
                        this.deleteHolidayName = name;
                        this.$dispatch('open-modal', 'delete-holiday-modal');
                    }
                 }"
                 class="bg-surface border border-line rounded-2xl p-6 shadow-sm space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-line pb-4">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                <path d="M12 14l1.25 2.5 2.75.4-2 1.95.47 2.75L12 20.3l-2.47 1.3.47-2.75-2-1.95 2.75-.4L12 14z"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-ink">{{ __('company.holiday_section_title') }}</h3>
                            <p class="text-xs text-ink-muted mt-0.5">{{ __('company.holiday_section_desc') }}</p>
                        </div>
                    </div>

                    @if ($canManageHolidays)
                        <button type="button"
                                @click="$dispatch('open-modal', 'create-holiday-modal')"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-accent text-accent-fg text-xs font-semibold shadow-sm hover:opacity-95 transition-all cursor-pointer shrink-0">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            <span>{{ __('company.holiday_btn_add') }}</span>
                        </button>
                    @endif
                </div>

                @if ($companyHolidays->isEmpty())
                    <div class="rounded-2xl border border-dashed border-line bg-surface-muted/40 p-8 text-center">
                        <p class="text-xs text-ink-muted">{{ __('company.holiday_empty') }}</p>
                    </div>
                @else
                    <x-ui.table :striped="true" class="rounded-2xl border border-line bg-surface shadow-2xs overflow-hidden">
                        <x-slot name="header">
                            <tr class="bg-surface-muted border-b border-line">
                                <th class="py-2.5 px-4 text-left text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.holiday_col_date') }}</th>
                                <th class="py-2.5 px-4 text-left text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.holiday_col_name') }}</th>
                                <th class="py-2.5 px-4 text-left text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.holiday_col_type') }}</th>
                                @if ($canManageHolidays)
                                    <th class="py-2.5 px-4 text-right text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.holiday_col_actions') }}</th>
                                @endif
                            </tr>
                        </x-slot>
                        @foreach ($companyHolidays as $holiday)
                            @php
                                $dateObj = $holiday->holiday_date instanceof \Carbon\CarbonInterface
                                    ? $holiday->holiday_date
                                    : \Carbon\Carbon::parse($holiday->holiday_date);
                                $dateIso = $dateObj->toDateString();
                            @endphp
                            <tr class="hover:bg-surface-muted/50 transition-colors">
                                <td class="py-2.5 px-4 font-mono text-xs font-semibold text-ink whitespace-nowrap">
                                    {{ $dateIso }}
                                </td>
                                <td class="py-2.5 px-4 text-xs font-medium text-ink">
                                    {{ $holiday->name }}
                                </td>
                                <td class="py-2.5 px-4 whitespace-nowrap">
                                    @if ($holiday->is_national)
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-2xs font-semibold bg-rose-500/10 text-rose-700 dark:text-rose-300 border border-rose-500/20">
                                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                            </svg>
                                            <span>{{ __('company.holiday_type_national') }}</span>
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-2xs font-semibold bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/20">
                                            <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"></path>
                                            </svg>
                                            <span>{{ __('company.holiday_type_company_event') }}</span>
                                        </span>
                                    @endif
                                </td>
                                @if ($canManageHolidays)
                                    <td class="py-2.5 px-4 text-right whitespace-nowrap">
                                        <div class="inline-flex items-center justify-end gap-1.5">
                                            <button type="button"
                                                    @click="openEditHoliday({{ $holiday->id }}, @js($dateIso), @js($holiday->name), {{ $holiday->is_national ? 'true' : 'false' }})"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl border border-line bg-surface hover:bg-surface-muted text-xs font-medium text-ink transition-colors cursor-pointer"
                                                    title="{{ __('company.holiday_modal_edit_title') }}">
                                                <svg class="w-3.5 h-3.5 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"></path>
                                                    <path d="M19.5 7.125L16.862 4.487"></path>
                                                </svg>
                                                <span>{{ __('common.edit') }}</span>
                                            </button>
                                            <button type="button"
                                                    @click="openDeleteHoliday({{ $holiday->id }}, @js($dateIso), @js($holiday->name))"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl border border-red-500/25 bg-red-500/10 hover:bg-red-500/20 text-xs font-medium text-red-600 dark:text-red-400 transition-colors cursor-pointer"
                                                    title="{{ __('company.holiday_modal_delete_title') }}">
                                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"></path>
                                                </svg>
                                                <span>{{ __('common.delete') }}</span>
                                            </button>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif

                @if ($canManageHolidays)
                    <!-- Modal Tambah Hari Libur / Event Khusus -->
                    <x-ui.modal name="create-holiday-modal" :title="__('company.holiday_modal_add_title')" maxWidth="md">
                        <form method="POST" action="{{ route('settings.holidays.store') }}" class="space-y-4">
                            @csrf
                            <div>
                                <label for="create-holiday-date" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.holiday_label_date') }} <span class="text-red-500">*</span>
                                </label>
                                <input id="create-holiday-date"
                                       type="date"
                                       name="holiday_date"
                                       required
                                       class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                            </div>

                            <div>
                                <label for="create-holiday-name" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.holiday_label_name') }} <span class="text-red-500">*</span>
                                </label>
                                <input id="create-holiday-name"
                                       type="text"
                                       name="name"
                                       required
                                       maxlength="150"
                                       placeholder="{{ __('company.holiday_placeholder_name') }}"
                                       class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                            </div>

                            <div class="rounded-2xl border border-line bg-surface-muted/50 p-3.5">
                                <label class="flex items-start gap-2.5 cursor-pointer">
                                    <input type="hidden" name="is_national" value="0" />
                                    <input type="checkbox"
                                           name="is_national"
                                           value="1"
                                           class="mt-0.5 rounded border-line text-accent focus:ring-accent" />
                                    <div>
                                        <span class="block text-xs font-semibold text-ink">{{ __('company.holiday_label_is_national') }}</span>
                                        <span class="block text-2xs text-ink-muted mt-0.5">{{ __('company.holiday_hint_is_national') }}</span>
                                    </div>
                                </label>
                            </div>

                            <div class="flex items-center justify-end gap-2 pt-3 border-t border-line">
                                <button type="button"
                                        @click="$dispatch('close-modal', 'create-holiday-modal')"
                                        class="px-4 py-2 rounded-2xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition-colors cursor-pointer">
                                    {{ __('common.cancel') }}
                                </button>
                                <button type="submit"
                                        class="px-4 py-2 rounded-2xl bg-accent text-accent-fg text-xs font-semibold shadow-sm hover:opacity-95 transition-all cursor-pointer">
                                    {{ __('company.holiday_btn_save') }}
                                </button>
                            </div>
                        </form>
                    </x-ui.modal>

                    <!-- Modal Edit Hari Libur / Event Khusus -->
                    <x-ui.modal name="edit-holiday-modal" :title="__('company.holiday_modal_edit_title')" maxWidth="md">
                        <form method="POST"
                              :action="`{{ url('/settings/holidays') }}/${editHolidayId}`"
                              class="space-y-4">
                            @csrf
                            @method('PUT')
                            <div>
                                <label for="edit-holiday-date" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.holiday_label_date') }} <span class="text-red-500">*</span>
                                </label>
                                <input id="edit-holiday-date"
                                       type="date"
                                       name="holiday_date"
                                       x-model="editHolidayDate"
                                       required
                                       class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                            </div>

                            <div>
                                <label for="edit-holiday-name" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.holiday_label_name') }} <span class="text-red-500">*</span>
                                </label>
                                <input id="edit-holiday-name"
                                       type="text"
                                       name="name"
                                       x-model="editHolidayName"
                                       required
                                       maxlength="150"
                                       class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                            </div>

                            <div class="rounded-2xl border border-line bg-surface-muted/50 p-3.5">
                                <label class="flex items-start gap-2.5 cursor-pointer">
                                    <input type="hidden" name="is_national" value="0" />
                                    <input type="checkbox"
                                           name="is_national"
                                           value="1"
                                           x-model="editHolidayIsNational"
                                           class="mt-0.5 rounded border-line text-accent focus:ring-accent" />
                                    <div>
                                        <span class="block text-xs font-semibold text-ink">{{ __('company.holiday_label_is_national') }}</span>
                                        <span class="block text-2xs text-ink-muted mt-0.5">{{ __('company.holiday_hint_is_national') }}</span>
                                    </div>
                                </label>
                            </div>

                            <div class="flex items-center justify-end gap-2 pt-3 border-t border-line">
                                <button type="button"
                                        @click="$dispatch('close-modal', 'edit-holiday-modal')"
                                        class="px-4 py-2 rounded-2xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition-colors cursor-pointer">
                                    {{ __('common.cancel') }}
                                </button>
                                <button type="submit"
                                        class="px-4 py-2 rounded-2xl bg-accent text-accent-fg text-xs font-semibold shadow-sm hover:opacity-95 transition-all cursor-pointer">
                                    {{ __('company.holiday_btn_update') }}
                                </button>
                            </div>
                        </form>
                    </x-ui.modal>

                    <!-- Modal Hapus Hari Libur / Event Khusus -->
                    <x-ui.modal name="delete-holiday-modal" :title="__('company.holiday_modal_delete_title')" maxWidth="sm">
                        <form method="POST"
                              :action="`{{ url('/settings/holidays') }}/${deleteHolidayId}`"
                              class="space-y-4">
                            @csrf
                            @method('DELETE')
                            <p class="text-xs text-ink-muted leading-relaxed">
                                {{ __('company.holiday_modal_delete_desc') }}
                            </p>
                            <div class="rounded-2xl border border-line bg-surface-muted/50 px-3.5 py-2.5 text-xs">
                                <div class="font-mono font-semibold text-ink" x-text="deleteHolidayDate"></div>
                                <div class="font-medium text-ink-muted mt-0.5" x-text="deleteHolidayName"></div>
                            </div>

                            <div class="flex items-center justify-end gap-2 pt-3 border-t border-line">
                                <button type="button"
                                        @click="$dispatch('close-modal', 'delete-holiday-modal')"
                                        class="px-4 py-2 rounded-2xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition-colors cursor-pointer">
                                    {{ __('common.cancel') }}
                                </button>
                                <button type="submit"
                                        class="px-4 py-2 rounded-2xl bg-red-600 hover:bg-red-700 text-white text-xs font-semibold shadow-sm transition-colors cursor-pointer">
                                    {{ __('company.holiday_btn_delete') }}
                                </button>
                            </div>
                        </form>
                    </x-ui.modal>
                @endif
            </div>
        @endif

        @if ($isSuperAdmin ?? auth()->user()?->isSuperAdmin())
            <!-- Konsol Super Admin: Daftar Tenant Terdaftar -->
            <div class="bg-surface border border-line rounded-2xl p-6 shadow-sm space-y-4">
                <div class="flex items-center justify-between border-b border-line pb-3">
                    <div>
                        <h3 class="text-sm font-bold text-ink">{{ __('company.section_new_tenant') }}</h3>
                        <p class="text-xs text-ink-muted mt-0.5">{{ __('company.section_new_tenant_desc') }}</p>
                    </div>
                </div>

                @if (isset($allCompanies) && $allCompanies->isNotEmpty())
                    <x-ui.table :striped="true" class="rounded-2xl border border-line bg-surface shadow-2xs overflow-hidden">
                        <x-slot name="header">
                            <tr class="bg-surface-muted border-b border-line">
                                <th class="py-2.5 px-4 text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.code') }}</th>
                                <th class="py-2.5 px-4 text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.name') }}</th>
                                <th class="py-2.5 px-4 text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.timezone') }}</th>
                                <th class="py-2.5 px-4 text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.geofence_radius') }}</th>
                            </tr>
                        </x-slot>
                        @foreach ($allCompanies as $tenant)
                            <tr class="hover:bg-surface-muted/50 transition-colors">
                                <td class="py-2.5 px-4 font-mono font-bold text-ink">{{ $tenant->code }}</td>
                                <td class="py-2.5 px-4 font-medium text-ink">{{ $tenant->name }}</td>
                                <td class="py-2.5 px-4 text-xs text-ink-muted">{{ $tenant->timezone ?? 'Asia/Jakarta' }}</td>
                                <td class="py-2.5 px-4 font-mono text-xs text-ink">{{ $tenant->geofence_radius ?? $tenant->geofence_radius_meters }} m</td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </div>

            <!-- Modal Registrasi Tenant Baru (Super Admin) -->
            <x-ui.modal name="create-tenant-modal" :title="__('company.section_new_tenant')" maxWidth="lg">
                <form method="POST" action="{{ route('admin.companies.store') }}" class="space-y-4">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2">
                            <label for="new-tenant-name" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('company.name') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="new-tenant-name"
                                   type="text"
                                   name="name"
                                   required
                                   maxlength="150"
                                   class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                        </div>

                        <div>
                            <label for="new-tenant-code" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('company.code') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="new-tenant-code"
                                   type="text"
                                   name="code"
                                   required
                                   maxlength="20"
                                   class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono uppercase text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2">
                            <label for="new-tenant-address" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('company.address') }}
                            </label>
                            <input id="new-tenant-address"
                                   type="text"
                                   name="address"
                                   maxlength="255"
                                   class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                        </div>

                        <div>
                            <label for="new-tenant-timezone" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('company.timezone') }} <span class="text-red-500">*</span>
                            </label>
                            <select id="new-tenant-timezone"
                                    name="timezone"
                                    required
                                    class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                                <option value="Asia/Jakarta">{{ __('company.tz_wib') }}</option>
                                <option value="Asia/Makassar">{{ __('company.tz_wita') }}</option>
                                <option value="Asia/Jayapura">{{ __('company.tz_wit') }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label for="new-tenant-lat" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('company.latitude') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="new-tenant-lat"
                                   type="number"
                                   step="any"
                                   min="-90"
                                   max="90"
                                   name="latitude"
                                   value="-6.2000000"
                                   required
                                   class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                        </div>

                        <div>
                            <label for="new-tenant-lng" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('company.longitude') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="new-tenant-lng"
                                   type="number"
                                   step="any"
                                   min="-180"
                                   max="180"
                                   name="longitude"
                                   value="106.8166667"
                                   required
                                   class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                        </div>

                        <div>
                            <label for="new-tenant-radius" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('company.geofence_radius') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="new-tenant-radius"
                                   type="number"
                                   min="20"
                                   max="1000"
                                   name="geofence_radius"
                                   value="100"
                                   required
                                   class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="new-tenant-retention" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('company.photo_retention_label') }} <span class="text-red-500">*</span>
                            </label>
                            <select id="new-tenant-retention"
                                    name="photo_retention_months"
                                    required
                                    class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                                <option value="1">{{ __('company.retention_1_month') }}</option>
                                <option value="3" selected>{{ __('company.retention_3_months') }}</option>
                                <option value="6">{{ __('company.retention_6_months') }}</option>
                                <option value="12">{{ __('company.retention_12_months') }}</option>
                            </select>
                        </div>

                        <div>
                            <label for="new-tenant-storage-limit" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('company.storage_limit_label') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="new-tenant-storage-limit"
                                   type="number"
                                   name="storage_limit_mb"
                                   min="10"
                                   max="1048576"
                                   value="1024"
                                   required
                                   class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-line">
                        <button type="button"
                                @click="$dispatch('close-modal', 'create-tenant-modal')"
                                class="px-4 py-2 rounded-2xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition-colors cursor-pointer">
                            {{ __('common.cancel') }}
                        </button>
                        <button type="submit"
                                class="px-4 py-2 rounded-2xl bg-accent text-accent-fg text-xs font-semibold shadow-sm hover:opacity-95 transition-all cursor-pointer">
                            {{ __('company.btn_register_tenant') }}
                        </button>
                    </div>
                </form>
            </x-ui.modal>
        @endif
    </div>
</x-app-layout>
