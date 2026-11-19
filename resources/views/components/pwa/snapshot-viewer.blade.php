<div x-data class="space-y-4">
    <!-- Header Info Snapshot & Last Synced -->
    <div class="rounded-2xl border border-line bg-surface p-4 sm:p-5 shadow-2xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-line pb-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-accent/10 text-accent shrink-0">
                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                    </svg>
                </span>
                <div>
                    <h2 class="text-sm sm:text-base font-bold text-ink">
                        {{ __('pwa.snapshot_card_title') }}
                    </h2>
                    <p class="text-2xs text-ink-muted">
                        <template x-if="$store.pwa && $store.pwa.snapshot">
                            <span>
                                {{ __('pwa.last_synced') }}:
                                <span class="font-mono font-semibold text-ink"
                                      x-text="$store.pwa.snapshot.generated_at_formatted || $store.pwa.snapshot.generated_at"></span>
                            </span>
                        </template>
                        <template x-if="!$store.pwa || !$store.pwa.snapshot">
                            <span>{{ __('pwa.not_synced_yet') }}</span>
                        </template>
                    </p>
                </div>
            </div>

            <template x-if="$store.pwa && $store.pwa.isOnline && {{ auth()->check() ? 'true' : 'false' }}">
                <button type="button"
                        @click="$store.pwa.syncSnapshot()"
                        :disabled="$store.pwa.syncingSnapshot"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-line bg-surface-muted px-3 py-1.5 text-2xs font-semibold text-ink hover:bg-surface transition cursor-pointer disabled:opacity-50 self-start sm:self-auto">
                    <svg class="w-3.5 h-3.5" :class="$store.pwa.syncingSnapshot ? 'animate-spin' : ''" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    <span>{{ __('pwa.btn_refresh_snapshot') }}</span>
                </button>
            </template>
        </div>

        <!-- Empty State -->
        <template x-if="!$store.pwa || !$store.pwa.snapshot">
            <div class="rounded-xl border border-dashed border-line bg-surface-muted/40 p-6 text-center space-y-2">
                <svg class="w-8 h-8 mx-auto text-ink-muted" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                </svg>
                <p class="text-xs font-semibold text-ink">{{ __('pwa.not_synced_yet') }}</p>
                <p class="text-2xs text-ink-muted max-w-md mx-auto">{{ __('pwa.not_synced_hint') }}</p>
            </div>
        </template>

        <!-- Populated Snapshot Content -->
        <template x-if="$store.pwa && $store.pwa.snapshot">
            <div class="space-y-4">
                <!-- Staff Summary & Leave Balances (AL & DP) -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="rounded-xl border border-line bg-surface-muted/40 p-3.5 space-y-1">
                        <div class="text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                            {{ __('pwa.snapshot_staff_label') }}
                        </div>
                        <div class="text-sm font-bold text-ink truncate" x-text="$store.pwa.snapshot.user?.name || '-'"></div>
                        <div class="text-2xs text-ink-muted truncate">
                            <span x-text="$store.pwa.snapshot.user?.company_name || '-'"></span>
                            <template x-if="$store.pwa.snapshot.user?.department_name">
                                <span> &bull; <span x-text="$store.pwa.snapshot.user.department_name"></span></span>
                            </template>
                        </div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface-muted/40 p-3.5 space-y-1">
                        <div class="text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                            {{ __('pwa.snapshot_al_balance') }}
                        </div>
                        <div class="text-xl font-mono font-bold text-ink">
                            <span x-text="Math.round(Number($store.pwa.snapshot.leave_balances?.AL ?? 0))"></span>
                            <span class="text-xs font-sans font-medium text-ink-muted">{{ __('pwa.snapshot_days_unit') }}</span>
                        </div>
                    </div>

                    <div class="rounded-xl border border-line bg-surface-muted/40 p-3.5 space-y-1">
                        <div class="text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                            {{ __('pwa.snapshot_dp_balance') }}
                        </div>
                        <div class="text-xl font-mono font-bold text-ink">
                            <span x-text="Math.round(Number($store.pwa.snapshot.leave_balances?.DP ?? 0))"></span>
                            <span class="text-xs font-sans font-medium text-ink-muted">{{ __('pwa.snapshot_days_unit') }}</span>
                        </div>
                    </div>
                </div>

                <!-- 14-Day Upcoming Published Schedules -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-ink-muted">
                            {{ __('pwa.snapshot_14_days_title') }}
                        </h3>
                        <span class="inline-flex items-center gap-1 text-2xs font-mono text-ink-muted">
                            <span x-text="$store.pwa.snapshot.window?.from || ''"></span>
                            <svg class="w-3 h-3 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                            </svg>
                            <span x-text="$store.pwa.snapshot.window?.to || ''"></span>
                        </span>
                    </div>

                    <template x-if="!$store.pwa.snapshot.upcoming_schedules || $store.pwa.snapshot.upcoming_schedules.length === 0">
                        <div class="rounded-xl border border-line bg-surface-muted/30 p-4 text-center text-xs text-ink-muted">
                            {{ __('pwa.no_upcoming_schedules') }}
                        </div>
                    </template>

                    <template x-if="$store.pwa.snapshot.upcoming_schedules && $store.pwa.snapshot.upcoming_schedules.length > 0">
                        <div class="overflow-x-auto rounded-xl border border-line">
                            <table class="o-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('pwa.col_date') }}</th>
                                        <th>{{ __('pwa.col_shift') }}</th>
                                        <th>{{ __('pwa.col_hours') }}</th>
                                        <th>{{ __('pwa.col_notes') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(item, idx) in $store.pwa.snapshot.upcoming_schedules" :key="item.date + '-' + idx">
                                        <tr>
                                            <td class="font-medium text-xs text-ink">
                                                <div x-text="item.date_formatted || item.date"></div>
                                                <div class="text-2xs font-mono text-ink-muted" x-text="item.date"></div>
                                            </td>
                                            <td>
                                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-2xs font-bold text-white"
                                                      :style="'background-color: ' + (item.color_badge || '#71717A')"
                                                      x-text="item.shift_code"></span>
                                                <span class="ml-1.5 text-xs text-ink" x-text="item.shift_name"></span>
                                            </td>
                                            <td class="font-mono text-xs text-ink">
                                                <template x-if="!item.is_off && item.start_time && item.end_time">
                                                    <span x-text="item.start_time + ' – ' + item.end_time"></span>
                                                </template>
                                                <template x-if="item.is_off || !item.start_time">
                                                    <span class="o-badge o-badge-neutral">{{ __('pwa.badge_off') }}</span>
                                                </template>
                                            </td>
                                            <td class="text-xs">
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    <template x-if="item.is_holiday">
                                                        <span class="o-badge o-badge-accent"
                                                              x-text="item.holiday_name || '{{ __('pwa.badge_holiday') }}'"></span>
                                                    </template>
                                                    <template x-if="item.is_leave && item.leave_label">
                                                        <span class="o-badge o-badge-warning" x-text="item.leave_label"></span>
                                                    </template>
                                                    <template x-if="item.duty_tag_code">
                                                        <span class="o-badge o-badge-neutral" x-text="item.duty_tag_code"></span>
                                                    </template>
                                                    <template x-if="!item.is_holiday && !item.is_leave && !item.duty_tag_code">
                                                        <span class="text-ink-muted">-</span>
                                                    </template>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>
</div>
