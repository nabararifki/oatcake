<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
                <h2 class="font-bold text-xl text-ink leading-tight">
                    {{ __('nav.dashboard') }}
                </h2>
                <p class="text-xs text-ink-muted mt-0.5">
                    @if ($user->isSuperAdmin() && ! $activeCompany)
                        {{ __('common.platform_admin_subtitle') }}
                    @else
                        {{ $activeCompany?->name ?? __('common.tenant_workspace') }} &mdash; {{ __('common.active_role') }}: <span class="font-semibold uppercase">{{ str_replace('_', ' ', $user->role) }}</span>
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button"
                        @click="$dispatch('open-modal', 'today-schedules-modal')"
                        class="rounded-xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-semibold px-3.5 py-2 shadow-sm flex items-center gap-2 cursor-pointer transition-colors">
                    <svg class="h-4 w-4 text-accent shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                    </svg>
                    <span>{{ __('dashboard.btn_view_today_schedule') }}</span>
                </button>

                @if ($user->isManager())
                    <a href="{{ route('staff.create') }}"
                       class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold text-accent-fg shadow-xs transition-opacity hover:opacity-90"
                       style="background-color: var(--primary-accent);">
                        {{ __('common.add_team_member') }}
                    </a>
                @elseif ($user->isAdmin() || $user->isSuperUser())
                    <a href="{{ route('staff.create') }}"
                       class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold text-accent-fg shadow-xs transition-opacity hover:opacity-90"
                       style="background-color: var(--primary-accent);">
                        {{ __('common.onboard_new_staff') }}
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="space-y-8">
        @if (session('status'))
            <div class="bg-emerald-500/10 border border-emerald-500/20 text-emerald-700 dark:text-emerald-300 rounded-xl p-3.5 text-sm">
                <span>{{ session('status') }}</span>
            </div>
        @endif

        {{-- =====================================================================
             WIDGET 0: MINIMALIST QUICK ATTENDANCE WIDGET (ALL EMPLOYEES EXCEPT PURE SUPER ADMIN)
             ===================================================================== --}}
        @php
            $isSuperAdmin = $isSuperAdmin ?? auth()->user()->isSuperAdmin();
            $employee = $employee ?? auth()->user()->employee;
            $companyTarget = $company ?? $activeCompany ?? $employee?->company ?? $user->company;
            $companyLat = (float) ($companyTarget?->latitude ?? -7.9048250);
            $companyLon = (float) ($companyTarget?->longitude ?? 110.0572110);
            $radiusLimit = (int) ($companyTarget?->geofence_radius ?? 100);
            $hasActiveClockIn = isset($todayAttendance) && $todayAttendance !== null && $todayAttendance->clock_out_at === null;
            $isWaitingNextSession = (bool) ($todaySessionsState['is_waiting_next_session'] ?? false);
            $nextSessionStartTime = (string) ($todaySessionsState['next_session_start_time'] ?? '');
            $guardSchedule = ($hasActiveClockIn && $todayAttendance->workSchedule && ! $todayAttendance->workSchedule->isOff())
                ? $todayAttendance->workSchedule
                : null;
            $effectiveEndDt = $guardSchedule?->getEffectiveEndDateTime();
            $effectiveEndTime = $effectiveEndDt ? $effectiveEndDt->format('H:i') . ' WIB' : '';
            $hasCompletedToday = isset($todayAttendance) && $todayAttendance !== null && $todayAttendance->clock_out_at !== null && ! $isWaitingNextSession;
        @endphp

        @if (! $isSuperAdmin && $employee)
            <section id="dashboard-attendance-widget"
                     aria-label="{{ __('dashboard.attendance_widget_label') }}"
                     x-data="dashboardAttendanceWidget({
                         companyLat: {{ Js::from($companyLat) }},
                         companyLon: {{ Js::from($companyLon) }},
                         radiusLimit: {{ Js::from($radiusLimit) }},
                         clockInUrl: {{ Js::from(route('attendance.clockIn')) }},
                         outsideClockInUrl: {{ Js::from(route('attendance.clockInOutside')) }},
                         clockOutUrl: {{ Js::from(route('attendance.clockOut')) }},
                         csrfToken: {{ Js::from(csrf_token()) }},
                         hasActiveClockIn: {{ Js::from($hasActiveClockIn) }},
                         hasCompletedToday: {{ Js::from($hasCompletedToday) }},
                         shiftEndIso: {{ Js::from($effectiveEndDt?->toIso8601String()) }},
                         i18n: {
                             earlyRemaining: {{ Js::from(__('attendance.early_clockout_remaining', ['remaining' => ':remaining'])) }},
                             gpsLocating: {{ Js::from(__('dashboard.gps_detecting')) }},
                             gpsDenied: {{ Js::from(__('attendance.err_gps_permission_denied')) }},
                             errInsecureContext: {{ Js::from(__('attendance.err_insecure_context')) }},
                             errGpsPermissionDenied: {{ Js::from(__('attendance.err_gps_permission_denied')) }},
                             errGpsUnavailable: {{ Js::from(__('attendance.err_gps_unavailable')) }},
                             errGpsTimeout: {{ Js::from(__('attendance.err_gps_timeout')) }},
                             selfieRequired: {{ Js::from(__('attendance.selfie_required')) }},
                             btnRetryGps: {{ Js::from(__('attendance.btn_retry_gps')) }},
                             errGpsGeneric: {{ Js::from(__('attendance.err_gps_generic')) }},
                             errValidation: {{ Js::from(__('attendance.err_validation')) }},
                             errNetwork: {{ Js::from(__('attendance.err_network')) }},
                             errPayloadTooLarge: {{ Js::from(__('attendance.err_payload_too_large')) }},
                             errPhotoProcessingFailed: {{ Js::from(__('attendance.err_photo_processing_failed')) }},
                             errServerResponse: {{ Js::from(__('attendance.err_server_response')) }},
                         }
                     })"
                     class="bg-surface border border-line rounded-2xl p-4 sm:p-5 shadow-sm space-y-4">

                <!-- Feedback Alert Banner -->
                <div x-show="feedbackMessage !== ''"
                     x-cloak
                     x-transition
                     :class="feedbackSuccess
                         ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                         : 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300'"
                     class="rounded-xl border px-3.5 py-2.5 text-xs font-medium flex items-center justify-between gap-2">
                    <span x-text="feedbackMessage"></span>
                    <button type="button" @click="feedbackMessage = ''" class="text-ink-muted hover:text-ink cursor-pointer">×</button>
                </div>

                <!-- Banner Diagnostik Error GPS Laptop/Browser (HTTPS / Permission / Network Timeout) -->
                <div x-show="gpsErrorMessage !== ''"
                     x-cloak
                     x-transition
                     :class="gpsErrorType === 'INSECURE_CONTEXT'
                         ? 'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-300'
                         : 'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-300'"
                     class="rounded-xl border px-3.5 py-2.5 text-xs flex flex-wrap items-center justify-between gap-2.5">
                    <div class="flex items-start gap-2 min-w-0 flex-1">
                        <svg class="w-4 h-4 shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        <div class="space-y-0.5">
                            <p class="font-semibold" x-text="gpsErrorMessage"></p>
                            @if (! app()->isProduction())
                                <p x-show="gpsErrorType === 'INSECURE_CONTEXT'" class="text-2xs opacity-85 font-mono">
                                    {{ __('attendance.tip_dev_local') }}
                                </p>
                            @endif
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                        <button type="button"
                                @click="detectLocation()"
                                class="inline-flex items-center gap-1 rounded-lg bg-surface px-2.5 py-1 text-2xs font-bold text-ink border border-line hover:bg-surface-muted transition cursor-pointer">
                            <svg class="w-3.5 h-3.5 text-accent" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                            <span>{{ __('attendance.btn_retry_gps') }}</span>
                        </button>

                        @if (! app()->isProduction())
                            <button type="button"
                                    @click="simulateCoordinates(companyLat, companyLon)"
                                    class="inline-flex items-center gap-1 rounded-lg bg-accent px-2.5 py-1 text-2xs font-bold text-white hover:opacity-90 transition cursor-pointer">
                                <span>{{ __('attendance.btn_simulate_gps_dev') }}</span>
                            </button>
                        @endif
                    </div>
                </div>

                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <!-- Baris Atas / Kiri: Waktu Digital WIB, Pill Shift Hari Ini, Pill Radius GPS Dinamis & Badge Provisional DP -->
                    <div class="flex flex-wrap items-center gap-2 sm:gap-2.5">
                        <!-- Waktu Digital WIB Real-Time (Jam:Menit) -->
                        <div class="inline-flex items-baseline gap-1.5 rounded-xl bg-surface-muted border border-line px-3 py-1.5">
                            <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse self-center mr-0.5"></span>
                            <span x-text="currentTimeDisplay" class="text-base sm:text-lg font-mono font-bold tracking-tight text-ink">
                                {{ \Carbon\Carbon::now('Asia/Jakarta')->format('H:i') }}
                            </span>
                            <span class="text-2xs font-mono font-semibold text-ink-muted">WIB</span>
                        </div>

                        <!-- Pill Status Shift Hari Ini -->
                        @if (isset($todaySchedule) && $todaySchedule && ! $todaySchedule->isOff() && $todaySchedule->shift)
                            <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold text-white shadow-2xs"
                                  style="background-color: {{ $todaySchedule->shift->color_badge ?? '#3B82F6' }};">
                                <span>{{ $todaySchedule->shift->code }}</span>
                                <span class="font-mono opacity-95">{{ substr($todaySchedule->getEffectiveStartTime(), 0, 5) }}–{{ substr($todaySchedule->getEffectiveEndTime(), 0, 5) }}</span>
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-muted border border-line px-3 py-1 text-xs font-semibold text-ink-muted">
                                {{ __('dashboard.no_shift_today') }}
                            </span>
                        @endif

                        <!-- Pill Radius GPS Dinamis -->
                        <span x-show="distanceMeters !== null && isInsideGeofence"
                              x-cloak
                              class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 px-3 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                            <span>{{ __('dashboard.gps_inside_hotel') }}</span>
                            <span class="font-mono text-2xs opacity-80" x-text="'(' + distanceMeters + 'm)'"></span>
                        </span>

                        <span x-show="distanceMeters !== null && !isInsideGeofence"
                              x-cloak
                              class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/10 border border-amber-500/30 px-3 py-1 text-xs font-semibold text-amber-700 dark:text-amber-300">
                            <span>{{ __('dashboard.gps_outside_hotel') }}</span>
                            <span class="font-mono text-2xs opacity-80" x-text="'(' + distanceMeters + 'm)'"></span>
                        </span>

                        <button type="button"
                                x-show="distanceMeters === null"
                                @click="detectLocation()"
                                class="inline-flex items-center gap-1.5 rounded-full bg-surface-muted border border-line px-3 py-1 text-xs font-medium text-ink-muted hover:text-ink cursor-pointer transition">
                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500 animate-ping"></span>
                            <span x-text="gpsStatusText">{{ __('dashboard.gps_detecting') }}</span>
                        </button>

                        <!-- Badge Provisional DP (Jika Hari Libur Nasional / Tanggal Merah) -->
                        @if (($isHoliday ?? false) || ($todayAttendance?->is_provisional_dp ?? false))
                            <span title="{{ __('attendance.tooltip_provisional_dp') }}"
                                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-2xs font-semibold bg-purple-500/10 text-purple-700 dark:text-purple-300 border border-purple-500/20">
                                <span>✦ {{ __('attendance.badge_provisional_dp') }}</span>
                            </span>
                        @endif
                    </div>

                    <!-- Baris Tindakan Cepat (One-Touch Action) -->
                    <div class="flex flex-col gap-2.5 w-full lg:w-auto lg:min-w-[20rem] shrink-0">
                        @if ($isWaitingNextSession)
                            {{-- Kondisi Split Shift: Sesi 1 Selesai, Menunggu / Siap Clock-In Sesi Berikutnya --}}
                            <div class="inline-flex items-center gap-2 rounded-xl bg-amber-500/10 border border-amber-500/30 px-3.5 py-2.5 text-xs font-semibold text-amber-800 dark:text-amber-300">
                                <span class="h-2 w-2 rounded-full bg-amber-500 shrink-0"></span>
                                <span>{{ __('attendance.split_session_1_done_waiting', ['time' => $nextSessionStartTime ?: '--:--']) }}</span>
                            </div>

                            <button type="button"
                                    x-show="distanceMeters === null || isInsideGeofence"
                                    @click="startClockIn()"
                                    :disabled="submitting"
                                    class="bg-accent hover:opacity-95 text-white w-full py-4 px-6 text-base sm:text-lg font-bold rounded-2xl shadow-md transition-all active:scale-[0.99] flex items-center justify-center gap-3 cursor-pointer disabled:opacity-50">
                                <svg class="w-6 h-6 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                                </svg>
                                <span>{{ __('dashboard.clock_in_now') }}</span>
                            </button>

                            <button type="button"
                                    x-show="distanceMeters !== null && !isInsideGeofence"
                                    x-cloak
                                    @click="openOutsideModal()"
                                    class="bg-accent hover:opacity-95 text-white w-full py-4 px-6 text-base sm:text-lg font-bold rounded-2xl shadow-md transition-all active:scale-[0.99] flex items-center justify-center gap-3 cursor-pointer">
                                <svg class="w-6 h-6 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                                </svg>
                                <span>{{ __('dashboard.outside_duty_prompt') }}</span>
                            </button>
                        @elseif (! isset($todayAttendance) || ! $todayAttendance)
                            {{-- Kondisi 1: Belum Presensi Masuk --}}
                            <button type="button"
                                    x-show="distanceMeters === null || isInsideGeofence"
                                    @click="startClockIn()"
                                    :disabled="submitting"
                                    class="bg-accent hover:opacity-95 text-white w-full py-4 px-6 text-base sm:text-lg font-bold rounded-2xl shadow-md transition-all active:scale-[0.99] flex items-center justify-center gap-3 cursor-pointer disabled:opacity-50">
                                <svg class="w-6 h-6 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                                </svg>
                                <span>{{ __('dashboard.clock_in_now') }}</span>
                            </button>

                            <button type="button"
                                    x-show="distanceMeters !== null && !isInsideGeofence"
                                    x-cloak
                                    @click="openOutsideModal()"
                                    class="bg-accent hover:opacity-95 text-white w-full py-4 px-6 text-base sm:text-lg font-bold rounded-2xl shadow-md transition-all active:scale-[0.99] flex items-center justify-center gap-3 cursor-pointer">
                                <svg class="w-6 h-6 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                                </svg>
                                <span>{{ __('dashboard.outside_duty_prompt') }}</span>
                            </button>
                        @elseif (! $todayAttendance->clock_out_at)
                            {{-- Kondisi 2: Sudah Clock-In, Belum Clock-Out --}}
                            @php
                                $clockInTimeStr = $todayAttendance->clock_in_at?->timezone('Asia/Jakarta')->format('H:i') ?? '--:--';
                                $clockInStatusLabel = match ($todayAttendance->status) {
                                    'late' => __('attendance.status_late') . ($todayAttendance->late_minutes > 0 ? " ({$todayAttendance->late_minutes}m)" : ''),
                                    'pending_verification' => __('attendance.status_pending_verification'),
                                    'rejected' => __('attendance.status_rejected'),
                                    default => __('attendance.status_on_time'),
                                };
                            @endphp
                            <div class="inline-flex items-center gap-2 rounded-xl bg-surface-muted border border-line px-3.5 py-2.5 text-xs font-semibold text-ink">
                                <span class="h-2 w-2 rounded-full bg-emerald-500 shrink-0"></span>
                                <span>{{ __('dashboard.clocked_in_summary', ['time' => $clockInTimeStr, 'status' => $clockInStatusLabel]) }}</span>
                            </div>

                            <button type="button"
                                    @click="startClockOut()"
                                    :disabled="submitting"
                                    class="bg-surface-raised border-2 border-line hover:bg-surface-muted text-ink dark:text-zinc-100 w-full py-4 px-6 text-base sm:text-lg font-bold rounded-2xl shadow-md transition-all active:scale-[0.99] flex items-center justify-center gap-3 cursor-pointer disabled:opacity-50">
                                <svg class="w-6 h-6 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15m-3 0-3-3m0 0 3-3m-3 3H15" />
                                </svg>
                                <span>{{ __('dashboard.clock_out_now') }}</span>
                            </button>
                        @else
                            {{-- Kondisi 3: Sudah Clock-Out --}}
                            @php
                                $clockInTimeStr = $todayAttendance->clock_in_at?->timezone('Asia/Jakarta')->format('H:i') ?? '--:--';
                                $clockOutTimeStr = $todayAttendance->clock_out_at?->timezone('Asia/Jakarta')->format('H:i') ?? '--:--';
                                $workHours = $todayAttendance->getWorkDurationInHours();
                            @endphp
                            <div class="inline-flex items-center gap-2 rounded-xl bg-emerald-500/10 border border-emerald-500/20 px-4 py-2.5 text-xs sm:text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                                <svg class="w-4 h-4 shrink-0 text-emerald-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                                <span>{{ __('dashboard.today_completed', ['in' => $clockInTimeStr, 'out' => $clockOutTimeStr, 'hours' => $workHours]) }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Modal Konfirmasi Pulang Lebih Awal (Early Clock-Out Guard) -->
                <x-ui.modal name="early-clockout-confirm-modal" :title="__('attendance.modal_early_clockout_title')" maxWidth="md">
                    <div class="space-y-4">
                        <p class="text-sm text-ink">{{ __('attendance.modal_early_clockout_desc', ['time' => $effectiveEndTime]) }}</p>
                        <p class="text-xs font-semibold text-amber-700 dark:text-amber-300" x-text="earlyRemainingText"></p>
                        <div class="pt-2 flex items-center justify-end gap-2">
                            <button type="button"
                                    @click="$dispatch('close-modal', 'early-clockout-confirm-modal')"
                                    class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                                {{ __('common.cancel') }}
                            </button>
                            <button type="button"
                                    @click="$dispatch('close-modal', 'early-clockout-confirm-modal'); openClockOutSelfieModal()"
                                    class="rounded-xl bg-accent px-4 py-2 text-xs font-bold text-white shadow-sm hover:opacity-95 transition cursor-pointer">
                                {{ __('attendance.btn_confirm_early_clockout') }}
                            </button>
                        </div>
                    </div>
                </x-ui.modal>

                <!-- Modal Swafoto Presensi Masuk (Clock-In) -->
                <x-ui.modal name="clock-in-selfie-modal" :title="__('attendance.modal_clock_in_selfie_title')" maxWidth="md">
                    <form @submit.prevent="submitSelfieAttendance('clock-in')" class="space-y-4">
                        <div class="space-y-2">
                            <label class="block text-xs font-semibold text-ink">
                                {{ __('attendance.take_selfie') }} <span class="text-red-500">*</span>
                            </label>

                            <input type="file"
                                   name="photo"
                                   accept="image/*"
                                   capture="user"
                                   required
                                   @change="handleSelfieInCapture($event)"
                                   class="sr-only"
                                   id="clock-in-camera-input">

                            <div class="rounded-xl border border-dashed border-line bg-surface-muted/40 p-4 text-center space-y-3">
                                <template x-if="selfieInPreviewUrl">
                                    <div class="space-y-3">
                                        <img :src="selfieInPreviewUrl"
                                             alt="{{ __('attendance.selfie_preview_alt') }}"
                                             class="mx-auto max-h-56 rounded-xl object-cover border border-line shadow-sm" />
                                        <label for="clock-in-camera-input"
                                               class="inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-ink hover:bg-surface-muted cursor-pointer transition">
                                            <span>{{ __('attendance.retake_selfie') }}</span>
                                        </label>
                                    </div>
                                </template>

                                <template x-if="!selfieInPreviewUrl">
                                    <div class="py-6 space-y-2.5">
                                        <label for="clock-in-camera-input"
                                               class="inline-flex items-center gap-2 rounded-xl bg-accent px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:opacity-95 cursor-pointer transition">
                                            <svg class="w-4 h-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                                            </svg>
                                            <span>{{ __('attendance.take_selfie') }}</span>
                                        </label>
                                        <p class="text-2xs text-ink-muted">
                                            {{ __('attendance.camera_direct_hint') }}
                                        </p>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div class="pt-2 flex items-center justify-end gap-2">
                            <button type="button"
                                    @click="$dispatch('close-modal', 'clock-in-selfie-modal')"
                                    :disabled="submitting"
                                    class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                                {{ __('common.cancel') }}
                            </button>

                            <button type="submit"
                                    :disabled="submitting || !selfieInFile"
                                    class="inline-flex items-center gap-2 rounded-xl bg-accent px-4 py-2 text-xs font-bold text-white shadow-sm hover:opacity-95 disabled:opacity-50 transition cursor-pointer">
                                <span x-text="submitting ? '{{ __('attendance.compressing_uploading') }}' : '{{ __('attendance.btn_submit_clock_in') }}'"></span>
                            </button>
                        </div>
                    </form>
                </x-ui.modal>

                <!-- Modal Swafoto Presensi Pulang (Clock-Out) -->
                <x-ui.modal name="clock-out-selfie-modal" :title="__('attendance.modal_clock_out_selfie_title')" maxWidth="md">
                    <form @submit.prevent="submitSelfieAttendance('clock-out')" class="space-y-4">
                        <div class="space-y-2">
                            <label class="block text-xs font-semibold text-ink">
                                {{ __('attendance.take_selfie') }} <span class="text-red-500">*</span>
                            </label>

                            <input type="file"
                                   name="photo"
                                   accept="image/*"
                                   capture="user"
                                   required
                                   @change="handleSelfieOutCapture($event)"
                                   class="sr-only"
                                   id="clock-out-camera-input">

                            <div class="rounded-xl border border-dashed border-line bg-surface-muted/40 p-4 text-center space-y-3">
                                <template x-if="selfieOutPreviewUrl">
                                    <div class="space-y-3">
                                        <img :src="selfieOutPreviewUrl"
                                             alt="{{ __('attendance.selfie_preview_alt') }}"
                                             class="mx-auto max-h-56 rounded-xl object-cover border border-line shadow-sm" />
                                        <label for="clock-out-camera-input"
                                               class="inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-ink hover:bg-surface-muted cursor-pointer transition">
                                            <span>{{ __('attendance.retake_selfie') }}</span>
                                        </label>
                                    </div>
                                </template>

                                <template x-if="!selfieOutPreviewUrl">
                                    <div class="py-6 space-y-2.5">
                                        <label for="clock-out-camera-input"
                                               class="inline-flex items-center gap-2 rounded-xl bg-accent px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:opacity-95 cursor-pointer transition">
                                            <svg class="w-4 h-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                                            </svg>
                                            <span>{{ __('attendance.take_selfie') }}</span>
                                        </label>
                                        <p class="text-2xs text-ink-muted">
                                            {{ __('attendance.camera_direct_hint') }}
                                        </p>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div class="pt-2 flex items-center justify-end gap-2">
                            <button type="button"
                                    @click="$dispatch('close-modal', 'clock-out-selfie-modal')"
                                    :disabled="submitting"
                                    class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                                {{ __('common.cancel') }}
                            </button>

                            <button type="submit"
                                    :disabled="submitting || !selfieOutFile"
                                    class="inline-flex items-center gap-2 rounded-xl bg-accent px-4 py-2 text-xs font-bold text-white shadow-sm hover:opacity-95 disabled:opacity-50 transition cursor-pointer">
                                <span x-text="submitting ? '{{ __('attendance.compressing_uploading') }}' : '{{ __('attendance.btn_submit_clock_out') }}'"></span>
                            </button>
                        </div>
                    </form>
                </x-ui.modal>

                <!-- Modal Formulir Presensi Dinas Luar (Kamera Langsung Terkunci) -->
                <x-ui.modal name="dashboard-outside-attendance-modal" :title="__('attendance.modal_outside_title')" maxWidth="lg">
                    <form @submit.prevent="submitOutsideAttendance()" class="space-y-4">
                        <div class="space-y-2">
                            <label class="block text-xs font-semibold text-ink">
                                {{ __('attendance.take_selfie') }} <span class="text-red-500">*</span>
                            </label>

                            <input type="file"
                                   name="photo"
                                   accept="image/*"
                                   capture="user"
                                   required
                                   @change="handlePhotoCapture($event)"
                                   class="sr-only"
                                   id="camera-capture-input">

                            <div class="rounded-xl border border-dashed border-line bg-surface-muted/40 p-4 text-center space-y-3">
                                <template x-if="selfiePreviewUrl">
                                    <div class="space-y-3">
                                        <img :src="selfiePreviewUrl"
                                             alt="{{ __('attendance.selfie_preview_alt') }}"
                                             class="mx-auto max-h-52 rounded-xl object-cover border border-line shadow-sm" />
                                        <label for="camera-capture-input"
                                               class="inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-ink hover:bg-surface-muted cursor-pointer transition">
                                            <span>{{ __('attendance.retake_selfie') }}</span>
                                        </label>
                                    </div>
                                </template>

                                <template x-if="!selfiePreviewUrl">
                                    <div class="py-4 space-y-2.5">
                                        <label for="camera-capture-input"
                                               class="inline-flex items-center gap-2 rounded-xl bg-accent px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:opacity-95 cursor-pointer transition">
                                            <span>{{ __('attendance.take_selfie') }}</span>
                                        </label>
                                        <p class="text-2xs text-ink-muted">
                                            {{ __('attendance.camera_direct_hint') }}
                                        </p>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label for="dashboard_outside_category" class="block text-xs font-semibold text-ink">
                                {{ __('attendance.outside_category') }} <span class="text-red-500">*</span>
                            </label>
                            <select id="dashboard_outside_category"
                                    x-model="outsideCategory"
                                    required
                                    class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                                <option value="client_visit">{{ __('attendance.cat_client_visit') }}</option>
                                <option value="airport_pickup">{{ __('attendance.cat_airport_pickup') }}</option>
                                <option value="procurement">{{ __('attendance.cat_procurement') }}</option>
                                <option value="event">{{ __('attendance.cat_event') }}</option>
                                <option value="other">{{ __('attendance.cat_other') }}</option>
                            </select>
                        </div>

                        <div class="space-y-1.5">
                            <label for="dashboard_outside_location_name" class="block text-xs font-semibold text-ink">
                                {{ __('attendance.outside_location_name') }} <span class="text-red-500">*</span>
                            </label>
                            <input type="text"
                                   id="dashboard_outside_location_name"
                                   x-model="outsideLocationName"
                                   maxlength="150"
                                   required
                                   placeholder="{{ __('attendance.outside_location_placeholder') }}"
                                   class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink placeholder:text-ink-muted/70 focus:outline-none focus:ring-2 focus:ring-accent" />
                        </div>

                        <div class="space-y-1.5">
                            <label for="dashboard_outside_notes" class="block text-xs font-semibold text-ink">
                                {{ __('attendance.outside_notes_label') }} <span class="text-red-500">*</span>
                            </label>
                            <textarea id="dashboard_outside_notes"
                                      x-model="outsideNotes"
                                      rows="3"
                                      minlength="5"
                                      maxlength="500"
                                      required
                                      placeholder="{{ __('attendance.outside_notes_placeholder') }}"
                                      class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink placeholder:text-ink-muted/70 focus:outline-none focus:ring-2 focus:ring-accent"></textarea>
                        </div>

                        <div class="pt-2 flex items-center justify-end gap-2">
                            <button type="button"
                                    @click="$dispatch('close-modal', 'dashboard-outside-attendance-modal')"
                                    :disabled="submittingOutside"
                                    class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                                {{ __('common.cancel') }}
                            </button>

                            <button type="submit"
                                    :disabled="submittingOutside"
                                    class="inline-flex items-center gap-2 rounded-xl bg-accent px-4 py-2 text-xs font-bold text-white shadow-sm hover:opacity-95 disabled:opacity-50 transition cursor-pointer">
                                <span x-text="submittingOutside ? '{{ __('attendance.compressing_uploading') }}' : '{{ __('attendance.btn_outside_attendance') }}'"></span>
                            </button>
                        </div>
                    </form>
                </x-ui.modal>
            </section>

            <script>
                document.addEventListener('alpine:init', () => {
                    Alpine.data('dashboardAttendanceWidget', (config) => ({
                        companyLat: config.companyLat,
                        companyLon: config.companyLon,
                        radiusLimit: config.radiusLimit,
                        clockInUrl: config.clockInUrl,
                        outsideClockInUrl: config.outsideClockInUrl,
                        clockOutUrl: config.clockOutUrl,
                        csrfToken: config.csrfToken,
                        hasActiveClockIn: config.hasActiveClockIn,
                        hasCompletedToday: config.hasCompletedToday,
                        i18n: config.i18n,
                        shiftEndIso: config.shiftEndIso,
                        earlyRemainingText: '',

                        currentTimeDisplay: '--:--',
                        gpsState: 'locating',
                        gpsStatusText: config.i18n.gpsLocating,
                        gpsErrorType: '',
                        gpsErrorMessage: '',
                        userLat: null,
                        userLon: null,
                        distanceMeters: null,
                        isInsideGeofence: true,

                        submitting: false,
                        feedbackMessage: '',
                        feedbackSuccess: false,

                        selfieFile: null,
                        selfiePreviewUrl: null,
                        selfieInFile: null,
                        selfieInPreviewUrl: null,
                        selfieOutFile: null,
                        selfieOutPreviewUrl: null,
                        outsideCategory: 'client_visit',
                        outsideLocationName: '',
                        outsideNotes: '',
                        submittingOutside: false,

                        init() {
                            this.updateClock();
                            setInterval(() => this.updateClock(), 10000);
                            this.detectLocation();
                        },

                        updateClock() {
                            const now = new Date();
                            const formatter = new Intl.DateTimeFormat('en-GB', {
                                timeZone: 'Asia/Jakarta',
                                hour: '2-digit',
                                minute: '2-digit',
                                hour12: false,
                            });
                            this.currentTimeDisplay = formatter.format(now);
                        },

                        calculateHaversine(lat1, lon1, lat2, lon2) {
                            const R = 6371000;
                            const toRad = (deg) => (deg * Math.PI) / 180;
                            const dLat = toRad(lat2 - lat1);
                            const dLon = toRad(lon2 - lon1);
                            const a =
                                Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                                Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
                                Math.sin(dLon / 2) * Math.sin(dLon / 2);
                            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
                            return Math.round(R * c);
                        },

                        getPreciseLocation() {
                            return new Promise((resolve, reject) => {
                                if (!window.isSecureContext) {
                                    return reject({
                                        code: 'INSECURE_CONTEXT',
                                        message: this.i18n.errInsecureContext
                                    });
                                }

                                if (!navigator.geolocation) {
                                    return reject({
                                        code: 'NOT_SUPPORTED',
                                        message: this.i18n.errGpsUnavailable
                                    });
                                }

                                // Upaya 1: Coba High Accuracy (Satelit / Mobile GPS)
                                navigator.geolocation.getCurrentPosition(
                                    (pos) => resolve(pos),
                                    (err) => {
                                        console.warn('High-accuracy GPS timeout/gagal, beralih ke Network Location fallback...', err);
                                        // Upaya 2 (Fallback untuk Laptop): Standard Accuracy dengan timeout lebih toleran
                                        navigator.geolocation.getCurrentPosition(
                                            (fallbackPos) => resolve(fallbackPos),
                                            (finalErr) => reject(finalErr),
                                            { enableHighAccuracy: false, timeout: 20000, maximumAge: 60000 }
                                        );
                                    },
                                    { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
                                );
                            });
                        },

                        mapGeolocationError(err) {
                            if (!err) {
                                this.gpsErrorType = 'POSITION_UNAVAILABLE';
                                this.gpsErrorMessage = this.i18n.errGpsUnavailable;
                                return;
                            }

                            if (err.code === 'INSECURE_CONTEXT') {
                                this.gpsErrorType = 'INSECURE_CONTEXT';
                                this.gpsErrorMessage = this.i18n.errInsecureContext;
                            } else if (err.code === 1) {
                                this.gpsErrorType = 'PERMISSION_DENIED';
                                this.gpsErrorMessage = this.i18n.errGpsPermissionDenied;
                            } else if (err.code === 3) {
                                this.gpsErrorType = 'TIMEOUT';
                                this.gpsErrorMessage = this.i18n.errGpsTimeout;
                            } else {
                                this.gpsErrorType = 'POSITION_UNAVAILABLE';
                                this.gpsErrorMessage = this.i18n.errGpsUnavailable;
                            }
                        },

                        simulateCoordinates(lat, lon) {
                            this.gpsState = 'locked';
                            this.gpsErrorType = '';
                            this.gpsErrorMessage = '';
                            this.userLat = Number(lat);
                            this.userLon = Number(lon);
                            this.distanceMeters = this.calculateHaversine(
                                this.companyLat,
                                this.companyLon,
                                this.userLat,
                                this.userLon
                            );
                            this.isInsideGeofence = this.distanceMeters <= this.radiusLimit;
                        },

                        detectLocation(callback = null) {
                            this.gpsState = 'locating';
                            this.gpsStatusText = this.i18n.gpsLocating;
                            this.gpsErrorType = '';
                            this.gpsErrorMessage = '';

                            this.getPreciseLocation()
                                .then((position) => {
                                    this.gpsState = 'locked';
                                    this.userLat = position.coords.latitude;
                                    this.userLon = position.coords.longitude;
                                    this.distanceMeters = this.calculateHaversine(
                                        this.companyLat,
                                        this.companyLon,
                                        this.userLat,
                                        this.userLon
                                    );
                                    this.isInsideGeofence = this.distanceMeters <= this.radiusLimit;
                                    if (callback) callback(true);
                                })
                                .catch((err) => {
                                    this.gpsState = 'denied';
                                    this.mapGeolocationError(err);
                                    this.gpsStatusText = this.i18n.btnRetryGps || this.i18n.errGpsGeneric;
                                    if (callback) callback(false);
                                });
                        },

                        openOutsideModal() {
                            window.dispatchEvent(new CustomEvent('open-modal', { detail: 'dashboard-outside-attendance-modal' }));
                        },

                        handlePhotoCapture(event) {
                            const file = event.target.files[0];
                            if (!file) return;
                            this.selfieFile = file;
                            if (this.selfiePreviewUrl) {
                                URL.revokeObjectURL(this.selfiePreviewUrl);
                            }
                            this.selfiePreviewUrl = URL.createObjectURL(file);
                        },

                        async compressImageClientSide(file, maxDimension = 1280, quality = 0.8) {
                            if (!file || !file.type || !file.type.startsWith('image/')) {
                                return file;
                            }
                            if (file.size <= 300 * 1024) {
                                return file;
                            }

                            return new Promise((resolve) => {
                                const reader = new FileReader();
                                reader.onload = (e) => {
                                    const img = new Image();
                                    img.onload = () => {
                                        let { width, height } = img;
                                        if (width > maxDimension || height > maxDimension) {
                                            if (width > height) {
                                                height = Math.round((height * maxDimension) / width);
                                                width = maxDimension;
                                            } else {
                                                width = Math.round((width * maxDimension) / height);
                                                height = maxDimension;
                                            }
                                        }

                                        const canvas = document.createElement('canvas');
                                        canvas.width = width;
                                        canvas.height = height;
                                        const ctx = canvas.getContext('2d');
                                        if (!ctx) {
                                            return resolve(file);
                                        }
                                        ctx.drawImage(img, 0, 0, width, height);

                                        canvas.toBlob(
                                            (blob) => {
                                                if (!blob || blob.size >= file.size) {
                                                    return resolve(file);
                                                }
                                                const compressedFile = new File([blob], file.name.replace(/\.[^/.]+$/, '') + '.jpg', {
                                                    type: 'image/jpeg',
                                                    lastModified: Date.now(),
                                                });
                                                resolve(compressedFile);
                                            },
                                            'image/jpeg',
                                            quality
                                        );
                                    };
                                    img.onerror = () => resolve(file);
                                    img.src = e.target.result;
                                };
                                reader.onerror = () => resolve(file);
                                reader.readAsDataURL(file);
                            });
                        },

                        async parseServerResponse(response) {
                            const contentType = response.headers.get('content-type') || '';
                            if (contentType.includes('application/json')) {
                                try {
                                    return await response.json();
                                } catch (e) {
                                    console.error('JSON parse error despite application/json header:', e);
                                }
                            }

                            let errorDetail = '';
                            if (response.status === 413) {
                                errorDetail = this.i18n.errPayloadTooLarge;
                            } else if (response.status === 419) {
                                errorDetail = 'Sesi telah kedaluwarsa. Silakan muat ulang halaman.';
                            } else {
                                errorDetail = (this.i18n.errServerResponse || 'Respons server tidak valid (:status).').replace(':status', response.status);
                            }

                            return {
                                success: false,
                                message: errorDetail,
                            };
                        },

                        async submitOutsideAttendance() {
                            if (!this.selfieFile) {
                                this.feedbackSuccess = false;
                                this.feedbackMessage = this.i18n.selfieRequired;
                                return;
                            }

                            this.submittingOutside = true;
                            this.feedbackMessage = '';

                            let uploadFile = this.selfieFile;
                            try {
                                uploadFile = await this.compressImageClientSide(this.selfieFile);
                            } catch (e) {
                                console.warn('Client-side compression fallback:', e);
                            }

                            const formData = new FormData();
                            formData.append('latitude', this.userLat ?? 0);
                            formData.append('longitude', this.userLon ?? 0);
                            formData.append('outside_category', this.outsideCategory);
                            formData.append('outside_location_name', this.outsideLocationName);
                            formData.append('notes', this.outsideNotes);
                            formData.append('photo', uploadFile);

                            try {
                                const response = await fetch(this.outsideClockInUrl, {
                                    method: 'POST',
                                    headers: {
                                        'Accept': 'application/json',
                                        'X-Requested-With': 'XMLHttpRequest',
                                        'X-CSRF-TOKEN': this.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                                    },
                                    body: formData,
                                });

                                const result = await this.parseServerResponse(response);

                                if (response.ok && result.success) {
                                    this.feedbackSuccess = true;
                                    this.feedbackMessage = result.message;
                                    window.dispatchEvent(new CustomEvent('close-modal', { detail: 'dashboard-outside-attendance-modal' }));
                                    setTimeout(() => window.location.reload(), 500);
                                } else {
                                    this.feedbackSuccess = false;
                                    this.feedbackMessage = result.message || this.i18n.errValidation;
                                    this.submittingOutside = false;
                                }
                            } catch (error) {
                                this.feedbackSuccess = false;
                                this.feedbackMessage = error.message || this.i18n.errNetwork;
                                this.submittingOutside = false;
                            }
                        },

                        isEarlyClockOut() {
                            if (!this.shiftEndIso) return false;
                            const end = new Date(this.shiftEndIso).getTime();
                            return Number.isFinite(end) && Date.now() < end;
                        },

                        formatRemaining() {
                            const totalMinutes = Math.max(1, Math.ceil((new Date(this.shiftEndIso).getTime() - Date.now()) / 60000));
                            const h = Math.floor(totalMinutes / 60);
                            const m = totalMinutes % 60;
                            return (h > 0 ? h + 'h ' : '') + m + 'm';
                        },

                        startClockIn() {
                            if (this.distanceMeters !== null && !this.isInsideGeofence) {
                                this.openOutsideModal();
                                return;
                            }
                            this.selfieInFile = null;
                            if (this.selfieInPreviewUrl) {
                                URL.revokeObjectURL(this.selfieInPreviewUrl);
                                this.selfieInPreviewUrl = null;
                            }
                            window.dispatchEvent(new CustomEvent('open-modal', { detail: 'clock-in-selfie-modal' }));
                        },

                        openClockOutSelfieModal() {
                            this.selfieOutFile = null;
                            if (this.selfieOutPreviewUrl) {
                                URL.revokeObjectURL(this.selfieOutPreviewUrl);
                                this.selfieOutPreviewUrl = null;
                            }
                            window.dispatchEvent(new CustomEvent('open-modal', { detail: 'clock-out-selfie-modal' }));
                        },

                        startClockOut() {
                            if (this.isEarlyClockOut()) {
                                this.earlyRemainingText = this.i18n.earlyRemaining.replace(':remaining', this.formatRemaining());
                                window.dispatchEvent(new CustomEvent('open-modal', { detail: 'early-clockout-confirm-modal' }));
                                return;
                            }
                            this.openClockOutSelfieModal();
                        },

                        handleSelfieInCapture(event) {
                            const file = event.target.files[0];
                            if (!file) return;
                            this.selfieInFile = file;
                            if (this.selfieInPreviewUrl) {
                                URL.revokeObjectURL(this.selfieInPreviewUrl);
                            }
                            this.selfieInPreviewUrl = URL.createObjectURL(file);
                        },

                        handleSelfieOutCapture(event) {
                            const file = event.target.files[0];
                            if (!file) return;
                            this.selfieOutFile = file;
                            if (this.selfieOutPreviewUrl) {
                                URL.revokeObjectURL(this.selfieOutPreviewUrl);
                            }
                            this.selfieOutPreviewUrl = URL.createObjectURL(file);
                        },

                        async submitSelfieAttendance(action) {
                            const file = action === 'clock-in' ? this.selfieInFile : this.selfieOutFile;
                            if (!file) {
                                this.feedbackSuccess = false;
                                this.feedbackMessage = this.i18n.selfieRequired;
                                return;
                            }

                            this.submitting = true;
                            this.feedbackMessage = '';

                            const executeRequest = async (ok) => {
                                if (!ok || this.userLat === null || this.userLon === null) {
                                    this.submitting = false;
                                    this.feedbackSuccess = false;
                                    this.feedbackMessage = this.gpsErrorMessage || this.i18n.gpsDenied;
                                    return;
                                }

                                if (action === 'clock-in' && !this.isInsideGeofence) {
                                    this.submitting = false;
                                    window.dispatchEvent(new CustomEvent('close-modal', { detail: 'clock-in-selfie-modal' }));
                                    this.openOutsideModal();
                                    return;
                                }

                                let uploadFile = file;
                                try {
                                    uploadFile = await this.compressImageClientSide(file);
                                } catch (e) {
                                    console.warn('Client-side compression fallback:', e);
                                }

                                const targetUrl = action === 'clock-in' ? this.clockInUrl : this.clockOutUrl;
                                const formData = new FormData();
                                formData.append('latitude', this.userLat);
                                formData.append('longitude', this.userLon);
                                formData.append('photo', uploadFile);

                                try {
                                    const response = await fetch(targetUrl, {
                                        method: 'POST',
                                        headers: {
                                            'Accept': 'application/json',
                                            'X-Requested-With': 'XMLHttpRequest',
                                            'X-CSRF-TOKEN': this.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                                        },
                                        body: formData,
                                    });

                                    const result = await this.parseServerResponse(response);

                                    if (typeof result.distance === 'number') {
                                        this.distanceMeters = result.distance;
                                        this.isInsideGeofence = !result.is_outside;
                                    }

                                    if (response.ok && result.success) {
                                        this.feedbackSuccess = true;
                                        this.feedbackMessage = result.message;
                                        const modalToClose = action === 'clock-in' ? 'clock-in-selfie-modal' : 'clock-out-selfie-modal';
                                        window.dispatchEvent(new CustomEvent('close-modal', { detail: modalToClose }));
                                        setTimeout(() => window.location.reload(), 500);
                                    } else {
                                        this.feedbackSuccess = false;
                                        this.feedbackMessage = result.message || this.i18n.errValidation;
                                        this.submitting = false;
                                    }
                                } catch (error) {
                                    this.feedbackSuccess = false;
                                    this.feedbackMessage = error.message || this.i18n.errNetwork;
                                    this.submitting = false;
                                }
                            };

                            if (this.gpsState === 'locked' && this.userLat !== null && this.userLon !== null) {
                                executeRequest(true);
                            } else {
                                this.detectLocation(executeRequest);
                            }
                        },

                        submitAttendance(action, confirmedEarly = false) {
                            if (action === 'clock-in') {
                                this.startClockIn();
                                return;
                            }
                            if (action === 'clock-out') {
                                if (!confirmedEarly && this.isEarlyClockOut()) {
                                    this.earlyRemainingText = this.i18n.earlyRemaining.replace(':remaining', this.formatRemaining());
                                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'early-clockout-confirm-modal' }));
                                    return;
                                }
                                this.openClockOutSelfieModal();
                                return;
                            }
                        },
                    }));
                });
            </script>
        @endif

        {{-- =====================================================================
             WIDGET 4: SUPER ADMIN (PLATFORM OWNER)
             ===================================================================== --}}
        @if ($user->isSuperAdmin() && $platformStats)
            <section aria-label="Super Admin Platform Overview" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-ink-muted flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" style="background-color: var(--primary-accent);"></span>
                        {{ __('common.platform_global_overview') }}
                    </h3>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.total_tenant_companies') }}</div>
                        <div class="mt-2 text-3xl font-bold text-ink">
                            {{ $platformStats['total_companies'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-faint">{{ __('common.registered_active_tenants') }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.total_registered_accounts') }}</div>
                        <div class="mt-2 text-3xl font-bold text-ink">
                            {{ $platformStats['total_users_global'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-faint">{{ __('common.all_platform_users') }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.current_tenant_context') }}</div>
                        <div class="mt-2 text-lg font-bold text-ink truncate">
                            {{ $activeCompany?->name ?? __('common.global_root_all_tenants') }}
                        </div>
                        <div class="mt-1 text-xs font-mono text-ink-faint">
                            {{ $activeCompany?->code ?? 'ROOT-GLOBAL' }}
                        </div>
                    </div>
                </div>

                <!-- Quick-Switch Context Panel -->
                <div class="rounded-xl border border-line bg-surface p-6 shadow-sm">
                    <h4 class="text-sm font-semibold text-ink mb-3">
                        {{ __('common.quick_switch_tenant_panel') }}
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach ($tenants as $tenant)
                            <form method="POST" action="{{ route('company.switch') }}">
                                @csrf
                                <input type="hidden" name="company_id" value="{{ $tenant->id }}" />
                                <button type="submit"
                                        class="w-full text-left p-3.5 rounded-xl border transition flex items-center justify-between {{ (int) session('active_company_id') === (int) $tenant->id ? 'border-[var(--primary-accent)] bg-surface-muted font-semibold' : 'border-line hover:bg-surface-muted/60' }}">
                                    <div>
                                        <div class="text-sm font-bold text-ink">{{ $tenant->code }}</div>
                                        <div class="text-xs text-ink-muted truncate max-w-[200px]">{{ $tenant->name }}</div>
                                    </div>
                                    <span class="w-3 h-3 rounded-full shrink-0" style="background-color: {{ $tenant->accent_color }};"></span>
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        {{-- =====================================================================
             WIDGET 3: ADMIN (HRD) & SUPER USER (OWNER / GM)
             ===================================================================== --}}
        @if (($user->isAdmin() || $user->isSuperUser() || ($user->isSuperAdmin() && $activeCompany)) && $corporateStats)
            <section aria-label="Corporate Metrics and Department Breakdown" class="space-y-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-ink-muted flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" style="background-color: var(--primary-accent);"></span>
                        {{ __('common.corporate_stats_heading', ['code' => $activeCompany?->code]) }}
                    </h3>
                </div>

                <!-- Top Corporate Metrics -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.total_active_employees') }}</div>
                        <div class="mt-2 text-3xl font-bold text-ink">
                            {{ $corporateStats['total_active_employees'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-faint">{{ __('common.active_status_staff') }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.total_departments') }}</div>
                        <div class="mt-2 text-3xl font-bold text-ink">
                            {{ $corporateStats['total_departments'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-faint">{{ __('common.registered_org_units') }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.ratio_24h_vs_backoffice') }}</div>
                        <div class="mt-2 text-lg font-bold text-ink">
                            {{ $corporateStats['operational_departments'] }} : {{ $corporateStats['back_office_departments'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-muted">{{ $corporateStats['ratio_label'] }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.active_geofence_params') }}</div>
                        <div class="mt-2 text-sm font-mono font-semibold text-ink">
                            {{ $corporateStats['geofence_lat'] }}, {{ $corporateStats['geofence_lng'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-muted">
                            {{ __('common.attendance_radius') }}: <span class="font-bold">{{ $corporateStats['geofence_radius'] }} {{ __('common.meters') }}</span>
                        </div>
                    </div>
                </div>

                <!-- Breakdown Staf per Departemen -->
                <div class="rounded-xl border border-line bg-surface p-6 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-sm font-semibold text-ink">
                            {{ __('common.dept_staff_breakdown') }}
                        </h4>
                        <span class="text-xs text-ink-muted">{{ __('common.total_dept_count', ['count' => $departmentBreakdown->count()]) }}</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                        @foreach ($departmentBreakdown as $dept)
                            <div class="p-4 rounded-xl border border-line bg-surface-muted/40 flex flex-col justify-between gap-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <span class="inline-flex items-center text-xs font-mono font-bold px-2.5 py-0.5 rounded-md bg-ink text-canvas">
                                            {{ $dept->code }}
                                        </span>
                                        <h5 class="text-sm font-semibold text-ink mt-1.5">
                                            {{ $dept->name }}
                                        </h5>
                                    </div>
                                    @if ($dept->is_operational)
                                        <span class="o-badge o-badge-warning">{{ __('common.hours_24') }}</span>
                                    @else
                                        <span class="o-badge o-badge-neutral">{{ __('common.back_office') }}</span>
                                    @endif
                                </div>

                                <div class="pt-2 border-t border-line flex items-center justify-between text-xs">
                                    <span class="text-ink-muted">{{ __('common.active_staff_label') }}</span>
                                    <span class="font-bold text-sm text-ink">{{ __('common.people_count', ['count' => $dept->active_employees_count]) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        {{-- =====================================================================
             WIDGET 2: MANAGER (KEPALA DEPARTEMEN / SUPERVISOR)
             ===================================================================== --}}
        @if ($user->isManager() && $managerStats)
            <section aria-label="Manager Department Overview" class="space-y-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-ink-muted flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" style="background-color: var(--primary-accent);"></span>
                        {{ __('common.dept_stats_heading', ['name' => $managerStats['department_name'], 'code' => $managerStats['department_code']]) }}
                    </h3>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.total_team_members') }}</div>
                        <div class="mt-2 text-3xl font-bold text-ink">
                            {{ $managerStats['total_team_members'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-faint">{{ __('common.department_prefix', ['name' => $managerStats['department_name']]) }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.dept_operational_status') }}</div>
                        <div class="mt-2 text-lg font-bold text-ink">
                            {{ $managerStats['operational_label'] }}
                        </div>
                        <div class="mt-1 text-xs text-ink-faint">{{ __('common.unit_service_type') }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                        <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.min_staff_per_shift_quota') }}</div>
                        <div class="mt-2 text-3xl font-bold text-ink">
                            {{ $managerStats['min_staff_per_shift'] }} <span class="text-sm font-normal text-ink-muted">{{ __('common.staff_per_shift_unit') }}</span>
                        </div>
                        <div class="mt-1 text-xs text-ink-faint">{{ __('common.roster_threshold') }}</div>
                    </div>
                </div>

                <!-- Tabel Ringkas Bawahan Langsung -->
                <div class="rounded-xl border border-line bg-surface overflow-hidden shadow-sm">
                    <div class="px-6 py-4 border-b border-line flex items-center justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-ink">
                                {{ __('common.direct_subordinates_heading', ['code' => $managerStats['department_code']]) }}
                            </h4>
                            <p class="text-xs text-ink-muted">{{ __('common.showing_only_dept_staff', ['name' => $managerStats['department_name']]) }}</p>
                        </div>
                        <a href="{{ route('staff.create') }}"
                           class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold text-accent-fg shadow-xs"
                           style="background-color: var(--primary-accent);">
                            {{ __('common.add_team_member') }}
                        </a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="o-table">
                            <thead>
                                <tr>
                                    <th>{{ __('common.staff_name') }}</th>
                                    <th>{{ __('common.position') }}</th>
                                    <th>{{ __('common.join_date') }}</th>
                                    <th>{{ __('common.tags') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($subordinates as $sub)
                                    <tr>
                                        <td>
                                            <div class="font-semibold text-ink">{{ $sub->user?->name }}</div>
                                            <div class="text-xs text-ink-muted">{{ $sub->user?->email }}</div>
                                        </td>
                                        <td>{{ $sub->position }}</td>
                                        <td class="text-xs text-ink-muted">{{ $sub->join_date?->format('Y-m-d') }}</td>
                                        <td>
                                            <div class="flex flex-wrap gap-1">
                                                @forelse ($sub->tags as $tag)
                                                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-2xs font-semibold text-white"
                                                          style="background-color: {{ $tag->badge_color }};">
                                                        {{ $tag->code }}
                                                    </span>
                                                @empty
                                                    <span class="text-xs text-ink-faint">&mdash;</span>
                                                @endforelse
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="py-6 text-center text-ink-muted text-xs">
                                            {{ __('common.no_direct_subordinates') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        @endif

        {{-- =====================================================================
             WIDGET 1: USER (KARYAWAN BIASA) / PROFIL MANDIRI KEPEGAWAIAN
             ===================================================================== --}}
        @if ($user->isUser() || $user->employee)
            <section aria-label="Employee Self-Service Profile" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-ink-muted flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" style="background-color: var(--primary-accent);"></span>
                        {{ __('common.self_service_profile_heading') }}
                    </h3>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Kartu Profil Kepegawaian Mandiri -->
                    <div class="lg:col-span-2 rounded-xl border border-line bg-surface p-6 shadow-sm">
                        <div class="border-b border-line pb-4 mb-4">
                            <h4 class="text-lg font-bold text-ink">{{ $user->name }}</h4>
                            <p class="text-xs text-ink-muted">{{ $user->email }} &bull; @<span>{{ $user->username }}</span></p>
                        </div>

                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt class="text-xs text-ink-muted uppercase">{{ __('nav.departments') }}</dt>
                                <dd class="font-semibold text-ink mt-0.5">
                                    {{ $user->employee?->department?->name ?? '-' }}
                                    @if ($user->employee?->department?->code)
                                        ({{ $user->employee->department->code }})
                                    @endif
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs text-ink-muted uppercase">{{ __('common.position_title') }}</dt>
                                <dd class="font-semibold text-ink mt-0.5">
                                    {{ $user->employee?->position ?? '-' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs text-ink-muted uppercase">{{ __('common.join_date') }}</dt>
                                <dd class="font-semibold text-ink mt-0.5">
                                    {{ $user->employee?->join_date?->format('Y-m-d') ?? '-' }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-xs text-ink-muted uppercase">{{ __('common.direct_manager') }}</dt>
                                <dd class="font-semibold text-ink mt-0.5">
                                    {{ $user->employee?->manager?->user?->name ?? __('common.no_direct_manager') }}
                                </dd>
                            </div>
                        </dl>

                        @if ($user->employee && $user->employee->tags->isNotEmpty())
                            <div class="mt-4 pt-4 border-t border-line flex items-center gap-2 flex-wrap">
                                <span class="text-xs text-ink-muted">{{ __('common.functional_attributes') }}</span>
                                @foreach ($user->employee->tags as $tag)
                                    <span class="inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-semibold text-white"
                                          style="background-color: {{ $tag->badge_color }};">
                                        {{ $tag->code }} — {{ $tag->name }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <!-- Placeholder Sprint 2 (Shift Hari Ini) & Sprint 4 (Saldo Cuti & DP) -->
                    <div class="space-y-4">
                        <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.today_shift_status') }}</div>
                                <button type="button"
                                        @click="$dispatch('open-modal', 'today-schedules-modal')"
                                        class="rounded-xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-semibold px-3.5 py-2 shadow-sm flex items-center gap-2 cursor-pointer transition-colors">
                                    <span>{{ __('dashboard.btn_view_today_schedule') }}</span>
                                </button>
                            </div>
                            @if (isset($todaySchedule) && $todaySchedule && ! $todaySchedule->isOff() && $todaySchedule->shift)
                                <div class="mt-2 text-base font-bold text-ink">
                                    {{ $todaySchedule->shift->code }} — {{ $todaySchedule->shift->name }} ({{ substr($todaySchedule->getEffectiveStartTime(), 0, 5) }}–{{ substr($todaySchedule->getEffectiveEndTime(), 0, 5) }})
                                </div>
                            @else
                                <div class="mt-2 text-base font-bold text-ink">
                                    {{ __('common.not_scheduled_sprint2') }}
                                </div>
                            @endif
                            <p class="mt-1 text-xs text-ink-faint">
                                {{ __('common.sprint2_roster_desc') }}
                            </p>
                        </div>

                        <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                            <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.annual_leave_balance_est') }}</div>
                            <div class="mt-2 text-2xl font-bold text-ink">
                                12 <span class="text-xs font-normal text-ink-muted">{{ __('common.working_days_sprint4') }}</span>
                            </div>
                            <p class="mt-1 text-xs text-ink-faint">
                                {{ __('common.sprint4_leave_desc') }}
                            </p>
                        </div>

                        <!-- Placeholder DP (Day of Payment / Hak Libur Pengganti) -->
                        <div class="rounded-xl border border-line bg-surface p-5 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div class="text-xs font-medium text-ink-muted uppercase">{{ __('common.dp_balance_est') }}</div>
                                <span class="o-badge o-badge-accent cursor-help" title="{{ __('common.dp_tooltip') }}">
                                    {{ __('common.dp_badge') }}
                                </span>
                            </div>
                            <div class="mt-2 text-2xl font-bold text-ink">
                                0 <span class="text-xs font-normal text-ink-muted">{{ __('common.days_unit') }}</span>
                            </div>
                            <p class="mt-1 text-xs text-ink-faint">
                                {{ __('common.dp_desc') }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        {{-- =====================================================================
             MODAL NATIVE: JADWAL HARI INI LINTAS DEPARTEMEN (PURE 24-HOUR GANTT)
             ===================================================================== --}}
        <x-ui.modal name="today-schedules-modal" :title="__('dashboard.modal_today_title', ['date' => $todayFormatted ?? ''])" maxWidth="4xl">
            <div class="rounded-2xl border border-line bg-surface overflow-hidden shadow-2xs">
                <div class="w-full max-h-[65dvh] overflow-x-auto overflow-y-auto overscroll-x-contain overscroll-y-contain rounded-2xl">
                    <div class="min-w-[720px] sm:min-w-[860px] relative rounded-2xl bg-surface flex flex-col">
                        <!-- Header Sumbu Waktu 24 Jam (Sticky Top) -->
                        <div class="sticky top-0 z-30 flex items-center bg-surface-muted border-b border-line rounded-t-2xl">
                            <div class="sticky left-0 z-40 w-36 sm:w-48 shrink-0 bg-surface-muted border-r border-line shadow-[2px_0_6px_-2px_rgba(0,0,0,0.08)] px-3 py-2 text-2xs font-bold uppercase tracking-wider text-ink-muted truncate rounded-tl-2xl">
                                {{ __('dashboard.today_col_staff') }}
                            </div>
                            <div class="flex-1 min-w-0 relative h-8 flex items-center justify-between px-2 text-[10px] font-mono font-semibold text-ink-muted select-none whitespace-nowrap rounded-tr-2xl">
                                <span class="whitespace-nowrap">00:00</span>
                                <span class="whitespace-nowrap">02:00</span>
                                <span class="whitespace-nowrap">04:00</span>
                                <span class="whitespace-nowrap">06:00</span>
                                <span class="whitespace-nowrap">08:00</span>
                                <span class="whitespace-nowrap">10:00</span>
                                <span class="whitespace-nowrap">12:00</span>
                                <span class="whitespace-nowrap">14:00</span>
                                <span class="whitespace-nowrap">16:00</span>
                                <span class="whitespace-nowrap">18:00</span>
                                <span class="whitespace-nowrap">20:00</span>
                                <span class="whitespace-nowrap">22:00</span>
                                <span class="whitespace-nowrap">24:00</span>
                            </div>
                        </div>

                        @if (($todaySchedulesByDepartment ?? collect())->isNotEmpty())
                            @foreach (($todaySchedulesByDepartment ?? collect()) as $deptName => $deptSchedules)
                                <!-- Baris Header Pemisah Departemen (Sticky Left / Freeze saat Scroll Horizontal) -->
                                <div class="flex items-center bg-surface-muted border-b border-line">
                                    <div class="sticky left-0 z-20 w-36 sm:w-48 shrink-0 bg-surface-muted border-r border-line shadow-[2px_0_6px_-2px_rgba(0,0,0,0.08)] px-3 py-1.5 text-2xs font-bold text-ink-muted uppercase tracking-wider truncate"
                                         title="{{ $deptName }} ({{ $deptSchedules->first()?->employee?->department?->code ?? '-' }})">
                                        {{ $deptName }} ({{ $deptSchedules->first()?->employee?->department?->code ?? '-' }})
                                    </div>
                                    <div class="flex-1 min-w-0 h-7 bg-surface-muted"></div>
                                </div>

                                <!-- Baris Staf & Balok Shift 24 Jam -->
                                @foreach ($deptSchedules->groupBy('employee_id') as $empId => $empSchedules)
                                    @php
                                        $emp = $empSchedules->first()?->employee;
                                        $staffName = $emp?->user?->name ?? '-';
                                        $staffPos = $emp?->position ?? '-';
                                        // Badge MOD hanya untuk shift bertipe MOD pada hari berjalan (bukan tag profil statis).
                                        $hasMod = $empSchedules->contains(fn ($schedule) => ! $schedule->isOff()
                                            && $schedule->shift !== null
                                            && ($schedule->shift->code === 'MOD' || ($schedule->shift->is_mod ?? false)));
                                    @endphp
                                    <div class="flex items-center hover:bg-surface-muted/20 transition-colors">
                                        <!-- Kolom Kiri Staf (Sticky Left) -->
                                        <div class="sticky left-0 z-20 w-36 sm:w-48 shrink-0 bg-surface border-r border-line shadow-[2px_0_6px_-2px_rgba(0,0,0,0.08)] px-2.5 sm:px-3 h-10 sm:h-12 flex items-center gap-2 border-b border-line/40">
                                            <div class="h-6 w-6 rounded-full flex items-center justify-center text-[10px] font-bold text-accent-fg shrink-0 shadow-2xs"
                                                 style="background-color: var(--primary-accent);">
                                                {{ strtoupper(substr($staffName, 0, 1)) }}
                                            </div>
                                            <div class="min-w-0 flex-1 truncate">
                                                <div class="flex items-center gap-1">
                                                    <span class="text-xs font-semibold text-ink truncate" title="{{ $staffName }}">{{ $staffName }}</span>
                                                    @if ($hasMod)
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-3xs font-bold bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/20 shrink-0">
                                                            MOD
                                                        </span>
                                                    @endif
                                                </div>
                                                <p class="text-[10px] text-ink-muted truncate">{{ $staffPos }}</p>
                                            </div>
                                        </div>

                                        <!-- Kolom Kanan Linimasa 24 Jam -->
                                        <div class="flex-1 min-w-0 relative h-10 sm:h-12 border-b border-line/40 overflow-hidden">
                                            <!-- Garis Grid Vertikal Penanda Waktu per 2 Jam (12 Segmen) -->
                                            <div class="pointer-events-none absolute inset-0 grid grid-cols-12 divide-x divide-line/40 z-0">
                                                <div></div><div></div><div></div><div></div><div></div><div></div>
                                                <div></div><div></div><div></div><div></div><div></div><div></div>
                                            </div>

                                            @foreach ($empSchedules as $sched)
                                                @php
                                                    $startStr = $sched->getEffectiveStartTime();
                                                    $endStr = $sched->getEffectiveEndTime();
                                                    $startShort = $startStr !== '' ? substr($startStr, 0, 5) : '00:00';
                                                    $endShort = $endStr !== '' ? substr($endStr, 0, 5) : '24:00';
                                                    $startParts = $startStr !== '' ? explode(':', $startStr) : [0, 0];
                                                    $effectiveStartHour = ((int) ($startParts[0] ?? 0)) + (((int) ($startParts[1] ?? 0)) / 60.0);
                                                    $durationHours = $sched->isOff() ? 24.0 : $sched->getDurationInHours();
                                                    $leftPercent = $sched->isOff() ? 0 : round(($effectiveStartHour / 24.0) * 100, 2);
                                                    $widthPercent = $sched->isOff() ? 100 : round(($durationHours / 24.0) * 100, 2);
                                                    $isCrossDay = ! $sched->isOff() && $startStr !== '' && $endStr !== '' && $endStr <= $startStr;
                                                    $shiftCode = $sched->shift ? $sched->shift->code : 'OFF';
                                                    $shiftColor = $sched->shift ? $sched->shift->color_badge : '#71717A';
                                                @endphp
                                                <div class="absolute top-1 bottom-1 rounded-md px-2 py-0.5 text-white flex items-center justify-between overflow-hidden shadow-2xs z-10 {{ $sched->status === 'draft' ? 'opacity-75 border border-dashed border-amber-400' : 'border border-line-strong/40' }} {{ $isCrossDay ? 'border-r-4 border-r-amber-300 bg-[repeating-linear-gradient(135deg,transparent,transparent_6px,rgba(255,255,255,0.16)_6px,rgba(255,255,255,0.16)_12px)]' : '' }}"
                                                     style="background-color: {{ $shiftColor }}; left: {{ $leftPercent }}%; width: {{ $widthPercent }}%;"
                                                     title="{{ $staffName }} — {{ $shiftCode }} ({{ $startShort }} - {{ $endShort }})">
                                                    <div class="flex items-center gap-1.5 min-w-0 pr-5">
                                                        @if ($sched->status === 'draft')
                                                            <span class="h-1.5 w-1.5 rounded-full bg-amber-300 shrink-0" title="{{ __('roster.status_draft') }}"></span>
                                                        @endif
                                                        <span class="text-[11px] font-bold leading-none shrink-0">{{ $shiftCode }}</span>
                                                        @if (! $sched->isOff())
                                                            <span class="text-[10px] font-mono opacity-90 truncate whitespace-nowrap">{{ $startShort }} - {{ $endShort }}</span>
                                                        @endif
                                                        @if ($isCrossDay)
                                                            <span class="text-[8px] font-mono px-1 rounded bg-black/30 text-amber-200 shrink-0">{{ __('roster.cross_day_badge') }}</span>
                                                        @endif
                                                    </div>
                                                    @if (! $sched->isOff())
                                                        <span class="absolute top-0.5 right-1 text-3xs font-bold opacity-75 dark:opacity-90 leading-none pointer-events-none select-none">
                                                            {{ $sched->getDurationInHours() }}h
                                                        </span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            @endforeach
                        @else
                            <div class="py-10 text-center text-xs text-ink-muted font-medium">
                                {{ __('dashboard.today_empty_schedules') }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </x-ui.modal>
    </div>
</x-app-layout>
