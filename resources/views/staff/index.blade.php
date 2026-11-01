<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-ink leading-tight">
                    {{ __('staff.title_directory') }}
                </h2>
                <p class="text-xs text-ink-muted mt-1">
                    {{ $company?->name ?? 'Global Tenant' }} &mdash; {{ __('staff.subtitle_directory', ['count' => $employees->count()]) }}
                </p>
            </div>
            <a href="{{ route('staff.create') }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-accent text-accent-fg font-semibold shadow-md shadow-accent/20 hover:opacity-95 transition-all text-xs">
                {{ __('staff.add_new_staff') }}
            </a>
        </div>
    </x-slot>

    <div class="space-y-4">
        @if (session('success'))
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 p-4 text-sm flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                    <span class="font-medium">{{ session('success') }}</span>
                </div>
            </div>
        @endif

        <x-ui.table :striped="true" class="rounded-xl border border-line bg-surface shadow-sm overflow-hidden">
            <x-slot name="header">
                <tr class="bg-surface-muted border-b border-line">
                    <th class="py-3 px-4 bg-surface-muted text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('staff.table_name_email') }}</th>
                    <th class="py-3 px-4 bg-surface-muted text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('staff.table_department') }}</th>
                    <th class="py-3 px-4 bg-surface-muted text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('staff.table_position') }}</th>
                    <th class="py-3 px-4 bg-surface-muted text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('staff.table_role') }}</th>
                    <th class="py-3 px-4 bg-surface-muted text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('staff.table_status') }}</th>
                    <th class="py-3 px-4 bg-surface-muted text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('staff.table_tags') }}</th>
                </tr>
            </x-slot>

            @forelse ($employees as $employee)
                <tr class="hover:bg-surface-muted/50 transition-colors">
                    <td class="py-3 px-4">
                        <div class="font-semibold text-ink">{{ $employee->user?->name ?? '-' }}</div>
                        <div class="text-xs text-ink-muted">{{ $employee->user?->email ?? '-' }} (@<span>{{ $employee->user?->username }}</span>)</div>
                    </td>
                    <td class="py-3 px-4">
                        @if ($employee->department)
                            <span class="font-medium text-ink">{{ $employee->department->name }}</span>
                            <span class="text-xs text-ink-muted block">{{ $employee->department->code }}</span>
                        @else
                            <span class="text-ink-faint">-</span>
                        @endif
                    </td>
                    <td class="py-3 px-4">
                        <div class="text-ink">{{ $employee->position }}</div>
                        <div class="text-xs text-ink-muted">{{ __('staff.field_join_date') }}: {{ $employee->join_date?->format('Y-m-d') }}</div>
                    </td>
                    <td class="py-3 px-4">
                        <span class="o-badge o-badge-neutral uppercase">
                            {{ $employee->user?->role ?? 'user' }}
                        </span>
                    </td>
                    <td class="py-3 px-4">
                        <span class="o-badge o-badge-success uppercase">
                            {{ $employee->status }}
                        </span>
                    </td>
                    <td class="py-3 px-4">
                        <div class="flex flex-wrap gap-1.5">
                            @forelse ($employee->tags as $tag)
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-2xs font-semibold text-white"
                                      style="background-color: {{ $tag->badge_color }};"
                                      title="{{ $tag->name }}">
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
                    <td colspan="6" class="py-8 text-center text-ink-muted">
                        {{ __('staff.empty_directory') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>
    </div>
</x-app-layout>
