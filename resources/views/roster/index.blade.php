<x-app-layout>
    <x-slot name="fullbleed">
        <div x-data="rosterMatrix({
                employees: @js($employeesPayload),
                days: @js($daysInMonth),
                shifts: @js($shiftsPayload),
                schedules: @js($schedulesMatrix),
                formationByDate: @js($formationByDate),
                modAuditByDate: @js($modAuditByDate),
                draftCount: {{ $draftCount }},
                publishedCount: {{ $publishedCount }},
                pendingDeleteCount: {{ $pendingDeleteCount ?? 0 }},
                rosterStatus: '{{ $rosterStatus }}',
                modOnly: {{ $modOnly ? 'true' : 'false' }},
                canEdit: {{ $canEdit ? 'true' : 'false' }},
                month: {{ $month }},
                year: {{ $year }},
                selectedDepartmentId: {{ $selectedDepartmentId ? $selectedDepartmentId : 'null' }},
                crossDayBadgeLabel: '{{ __('roster.cross_day_badge') }}',
                i18n: {
                    savedDraft: '{{ __('roster.saved_successfully') }}',
                    saveFailed: '{{ __('roster.sync_error') }}',
                    turnaroundDefault: '{{ __('roster.turnaround_warning_title') }}',
                    deleted: '{{ __('roster.deleted_successfully') }}',
                    restored: '{{ __('roster.restored_successfully') }}',
                    resized: '{{ __('roster.resized_successfully') }}',
                    shiftCreated: '{{ __('roster.shift_created_successfully') }}',
                    shiftUpdated: '{{ __('roster.shift_updated_successfully') }}',
                    published: '{{ __('roster.status_published') }}',
                    discarded: '{{ __('roster.discard_success') }}',
                    splitSuccess: '{{ __('roster.split_success') }}',
                    defaultNewShiftName: '{{ __('roster.default_new_shift_name') }}'
                },
                assignUrl: '{{ route('roster.assign') }}',
                splitUrlTemplate: '{{ route('roster.split', '__ID__') }}',
                resizeUrlTemplate: '{{ route('roster.resize', '__ID__') }}',
                restoreUrlTemplate: '{{ route('roster.restore', '__ID__') }}',
                destroyUrlTemplate: '{{ route('roster.destroy', '__ID__') }}',
                publishUrl: '{{ route('roster.publish') }}',
                discardUrl: '{{ route('roster.discard') }}',
                storeShiftUrl: '{{ route('roster.shifts.store') }}',
                updateShiftUrlTemplate: '{{ route('roster.shifts.update', '__ID__') }}'
             })"
             class="flex-1 min-h-0 flex flex-col overflow-hidden bg-canvas text-ink select-none">

            <!-- Toolbar Palet Shift & Status Sinkronisasi -->
            <div class="shrink-0 border-b border-line bg-surface px-3 py-2 sm:px-4 flex flex-wrap items-center justify-between gap-2 sm:gap-3 w-full max-w-full z-30">
                <!-- Sisi Kiri: Judul Halaman & Palet Shift Draggable Murni dengan Hover Tooltip -->
                <div class="flex flex-wrap items-center gap-2 sm:gap-3 min-w-0 w-full sm:w-auto">
                    <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto min-w-0">
                        <span class="inline-flex h-2.5 w-2.5 rounded-full shrink-0" style="background-color: var(--primary-accent);"></span>
                        <h1 class="text-xs sm:text-sm font-bold tracking-tight text-ink truncate">
                            {{ __('roster.title') }}
                        </h1>
                        <span class="hidden md:inline-flex rounded-md bg-surface-muted px-2 py-0.5 text-[10px] font-mono font-semibold text-ink-muted ring-1 ring-inset ring-line shrink-0">
                            {{ $startOfMonth->translatedFormat('F Y') }}
                        </span>

                        <!-- Badge Status Publikasi Reaktif -->
                        <span class="o-badge font-mono text-[10px] shrink-0 whitespace-nowrap"
                              :class="!hasPendingChanges && publishedCount > 0 ? 'o-badge-success' : 'o-badge-warning'"
                              x-text="!hasPendingChanges && publishedCount > 0 ? '{{ __('roster.status_published') }}' : ('{{ __('roster.status_draft') }}' + (hasPendingChanges ? ' (' + (draftCount + pendingDeleteCount) + ')' : ''))">
                        </span>
                    </div>

                    <!-- Shift Palette Toolbar (Pure Drag-and-Drop & Hover Tooltips — Tanpa Mode Kuas) -->
                    @if ($canEdit)
                        <div class="h-4 w-px bg-line hidden sm:block"></div>
                        <div class="flex flex-wrap items-center gap-1.5 py-0.5 max-w-full">
                            <div id="roster-shift-palette" class="flex flex-wrap items-center gap-1.5 max-w-full">
                                @foreach ($shifts as $s)
                                    <div class="relative group shrink-0"
                                         draggable="true"
                                         data-shift-id="{{ $s->id }}"
                                         data-shift-code="{{ $s->code }}"
                                         data-is-off="false"
                                         @dragstart="dragStartShift($event, { shift_id: {{ $s->id }}, code: '{{ $s->code }}', is_off: false })"
                                         @touchstart="handleTouchStart($event, { id: {{ $s->id }}, shift_id: {{ $s->id }}, code: '{{ $s->code }}', color_badge: '{{ $s->color_badge }}', is_off: false })"
                                         @touchmove="handleTouchMove($event)"
                                         @touchend="handleTouchEnd($event)">
                                        <button type="button"
                                                draggable="true"
                                                data-shift-id="{{ $s->id }}"
                                                data-shift-code="{{ $s->code }}"
                                                data-is-off="false"
                                                @dragstart="dragStartShift($event, { shift_id: {{ $s->id }}, code: '{{ $s->code }}', is_off: false })"
                                                @touchstart="handleTouchStart($event, { id: {{ $s->id }}, shift_id: {{ $s->id }}, code: '{{ $s->code }}', color_badge: '{{ $s->color_badge }}', is_off: false })"
                                                @touchmove="handleTouchMove($event)"
                                                @touchend="handleTouchEnd($event)"
                                                :class="isTouchSelected({{ $s->id }}, false) ? 'ring-2 ring-offset-1 ring-accent scale-105' : ''"
                                                :style="'background-color: ' + (getShiftByCode('{{ $s->code }}')?.color_badge || '{{ $s->color_badge }}')"
                                                style="background-color: {{ $s->color_badge }};"
                                                class="cursor-grab active:cursor-grabbing px-2 py-1 rounded-md text-white text-[11px] font-bold shadow-2xs opacity-95 hover:opacity-100 transition-all flex items-center gap-1 shrink-0 touch-none">
                                            <span class="pointer-events-none">{{ $s->code }}</span>
                                            <span class="pointer-events-none text-[9px] font-mono opacity-80 hidden lg:inline"
                                                  x-text="formatTimeShort(getShiftByCode('{{ $s->code }}')?.start_time || '{{ $s->start_time }}')">{{ \Carbon\Carbon::parse($s->start_time)->format('H:i') }}</span>
                                        </button>

                                        <!-- Floating Tooltip Detail Palet Shift -->
                                        <div role="tooltip"
                                             class="pointer-events-none absolute top-full left-0 mt-1.5 z-50 px-2.5 py-1.5 text-2xs font-medium text-ink bg-surface-raised border border-line-strong rounded-lg shadow-xl opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 transition-opacity duration-200 whitespace-nowrap">
                                            <span class="font-bold" x-text="getShiftByCode('{{ $s->code }}')?.name || '{{ $s->name }}'">{{ $s->name }}</span>
                                            <span class="font-mono text-ink-muted ml-1"
                                                  x-text="'(' + formatTimeShort(getShiftByCode('{{ $s->code }}')?.start_time || '{{ $s->start_time }}') + ' – ' + formatTimeShort(getShiftByCode('{{ $s->code }}')?.end_time || '{{ $s->end_time }}') + ')'">({{ \Carbon\Carbon::parse($s->start_time)->format('H:i') }} – {{ \Carbon\Carbon::parse($s->end_time)->format('H:i') }})</span>
                                            <span class="mx-1 text-ink-faint">•</span>
                                            <span class="font-semibold text-accent"
                                                  x-text="(getShiftByCode('{{ $s->code }}')?.duration_hours ?? {{ $s->getDurationInHours() }}) + ' {{ __('roster.operational_hours') }}'">{{ $s->getDurationInHours() }} {{ __('roster.operational_hours') }}</span>
                                            <template x-if="getShiftByCode('{{ $s->code }}')?.cross_day">
                                                <span class="ml-1.5 px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-700 dark:text-amber-300 font-mono text-[9px] font-bold">
                                                    {{ __('roster.cross_day_label') }}
                                                </span>
                                            </template>
                                        </div>
                                    </div>
                                @endforeach

                                <!-- Shift Baru yang Ditambahkan secara Reaktif via Modal -->
                                <template x-for="dynShift in shifts.filter(item => ![{{ $shifts->pluck('id')->implode(',') }}].includes(Number(item.id)))" :key="'dyn-shift-' + dynShift.id">
                                    <div class="relative group shrink-0"
                                         draggable="true"
                                         :data-shift-id="dynShift.id"
                                         :data-shift-code="dynShift.code"
                                         data-is-off="false"
                                         @dragstart="dragStartShift($event, { shift_id: dynShift.id, code: dynShift.code, is_off: false })"
                                         @touchstart="handleTouchStart($event, dynShift)"
                                         @touchmove="handleTouchMove($event)"
                                         @touchend="handleTouchEnd($event)">
                                        <button type="button"
                                                draggable="true"
                                                :data-shift-id="dynShift.id"
                                                :data-shift-code="dynShift.code"
                                                data-is-off="false"
                                                @dragstart="dragStartShift($event, { shift_id: dynShift.id, code: dynShift.code, is_off: false })"
                                                @touchstart="handleTouchStart($event, dynShift)"
                                                @touchmove="handleTouchMove($event)"
                                                @touchend="handleTouchEnd($event)"
                                                :class="isTouchSelected(dynShift.id, false) ? 'ring-2 ring-offset-1 ring-accent scale-105' : ''"
                                                :style="'background-color: ' + (dynShift.color_badge || '#3B82F6')"
                                                class="cursor-grab active:cursor-grabbing px-2 py-1 rounded-md text-white text-[11px] font-bold shadow-2xs opacity-95 hover:opacity-100 transition-all flex items-center gap-1 shrink-0 touch-none">
                                            <span class="pointer-events-none" x-text="dynShift.code"></span>
                                            <span class="pointer-events-none text-[9px] font-mono opacity-80 hidden lg:inline"
                                                  x-text="formatTimeShort(dynShift.start_time)"></span>
                                        </button>

                                        <div role="tooltip"
                                             class="pointer-events-none absolute top-full left-0 mt-1.5 z-50 px-2.5 py-1.5 text-2xs font-medium text-ink bg-surface-raised border border-line-strong rounded-lg shadow-xl opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 transition-opacity duration-200 whitespace-nowrap">
                                            <span class="font-bold" x-text="dynShift.name"></span>
                                            <span class="font-mono text-ink-muted ml-1"
                                                  x-text="'(' + formatTimeShort(dynShift.start_time) + ' – ' + formatTimeShort(dynShift.end_time) + ')'"></span>
                                            <span class="mx-1 text-ink-faint">•</span>
                                            <span class="font-semibold text-accent"
                                                  x-text="(dynShift.duration_hours ?? 8) + ' {{ __('roster.operational_hours') }}'"></span>
                                        </div>
                                    </div>
                                </template>

                                <!-- Tombol OFF / DP (Day of Payment) Draggable Murni -->
                                <div class="relative group shrink-0"
                                     draggable="true"
                                     data-shift-id=""
                                     data-shift-code="OFF"
                                     data-is-off="true"
                                     @dragstart="dragStartShift($event, { shift_id: null, code: 'OFF', is_off: true })"
                                     @touchstart="handleTouchStart($event, { id: null, shift_id: null, code: 'OFF', color_badge: '#52525B', is_off: true })"
                                     @touchmove="handleTouchMove($event)"
                                     @touchend="handleTouchEnd($event)">
                                    <button type="button"
                                            draggable="true"
                                            data-shift-id=""
                                            data-shift-code="OFF"
                                            data-is-off="true"
                                            @dragstart="dragStartShift($event, { shift_id: null, code: 'OFF', is_off: true })"
                                            @touchstart="handleTouchStart($event, { id: null, shift_id: null, code: 'OFF', color_badge: '#52525B', is_off: true })"
                                            @touchmove="handleTouchMove($event)"
                                            @touchend="handleTouchEnd($event)"
                                            :class="isTouchSelected(null, true) ? 'ring-2 ring-offset-1 ring-accent scale-105' : ''"
                                            class="cursor-grab active:cursor-grabbing px-2 py-1 rounded-md bg-zinc-600 hover:bg-zinc-700 text-white text-[11px] font-bold shadow-2xs opacity-95 hover:opacity-100 transition-all flex items-center gap-1 shrink-0 touch-none">
                                        <span class="pointer-events-none">OFF</span>
                                        <span class="pointer-events-none text-[9px] font-mono opacity-80 hidden lg:inline">DP</span>
                                    </button>

                                    <div role="tooltip"
                                         class="pointer-events-none absolute top-full left-0 mt-1.5 z-50 px-2.5 py-1.5 text-2xs font-medium text-ink bg-surface-raised border border-line-strong rounded-lg shadow-xl opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 transition-opacity duration-200 whitespace-nowrap">
                                        {{ __('roster.day_off_tooltip') }}
                                    </div>
                                </div>
                            </div>

                            <!-- Tombol Pemicu Modal Pengaturan Master Shift (Ikon Gear ⚙) -->
                            <div class="relative group shrink-0 ml-0.5">
                                <button type="button"
                                        @click="$dispatch('open-modal', 'shift-settings-modal')"
                                        aria-label="{{ __('roster.shift_settings_tooltip') }}"
                                        class="p-1.5 rounded-md bg-surface-muted hover:bg-surface border border-line text-ink-muted hover:text-ink transition-colors cursor-pointer flex items-center justify-center">
                                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                </button>
                                <div role="tooltip"
                                     class="pointer-events-none absolute top-full left-1/2 -translate-x-1/2 mt-1.5 z-50 px-2.5 py-1.5 text-2xs font-medium text-ink bg-surface-raised border border-line-strong rounded-lg shadow-xl opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap">
                                    {{ __('roster.shift_settings_tooltip') }}
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Sisi Kanan: Kontrol Publikasi, Filter MOD, dan Status Sinkronisasi -->
                <div class="overflow-x-auto no-scrollbar w-full sm:w-auto flex items-center gap-2 pb-1 sm:pb-0 text-2xs min-w-0">
                    <!-- Indikator Tanggal 24 Jam Aktif (Jika Ada Kolom Tanggal yang Sedang Di-Expand) -->
                    <template x-if="expandedDate">
                        <button type="button"
                                @click="expandedDate = null"
                                class="px-2 py-1 rounded-md bg-accent/15 text-accent font-mono text-2xs font-bold ring-1 ring-inset ring-accent/30 flex items-center gap-1 cursor-pointer shrink-0 whitespace-nowrap">
                            <span x-text="expandedDate"></span>
                            <span>(24h ×)</span>
                        </button>
                    </template>

                    <!-- Toggle Filter MOD -->
                    <button type="button"
                            @click="modOnly = !modOnly"
                            :class="modOnly ? 'bg-amber-500 text-white font-bold ring-2 ring-amber-500/50' : 'bg-surface-muted text-ink-muted hover:text-ink'"
                            class="px-2 py-1 rounded-md text-2xs font-semibold ring-1 ring-inset ring-line flex items-center gap-1.5 transition-all cursor-pointer shrink-0 whitespace-nowrap">
                        <span class="h-1.5 w-1.5 rounded-full shrink-0" :class="modOnly ? 'bg-white' : 'bg-amber-500'"></span>
                        <span>{{ __('roster.mod_filter_label') }}</span>
                    </button>

                    <!-- Tombol Batalkan Ubahan Draft & Publikasikan Roster -->
                    @if ($canEdit)
                        <button type="button"
                                @click="confirmDiscardDrafts()"
                                :disabled="!hasPendingChanges"
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 sm:px-3.5 sm:py-2 rounded-lg sm:rounded-xl border border-line bg-surface hover:bg-surface-muted text-ink text-2xs sm:text-xs font-semibold shadow-sm transition-all disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer shrink-0 whitespace-nowrap">
                            <svg class="w-3.5 h-3.5 text-ink-muted shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                            </svg>
                            <span>{{ __('roster.btn_discard_draft') }}</span>
                        </button>

                        <button type="button"
                                @click="publishRoster()"
                                :disabled="!hasPendingChanges || savingState === 'saving'"
                                :class="(!hasPendingChanges || savingState === 'saving')
                                    ? 'opacity-50 cursor-not-allowed bg-surface-muted text-ink-muted border border-line'
                                    : 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-2xs cursor-pointer'"
                                class="px-2.5 py-1 rounded-md text-2xs font-bold transition-all flex items-center gap-1 shrink-0 whitespace-nowrap">
                            <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <span>{{ __('roster.btn_publish') }}</span>
                        </button>
                    @endif

                    <!-- Indikator Sinkronisasi Real-Time -->
                    <div class="flex items-center gap-1.5 px-2 py-1 rounded-md bg-surface-muted ring-1 ring-inset ring-line text-ink-muted shrink-0 whitespace-nowrap">
                        <template x-if="savingState === 'saving'">
                            <span class="flex items-center gap-1.5 text-accent">
                                <svg class="animate-spin h-3.5 w-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ __('roster.sync_saving') }}</span>
                            </span>
                        </template>
                        <template x-if="savingState === 'saved'">
                            <span class="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                                <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                </svg>
                                <span x-text="statusMessage || '{{ __('roster.sync_saved') }}'"></span>
                            </span>
                        </template>
                        <template x-if="savingState === 'error'">
                            <span class="flex items-center gap-1.5 text-red-600 dark:text-red-400">
                                <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                <span x-text="statusMessage || '{{ __('roster.sync_error') }}'"></span>
                            </span>
                        </template>
                        <template x-if="savingState === 'idle'">
                            <span class="flex items-center gap-1.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                                <span>{{ __('roster.sync_idle') }}</span>
                            </span>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Banner Peringatan Mode Karyawan (Jika Belum Dipublikasikan) -->
            @if ($actor->isUser() && $publishedCount === 0)
                <div class="px-3 sm:px-4 py-2 bg-amber-500/10 border-b border-amber-500/20 text-amber-800 dark:text-amber-300 text-xs flex flex-wrap items-center gap-2 w-full">
                    <svg class="h-4 w-4 shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span class="min-w-0 flex-1">{{ __('roster.unpublished_notice') }}</span>
                </div>
            @endif

            <!-- Gantt Dual-Axis Sticky Matrix Viewport -->
            <div id="roster-gantt-viewport" class="flex-1 min-h-0 overflow-auto relative o-inset-stage bg-canvas">
                <table class="o-roster-grid border-separate border-spacing-0 min-w-max w-full text-cell">
                    <thead>
                        <!-- Baris 1: Header Tanggal & Kontrol Filter + Tombol Collapse Kolom Staf -->
                        <tr>
                            <!-- Sudut Kiri Atas Sticky (Dual-Axis Intersection: z-30) -->
                            <th :class="staffColumnCollapsed ? 'w-14 sm:w-16 min-w-[3.5rem] sm:min-w-[4rem] max-w-[3.5rem] sm:max-w-[4rem]' : 'w-56 sm:w-64 min-w-[14rem] sm:min-w-[16rem] max-w-[14rem] sm:max-w-[16rem]'"
                                class="o-roster-corner sticky top-0 left-0 z-30 bg-surface border-b border-r border-line p-1.5 sm:p-2 align-middle shadow-2xs transition-[width] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] overflow-hidden shrink-0">
                                <div class="flex items-center justify-between gap-1">
                                    <form x-show="!staffColumnCollapsed"
                                          x-transition:enter="transition-opacity duration-200 delay-100 ease-out"
                                          x-transition:enter-start="opacity-0"
                                          x-transition:enter-end="opacity-100"
                                          x-transition:leave="transition-opacity duration-150 ease-in"
                                          x-transition:leave-start="opacity-100"
                                          x-transition:leave-end="opacity-0"
                                          method="GET" action="{{ route('roster.index') }}" class="m-0 flex items-center gap-1 min-w-0 flex-1 whitespace-nowrap">
                                        <!-- Pilih Bulan -->
                                        <select name="month" onchange="this.form.submit()" aria-label="{{ __('roster.month') }}"
                                                class="bg-surface-muted border border-line text-ink text-2xs font-semibold rounded-lg px-1.5 py-1 focus:outline-none focus:ring-1 focus:ring-accent">
                                            @for ($m = 1; $m <= 12; $m++)
                                                <option value="{{ $m }}" @selected($month === $m)>
                                                    {{ \Carbon\Carbon::create(2026, $m, 1)->translatedFormat('M') }}
                                                </option>
                                            @endfor
                                        </select>

                                        <!-- Pilih Tahun -->
                                        <select name="year" onchange="this.form.submit()" aria-label="{{ __('roster.year') }}"
                                                class="bg-surface-muted border border-line text-ink text-2xs font-semibold rounded-lg px-1.5 py-1 focus:outline-none focus:ring-1 focus:ring-accent">
                                            @for ($y = 2024; $y <= 2028; $y++)
                                                <option value="{{ $y }}" @selected($year === $y)>{{ $y }}</option>
                                            @endfor
                                        </select>

                                        <!-- Filter Departemen -->
                                        @if ($canViewAllDepartments ?? ($actor->isSuperAdmin() || $actor->isAdmin() || $actor->isSuperUser()))
                                            <select name="department_id" onchange="this.form.submit()" aria-label="{{ __('roster.all_departments') }}"
                                                    class="flex-1 min-w-0 bg-surface-muted border border-line text-ink text-2xs font-semibold rounded-lg px-1.5 py-1 truncate focus:outline-none focus:ring-1 focus:ring-accent">
                                                <option value="">{{ __('roster.all_departments') }}</option>
                                                @foreach ($departments as $dept)
                                                    <option value="{{ $dept->id }}" @selected($selectedDepartmentId === $dept->id)>
                                                        {{ $dept->code }} — {{ $dept->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        @else
                                            <span class="text-2xs font-mono font-semibold text-ink-muted truncate px-1">
                                                {{ $actor->employee?->department?->code ?? '-' }}
                                            </span>
                                        @endif

                                        @if ($modOnly)
                                            <input type="hidden" name="mod_only" value="1" />
                                        @endif
                                    </form>

                                    <!-- Tombol Ciutkan / Lebarkan Kolom Nama Staf (« / ») -->
                                    <button type="button"
                                            @click="toggleStaffColumn()"
                                            :class="staffColumnCollapsed ? 'mx-auto' : 'shrink-0'"
                                            title="{{ __('roster.toggle_staff_column') }}"
                                            aria-label="{{ __('roster.toggle_staff_column') }}"
                                            class="h-6 w-6 rounded-md bg-surface-muted hover:bg-accent/15 text-ink-muted hover:text-accent border border-line flex items-center justify-center font-mono text-xs font-bold transition-colors cursor-pointer">
                                        <span x-text="staffColumnCollapsed ? '»' : '«'">«</span>
                                    </button>
                                </div>
                            </th>

                            <!-- Header Tanggal Bulanan Sticky (Top-Axis: z-20, Klik untuk Expand 24-Hour Timeline) -->
                            @foreach ($daysInMonth as $d)
                                <th @click="toggleDateExpand('{{ $d['date'] }}')"
                                    title="{{ __('roster.click_to_expand_timeline') }}"
                                    :class="expandedDate === '{{ $d['date'] }}'
                                        ? 'w-96 min-w-[24rem] sm:w-[500px] sm:min-w-[500px] shrink-0 bg-surface-muted/30 border-x-2 border-accent/40'
                                        : 'w-14 min-w-[3.5rem] sm:w-16 sm:min-w-[4rem] shrink-0'"
                                    class="o-roster-date-th cursor-pointer sticky top-0 z-20 border-b border-r border-line px-1 py-1.5 text-center select-none transition-[width] duration-300 ease-in-out {{ $d['is_today'] ? 'bg-accent/15 text-accent font-bold ring-1 ring-inset ring-accent/30' : ($d['is_weekend'] ? 'bg-surface-muted/90 text-red-600 dark:text-red-400' : 'bg-surface-muted text-ink hover:bg-surface-muted/70') }}">
                                    <div class="flex flex-col items-center justify-center leading-tight">
                                        <div class="flex items-center justify-center gap-1">
                                            <span class="text-[10px] font-medium uppercase tracking-wider opacity-80">{{ $d['day_name'] }}</span>
                                            <span class="text-xs font-bold">{{ $d['day_padded'] }}</span>
                                            @if ($d['is_weekend'])
                                                <span class="text-[8px] font-mono tracking-tight font-semibold opacity-75">{{ __('common.dp_badge') }}</span>
                                            @endif
                                            <span class="text-[9px] font-mono text-accent opacity-70" x-show="expandedDate === '{{ $d['date'] }}'">24h</span>
                                        </div>

                                        <!-- Sub-Header Linimasa 24 Jam (Muncul Saat Kolom Tanggal Di-Expand) -->
                                        <div x-show="expandedDate === '{{ $d['date'] }}'"
                                             x-transition.opacity.duration.200ms
                                             class="mt-1.5 pt-1 border-t border-line-strong/60 w-full flex items-center justify-between text-[9px] font-mono font-semibold text-ink-muted px-1">
                                            <span>00</span>
                                            <span>04</span>
                                            <span>08</span>
                                            <span>12</span>
                                            <span>16</span>
                                            <span>20</span>
                                            <span>24</span>
                                        </div>
                                    </div>
                                </th>
                            @endforeach
                        </tr>

                        <!-- Baris 2: Indikator Formasi 24 Jam Harian (Gap Alert) -->
                        <tr class="bg-surface-muted/60 text-2xs select-none">
                            <th :class="staffColumnCollapsed ? 'w-14 sm:w-16 min-w-[3.5rem] sm:min-w-[4rem] max-w-[3.5rem] sm:max-w-[4rem] px-1 text-center' : 'w-56 sm:w-64 min-w-[14rem] sm:min-w-[16rem] max-w-[14rem] sm:max-w-[16rem] px-3 text-left'"
                                class="sticky left-0 z-20 bg-surface-muted border-b border-r border-line py-1 font-semibold text-ink-muted transition-[width] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] overflow-hidden shrink-0">
                                <div x-show="!staffColumnCollapsed"
                                     x-transition:enter="transition-opacity duration-200 delay-100 ease-out"
                                     x-transition:enter-start="opacity-0"
                                     x-transition:enter-end="opacity-100"
                                     x-transition:leave="transition-opacity duration-150 ease-in"
                                     x-transition:leave-start="opacity-100"
                                     x-transition:leave-end="opacity-0"
                                     class="flex items-center justify-between whitespace-nowrap">
                                    <span class="truncate">{{ __('roster.formation_24h_title') }}</span>
                                    <span class="text-[9px] font-mono opacity-70">{{ __('roster.min_per_shift', ['min' => $formationByDate[$daysInMonth[0]['date']]['min_quota'] ?? 1]) }}</span>
                                </div>
                                <span x-show="staffColumnCollapsed" class="text-[9px] font-mono font-bold" title="{{ __('roster.formation_24h_title') }}">24h</span>
                            </th>
                            @foreach ($daysInMonth as $d)
                                @php
                                    $form = $formationByDate[$d['date']] ?? null;
                                    $hasGap = $form['has_gap'] ?? false;
                                    $gapsText = $form && !empty($form['gaps']) ? implode(', ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($form['gaps']), $form['gaps'])) : '';
                                @endphp
                                <th :class="expandedDate === '{{ $d['date'] }}' ? 'w-96 min-w-[24rem] sm:w-[500px] sm:min-w-[500px] border-x-2 border-accent/40' : 'w-14 min-w-[3.5rem] sm:w-16 sm:min-w-[4rem]'"
                                    class="border-b border-r border-line px-1 py-1 text-center align-middle transition-[width] duration-300 ease-in-out {{ $hasGap ? 'bg-amber-500/10' : '' }}"
                                    title="{{ $hasGap ? __('roster.formation_gap_tooltip', ['shifts' => $gapsText, 'min' => $form['min_quota'] ?? 1]) : __('roster.formation_ok') }}">
                                    @if ($hasGap)
                                        <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded text-[9px] font-mono font-bold bg-amber-500/20 text-amber-700 dark:text-amber-300 ring-1 ring-inset ring-amber-500/30">
                                            {{ __('roster.gap_badge') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center justify-center px-1 py-0.5 text-[10px] text-emerald-600 dark:text-emerald-400 font-bold">
                                            ✓
                                        </span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>

                        <!-- Baris 3: Indikator Audit Ketersediaan MOD Harian -->
                        <tr class="bg-surface-muted/40 text-2xs select-none">
                            <th :class="staffColumnCollapsed ? 'w-14 sm:w-16 min-w-[3.5rem] sm:min-w-[4rem] max-w-[3.5rem] sm:max-w-[4rem] px-1 text-center' : 'w-56 sm:w-64 min-w-[14rem] sm:min-w-[16rem] max-w-[14rem] sm:max-w-[16rem] px-3 text-left'"
                                class="sticky left-0 z-20 bg-surface-muted border-b border-r border-line py-1 font-semibold text-ink-muted transition-[width] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] overflow-hidden shrink-0">
                                <div x-show="!staffColumnCollapsed"
                                     x-transition:enter="transition-opacity duration-200 delay-100 ease-out"
                                     x-transition:enter-start="opacity-0"
                                     x-transition:enter-end="opacity-100"
                                     x-transition:leave="transition-opacity duration-150 ease-in"
                                     x-transition:leave-start="opacity-100"
                                     x-transition:leave-end="opacity-0"
                                     class="flex items-center justify-between whitespace-nowrap">
                                    <span class="truncate">{{ __('roster.mod_audit_title') }}</span>
                                    <span class="text-[9px] font-mono opacity-70">{{ __('roster.min_per_night') }}</span>
                                </div>
                                <span x-show="staffColumnCollapsed" class="text-[9px] font-mono font-bold" title="{{ __('roster.mod_audit_title') }}">{{ __('roster.duty_mod_badge') }}</span>
                            </th>
                            @foreach ($daysInMonth as $d)
                                @php
                                    $modAudit = $modAuditByDate[$d['date']] ?? null;
                                    $isCovered = $modAudit['is_covered'] ?? false;
                                    $modCount = $modAudit['count'] ?? 0;
                                @endphp
                                <th :class="[
                                        expandedDate === '{{ $d['date'] }}' ? 'w-96 min-w-[24rem] sm:w-[500px] sm:min-w-[500px] border-x-2 border-accent/40' : 'w-14 min-w-[3.5rem] sm:w-16 sm:min-w-[4rem]',
                                        !isModCovered('{{ $d['date'] }}') ? 'bg-red-500/5' : ''
                                    ]"
                                    class="border-b border-r border-line px-1 py-0.5 text-center align-middle transition-[width] duration-300 ease-in-out {{ ! $isCovered ? 'bg-red-500/5' : '' }}"
                                    :title="isModCovered('{{ $d['date'] }}') ? ('{{ __('roster.mod_assigned') }} (' + getModCount('{{ $d['date'] }}') + ')') : '{{ __('roster.mod_missing') }}'">
                                    <span x-show="isModCovered('{{ $d['date'] }}')"
                                          class="inline-flex items-center justify-center px-1.5 py-0.5 rounded text-[9px] font-mono font-bold bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 ring-1 ring-inset ring-emerald-500/30"
                                          x-text="'{{ __('roster.duty_mod_badge') }}: ' + getModCount('{{ $d['date'] }}')">
                                        {{ __('roster.duty_mod_badge') }}: {{ $modCount }}
                                    </span>
                                    <span x-show="!isModCovered('{{ $d['date'] }}')"
                                          class="inline-flex items-center justify-center px-1.5 py-0.5 rounded text-[9px] font-mono font-bold bg-red-500/15 text-red-600 dark:text-red-400 ring-1 ring-inset ring-red-500/30">
                                        0
                                    </span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody class="o-roster-tbody divide-y divide-line/70">
                        @forelse ($employees as $emp)
                            <tr x-show="!modOnly || {{ $emp->tags->contains('code', 'MOD_ELIGIBLE') ? 'true' : 'false' }}"
                                class="hover:bg-surface-muted/30 transition-colors group">
                                <!-- Kolom Data Pegawai Sticky (Left-Axis: z-10, Mendukung Collapsible Rail Mode) -->
                                <td :class="staffColumnCollapsed ? 'w-14 sm:w-16 min-w-[3.5rem] sm:min-w-[4rem] max-w-[3.5rem] sm:max-w-[4rem] px-1.5' : 'w-56 sm:w-64 min-w-[14rem] sm:min-w-[16rem] max-w-[14rem] sm:max-w-[16rem] px-3'"
                                    class="o-roster-emp-col sticky left-0 z-10 bg-surface border-b border-r border-line py-1.5 align-middle shadow-2xs transition-[width] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] overflow-hidden shrink-0">
                                    <div class="flex items-center justify-between gap-2" :class="staffColumnCollapsed ? 'justify-center' : ''">
                                        <div class="flex items-center gap-2 min-w-0" :class="staffColumnCollapsed ? 'justify-center' : 'flex-1'">
                                            <!-- Avatar Bulat Pegawai dengan Tooltip Nama Saat Mode Collapsed -->
                                            <div class="relative group/emp shrink-0"
                                                 title="{{ $emp->user?->name ?? '-' }} ({{ $emp->department?->code ?? '-' }} • {{ $emp->position }})">
                                                <div class="h-7 w-7 rounded-full flex items-center justify-center text-2xs font-bold text-accent-fg shrink-0 shadow-2xs"
                                                     style="background-color: var(--primary-accent);">
                                                    {{ strtoupper(substr($emp->user?->name ?? 'E', 0, 1)) }}
                                                </div>
                                            </div>

                                            <div x-show="!staffColumnCollapsed"
                                                 x-transition:enter="transition-opacity duration-200 delay-100 ease-out"
                                                 x-transition:enter-start="opacity-0"
                                                 x-transition:enter-end="opacity-100"
                                                 x-transition:leave="transition-opacity duration-150 ease-in"
                                                 x-transition:leave-start="opacity-100"
                                                 x-transition:leave-end="opacity-0"
                                                 class="min-w-0 flex-1 truncate">
                                                <div class="flex items-center gap-1 whitespace-nowrap">
                                                    <span class="text-xs font-semibold text-ink truncate max-w-[95px] sm:max-w-[110px]" title="{{ $emp->user?->name }}">
                                                        {{ $emp->user?->name ?? '-' }}
                                                    </span>
                                                    <span class="text-[9px] font-mono font-semibold px-1 rounded bg-surface-muted text-ink-muted ring-1 ring-inset ring-line shrink-0">
                                                        {{ $emp->department?->code ?? '-' }}
                                                    </span>
                                                    @if ($emp->tags->contains('code', 'MOD_ELIGIBLE'))
                                                        <span class="text-[9px] font-bold px-1 rounded bg-amber-500/15 text-amber-700 dark:text-amber-400 ring-1 ring-inset ring-amber-500/30 shrink-0"
                                                              title="{{ __('roster.tag_desc_mod_eligible') }}">
                                                            {{ __('roster.duty_mod_badge') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <p class="text-[10px] text-ink-muted truncate max-w-[140px]">
                                                    {{ $emp->position }}
                                                </p>
                                            </div>
                                        </div>

                                        <!-- Badge Jam Kerja Terkomputasi (Reaktif, Disembunyikan pada Mode Rail) -->
                                        <div x-show="!staffColumnCollapsed"
                                             x-transition:enter="transition-opacity duration-200 delay-100 ease-out"
                                             x-transition:enter-start="opacity-0"
                                             x-transition:enter-end="opacity-100"
                                             x-transition:leave="transition-opacity duration-150 ease-in"
                                             x-transition:leave-start="opacity-100"
                                             x-transition:leave-end="opacity-0"
                                             class="flex flex-col items-end gap-0.5 shrink-0">
                                            <span class="o-badge o-badge-neutral text-[10px] font-mono py-0 px-1"
                                                  title="{{ __('roster.monthly_hours') }}"
                                                  x-text="getMonthlyHours({{ $emp->id }}) + 'h'">
                                            </span>
                                            <span class="o-badge text-[9px] font-mono py-0 px-1 transition-colors"
                                                  :class="isOvertimeWeek({{ $emp->id }}) ? 'o-badge-warning ring-amber-500/50 text-amber-700 dark:text-amber-300' : 'o-badge-neutral text-ink-muted'"
                                                  :title="isOvertimeWeek({{ $emp->id }}) ? '{{ __('roster.overtime_warning') }}' : '{{ __('roster.max_weekly_hours') }}'"
                                                  x-text="getMaxWeeklyHours({{ $emp->id }}) + 'h/w'">
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <!-- Sel-Sel Kalender Bulanan (Multi-Shift Stack, Cross-Day 24h Continuation & Pure Drag-and-Drop) -->
                                @foreach ($daysInMonth as $d)
                                    <td :class="expandedDate === '{{ $d['date'] }}'
                                            ? 'w-96 min-w-[24rem] sm:w-[500px] sm:min-w-[500px] shrink-0 bg-surface-muted/30 border-x-2 border-accent/40'
                                            : 'w-14 min-w-[3.5rem] sm:w-16 sm:min-w-[4rem] shrink-0'"
                                        class="o-roster-cell relative z-0 min-h-[3rem] border-b border-r border-line/70 p-0.5 text-center align-middle overflow-hidden transition-all duration-300 ease-in-out {{ $d['is_weekend'] ? 'bg-surface-muted/20' : '' }}"
                                        data-employee-id="{{ $emp->id }}"
                                        data-date="{{ $d['date'] }}"
                                        @if ($canEdit)
                                            @dragover.prevent="dragOverCell($event)"
                                            @dragleave="dragLeaveCell($event)"
                                            @drop.prevent="dropOnCell($event, {{ $emp->id }}, '{{ $d['date'] }}')"
                                            @touchend="handleCellTouchEnd($event, {{ $emp->id }}, '{{ $d['date'] }}')"
                                        @endif
                                    >
                                        <!-- Garis Bantu Grid Linimasa 24 Jam (6 Segmen @ 4 Jam) Saat Tanggal Di-Expand -->
                                        <div x-show="expandedDate === '{{ $d['date'] }}'"
                                             class="pointer-events-none absolute inset-0 grid grid-cols-6 divide-x divide-line/50 z-0">
                                            <div></div><div></div><div></div><div></div><div></div><div></div>
                                        </div>

                                        <!-- Wadah Vertikal Multi-Shift & Sambungan Lintas Hari (flex flex-col gap-1) -->
                                        <div class="relative z-10 flex flex-col gap-1 w-full min-h-[2.5rem] justify-center">

                                            <!-- 1. Balok Sambungan Shift Lintas Hari dari Tanggal D-1 (HANYA Tampil pada Mode 24 Jam) -->
                                            <template x-if="expandedDate === '{{ $d['date'] }}'">
                                                <template x-for="prevSched in getOvernightCarryovers({{ $emp->id }}, '{{ $d['date'] }}')" :key="'cont-' + prevSched.id">
                                                    <div class="relative h-9 w-full">
                                                        <div :data-schedule-id="prevSched.id"
                                                             :data-schedule-uuid="prevSched.uuid"
                                                             :data-employee-id="{{ $emp->id }}"
                                                             :data-date="prevSched.schedule_date"
                                                             class="o-shift-continuation o-shift-pill-resizable group/cont absolute top-0 bottom-0 rounded-r-md px-1.5 py-0.5 flex flex-col justify-between overflow-hidden transition-all duration-300 ease-in-out border-l-4 border-l-amber-300 bg-[repeating-linear-gradient(135deg,transparent,transparent_6px,rgba(255,255,255,0.16)_6px,rgba(255,255,255,0.16)_12px)]"
                                                             :class="prevSched.status === 'pending_delete'
                                                                 ? 'opacity-30 dark:opacity-20 line-through grayscale border border-dashed border-red-500 bg-red-500/10 pointer-events-auto text-red-700 dark:text-red-300'
                                                                 : (prevSched.status === 'draft'
                                                                     ? 'opacity-70 dark:opacity-65 border-dashed border border-amber-400/70 text-white'
                                                                     : 'opacity-95 shadow-2xs border-solid border border-line-strong text-white')"
                                                             :style="getContinuationBarStyle(prevSched)"
                                                             :title="'{{ __('roster.overnight_continuation_from') }} (' + prevSched.schedule_date + ')'">
                                                            <div class="flex items-center justify-between gap-1 w-full">
                                                                <div class="flex items-center gap-1 min-w-0">
                                                                    <span class="text-[9px] font-mono font-bold">←</span>
                                                                    <span class="text-[10px] font-bold truncate" x-text="prevSched.shift_code"></span>
                                                                    <span class="text-[8px] font-mono px-0.5 rounded bg-black/30 text-amber-200">{{ __('roster.cross_day_badge') }}</span>
                                                                </div>

                                                                @if ($canEdit)
                                                                    <template x-if="prevSched.status !== 'pending_delete'">
                                                                        <button type="button"
                                                                                @click.stop="removeScheduleById({{ $emp->id }}, prevSched.schedule_date, prevSched.id, $event)"
                                                                                title="{{ __('roster.remove_shift') }}"
                                                                                class="hover:bg-black/40 px-1 rounded text-white/90 hover:text-white pointer-events-auto text-[11px] font-bold leading-none cursor-pointer">
                                                                            ×
                                                                        </button>
                                                                    </template>
                                                                    <template x-if="prevSched.status === 'pending_delete'">
                                                                        <button type="button"
                                                                                @click.stop="restoreScheduleById({{ $emp->id }}, prevSched.schedule_date, prevSched.id, $event)"
                                                                                title="{{ __('roster.undo_delete') }}"
                                                                                class="hover:bg-black/40 px-1 rounded text-red-700 dark:text-red-300 pointer-events-auto text-[10px] font-bold leading-none cursor-pointer">
                                                                            ↺
                                                                        </button>
                                                                    </template>
                                                                @endif
                                                            </div>
                                                            <div class="flex items-center justify-between text-[8px] font-mono leading-none opacity-90">
                                                                <span>00:00</span>
                                                                <span x-text="formatTimeShort(prevSched.effective_end_time)"></span>
                                                            </div>

                                                            @if ($canEdit)
                                                                <div x-show="prevSched.status !== 'pending_delete' && !prevSched.is_off"
                                                                     @click.stop
                                                                     class="o-resize-handle-right absolute inset-y-0 right-0 w-2 cursor-ew-resize hover:bg-white/40 rounded-r transition-colors z-20"
                                                                     title="{{ __('roster.resize_right_tooltip') }}"></div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </template>
                                            </template>

                                            <!-- 2. Daftar Jadwal yang Dimulai pada Tanggal D (Mendukung Multi-Shift per Hari & Split Shift) -->
                                            <template x-for="sched in getSchedules({{ $emp->id }}, '{{ $d['date'] }}')" :key="sched.id">
                                                <div x-data="{ get schedule() { return sched; } }" class="relative h-10 w-full">
                                                    <div :data-schedule-id="sched.id"
                                                         :data-schedule-uuid="sched.uuid"
                                                         :data-employee-id="{{ $emp->id }}"
                                                         :data-date="'{{ $d['date'] }}'"
                                                         @if ($canEdit)
                                                             @click="if ($el.dataset.justSlid === '1') return; if (sched.status === 'pending_delete') { restoreScheduleById({{ $emp->id }}, '{{ $d['date'] }}', sched.id, $event); } else if (!sched.is_off) { toggleScheduleMenu(sched.id, $event); }"
                                                         @endif
                                                         :title="sched.status === 'pending_delete' ? '{{ __('roster.pending_delete_badge') }}' : (sched.shift_name + ' (' + formatTimeShort(sched.effective_start_time) + ' – ' + formatTimeShort(sched.effective_end_time) + ')')"
                                                         class="o-shift-pill o-shift-pill-resizable group group/pill absolute top-0 bottom-0 rounded-md px-1.5 py-0.5 flex flex-col justify-between overflow-hidden transition-all duration-300 ease-in-out hover:z-20 touch-none"
                                                         :class="[
                                                             sched.status === 'pending_delete'
                                                                 ? 'opacity-30 dark:opacity-20 line-through grayscale border border-dashed border-red-500 bg-red-500/10 pointer-events-auto text-red-700 dark:text-red-300 cursor-pointer'
                                                                 : (sched.status === 'draft'
                                                                     ? 'opacity-70 dark:opacity-65 border-dashed border border-amber-400/70 text-white cursor-grab active:cursor-grabbing'
                                                                     : 'opacity-100 shadow-sm border-solid border border-line-strong text-white cursor-grab active:cursor-grabbing'),
                                                             sched.has_turnaround_warning && sched.status !== 'pending_delete' ? 'ring-2 ring-amber-400 dark:ring-amber-300' : '',
                                                             isCrossDaySchedule(sched) ? 'border-r-4 border-r-amber-300 bg-[repeating-linear-gradient(135deg,transparent,transparent_6px,rgba(255,255,255,0.16)_6px,rgba(255,255,255,0.16)_12px)]' : ''
                                                         ]"
                                                         :style="getScheduleBarStyle(sched, '{{ $d['date'] }}')">

                                                        <!-- Handle Resize Sisi Kiri (InteractJS) -->
                                                        @if ($canEdit)
                                                            <div x-show="sched.status !== 'pending_delete' && !sched.is_off"
                                                                 @click.stop
                                                                 class="o-resize-handle-left absolute inset-y-0 left-0 w-2 cursor-ew-resize hover:bg-white/40 rounded-l transition-colors z-20"
                                                                 title="{{ __('roster.resize_left_tooltip') }}"></div>

                                                            <!-- Split Shift Trigger Handle (Titik Tengah Balok) -->
                                                            <div x-show="sched.status !== 'pending_delete' && !sched.is_off"
                                                                 class="absolute inset-y-0 left-1/2 -translate-x-1/2 w-3 flex items-center justify-center opacity-0 group-hover:opacity-100 hover:opacity-100 transition-opacity cursor-col-resize z-20"
                                                                 @click.stop="splitSchedule(schedule.id)"
                                                                 title="{{ __('roster.action_split_shift') }}">
                                                                <div class="h-3/4 w-0.5 bg-white/80 dark:bg-zinc-200 border-x border-zinc-950/20 rounded-full"></div>
                                                            </div>
                                                        @endif

                                                        <!-- Badge Akumulasi Durasi Jam di Sudut Kanan Atas Balok Shift -->
                                                        <span x-show="!sched.is_off"
                                                              :class="canEdit && sched.status !== 'pending_delete' ? 'group-hover/pill:opacity-0' : ''"
                                                              class="absolute top-0.5 right-1 text-3xs font-bold opacity-75 dark:opacity-90 leading-none pointer-events-none select-none"
                                                              x-text="getScheduleDurationHours(sched) + 'h'">
                                                        </span>

                                                        <!-- Baris Atas Balok: Titik Draft / Badge Pending Delete + Kode Shift + Tombol Hapus / Undo -->
                                                        <div class="flex items-center justify-between gap-1 w-full pr-4 pointer-events-none">
                                                            <div class="flex items-center gap-1 min-w-0">
                                                                <template x-if="sched.status === 'draft'">
                                                                    <span class="h-1.5 w-1.5 rounded-full bg-amber-400 ring-1 ring-black/30 shrink-0"
                                                                          title="{{ __('roster.draft_indicator_tooltip') }}"></span>
                                                                </template>

                                                                <span class="text-[11px] font-bold tracking-tight leading-none truncate" x-text="sched.shift_code"></span>

                                                                <template x-if="sched.has_turnaround_warning && sched.status !== 'pending_delete'">
                                                                    <span class="text-[8px] font-bold px-0.5 rounded bg-amber-400 text-black leading-tight"
                                                                          :title="'{{ __('roster.turnaround_badge') }} (' + (sched.rest_hours ?? 0) + 'h)'">
                                                                        {{ __('roster.turnaround_badge') }}
                                                                    </span>
                                                                </template>

                                                                <template x-if="sched.duty_tag_code === 'MOD_ELIGIBLE' || sched.shift_code === 'MOD'">
                                                                    <span class="text-[8px] font-bold px-0.5 rounded bg-black/30 text-amber-300 leading-tight">{{ __('roster.duty_mod_badge') }}</span>
                                                                </template>
                                                                <template x-if="isCrossDaySchedule(sched)">
                                                                    <span class="text-[8px] font-mono px-0.5 rounded bg-black/30 text-white/90 leading-tight">{{ __('roster.cross_day_badge') }}</span>
                                                                </template>
                                                            </div>
                                                        </div>

                                                        @if ($canEdit)
                                                            <template x-if="sched.status !== 'pending_delete'">
                                                                <button type="button"
                                                                        @click.stop="removeScheduleById({{ $emp->id }}, '{{ $d['date'] }}', sched.id, $event)"
                                                                        title="{{ __('roster.remove_shift') }}"
                                                                        class="absolute top-0.5 right-0.5 z-30 opacity-0 group-hover/pill:opacity-100 hover:bg-black/40 p-0.5 rounded text-white/90 hover:text-white pointer-events-auto transition-opacity cursor-pointer">
                                                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                                                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                                                    </svg>
                                                                </button>
                                                            </template>
                                                            <template x-if="sched.status === 'pending_delete'">
                                                                <button type="button"
                                                                        @click.stop="restoreScheduleById({{ $emp->id }}, '{{ $d['date'] }}', sched.id, $event)"
                                                                        title="{{ __('roster.undo_delete') }}"
                                                                        class="absolute top-0.5 right-0.5 z-30 px-1 rounded bg-red-500/20 text-red-700 dark:text-red-300 pointer-events-auto text-[10px] font-bold leading-none cursor-pointer">
                                                                    ↺
                                                                </button>
                                                            </template>
                                                        @endif

                                                        <!-- Baris Bawah Balok: Rentang Waktu Efektif -->
                                                        <div class="flex items-center justify-between text-[9px] font-mono leading-none opacity-90 pointer-events-none">
                                                            <span x-text="sched.is_off ? '{{ __('roster.day_off_short') }}' : formatTimeShort(sched.effective_start_time)"></span>
                                                            <span x-text="sched.is_off ? '{{ __('common.dp_badge') }}' : (expandedDate === '{{ $d['date'] }}' && isCrossDaySchedule(sched) ? '24:00→' : formatTimeShort(sched.effective_end_time))"></span>
                                                        </div>

                                                        <!-- Popover Interaksi Cepat Mobile/Touch (Split Shift & Hapus) -->
                                                        @if ($canEdit)
                                                            <div x-cloak
                                                                 x-show="activeScheduleMenuId === sched.id && sched.status !== 'pending_delete' && !sched.is_off"
                                                                 @click.away="if (activeScheduleMenuId === sched.id) activeScheduleMenuId = null"
                                                                 class="absolute inset-x-0.5 bottom-0.5 z-30 flex items-center justify-center gap-1 rounded bg-zinc-950/90 px-1 py-0.5 text-[9px] font-bold text-white shadow-lg sm:hidden">
                                                                <button type="button"
                                                                        @click.stop="splitSchedule(schedule.id)"
                                                                        class="px-1.5 py-0.5 rounded bg-accent text-accent-fg cursor-pointer whitespace-nowrap">
                                                                    {{ __('roster.action_split_shift') }}
                                                                </button>
                                                                <button type="button"
                                                                        @click.stop="removeScheduleById({{ $emp->id }}, '{{ $d['date'] }}', sched.id, $event)"
                                                                        class="px-1 py-0.5 rounded bg-red-600 text-white cursor-pointer">
                                                                    ×
                                                                </button>
                                                            </div>
                                                        @endif

                                                        <!-- Handle Resize Sisi Kanan (InteractJS) -->
                                                        @if ($canEdit)
                                                            <div x-show="sched.status !== 'pending_delete' && !sched.is_off"
                                                                 @click.stop
                                                                 class="o-resize-handle-right absolute inset-y-0 right-0 w-2 cursor-ew-resize hover:bg-white/40 rounded-r transition-colors z-20"
                                                                 title="{{ __('roster.resize_right_tooltip') }}"></div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </template>

                                            <!-- Placeholder Dropzone Kosong (Murni Target Drop, Tanpa Klik Kuas) -->
                                            <template x-if="getSchedules({{ $emp->id }}, '{{ $d['date'] }}').length === 0 && (expandedDate !== '{{ $d['date'] }}' || getOvernightCarryovers({{ $emp->id }}, '{{ $d['date'] }}').length === 0)">
                                                <div class="pointer-events-none h-10 w-full rounded-md border border-dashed border-line/40 flex items-center justify-center text-2xs text-ink-faint transition-all">
                                                </div>
                                            </template>
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($daysInMonth) + 1 }}" class="p-8 text-center text-ink-muted text-xs">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <svg class="h-8 w-8 text-ink-faint" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.75 3v2.25M17.25 3v2.253 3.75h18M4.5 7.5h15a2.25 2.25 0 0 1 2.25 2.25v10.5A2.25 2.25 0 0 1 19.5 22.5H4.5A2.25 2.25 0 0 1 2.25 20.25V9.75A2.25 2.25 0 0 1 4.5 7.5Z"/>
                                        </svg>
                                        <p>{{ __('roster.empty_schedule') }}</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Floating Tooltip Real-Time Saat Drag-Resize Balok Shift -->
            <div x-cloak
                 x-show="resizeTooltip.visible"
                 class="fixed z-tooltip pointer-events-none rounded-lg bg-zinc-950/90 text-white font-mono font-bold text-2xs px-2.5 py-1.5 shadow-xl border border-line backdrop-blur-xs flex items-center gap-1.5 transition-all"
                 :style="'left: ' + resizeTooltip.x + 'px; top: ' + resizeTooltip.y + 'px; transform: translate(-50%, -100%);'">
                <svg class="h-3 w-3 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span x-text="resizeTooltip.text"></span>
            </div>

            <!-- Modal Konfirmasi Soft Turnaround (< 8 Jam Advisory Warning) -->
            <div x-cloak
                 x-show="turnaroundModal.open"
                 @keydown.escape.window="turnaroundModal.open = false"
                 class="fixed inset-0 z-modal flex items-center justify-center p-4 bg-zinc-950/60 backdrop-blur-xs">
                <div @click.away="turnaroundModal.open = false"
                     class="w-full max-w-md rounded-2xl border border-line bg-surface p-5 shadow-2xl text-ink space-y-4">
                    <div class="flex items-start gap-3">
                        <div class="h-10 w-10 rounded-xl bg-amber-500/15 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-sm font-bold text-ink">{{ __('roster.turnaround_warning_title') }}</h3>
                            <p class="text-xs text-ink-muted mt-1 leading-relaxed" x-text="turnaroundModal.message"></p>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-line">
                        <button type="button"
                                @click="turnaroundModal.open = false"
                                class="px-3 py-1.5 rounded-lg border border-line text-xs font-medium text-ink-muted hover:text-ink hover:bg-surface-muted transition-colors cursor-pointer">
                            {{ __('roster.cancel_override') }}
                        </button>
                        <button type="button"
                                @click="confirmTurnaroundOverride()"
                                class="px-3.5 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold shadow-2xs transition-colors cursor-pointer">
                            {{ __('roster.confirm_override') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Modal Native HTML5 <dialog> Pengaturan Master Shift & Konfirmasi Batalkan Draf -->
            @if ($canEdit)
                <x-ui.modal name="discard-drafts-modal" :title="__('roster.modal_discard_title')" maxWidth="md">
                    <div class="space-y-4">
                        <div class="flex items-start gap-3">
                            <div class="h-10 w-10 rounded-xl bg-red-500/15 text-red-600 dark:text-red-400 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                                </svg>
                            </div>
                            <p class="text-xs text-ink-muted leading-relaxed">
                                {{ __('roster.modal_discard_desc') }}
                            </p>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-3 border-t border-line">
                            <button type="button"
                                    @click="closeDiscardModal()"
                                    class="px-4 py-2 rounded-xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition-colors cursor-pointer">
                                {{ __('common.cancel') }}
                            </button>
                            <button type="button"
                                    @click="discardDrafts()"
                                    class="px-4 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white text-xs font-semibold shadow-sm transition-colors cursor-pointer">
                                {{ __('roster.btn_confirm_discard') }}
                            </button>
                        </div>
                    </div>
                </x-ui.modal>

                <x-ui.modal name="shift-settings-modal" :title="__('roster.shift_settings_title')" maxWidth="2xl">
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-2.5">
                            <div class="p-3 rounded-lg bg-surface-muted border border-line text-xs text-ink-muted flex items-start gap-2.5 flex-1 min-w-[240px]">
                                <svg class="h-4 w-4 text-accent shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                                </svg>
                                <span>{{ __('roster.shift_settings_desc') }}</span>
                            </div>

                            <button type="button"
                                    @click="openNewShiftForm()"
                                    class="shrink-0 px-3 py-2 rounded-lg bg-accent text-accent-fg text-xs font-bold hover:opacity-90 transition-opacity cursor-pointer flex items-center gap-1.5 shadow-2xs">
                                <span>{{ __('roster.add_shift_btn') }}</span>
                            </button>
                        </div>

                        <template x-if="shiftFormFeedback">
                            <div class="px-3 py-2 rounded-lg bg-emerald-500/15 border border-emerald-500/30 text-emerald-700 dark:text-emerald-300 text-xs font-medium"
                                 x-text="shiftFormFeedback"></div>
                        </template>

                        <!-- Form Tambah Master Shift Baru (Default Values: NEW, Shift Baru, 09:00-17:00, 15m, #3B82F6) -->
                        <div x-show="showNewShiftForm"
                             x-transition
                             class="p-3.5 rounded-xl border border-accent/40 bg-accent/5 space-y-3">
                            <div class="flex items-center justify-between">
                                <h4 class="text-xs font-bold text-ink">{{ __('roster.add_shift_btn') }}</h4>
                                <button type="button"
                                        @click="showNewShiftForm = false"
                                        class="text-2xs font-semibold text-ink-muted hover:text-ink cursor-pointer">
                                    ×
                                </button>
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-6 gap-2.5 items-end">
                                <div>
                                    <label class="block text-[10px] font-semibold uppercase text-ink-muted mb-1">{{ __('roster.shift_code_col') }}</label>
                                    <input type="text"
                                           maxlength="20"
                                           x-model="newShift.code"
                                           class="w-full bg-surface border border-line rounded-md px-2 py-1 text-xs font-mono font-bold uppercase text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-semibold uppercase text-ink-muted mb-1">{{ __('roster.shift_name_col') }}</label>
                                    <input type="text"
                                           maxlength="100"
                                           x-model="newShift.name"
                                           class="w-full bg-surface border border-line rounded-md px-2 py-1 text-xs text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold uppercase text-ink-muted mb-1">{{ __('roster.shift_start_col') }}</label>
                                    <input type="time"
                                           :value="formatTimeShort(newShift.start_time)"
                                           @change="newShift.start_time = $event.target.value"
                                           class="w-full bg-surface border border-line rounded-md px-2 py-1 text-xs font-mono text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold uppercase text-ink-muted mb-1">{{ __('roster.shift_end_col') }}</label>
                                    <input type="time"
                                           :value="formatTimeShort(newShift.end_time)"
                                           @change="newShift.end_time = $event.target.value"
                                           class="w-full bg-surface border border-line rounded-md px-2 py-1 text-xs font-mono text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                </div>
                                <div class="flex items-end gap-2">
                                    <div class="flex-1">
                                        <label class="block text-[10px] font-semibold uppercase text-ink-muted mb-1">{{ __('roster.shift_grace_col') }}</label>
                                        <input type="number"
                                               min="0"
                                               max="120"
                                               x-model.number="newShift.grace_period_minutes"
                                               class="w-full bg-surface border border-line rounded-md px-2 py-1 text-xs font-mono text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-semibold uppercase text-ink-muted mb-1">{{ __('roster.shift_color_col') }}</label>
                                        <input type="color"
                                               x-model="newShift.color_badge"
                                               class="h-7 w-10 rounded border border-line bg-surface p-0.5 cursor-pointer" />
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-end gap-2 pt-1">
                                <button type="button"
                                        @click="createMasterShift()"
                                        :disabled="creatingShift"
                                        class="px-3 py-1.5 rounded-md bg-emerald-600 hover:bg-emerald-700 text-white text-2xs font-bold disabled:opacity-50 transition-colors cursor-pointer">
                                    <span x-text="creatingShift ? '...' : '{{ __('roster.save_shift_btn') }}'"></span>
                                </button>
                            </div>
                        </div>

                        <div class="overflow-x-auto rounded-lg border border-line">
                            <table class="o-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('roster.shift_code_col') }}</th>
                                        <th>{{ __('roster.shift_name_col') }}</th>
                                        <th>{{ __('roster.shift_start_col') }}</th>
                                        <th>{{ __('roster.shift_end_col') }}</th>
                                        <th>{{ __('roster.shift_grace_col') }}</th>
                                        <th>{{ __('roster.shift_color_col') }}</th>
                                        <th class="text-right">{{ __('roster.shift_action_col') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="shiftItem in shifts" :key="shiftItem.id">
                                        <tr>
                                            <td>
                                                <span class="px-2 py-0.5 rounded text-white text-2xs font-mono font-bold"
                                                      :style="'background-color: ' + (shiftItem.color_badge || '#004F9F')"
                                                      x-text="shiftItem.code"></span>
                                            </td>
                                            <td>
                                                <input type="text"
                                                       x-model="shiftItem.name"
                                                       class="w-36 bg-surface border border-line rounded-md px-2 py-1 text-xs text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                            </td>
                                            <td>
                                                <input type="time"
                                                       :value="formatTimeShort(shiftItem.start_time)"
                                                       @change="shiftItem.start_time = $event.target.value"
                                                       class="bg-surface border border-line rounded-md px-2 py-1 text-xs font-mono text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                            </td>
                                            <td>
                                                <input type="time"
                                                       :value="formatTimeShort(shiftItem.end_time)"
                                                       @change="shiftItem.end_time = $event.target.value"
                                                       class="bg-surface border border-line rounded-md px-2 py-1 text-xs font-mono text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                            </td>
                                            <td>
                                                <input type="number"
                                                       min="0"
                                                       max="120"
                                                       x-model.number="shiftItem.grace_period_minutes"
                                                       class="w-16 bg-surface border border-line rounded-md px-2 py-1 text-xs font-mono text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                            </td>
                                            <td>
                                                <input type="color"
                                                       x-model="shiftItem.color_badge"
                                                       class="h-7 w-10 rounded border border-line bg-surface p-0.5 cursor-pointer" />
                                            </td>
                                            <td class="text-right">
                                                <button type="button"
                                                        @click="updateMasterShift(shiftItem)"
                                                        :disabled="savingShiftId === shiftItem.id"
                                                        class="px-2.5 py-1 rounded-md bg-accent text-accent-fg text-2xs font-bold hover:opacity-90 disabled:opacity-50 transition-opacity cursor-pointer">
                                                    <span x-text="savingShiftId === shiftItem.id ? '...' : '{{ __('roster.save_shift_btn') }}'"></span>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </x-ui.modal>
            @endif
        </div>
    </x-slot>
</x-app-layout>
