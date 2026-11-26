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

    @php
        $canManageBackups = auth()->check() && in_array(auth()->user()->role, ['super_user', 'super_admin'], true);
    @endphp

    <div class="space-y-6"
         x-data="{
             lat: '{{ old('latitude', $company?->latitude ?? -6.2000000) }}',
             lng: '{{ old('longitude', $company?->longitude ?? 106.8166667) }}',
             radius: {{ (int) old('geofence_radius', $company?->geofence_radius ?? $company?->geofence_radius_meters ?? 100) }},
             autoAssignShiftOnClockIn: {{ Js::from((bool) old('auto_assign_shift_on_clock_in', $company?->auto_assign_shift_on_clock_in ?? true)) }},
             canManageBackups: {{ Js::from($canManageBackups) }},
             activeCategory: 'all',
             searchQuery: '',
             sectionKeywords: {
                 profile: @js(strtolower(__('company.section_profile') . ' ' . __('company.section_profile_desc') . ' ' . __('company.name') . ' ' . __('company.code') . ' ' . __('company.address') . ' ' . __('company.timezone') . ' ' . __('company.auto_logout_minutes_label') . ' profil properti tenant kode alamat zona waktu auto logout idle timeout sesi')),
                 attendance: @js(strtolower(__('company.section_geofence') . ' ' . __('company.section_geofence_desc') . ' ' . __('company.geofence_preview_title') . ' ' . __('company.auto_assign_shift_label') . ' ' . __('company.auto_assign_shift_desc') . ' ' . __('company.latitude') . ' ' . __('company.longitude') . ' ' . __('company.geofence_radius') . ' presensi geofence gps radar auto-schedule shift clock-in')),
                 storage: @js(strtolower(__('company.storage_usage_heading') . ' ' . __('company.storage_usage_desc') . ' ' . __('company.photo_retention_label') . ' ' . __('company.storage_limit_label') . ' penyimpanan retensi foto storage quota')),
                 holidays: @js(strtolower(__('company.holiday_section_title') . ' ' . __('company.holiday_section_desc') . ' hari libur nasional event dp calendar holiday')),
                 tags: @js(strtolower(__('company.tag_section_title') . ' ' . __('company.tag_section_desc') . ' ' . __('company.cat_tags') . ' tag karyawan employee tags daily worker casual badge')),
                 backup: @js(strtolower(__('company.backup_section_title') . ' ' . __('company.backup_section_desc') . ' ' . __('company.cat_backup') . ' backup restore jsonl zip portabilitas portability export import'))
             },
             matchesSection(cat) {
                 if (cat === 'backup' && !this.canManageBackups) {
                     return false;
                 }
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
                 const cats = this.canManageBackups
                     ? ['profile', 'attendance', 'storage', 'holidays', 'tags', 'backup']
                     : ['profile', 'attendance', 'storage', 'holidays', 'tags'];
                 return cats.filter((c) => this.matchesSection(c)).length;
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
                        <span x-text="visibleSectionCount">{{ $canManageBackups ? 6 : 5 }}</span> / {{ $canManageBackups ? 6 : 5 }}
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
                    <button type="button"
                            @click="activeCategory = 'tags'"
                            :class="activeCategory === 'tags' ? 'bg-accent text-accent-fg border-accent shadow-xs' : 'bg-surface-muted text-ink hover:bg-surface border-line'"
                            class="px-3.5 py-1.5 rounded-xl border text-xs font-semibold transition-colors cursor-pointer">
                        {{ __('company.cat_tags') }}
                    </button>
                    @if(in_array(auth()->user()->role, ['super_user', 'super_admin']))
                        <button type="button"
                                @click="activeCategory = 'backup'"
                                :class="activeCategory === 'backup' ? 'bg-accent text-accent-fg border-accent shadow-xs' : 'bg-surface-muted text-ink hover:bg-surface border-line'"
                                class="px-3.5 py-1.5 rounded-xl border text-xs font-semibold transition-colors cursor-pointer">
                            {{ __('company.cat_backup') }}
                        </button>
                    @endif
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

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-line">
                            <div>
                                <label for="company-auto-logout-minutes" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.auto_logout_minutes_label') }} <span class="text-red-500">*</span>
                                </label>
                                <div class="flex items-center gap-2">
                                    <input id="company-auto-logout-minutes"
                                           type="number"
                                           name="auto_logout_minutes"
                                           min="5"
                                           max="1440"
                                           step="1"
                                           value="{{ old('auto_logout_minutes', $company->auto_logout_minutes ?? 30) }}"
                                           required
                                           class="w-36 rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                                    <span class="inline-flex items-center px-3 py-2 rounded-2xl border border-line bg-surface-muted text-xs font-semibold text-ink-muted">
                                        {{ __('company.auto_logout_unit_minutes') }}
                                    </span>
                                </div>
                                <p class="text-2xs text-ink-muted mt-1">{{ __('company.auto_logout_minutes_hint') }}</p>
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

            <!-- Manajemen Tag Karyawan (Daily Worker, Casual, MOD, dll.) -->
            @php
                $canManageTags = auth()->check() && auth()->user()->isAdminOrAbove();
                $companyTags = $tags ?? \App\Models\Tag::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->withCount('employees')
                    ->orderBy('name')
                    ->get();
            @endphp
            <div x-show="matchesSection('tags')"
                 x-data="{
                    createTagColor: '#0EA5E9',
                    editTagId: null,
                    editTagCode: '',
                    editTagName: '',
                    editTagColor: '#6B7280',
                    deleteTagId: null,
                    deleteTagName: '',
                    openEditTag(id, code, name, color) {
                        this.editTagId = id;
                        this.editTagCode = code;
                        this.editTagName = name;
                        this.editTagColor = color || '#6B7280';
                        this.$dispatch('open-modal', 'edit-tag-modal');
                    },
                    openDeleteTag(id, name) {
                        this.deleteTagId = id;
                        this.deleteTagName = name;
                        this.$dispatch('open-modal', 'delete-tag-modal');
                    }
                 }"
                 class="bg-surface border border-line rounded-2xl p-6 shadow-sm space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-line pb-4">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-xl bg-sky-500/10 border border-sky-500/20 text-sky-600 dark:text-sky-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" />
                                <path d="M6 6h.008v.008H6V6Z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-ink">{{ __('company.tag_section_title') }}</h3>
                            <p class="text-xs text-ink-muted mt-0.5">{{ __('company.tag_section_desc') }}</p>
                        </div>
                    </div>

                    @if ($canManageTags)
                        <button type="button"
                                @click="$dispatch('open-modal', 'create-tag-modal')"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-accent text-accent-fg text-xs font-semibold shadow-sm hover:opacity-95 transition-all cursor-pointer shrink-0">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            <span>{{ __('company.tag_btn_add') }}</span>
                        </button>
                    @endif
                </div>

                @if ($companyTags->isEmpty())
                    <div class="rounded-2xl border border-dashed border-line bg-surface-muted/40 p-8 text-center">
                        <p class="text-xs text-ink-muted">{{ __('company.tag_empty') }}</p>
                    </div>
                @else
                    <x-ui.table :striped="true" class="rounded-2xl border border-line bg-surface shadow-2xs overflow-hidden">
                        <x-slot name="header">
                            <tr class="bg-surface-muted border-b border-line">
                                <th class="py-2.5 px-4 text-left text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.tag_col_badge') }}</th>
                                <th class="py-2.5 px-4 text-left text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.tag_col_name') }}</th>
                                <th class="py-2.5 px-4 text-left text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.tag_col_staff_count') }}</th>
                                @if ($canManageTags)
                                    <th class="py-2.5 px-4 text-right text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.tag_col_actions') }}</th>
                                @endif
                            </tr>
                        </x-slot>
                        @foreach ($companyTags as $tagItem)
                            <tr class="hover:bg-surface-muted/50 transition-colors">
                                <td class="py-2.5 px-4 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-2xs font-semibold text-white shadow-2xs"
                                          style="background-color: {{ $tagItem->badge_color }};">
                                        {{ $tagItem->code }}
                                    </span>
                                </td>
                                <td class="py-2.5 px-4 text-xs font-medium text-ink">
                                    {{ $tagItem->name }}
                                </td>
                                <td class="py-2.5 px-4 text-xs font-mono text-ink-muted whitespace-nowrap">
                                    {{ $tagItem->employees_count ?? 0 }}
                                </td>
                                @if ($canManageTags)
                                    <td class="py-2.5 px-4 text-right whitespace-nowrap">
                                        <div class="inline-flex items-center justify-end gap-1.5">
                                            <button type="button"
                                                    @click="openEditTag({{ $tagItem->id }}, @js($tagItem->code), @js($tagItem->name), @js($tagItem->badge_color))"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl border border-line bg-surface hover:bg-surface-muted text-xs font-medium text-ink transition-colors cursor-pointer"
                                                    title="{{ __('company.tag_modal_edit_title') }}">
                                                <svg class="w-3.5 h-3.5 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"></path>
                                                    <path d="M19.5 7.125L16.862 4.487"></path>
                                                </svg>
                                                <span>{{ __('common.edit') }}</span>
                                            </button>
                                            <button type="button"
                                                    @click="openDeleteTag({{ $tagItem->id }}, @js($tagItem->name))"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl border border-red-500/25 bg-red-500/10 hover:bg-red-500/20 text-xs font-medium text-red-600 dark:text-red-400 transition-colors cursor-pointer"
                                                    title="{{ __('company.tag_modal_delete_title') }}">
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

                @if ($canManageTags)
                    <!-- Modal Tambah Tag Karyawan -->
                    <x-ui.modal name="create-tag-modal" :title="__('company.tag_modal_add_title')" maxWidth="md">
                        <form method="POST" action="{{ route('settings.tags.store') }}" class="space-y-4">
                            @csrf
                            <div>
                                <label for="create-tag-name" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.tag_label_name') }} <span class="text-red-500">*</span>
                                </label>
                                <input id="create-tag-name"
                                       type="text"
                                       name="name"
                                       required
                                       maxlength="100"
                                       placeholder="{{ __('company.tag_placeholder_name') }}"
                                       class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                            </div>

                            <div>
                                <label for="create-tag-code" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.tag_label_code') }}
                                </label>
                                <input id="create-tag-code"
                                       type="text"
                                       name="code"
                                       maxlength="50"
                                       placeholder="{{ __('company.tag_placeholder_code') }}"
                                       class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono uppercase text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                                <p class="text-2xs text-ink-muted mt-1">{{ __('company.tag_hint_code') }}</p>
                            </div>

                            <div>
                                <label for="create-tag-color" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.tag_label_color') }}
                                </label>
                                <div class="flex items-center gap-3">
                                    <input id="create-tag-color"
                                           type="color"
                                           x-model="createTagColor"
                                           class="h-10 w-14 rounded-xl border border-line bg-surface p-1 cursor-pointer" />
                                    <input type="text"
                                           name="badge_color"
                                           x-model="createTagColor"
                                           maxlength="20"
                                           pattern="^#[0-9A-Fa-f]{6}$"
                                           class="flex-1 rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                                </div>
                            </div>

                            <div class="flex items-center justify-end gap-2 pt-3 border-t border-line">
                                <button type="button"
                                        @click="$dispatch('close-modal', 'create-tag-modal')"
                                        class="px-4 py-2 rounded-2xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition-colors cursor-pointer">
                                    {{ __('common.cancel') }}
                                </button>
                                <button type="submit"
                                        class="px-4 py-2 rounded-2xl bg-accent text-accent-fg text-xs font-semibold shadow-sm hover:opacity-95 transition-all cursor-pointer">
                                    {{ __('company.tag_btn_save') }}
                                </button>
                            </div>
                        </form>
                    </x-ui.modal>

                    <!-- Modal Edit Tag Karyawan -->
                    <x-ui.modal name="edit-tag-modal" :title="__('company.tag_modal_edit_title')" maxWidth="md">
                        <form method="POST"
                              :action="`{{ url('/settings/tags') }}/${editTagId}`"
                              class="space-y-4">
                            @csrf
                            @method('PUT')
                            <div>
                                <label for="edit-tag-name" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.tag_label_name') }} <span class="text-red-500">*</span>
                                </label>
                                <input id="edit-tag-name"
                                       type="text"
                                       name="name"
                                       x-model="editTagName"
                                       required
                                       maxlength="100"
                                       class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                            </div>

                            <div>
                                <label for="edit-tag-code" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.tag_label_code') }}
                                </label>
                                <input id="edit-tag-code"
                                       type="text"
                                       name="code"
                                       x-model="editTagCode"
                                       maxlength="50"
                                       class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono uppercase text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                            </div>

                            <div>
                                <label for="edit-tag-color" class="block text-xs font-semibold text-ink mb-1">
                                    {{ __('company.tag_label_color') }}
                                </label>
                                <div class="flex items-center gap-3">
                                    <input id="edit-tag-color"
                                           type="color"
                                           x-model="editTagColor"
                                           class="h-10 w-14 rounded-xl border border-line bg-surface p-1 cursor-pointer" />
                                    <input type="text"
                                           name="badge_color"
                                           x-model="editTagColor"
                                           maxlength="20"
                                           pattern="^#[0-9A-Fa-f]{6}$"
                                           class="flex-1 rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                                </div>
                            </div>

                            <div class="flex items-center justify-end gap-2 pt-3 border-t border-line">
                                <button type="button"
                                        @click="$dispatch('close-modal', 'edit-tag-modal')"
                                        class="px-4 py-2 rounded-2xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition-colors cursor-pointer">
                                    {{ __('common.cancel') }}
                                </button>
                                <button type="submit"
                                        class="px-4 py-2 rounded-2xl bg-accent text-accent-fg text-xs font-semibold shadow-sm hover:opacity-95 transition-all cursor-pointer">
                                    {{ __('company.tag_btn_update') }}
                                </button>
                            </div>
                        </form>
                    </x-ui.modal>

                    <!-- Modal Hapus Tag Karyawan -->
                    <x-ui.modal name="delete-tag-modal" :title="__('company.tag_modal_delete_title')" maxWidth="sm">
                        <form method="POST"
                              :action="`{{ url('/settings/tags') }}/${deleteTagId}`"
                              class="space-y-4">
                            @csrf
                            @method('DELETE')
                            <p class="text-xs text-ink-muted leading-relaxed">
                                {{ __('company.tag_modal_delete_desc') }}
                            </p>
                            <div class="rounded-2xl border border-line bg-surface-muted/50 px-3.5 py-2.5 text-xs">
                                <div class="font-semibold text-ink" x-text="deleteTagName"></div>
                            </div>

                            <div class="flex items-center justify-end gap-2 pt-3 border-t border-line">
                                <button type="button"
                                        @click="$dispatch('close-modal', 'delete-tag-modal')"
                                        class="px-4 py-2 rounded-2xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition-colors cursor-pointer">
                                    {{ __('common.cancel') }}
                                </button>
                                <button type="submit"
                                        class="px-4 py-2 rounded-2xl bg-red-600 hover:bg-red-700 text-white text-xs font-semibold shadow-sm transition-colors cursor-pointer">
                                    {{ __('company.tag_btn_delete') }}
                                </button>
                            </div>
                        </form>
                    </x-ui.modal>
                @endif
            </div>

            @if(in_array(auth()->user()->role, ['super_user', 'super_admin']))
                <!-- Backup, Restore & Portabilitas (Eksklusif Super User & Super Admin) -->
                <div id="company-backup-console"
                     x-show="matchesSection('backup')"
                     x-data="{
                         backups: [],
                         loadingBackups: false,
                         creatingBackup: false,
                         restoringBackup: false,
                         deletingBackup: false,
                         pendingDeleteFilename: '',
                         selectedFile: null,
                         selectedFileName: '',
                         selectedFileSizeMb: '',
                         restoreConfirmedCheckbox: false,
                         feedbackMessage: '',
                         feedbackSuccess: false,
                         csrfToken: @js(csrf_token()),
                         indexUrl: @js(route('settings.company.backups.index')),
                         storeUrl: @js(route('settings.company.backups.store')),
                         restoreUrl: @js(route('settings.company.backups.restore')),
                         deleteBaseUrl: @js(url('/settings/company/backups')),
                         i18n: {
                             errNetwork: @js(__('company.backup_err_network')),
                             errRestoreFailed: @js(__('company.backup_err_restore_failed')),
                             confirmWarning: @js(__('company.backup_confirm_restore_warning'))
                         },
                         init() {
                             this.fetchBackups();
                         },
                         async fetchBackups() {
                             this.loadingBackups = true;
                             try {
                                 const response = await fetch(this.indexUrl, {
                                     headers: {
                                         'Accept': 'application/json',
                                         'X-Requested-With': 'XMLHttpRequest'
                                     },
                                     credentials: 'same-origin'
                                 });
                                 if (response.ok) {
                                     const payload = await response.json();
                                     this.backups = Array.isArray(payload.backups) ? payload.backups : [];
                                 }
                             } catch (e) {
                                 // Ignore passive initial load failure
                             } finally {
                                 this.loadingBackups = false;
                             }
                         },
                         async createBackup() {
                             if (this.creatingBackup || this.restoringBackup) return;
                             this.creatingBackup = true;
                             this.feedbackMessage = '';
                             try {
                                 const controller = new AbortController();
                                 const timer = setTimeout(() => controller.abort(), 120000);
                                 const response = await fetch(this.storeUrl, {
                                     method: 'POST',
                                     headers: {
                                         'Accept': 'application/json',
                                         'X-Requested-With': 'XMLHttpRequest',
                                         'X-CSRF-TOKEN': this.csrfToken
                                     },
                                     credentials: 'same-origin',
                                     signal: controller.signal
                                 }).finally(() => clearTimeout(timer));

                                 const payload = await response.json().catch(() => ({}));
                                 if (response.ok && payload.success) {
                                     this.feedbackSuccess = true;
                                     this.feedbackMessage = payload.message || '';
                                     await this.fetchBackups();
                                 } else {
                                     this.feedbackSuccess = false;
                                     this.feedbackMessage = payload.message || this.i18n.errNetwork;
                                 }
                             } catch (e) {
                                 this.feedbackSuccess = false;
                                 this.feedbackMessage = this.i18n.errNetwork;
                             } finally {
                                 this.creatingBackup = false;
                             }
                         },
                         confirmDeleteBackup(filename) {
                             this.pendingDeleteFilename = filename;
                             this.$dispatch('open-modal', 'confirm-delete-backup-modal');
                         },
                         async executeDeleteBackup() {
                             if (!this.pendingDeleteFilename || this.deletingBackup) return;
                             this.deletingBackup = true;
                             this.feedbackMessage = '';
                             try {
                                 const url = `${this.deleteBaseUrl}/${encodeURIComponent(this.pendingDeleteFilename)}`;
                                 const response = await fetch(url, {
                                     method: 'DELETE',
                                     headers: {
                                         'Accept': 'application/json',
                                         'X-Requested-With': 'XMLHttpRequest',
                                         'X-CSRF-TOKEN': this.csrfToken
                                     },
                                     credentials: 'same-origin'
                                 });
                                 const payload = await response.json().catch(() => ({}));
                                 if (response.ok && payload.success) {
                                     this.feedbackSuccess = true;
                                     this.feedbackMessage = payload.message || '';
                                     this.$dispatch('close-modal', 'confirm-delete-backup-modal');
                                     await this.fetchBackups();
                                 } else {
                                     this.feedbackSuccess = false;
                                     this.feedbackMessage = payload.message || this.i18n.errNetwork;
                                 }
                             } catch (e) {
                                 this.feedbackSuccess = false;
                                 this.feedbackMessage = this.i18n.errNetwork;
                             } finally {
                                 this.deletingBackup = false;
                             }
                         },
                         handleFileSelected(event) {
                             const file = event.target.files?.[0] || null;
                             this.selectedFile = file;
                             this.selectedFileName = file ? file.name : '';
                             this.selectedFileSizeMb = file ? (file.size / 1048576).toFixed(2) : '';
                         },
                         openRestoreConfirm() {
                             if (!this.selectedFile || this.restoringBackup) return;
                             this.restoreConfirmedCheckbox = false;
                             this.$dispatch('open-modal', 'confirm-restore-backup-modal');
                         },
                         async executeRestore() {
                             if (!this.selectedFile || !this.restoreConfirmedCheckbox || this.restoringBackup) return;
                             this.restoringBackup = true;
                             this.feedbackMessage = '';

                             const formData = new FormData();
                             formData.append('backup_file', this.selectedFile);

                             try {
                                 const controller = new AbortController();
                                 const timer = setTimeout(() => controller.abort(), 120000);
                                 const response = await fetch(this.restoreUrl, {
                                     method: 'POST',
                                     headers: {
                                         'Accept': 'application/json',
                                         'X-Requested-With': 'XMLHttpRequest',
                                         'X-CSRF-TOKEN': this.csrfToken
                                     },
                                     credentials: 'same-origin',
                                     body: formData,
                                     signal: controller.signal
                                 }).finally(() => clearTimeout(timer));

                                 const payload = await response.json().catch(() => ({}));
                                 if (response.ok && payload.success) {
                                     this.feedbackSuccess = true;
                                     this.feedbackMessage = payload.message || '';
                                     this.selectedFile = null;
                                     this.selectedFileName = '';
                                     this.selectedFileSizeMb = '';
                                     if (this.$refs.restoreFileInput) {
                                         this.$refs.restoreFileInput.value = '';
                                     }
                                     this.$dispatch('close-modal', 'confirm-restore-backup-modal');
                                     setTimeout(() => window.location.reload(), 900);
                                 } else {
                                     const validationMsg = payload.errors?.backup_file?.[0];
                                     this.feedbackSuccess = false;
                                     this.feedbackMessage = validationMsg || payload.message || this.i18n.errRestoreFailed;
                                     this.$dispatch('close-modal', 'confirm-restore-backup-modal');
                                 }
                             } catch (e) {
                                 this.feedbackSuccess = false;
                                 this.feedbackMessage = this.i18n.errNetwork;
                                 this.$dispatch('close-modal', 'confirm-restore-backup-modal');
                             } finally {
                                 this.restoringBackup = false;
                             }
                         }
                     }"
                     class="bg-surface border border-line rounded-2xl p-6 shadow-sm space-y-5">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-line pb-4">
                        <div class="flex items-start gap-3">
                            <div class="w-10 h-10 rounded-xl bg-accent/10 border border-accent/20 text-accent flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-ink">{{ __('company.backup_section_title') }}</h3>
                                <p class="text-xs text-ink-muted mt-0.5">{{ __('company.backup_section_desc') }}</p>
                            </div>
                        </div>

                        <button type="button"
                                @click="createBackup()"
                                :disabled="creatingBackup || restoringBackup"
                                class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-2xl bg-accent text-accent-fg text-xs font-semibold shadow-sm hover:opacity-95 disabled:opacity-50 transition-all cursor-pointer shrink-0">
                            <svg x-show="creatingBackup" x-cloak class="animate-spin w-4 h-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <svg x-show="!creatingBackup" class="w-4 h-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                            </svg>
                            <span x-text="creatingBackup ? '{{ __('company.backup_creating') }}' : '{{ __('company.backup_btn_create') }}'"></span>
                        </button>
                    </div>

                    <!-- Feedback Alert -->
                    <div x-show="feedbackMessage !== ''"
                         x-cloak
                         role="alert"
                         :class="feedbackSuccess
                             ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                             : 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300'"
                         class="rounded-xl border px-3.5 py-2.5 text-xs font-medium flex items-center justify-between gap-2">
                        <span x-text="feedbackMessage"></span>
                        <button type="button" @click="feedbackMessage = ''" class="text-ink-muted hover:text-ink cursor-pointer shrink-0">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Tabel Riwayat Backup -->
                    <div class="space-y-2.5">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-ink-muted">
                            {{ __('company.backup_history_title') }}
                        </h4>

                        <template x-if="loadingBackups && backups.length === 0">
                            <div class="rounded-2xl border border-dashed border-line bg-surface-muted/40 p-6 text-center text-xs text-ink-muted">
                                {{ __('company.backup_loading') }}
                            </div>
                        </template>

                        <template x-if="!loadingBackups && backups.length === 0">
                            <div class="rounded-2xl border border-dashed border-line bg-surface-muted/40 p-6 text-center text-xs text-ink-muted">
                                {{ __('company.backup_empty') }}
                            </div>
                        </template>

                        <template x-if="backups.length > 0">
                            <div class="overflow-x-auto rounded-2xl border border-line bg-surface shadow-2xs">
                                <table class="o-table">
                                    <thead>
                                        <tr class="bg-surface-muted border-b border-line">
                                            <th class="py-2.5 px-4 text-left text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.backup_col_filename') }}</th>
                                            <th class="py-2.5 px-4 text-left text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.backup_col_created_at') }}</th>
                                            <th class="py-2.5 px-4 text-right text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.backup_col_size') }}</th>
                                            <th class="py-2.5 px-4 text-right text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('company.backup_col_actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="item in backups" :key="item.filename">
                                            <tr class="hover:bg-surface-muted/50 transition-colors">
                                                <td class="py-2.5 px-4 font-mono text-xs font-semibold text-ink whitespace-nowrap" x-text="item.filename"></td>
                                                <td class="py-2.5 px-4 text-xs text-ink-muted whitespace-nowrap" x-text="item.created_at_formatted || item.created_at"></td>
                                                <td class="py-2.5 px-4 font-mono text-xs text-ink text-right whitespace-nowrap" x-text="Number(item.size_mb).toFixed(2) + ' MB'"></td>
                                                <td class="py-2.5 px-4 text-right whitespace-nowrap">
                                                    <div class="inline-flex items-center justify-end gap-2">
                                                        <a :href="item.download_url"
                                                           class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl border border-line bg-surface hover:bg-surface-muted text-ink text-2xs font-semibold transition">
                                                            <svg class="w-3.5 h-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                                            </svg>
                                                            <span>{{ __('company.backup_btn_download') }}</span>
                                                        </a>
                                                        <button type="button"
                                                                @click="confirmDeleteBackup(item.filename)"
                                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl border border-red-500/30 bg-red-500/10 hover:bg-red-500/20 text-red-700 dark:text-red-300 text-2xs font-semibold transition cursor-pointer">
                                                            <svg class="w-3.5 h-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                            </svg>
                                                            <span>{{ __('company.backup_btn_delete') }}</span>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </div>

                    <!-- Area Upload & Restore -->
                    <div class="rounded-2xl border border-red-500/30 bg-red-500/5 p-5 space-y-4">
                        <div class="flex items-start gap-3">
                            <div class="w-9 h-9 rounded-xl bg-red-500/15 border border-red-500/25 text-red-700 dark:text-red-300 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                                </svg>
                            </div>
                            <div class="space-y-1">
                                <h4 class="text-xs sm:text-sm font-bold text-ink">{{ __('company.backup_restore_title') }}</h4>
                                <p class="text-xs text-ink-muted leading-relaxed">{{ __('company.backup_restore_desc') }}</p>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row sm:items-end gap-3">
                            <div class="flex-1 space-y-1.5">
                                <label for="company-backup-restore-file" class="block text-xs font-semibold text-ink">
                                    {{ __('company.backup_restore_file_label') }}
                                </label>
                                <input id="company-backup-restore-file"
                                       x-ref="restoreFileInput"
                                       type="file"
                                       accept=".zip,application/zip"
                                       @change="handleFileSelected($event)"
                                       class="block w-full text-xs text-ink file:mr-3 file:py-2 file:px-3.5 file:rounded-xl file:border file:border-line file:text-xs file:font-semibold file:bg-surface file:text-ink hover:file:bg-surface-muted rounded-xl border border-line bg-surface-muted p-1.5 cursor-pointer" />
                                <p x-show="selectedFileName !== ''" x-cloak class="text-2xs font-mono text-ink-muted">
                                    <span x-text="selectedFileName"></span> (<span x-text="selectedFileSizeMb"></span> MB)
                                </p>
                            </div>

                            <button type="button"
                                    @click="openRestoreConfirm()"
                                    :disabled="!selectedFile || restoringBackup || creatingBackup"
                                    class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-2xl bg-red-600 hover:bg-red-700 text-white text-xs font-semibold shadow-sm disabled:opacity-40 disabled:cursor-not-allowed transition cursor-pointer shrink-0">
                                <svg class="w-4 h-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                                <span x-text="restoringBackup ? '{{ __('company.backup_restoring') }}' : '{{ __('company.backup_btn_restore') }}'"></span>
                            </button>
                        </div>
                    </div>

                    <!-- Modal Konfirmasi Hapus Arsip Backup -->
                    <x-ui.modal name="confirm-delete-backup-modal" :title="__('company.backup_confirm_delete_title')" maxWidth="sm">
                        <div class="space-y-4">
                            <p class="text-xs text-ink-muted leading-relaxed">
                                {{ __('company.backup_confirm_delete_desc') }}
                            </p>
                            <div class="rounded-2xl border border-line bg-surface-muted/50 px-3.5 py-2.5 text-xs font-mono font-semibold text-ink break-all"
                                 x-text="pendingDeleteFilename"></div>
                            <div class="flex items-center justify-end gap-2 pt-3 border-t border-line">
                                <button type="button"
                                        @click="$dispatch('close-modal', 'confirm-delete-backup-modal')"
                                        :disabled="deletingBackup"
                                        class="px-4 py-2 rounded-2xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition cursor-pointer">
                                    {{ __('common.cancel') }}
                                </button>
                                <button type="button"
                                        @click="executeDeleteBackup()"
                                        :disabled="deletingBackup"
                                        class="px-4 py-2 rounded-2xl bg-red-600 hover:bg-red-700 text-white text-xs font-semibold shadow-sm transition cursor-pointer">
                                    {{ __('company.backup_btn_delete') }}
                                </button>
                            </div>
                        </div>
                    </x-ui.modal>

                    <!-- Modal Konfirmasi Ganda Kritis (Peringatan Merah) Restore Data -->
                    <x-ui.modal name="confirm-restore-backup-modal" :title="__('company.backup_confirm_restore_title')" maxWidth="md">
                        <div class="space-y-4">
                            <div role="alert" class="rounded-2xl border border-red-500/40 bg-red-500/10 p-4 text-xs text-red-700 dark:text-red-300 space-y-1.5">
                                <p class="font-bold">{{ __('company.backup_confirm_restore_warning') }}</p>
                                <p class="opacity-90 leading-relaxed">{{ __('company.backup_confirm_restore_detail') }}</p>
                            </div>

                            <div class="rounded-2xl border border-line bg-surface-muted/50 px-3.5 py-2.5 text-xs space-y-0.5">
                                <div class="text-2xs uppercase tracking-wider text-ink-muted font-semibold">{{ __('company.backup_col_filename') }}</div>
                                <div class="font-mono font-semibold text-ink break-all" x-text="selectedFileName"></div>
                            </div>

                            <label class="flex items-start gap-2.5 rounded-2xl border border-red-500/30 bg-surface-muted/40 p-3 cursor-pointer">
                                <input type="checkbox"
                                       x-model="restoreConfirmedCheckbox"
                                       class="mt-0.5 rounded border-red-500/50 text-red-600 focus:ring-red-500" />
                                <span class="text-xs font-semibold text-ink leading-relaxed">
                                    {{ __('company.backup_confirm_restore_checkbox') }}
                                </span>
                            </label>

                            <div class="flex items-center justify-end gap-2 pt-3 border-t border-line">
                                <button type="button"
                                        @click="$dispatch('close-modal', 'confirm-restore-backup-modal')"
                                        :disabled="restoringBackup"
                                        class="px-4 py-2 rounded-2xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition cursor-pointer">
                                    {{ __('common.cancel') }}
                                </button>
                                <button type="button"
                                        @click="executeRestore()"
                                        :disabled="!restoreConfirmedCheckbox || restoringBackup"
                                        class="px-4 py-2 rounded-2xl bg-red-600 hover:bg-red-700 text-white text-xs font-semibold shadow-sm disabled:opacity-40 disabled:cursor-not-allowed transition cursor-pointer">
                                    <span x-text="restoringBackup ? '{{ __('company.backup_restoring') }}' : '{{ __('company.backup_confirm_restore_btn') }}'"></span>
                                </button>
                            </div>
                        </div>
                    </x-ui.modal>
                </div>
            @endif
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
