<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="text-lg sm:text-xl font-bold text-ink tracking-tight">
                    {{ __('attendance.history_title') }}
                </h1>
                <p class="text-xs text-ink-muted mt-0.5">
                    {{ __('attendance.audit_log_subtitle') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2 shrink-0">
                @if (! empty($todayHoliday))
                    <span class="inline-flex items-center gap-1.5 rounded-lg border border-purple-500/30 bg-purple-500/10 px-3 py-1.5 text-xs font-semibold text-purple-700 dark:text-purple-300">
                        <span>✦ {{ __('attendance.holiday_detected') }}: {{ $todayHoliday->name }}</span>
                    </span>
                @endif

                @if ($todaySchedule && ! $todaySchedule->isOff() && $todaySchedule->shift)
                    <span class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold text-white shadow-2xs"
                          style="background-color: {{ $todaySchedule->shift->color_badge ?? '#3B82F6' }};">
                        <span>{{ $todaySchedule->shift->code }} — {{ $todaySchedule->shift->name }}</span>
                        <span class="font-mono opacity-90">({{ substr($todaySchedule->getEffectiveStartTime(), 0, 5) }}–{{ substr($todaySchedule->getEffectiveEndTime(), 0, 5) }})</span>
                    </span>
                @endif

                <span class="inline-flex items-center gap-1.5 rounded-lg bg-surface-muted px-3 py-1.5 text-xs font-mono font-semibold text-ink border border-line">
                    <svg class="w-3.5 h-3.5 text-accent" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                    </svg>
                    {{ __('attendance.radius_limit_label') }}: {{ $currentCompany?->geofence_radius ?? 100 }}m
                </span>
            </div>
        </div>
    </x-slot>

    <div class="space-y-5" x-data="{
        edit: { action: '', clockIn: '', clockOut: '', status: 'on_time', name: '' },
        del: { action: '', name: '' },
        inspectPhoto: { url: '', title: '', name: '', time: '', distance: '' }
    }">
        @if (session('success'))
            <div class="bg-emerald-500/10 border border-emerald-500/20 text-emerald-700 dark:text-emerald-300 rounded-xl p-3.5 text-sm">
                {{ session('success') }}
            </div>
        @endif

        <!-- Bilah Filter Log Audit Presensi -->
        <div class="rounded-2xl border border-line bg-surface p-4 sm:p-5 shadow-2xs">
            <form method="GET" action="{{ route('attendance.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3.5 items-end">
                <!-- Rentang Tanggal: Dari -->
                <div class="{{ $canSearchStaff ? 'lg:col-span-2' : 'lg:col-span-3' }} space-y-1">
                    <label for="filter_date_from" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                        {{ __('attendance.filter_date_from') }}
                    </label>
                    <input type="date"
                           id="filter_date_from"
                           name="date_from"
                           value="{{ $filterDateFrom }}"
                           class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                </div>

                <!-- Rentang Tanggal: Sampai -->
                <div class="{{ $canSearchStaff ? 'lg:col-span-2' : 'lg:col-span-3' }} space-y-1">
                    <label for="filter_date_to" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                        {{ __('attendance.filter_date_to') }}
                    </label>
                    <input type="date"
                           id="filter_date_to"
                           name="date_to"
                           value="{{ $filterDateTo }}"
                           class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                </div>

                <!-- Filter Departemen (Admin/SuperUser: Bebas pilih; Manager: Terkunci pada departemennya; User: Disembunyikan) -->
                @if ($canFilterDepartment)
                    <div class="lg:col-span-2 space-y-1">
                        <label for="filter_department_id" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                            {{ __('attendance.filter_department') }}
                        </label>
                        <select id="filter_department_id"
                                name="department_id"
                                class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                            <option value="">{{ __('attendance.filter_all_departments') }}</option>
                            @foreach ($departments as $dept)
                                <option value="{{ $dept->id }}" @selected((string) $filterDepartmentId === (string) $dept->id)>
                                    {{ $dept->code }} — {{ $dept->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @elseif ($isManagerTier && $employee?->department)
                    <div class="lg:col-span-2 space-y-1">
                        <label class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                            {{ __('attendance.filter_department') }}
                        </label>
                        <select disabled
                                aria-label="{{ __('attendance.filter_department') }}"
                                class="w-full rounded-xl border border-line bg-surface-muted/60 px-3 py-2 text-xs font-semibold text-ink-muted cursor-not-allowed">
                            <option selected>{{ $employee->department->code }} — {{ $employee->department->name }}</option>
                        </select>
                    </div>
                @endif

                <!-- Filter Status Kehadiran -->
                <div class="{{ $canSearchStaff ? 'lg:col-span-2' : 'lg:col-span-4' }} space-y-1">
                    <label for="filter_status" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                        {{ __('attendance.filter_status') }}
                    </label>
                    <select id="filter_status"
                            name="status"
                            class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                        <option value="all" @selected($filterStatus === 'all')>{{ __('attendance.filter_all_statuses') }}</option>
                        <option value="on_time" @selected($filterStatus === 'on_time')>{{ __('attendance.status_on_time') }}</option>
                        <option value="late" @selected($filterStatus === 'late')>{{ __('attendance.status_late') }}</option>
                        <option value="early_leave" @selected($filterStatus === 'early_leave')>{{ __('attendance.status_early_leave') }}</option>
                        <option value="pending_verification" @selected($filterStatus === 'pending_verification')>{{ __('attendance.status_pending_verification') }}</option>
                        <option value="rejected" @selected($filterStatus === 'rejected')>{{ __('attendance.status_rejected') }}</option>
                    </select>
                </div>

                <!-- Filter Pencarian Nama Staf (Hanya Manager / Admin / Super User / Super Admin) -->
                @if ($canSearchStaff)
                    <div class="lg:col-span-2 space-y-1">
                        <label for="filter_search" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                            {{ __('attendance.filter_search_staff') }}
                        </label>
                        <input type="text"
                               id="filter_search"
                               name="search"
                               value="{{ $filterSearch }}"
                               placeholder="{{ __('attendance.filter_search_placeholder') }}"
                               class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink placeholder:text-ink-muted/70 focus:outline-none focus:ring-2 focus:ring-accent" />
                    </div>
                @endif

                <!-- Tombol Aksi Filter -->
                <div class="lg:col-span-2 flex items-center gap-2">
                    <button type="submit"
                            class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-xl bg-accent px-3.5 py-2 text-xs font-bold text-white shadow-2xs hover:opacity-90 transition cursor-pointer">
                        <span>{{ __('attendance.btn_filter') }}</span>
                    </button>
                    <a href="{{ route('attendance.index') }}"
                       class="inline-flex items-center justify-center rounded-xl border border-line bg-surface px-3 py-2 text-xs font-semibold text-ink-muted hover:text-ink hover:bg-surface-muted transition">
                        {{ __('attendance.btn_reset_filter') }}
                    </a>
                </div>
            </form>
        </div>

        <!-- Tabel Log Audit Riwayat Presensi (.o-table) -->
        <div class="rounded-2xl border border-line bg-surface p-4 sm:p-5 shadow-2xs space-y-4">
            <x-ui.table :striped="true">
                <x-slot name="header">
                    <th>{{ __('attendance.col_date') }}</th>
                    @if ($showEmployeeColumn)
                        <th>{{ __('attendance.col_employee') }}</th>
                    @endif
                    <th>{{ __('attendance.filter_department') }}</th>
                    <th>{{ __('attendance.col_shift') }}</th>
                    <th>{{ __('attendance.col_clock_in') }}</th>
                    <th>{{ __('attendance.col_clock_out') }}</th>
                    <th>{{ __('attendance.col_photo_attendance') }}</th>
                    <th>{{ __('attendance.col_duration') }}</th>
                    <th>{{ __('attendance.col_status') }}</th>
                    <th>{{ __('attendance.col_distance') }}</th>
                    @if ($canManageAttendance ?? false)
                        <th>{{ __('attendance.col_actions_admin') }}</th>
                    @endif
                </x-slot>

                @forelse ($attendances as $record)
                    <tr>
                        <td class="font-mono text-xs text-ink whitespace-nowrap">
                            {{ $record->clock_in_at?->timezone('Asia/Jakarta')->format('Y-m-d') ?? '-' }}
                        </td>

                        @if ($showEmployeeColumn)
                            <td>
                                <div class="font-semibold text-xs text-ink">
                                    {{ $record->employee?->user?->name ?? '-' }}
                                </div>
                                <div class="text-2xs text-ink-muted">
                                    {{ $record->employee?->position ?? '-' }}
                                </div>
                            </td>
                        @endif

                        <td class="text-xs text-ink whitespace-nowrap">
                            @if ($record->employee?->department)
                                <span class="inline-flex items-center gap-1 rounded-md bg-surface-muted px-2 py-0.5 text-2xs font-mono font-bold text-ink border border-line">
                                    {{ $record->employee->department->code }}
                                </span>
                                <span class="text-2xs text-ink-muted ml-1 hidden sm:inline">
                                    {{ $record->employee->department->name }}
                                </span>
                            @else
                                <span class="text-ink-muted">—</span>
                            @endif
                        </td>

                        <td class="text-xs text-ink whitespace-nowrap">
                            @if ($record->workSchedule?->shift)
                                <span class="inline-flex items-center gap-1.5 font-semibold">
                                    <span class="h-2 w-2 rounded-full shrink-0" style="background-color: {{ $record->workSchedule->shift->color_badge ?? '#3B82F6' }};"></span>
                                    <span>{{ $record->workSchedule->shift->code }}</span>
                                    <span class="font-mono text-2xs text-ink-muted">({{ substr($record->workSchedule->getEffectiveStartTime(), 0, 5) }}–{{ substr($record->workSchedule->getEffectiveEndTime(), 0, 5) }})</span>
                                </span>
                            @else
                                <span class="text-ink-muted">—</span>
                            @endif
                        </td>

                        <td class="font-mono text-xs text-ink whitespace-nowrap">
                            {{ $record->clock_in_at?->timezone('Asia/Jakarta')->format('H:i:s') ?? '-' }}
                        </td>

                        <td class="font-mono text-xs text-ink whitespace-nowrap">
                            {{ $record->clock_out_at?->timezone('Asia/Jakarta')->format('H:i:s') ?? '—' }}
                        </td>

                        <td class="whitespace-nowrap">
                            <div class="inline-flex items-center gap-2">
                                <!-- Swafoto Masuk -->
                                @if ($record->clock_in_photo_path || $record->proof_photo_path)
                                    <button type="button"
                                            @click="inspectPhoto = {
                                                url: {{ Js::from(route('attendance.proof', ['attendance' => $record->uuid, 'type' => 'clock_in'])) }},
                                                title: {{ Js::from(__('attendance.col_selfie_in')) }},
                                                name: {{ Js::from($record->employee?->user?->name ?? '-') }},
                                                time: {{ Js::from($record->clock_in_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') ?? '-') }},
                                                distance: {{ Js::from($record->clock_in_distance_meters !== null ? $record->clock_in_distance_meters . 'm' : '-') }}
                                            }; $dispatch('open-modal', 'inspect-attendance-photo-modal')"
                                            class="group inline-flex flex-col items-center cursor-pointer focus:outline-none"
                                            title="{{ __('attendance.col_selfie_in') }}">
                                        <img src="{{ route('attendance.proof', ['attendance' => $record->uuid, 'type' => 'clock_in']) }}"
                                             alt="{{ __('attendance.col_selfie_in') }}"
                                             loading="lazy"
                                             class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg object-cover border border-line shadow-2xs group-hover:scale-105 transition" />
                                        <span class="text-3xs font-medium text-ink-muted mt-0.5">{{ __('attendance.col_selfie_in') }}</span>
                                    </button>
                                @elseif ($record->clock_in_at)
                                    <div class="inline-flex flex-col items-center" title="{{ __('attendance.photo_expired') }}">
                                        <span class="inline-flex items-center justify-center w-7 h-7 sm:w-8 sm:h-8 rounded-lg bg-surface-muted border border-line text-3xs text-ink-muted italic">
                                            Ø
                                        </span>
                                        <span class="text-3xs text-ink-muted mt-0.5">{{ __('attendance.col_selfie_in') }}</span>
                                    </div>
                                @endif

                                <!-- Swafoto Pulang -->
                                @if ($record->clock_out_photo_path)
                                    <button type="button"
                                            @click="inspectPhoto = {
                                                url: {{ Js::from(route('attendance.proof', ['attendance' => $record->uuid, 'type' => 'clock_out'])) }},
                                                title: {{ Js::from(__('attendance.col_selfie_out')) }},
                                                name: {{ Js::from($record->employee?->user?->name ?? '-') }},
                                                time: {{ Js::from($record->clock_out_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') ?? '-') }},
                                                distance: {{ Js::from($record->clock_out_distance_meters !== null ? $record->clock_out_distance_meters . 'm' : ($record->clock_in_distance_meters !== null ? $record->clock_in_distance_meters . 'm' : '-')) }}
                                            }; $dispatch('open-modal', 'inspect-attendance-photo-modal')"
                                            class="group inline-flex flex-col items-center cursor-pointer focus:outline-none"
                                            title="{{ __('attendance.col_selfie_out') }}">
                                        <img src="{{ route('attendance.proof', ['attendance' => $record->uuid, 'type' => 'clock_out']) }}"
                                             alt="{{ __('attendance.col_selfie_out') }}"
                                             loading="lazy"
                                             class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg object-cover border border-line shadow-2xs group-hover:scale-105 transition" />
                                        <span class="text-3xs font-medium text-ink-muted mt-0.5">{{ __('attendance.col_selfie_out') }}</span>
                                    </button>
                                @elseif ($record->clock_out_at)
                                    <div class="inline-flex flex-col items-center" title="{{ __('attendance.photo_expired') }}">
                                        <span class="inline-flex items-center justify-center w-7 h-7 sm:w-8 sm:h-8 rounded-lg bg-surface-muted border border-line text-3xs text-ink-muted italic">
                                            Ø
                                        </span>
                                        <span class="text-3xs text-ink-muted mt-0.5">{{ __('attendance.col_selfie_out') }}</span>
                                    </div>
                                @endif
                            </div>
                        </td>

                        <td class="font-mono text-xs text-ink whitespace-nowrap">
                            {{ $record->clock_out_at ? $record->getWorkDurationInHours() . 'h' : '—' }}
                        </td>

                        <td class="whitespace-nowrap">
                            <div class="inline-flex flex-wrap items-center gap-1">
                                @if ($record->status === 'on_time')
                                    <span class="inline-flex items-center rounded-md bg-emerald-500/10 px-2 py-0.5 text-2xs font-semibold text-emerald-600 dark:text-emerald-400 border border-emerald-500/30">
                                        {{ __('attendance.status_on_time') }}
                                    </span>
                                @elseif ($record->status === 'late')
                                    <span class="inline-flex items-center gap-1 rounded-md bg-amber-500/10 px-2 py-0.5 text-2xs font-semibold text-amber-600 dark:text-amber-400 border border-amber-500/30">
                                        {{ __('attendance.status_late') }}
                                        @if ($record->late_minutes > 0)
                                            <span>({{ $record->late_minutes }}m)</span>
                                        @endif
                                    </span>
                                @elseif ($record->status === 'early_leave')
                                    <span class="inline-flex items-center rounded-md bg-amber-500/10 px-2 py-0.5 text-2xs font-semibold text-amber-600 dark:text-amber-400 border border-amber-500/30">
                                        {{ __('attendance.status_early_leave') }}
                                    </span>
                                @elseif ($record->status === 'pending_verification')
                                    <span class="inline-flex items-center gap-1 rounded-md bg-amber-500/10 px-2 py-0.5 text-2xs font-semibold text-amber-600 dark:text-amber-400 border border-amber-500/30">
                                        {{ __('attendance.status_pending_verification') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-md bg-red-500/10 px-2 py-0.5 text-2xs font-semibold text-red-600 dark:text-red-400 border border-red-500/30">
                                        {{ __('attendance.status_rejected') }}
                                    </span>
                                @endif

                                @if ($record->is_provisional_dp)
                                    <span title="{{ __('attendance.tooltip_provisional_dp') }}"
                                          class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-3xs font-semibold bg-purple-500/10 text-purple-700 dark:text-purple-300 border border-purple-500/20">
                                        <span>✦ {{ __('attendance.badge_provisional_dp') }}</span>
                                    </span>
                                @endif
                            </div>
                        </td>

                        <td class="font-mono text-xs text-ink-muted whitespace-nowrap">
                            @if ($record->clock_in_distance_meters !== null)
                                <span class="{{ $record->is_outside_geofence ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-ink-muted' }}">
                                    {{ $record->clock_in_distance_meters }}m
                                </span>
                            @else
                                <span>—</span>
                            @endif
                        </td>

                        @if ($canManageAttendance ?? false)
                            <td class="whitespace-nowrap">
                                <div class="inline-flex items-center gap-1.5">
                                    <button type="button"
                                            @click="edit = {
                                                action: {{ Js::from(route('attendance.update', $record->uuid)) }},
                                                clockIn: {{ Js::from($record->clock_in_at?->timezone('Asia/Jakarta')->format('Y-m-d\TH:i:s')) }},
                                                clockOut: {{ Js::from($record->clock_out_at?->timezone('Asia/Jakarta')->format('Y-m-d\TH:i:s') ?? '') }},
                                                status: {{ Js::from($record->status) }},
                                                name: {{ Js::from($record->employee?->user?->name ?? '') }}
                                            }; $dispatch('open-modal', 'edit-attendance-modal')"
                                            class="rounded-lg border border-line bg-surface px-2.5 py-1 text-2xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                                        {{ __('attendance.btn_edit') }}
                                    </button>
                                    <button type="button"
                                            @click="del = {
                                                action: {{ Js::from(route('attendance.destroy', $record->uuid)) }},
                                                name: {{ Js::from($record->employee?->user?->name ?? '') }}
                                            }; $dispatch('open-modal', 'delete-attendance-modal')"
                                            class="rounded-lg border border-red-500/30 bg-red-500/10 px-2.5 py-1 text-2xs font-semibold text-red-600 dark:text-red-400 hover:bg-red-500/20 transition cursor-pointer">
                                        {{ __('attendance.btn_delete') }}
                                    </button>
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ ($showEmployeeColumn ? 10 : 9) + (($canManageAttendance ?? false) ? 1 : 0) }}" class="py-8 text-center text-xs text-ink-muted">
                            {{ __('attendance.history_empty') }}
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>

            @if ($attendances->hasPages())
                <div class="pt-3 border-t border-line">
                    {{ $attendances->links() }}
                </div>
            @endif
        </div>

        @if ($canManageAttendance ?? false)
            <x-ui.modal name="edit-attendance-modal" :title="__('attendance.modal_edit_attendance_title')" maxWidth="lg">
                <form method="POST" :action="edit.action" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <p class="text-xs font-semibold text-ink" x-text="edit.name"></p>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-semibold text-ink">{{ __('attendance.col_clock_in') }}</label>
                        <input type="datetime-local" step="1" x-model="edit.clockIn" required
                               class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                        <input type="hidden" name="clock_in_at" :value="edit.clockIn ? edit.clockIn.replace('T', ' ') : ''" />
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-semibold text-ink">{{ __('attendance.col_clock_out') }}</label>
                        <input type="datetime-local" step="1" x-model="edit.clockOut"
                               class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                        <input type="hidden" name="clock_out_at" :value="edit.clockOut ? edit.clockOut.replace('T', ' ') : ''" />
                    </div>

                    <div class="space-y-1.5">
                        <label for="edit_attendance_status" class="block text-xs font-semibold text-ink">{{ __('attendance.col_status') }}</label>
                        <select id="edit_attendance_status" name="status" x-model="edit.status" required
                                class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                            <option value="on_time">{{ __('attendance.status_on_time') }}</option>
                            <option value="late">{{ __('attendance.status_late') }}</option>
                            <option value="early_leave">{{ __('attendance.status_early_leave') }}</option>
                            <option value="pending_verification">{{ __('attendance.status_pending_verification') }}</option>
                            <option value="rejected">{{ __('attendance.status_rejected') }}</option>
                        </select>
                    </div>

                    <div class="space-y-1.5">
                        <label for="edit_attendance_notes" class="block text-xs font-semibold text-ink">{{ __('attendance.correction_notes_label') }}</label>
                        <textarea id="edit_attendance_notes" name="notes" rows="3" maxlength="500"
                                  class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent"></textarea>
                    </div>

                    <div class="pt-2 flex items-center justify-end gap-2">
                        <button type="button" @click="$dispatch('close-modal', 'edit-attendance-modal')"
                                class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                            {{ __('common.cancel') }}
                        </button>
                        <button type="submit"
                                class="rounded-xl bg-accent px-4 py-2 text-xs font-bold text-white shadow-sm hover:opacity-95 transition cursor-pointer">
                            {{ __('attendance.btn_save_correction') }}
                        </button>
                    </div>
                </form>
            </x-ui.modal>

            <x-ui.modal name="delete-attendance-modal" :title="__('attendance.modal_delete_attendance_title')" maxWidth="md">
                <form method="POST" :action="del.action" class="space-y-4">
                    @csrf
                    @method('DELETE')
                    <p class="text-xs font-semibold text-ink" x-text="del.name"></p>
                    <p class="text-sm text-red-600 dark:text-red-400">{{ __('attendance.modal_delete_attendance_desc') }}</p>
                    <div class="pt-2 flex items-center justify-end gap-2">
                        <button type="button" @click="$dispatch('close-modal', 'delete-attendance-modal')"
                                class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                            {{ __('common.cancel') }}
                        </button>
                        <button type="submit"
                                class="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-red-700 transition cursor-pointer">
                            {{ __('attendance.btn_delete') }}
                        </button>
                    </div>
                </form>
            </x-ui.modal>
        @endif

        <!-- Modal Inspeksi Swafoto Resolusi Penuh -->
        <x-ui.modal name="inspect-attendance-photo-modal" :title="__('attendance.modal_inspect_selfie_title')" maxWidth="md">
            <div class="space-y-4">
                <div class="text-center">
                    <template x-if="inspectPhoto.url">
                        <img :src="inspectPhoto.url"
                             :alt="inspectPhoto.title"
                             class="mx-auto max-h-80 w-auto rounded-2xl object-cover border border-line shadow-md" />
                    </template>
                </div>

                <div class="rounded-xl bg-surface-muted/60 border border-line p-3 text-xs space-y-1.5">
                    <div class="flex items-center justify-between">
                        <span class="text-ink-muted font-medium">{{ __('attendance.col_employee') }}</span>
                        <span class="font-bold text-ink" x-text="inspectPhoto.name"></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-ink-muted font-medium" x-text="inspectPhoto.title"></span>
                        <span class="font-mono text-ink" x-text="inspectPhoto.time"></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-ink-muted font-medium">{{ __('attendance.col_distance') }}</span>
                        <span class="font-mono text-ink" x-text="inspectPhoto.distance"></span>
                    </div>
                </div>

                <div class="pt-2 flex justify-end">
                    <button type="button"
                            @click="$dispatch('close-modal', 'inspect-attendance-photo-modal')"
                            class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                        {{ __('common.close') }}
                    </button>
                </div>
            </div>
        </x-ui.modal>
    </div>
</x-app-layout>
