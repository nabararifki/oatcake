<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="text-lg sm:text-xl font-bold text-ink tracking-tight">
                    {{ __('audit.page_title') }}
                </h1>
                <p class="text-xs text-ink-muted mt-0.5">
                    {{ __('audit.page_subtitle') }}
                </p>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <span class="o-badge o-badge-accent">
                    <svg class="w-3.5 h-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                    </svg>
                    <span>{{ __('audit.immutable_badge') }}</span>
                </span>
            </div>
        </div>
    </x-slot>

    <div class="space-y-5" x-data="auditForensicConsole()">
        <!-- 4 Kartu Ringkasan Cepat -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
            <div class="rounded-2xl border border-line bg-surface p-4 shadow-2xs">
                <div class="text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                    {{ __('audit.stat_total_logs') }}
                </div>
                <div class="mt-1.5 text-2xl font-mono font-bold text-ink">
                    {{ number_format($stats['total_logs']) }}
                </div>
            </div>

            <div class="rounded-2xl border border-line bg-surface p-4 shadow-2xs">
                <div class="text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                    {{ __('audit.stat_today_logs') }}
                </div>
                <div class="mt-1.5 text-2xl font-mono font-bold text-ink">
                    {{ number_format($stats['today_logs']) }}
                </div>
            </div>

            <div class="rounded-2xl border border-line bg-surface p-4 shadow-2xs">
                <div class="text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                    {{ __('audit.stat_master_config') }}
                </div>
                <div class="mt-1.5 text-2xl font-mono font-bold text-ink">
                    {{ number_format($stats['master_config_changes']) }}
                </div>
            </div>

            <div class="rounded-2xl border border-line bg-surface p-4 shadow-2xs">
                <div class="text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                    {{ __('audit.stat_approvals_ledger') }}
                </div>
                <div class="mt-1.5 text-2xl font-mono font-bold text-ink">
                    {{ number_format($stats['approval_ledger_actions']) }}
                </div>
            </div>
        </div>

        <!-- Baris Filter Komprehensif + JSONB Containment (@>) -->
        <div class="rounded-2xl border border-line bg-surface p-4 sm:p-5 shadow-2xs">
            <form method="GET" action="{{ route('audit-logs.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3.5 items-end">
                <div class="lg:col-span-3 space-y-1">
                    <label for="audit_q" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                        {{ __('audit.filter_search_label') }}
                    </label>
                    <input type="text"
                           id="audit_q"
                           name="q"
                           value="{{ $filterQuery }}"
                           placeholder="{{ __('audit.filter_search_placeholder') }}"
                           class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                </div>

                <div class="lg:col-span-3 space-y-1">
                    <label for="audit_json_filter" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                        {{ __('audit.filter_json_label') }}
                    </label>
                    <input type="text"
                           id="audit_json_filter"
                           name="json_filter"
                           value="{{ $filterJson }}"
                           placeholder="{{ __('audit.filter_json_placeholder') }}"
                           class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                </div>

                <div class="lg:col-span-2 space-y-1">
                    <label for="audit_module" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                        {{ __('audit.filter_module_label') }}
                    </label>
                    <select id="audit_module"
                            name="auditable_type"
                            class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                        <option value="">{{ __('audit.filter_all_modules') }}</option>
                        @foreach ($moduleOptions as $moduleOpt)
                            <option value="{{ $moduleOpt }}" @selected($filterModule === $moduleOpt)>
                                {{ $moduleOpt }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="lg:col-span-2 space-y-1">
                    <label for="audit_event" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                        {{ __('audit.filter_event_label') }}
                    </label>
                    <select id="audit_event"
                            name="event_type"
                            class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                        <option value="">{{ __('audit.filter_all_events') }}</option>
                        @foreach ($eventOptions as $eventOpt)
                            <option value="{{ $eventOpt }}" @selected($filterEvent === $eventOpt)>
                                {{ $eventOpt }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="lg:col-span-2 space-y-1">
                    <label for="audit_actor" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                        {{ __('audit.filter_actor_label') }}
                    </label>
                    <select id="audit_actor"
                            name="actor_id"
                            class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
                        <option value="">{{ __('audit.filter_all_actors') }}</option>
                        @foreach ($actors as $actorOpt)
                            <option value="{{ $actorOpt->id }}" @selected((string) $filterActorId === (string) $actorOpt->id)>
                                {{ $actorOpt->name }} ({{ $actorOpt->role }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="lg:col-span-2 space-y-1">
                    <label for="audit_date_from" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                        {{ __('audit.filter_date_from') }}
                    </label>
                    <input type="date"
                           id="audit_date_from"
                           name="date_from"
                           value="{{ $filterDateFrom }}"
                           class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                </div>

                <div class="lg:col-span-2 space-y-1">
                    <label for="audit_date_to" class="block text-2xs font-semibold uppercase tracking-wider text-ink-muted">
                        {{ __('audit.filter_date_to') }}
                    </label>
                    <input type="date"
                           id="audit_date_to"
                           name="date_to"
                           value="{{ $filterDateTo }}"
                           class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs font-mono text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                </div>

                <div class="lg:col-span-8 flex flex-wrap items-center justify-end gap-2">
                    <a href="{{ route('audit-logs.index') }}"
                       class="inline-flex items-center justify-center rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition">
                        {{ __('audit.btn_reset_filters') }}
                    </a>
                    <button type="submit"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-accent px-4 py-2 text-xs font-bold text-accent-fg shadow-2xs hover:opacity-95 transition cursor-pointer">
                        <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <span>{{ __('audit.btn_apply_filters') }}</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Tabel Log Audit Immutable -->
        <div class="rounded-2xl border border-line bg-surface shadow-2xs overflow-hidden">
            @if ($logs->isEmpty())
                <div class="p-8 text-center text-xs text-ink-muted">
                    {{ __('audit.empty_state') }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="o-table">
                        <thead>
                            <tr>
                                <th>{{ __('audit.col_timestamp') }}</th>
                                <th>{{ __('audit.col_actor') }}</th>
                                <th>{{ __('audit.col_module') }}</th>
                                <th>{{ __('audit.col_event') }}</th>
                                <th>{{ __('audit.col_description_changes') }}</th>
                                <th class="text-right">{{ __('audit.col_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($logs as $log)
                                @php
                                    $eventBadgeClass = match ($log->event_type) {
                                        'created', 'approved_tier2', 'verified', 'published' => 'o-badge-success',
                                        'updated', 'approved_tier1', 'ledger_adjusted' => 'o-badge-accent',
                                        'deleted', 'rejected', 'cancelled' => 'o-badge-danger',
                                        default => 'o-badge-neutral',
                                    };
                                    $changedKeys = $log->getChangedKeys();
                                    $createdWib = $log->created_at?->timezone('Asia/Jakarta');
                                @endphp
                                <tr>
                                    <td class="font-mono text-xs text-ink">
                                        <div>{{ $createdWib?->format('d M Y') }}</div>
                                        <div class="text-2xs text-ink-muted">{{ $createdWib?->format('H:i:s') }} WIB</div>
                                    </td>
                                    <td>
                                        <div class="text-xs font-semibold text-ink">{{ $log->actor_name ?? 'System' }}</div>
                                        <div class="text-2xs font-mono text-ink-muted uppercase">{{ $log->actor_role ?? 'system' }}</div>
                                    </td>
                                    <td>
                                        <span class="o-badge o-badge-neutral font-mono">{{ $log->auditable_type }}</span>
                                        @if ($log->auditable_id !== null)
                                            <span class="ml-1 text-2xs font-mono text-ink-muted">#{{ $log->auditable_id }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="o-badge {{ $eventBadgeClass }} font-mono">{{ $log->event_type }}</span>
                                    </td>
                                    <td class="whitespace-normal min-w-[260px] max-w-md">
                                        <div class="text-xs font-medium text-ink">{{ $log->description }}</div>
                                        @if ($changedKeys !== [])
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                @foreach (array_slice($changedKeys, 0, 5) as $attrKey)
                                                    <span class="inline-flex items-center rounded bg-surface-muted px-1.5 py-0.5 text-[10px] font-mono text-ink-muted border border-line">
                                                        {{ $attrKey }}
                                                    </span>
                                                @endforeach
                                                @if (count($changedKeys) > 5)
                                                    <span class="text-[10px] font-mono text-ink-muted">+{{ count($changedKeys) - 5 }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <button type="button"
                                                @click="inspectLog('{{ route('audit-logs.show', $log) }}')"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface-muted px-2.5 py-1.5 text-2xs font-semibold text-ink hover:bg-surface transition cursor-pointer">
                                            <svg class="w-3.5 h-3.5 text-accent" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m5.231 13.481L15 17.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v16.5c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Zm3.75 11.625a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                                            </svg>
                                            <span>{{ __('audit.btn_inspect_diff') }}</span>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($logs->hasPages())
                    <div class="border-t border-line px-4 py-3">
                        {{ $logs->links() }}
                    </div>
                @endif
            @endif
        </div>

        <!-- Modal Side-by-Side JSONB Diff Inspection -->
        <x-ui.modal name="audit-diff-modal" :title="__('audit.modal_title')" maxWidth="4xl">
            <div class="space-y-4">
                <template x-if="loading">
                    <div class="p-6 text-center text-xs text-ink-muted">
                        {{ __('audit.loading_diff') }}
                    </div>
                </template>

                <template x-if="!loading && error !== ''">
                    <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-3.5 text-xs text-red-700 dark:text-red-300" x-text="error"></div>
                </template>

                <template x-if="!loading && detail">
                    <div class="space-y-4">
                        <!-- Header Metadata Forensik -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5 rounded-xl border border-line bg-surface-muted/50 p-3.5 text-xs">
                            <div>
                                <div class="text-[10px] font-semibold uppercase tracking-wider text-ink-muted">{{ __('audit.meta_actor') }}</div>
                                <div class="font-bold text-ink mt-0.5" x-text="detail.actor_name + ' (' + detail.actor_role + ')'"></div>
                            </div>
                            <div>
                                <div class="text-[10px] font-semibold uppercase tracking-wider text-ink-muted">{{ __('audit.meta_timestamp') }}</div>
                                <div class="font-mono text-ink mt-0.5" x-text="detail.created_at_formatted"></div>
                            </div>
                            <div>
                                <div class="text-[10px] font-semibold uppercase tracking-wider text-ink-muted">{{ __('audit.meta_ip_address') }}</div>
                                <div class="font-mono text-ink mt-0.5" x-text="detail.metadata?.ip_address || '-'"></div>
                            </div>
                            <div>
                                <div class="text-[10px] font-semibold uppercase tracking-wider text-ink-muted">{{ __('audit.meta_http_method') }}</div>
                                <div class="font-mono text-ink mt-0.5 truncate"
                                     x-text="(detail.metadata?.http_method || '-') + ' ' + (detail.metadata?.route_name || detail.metadata?.url || '')"></div>
                            </div>
                            <div class="sm:col-span-2 lg:col-span-4 border-t border-line/70 pt-2">
                                <span class="text-[10px] font-semibold uppercase tracking-wider text-ink-muted mr-2">{{ __('audit.meta_user_agent') }}:</span>
                                <span class="font-mono text-2xs text-ink-muted break-all" x-text="detail.metadata?.user_agent || '-'"></span>
                            </div>
                        </div>

                        <!-- Tabel Perbandingan Berdampingan (Sebelum vs Sesudah) -->
                        <div class="space-y-2">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-ink-muted">
                                {{ __('audit.diff_table_title') }}
                            </h3>

                            <template x-if="!detail.diff_rows || detail.diff_rows.length === 0">
                                <div class="rounded-xl border border-line bg-surface-muted/30 p-4 text-center text-xs text-ink-muted">
                                    {{ __('audit.diff_empty') }}
                                </div>
                            </template>

                            <template x-if="detail.diff_rows && detail.diff_rows.length > 0">
                                <div class="overflow-x-auto rounded-xl border border-line">
                                    <table class="o-table">
                                        <thead>
                                            <tr>
                                                <th>{{ __('audit.diff_col_attribute') }}</th>
                                                <th>{{ __('audit.diff_col_old') }}</th>
                                                <th>{{ __('audit.diff_col_new') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="row in detail.diff_rows" :key="row.key">
                                                <tr>
                                                    <td class="font-mono text-xs font-semibold text-ink" x-text="row.key"></td>
                                                    <td class="whitespace-normal break-all font-mono text-xs bg-rose-500/5 text-rose-800 dark:text-rose-300"
                                                        x-text="formatDiffValue(row.old)"></td>
                                                    <td class="whitespace-normal break-all font-mono text-xs bg-emerald-500/5 text-emerald-800 dark:text-emerald-300"
                                                        x-text="formatDiffValue(row.new)"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </template>
                        </div>

                        <!-- Pratinjau Payload JSONB Mentah -->
                        <div class="space-y-2">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-ink-muted">
                                {{ __('audit.raw_json_title') }}
                            </h3>
                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                                <div class="rounded-xl border border-line bg-surface-muted/40 p-3 space-y-1.5">
                                    <div class="text-[10px] font-mono font-bold text-rose-700 dark:text-rose-300">{{ __('audit.raw_old_values') }}</div>
                                    <pre class="text-[11px] font-mono text-ink overflow-x-auto max-h-48 whitespace-pre-wrap break-all"
                                         x-text="JSON.stringify(detail.old_values, null, 2)"></pre>
                                </div>
                                <div class="rounded-xl border border-line bg-surface-muted/40 p-3 space-y-1.5">
                                    <div class="text-[10px] font-mono font-bold text-emerald-700 dark:text-emerald-300">{{ __('audit.raw_new_values') }}</div>
                                    <pre class="text-[11px] font-mono text-ink overflow-x-auto max-h-48 whitespace-pre-wrap break-all"
                                         x-text="JSON.stringify(detail.new_values, null, 2)"></pre>
                                </div>
                                <div class="rounded-xl border border-line bg-surface-muted/40 p-3 space-y-1.5">
                                    <div class="text-[10px] font-mono font-bold text-ink-muted">{{ __('audit.raw_metadata') }}</div>
                                    <pre class="text-[11px] font-mono text-ink overflow-x-auto max-h-48 whitespace-pre-wrap break-all"
                                         x-text="JSON.stringify(detail.metadata, null, 2)"></pre>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end pt-1">
                            <button type="button"
                                    @click="$dispatch('close-modal', 'audit-diff-modal')"
                                    class="rounded-xl border border-line bg-surface px-4 py-2 text-xs font-semibold text-ink hover:bg-surface-muted transition cursor-pointer">
                                {{ __('audit.btn_close') }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    </div>

    <script>
        function auditForensicConsole() {
            return {
                loading: false,
                error: '',
                detail: null,

                async inspectLog(url) {
                    this.loading = true;
                    this.error = '';
                    this.detail = null;
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'audit-diff-modal' }));

                    try {
                        const response = await fetch(url, {
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                        });

                        if (!response.ok) {
                            this.error = '{{ __('audit.error_loading_diff') }}';
                            return;
                        }

                        this.detail = await response.json();
                    } catch (e) {
                        this.error = '{{ __('audit.error_loading_diff') }}';
                    } finally {
                        this.loading = false;
                    }
                },

                formatDiffValue(val) {
                    if (val === null || val === undefined) {
                        return 'null';
                    }
                    if (typeof val === 'object') {
                        return JSON.stringify(val);
                    }
                    return String(val);
                },
            };
        }
    </script>
</x-app-layout>
