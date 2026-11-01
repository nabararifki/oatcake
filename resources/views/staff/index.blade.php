<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-ink leading-tight">
                    {{ __('staff.title_directory') }}
                </h2>
                <p class="text-xs text-ink-muted mt-1">
                    {{ $company?->name ?? __('common.global_root_all_tenants') }} &mdash; {{ __('staff.subtitle_directory', ['count' => $employees->count()]) }}
                </p>
            </div>
            <a href="{{ route('staff.create') }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-accent text-accent-fg font-semibold shadow-md shadow-accent/20 hover:opacity-95 transition-all text-xs">
                {{ __('staff.add_new_staff') }}
            </a>
        </div>
    </x-slot>

    <div class="space-y-4"
         x-data="{
             deleteEmployeeId: null,
             deleteEmployeeName: '',
             deleteActionUrl: '',
             deleteUrlTemplate: '{{ route('staff.destroy', '__ID__') }}',
             deleteDescTemplate: @js(__('staff.modal_delete_desc', ['name' => '__NAME__'])),
             openDeleteModal(id, name) {
                 this.deleteEmployeeId = id;
                 this.deleteEmployeeName = name;
                 this.deleteActionUrl = this.deleteUrlTemplate.replace('__ID__', id);
                 window.dispatchEvent(new CustomEvent('open-modal', { detail: 'delete-staff-modal' }));
             },
             closeDeleteModal() {
                 window.dispatchEvent(new CustomEvent('close-modal', { detail: 'delete-staff-modal' }));
             }
         }">
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
                    <th class="py-3 px-4 bg-surface-muted text-2xs font-semibold uppercase tracking-wider text-ink-muted text-right">{{ __('staff.table_action') }}</th>
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
                                @php
                                    $tagDescKey = 'roster.tag_desc_' . strtolower($tag->code);
                                    $tagLabelKey = 'roster.tag_' . strtolower($tag->code);
                                    $resolvedTagDesc = __($tagDescKey) !== $tagDescKey ? __($tagDescKey) : $tag->name;
                                    $resolvedTagLabel = __($tagLabelKey) !== $tagLabelKey ? __($tagLabelKey) : $tag->code;
                                @endphp
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-2xs font-semibold text-white"
                                      style="background-color: {{ $tag->badge_color }};"
                                      title="{{ $resolvedTagDesc }}">
                                    {{ $resolvedTagLabel }}
                                </span>
                            @empty
                                <span class="text-xs text-ink-faint">&mdash;</span>
                            @endforelse
                        </div>
                    </td>
                    <td class="py-3 px-4 text-right">
                        <div class="inline-flex items-center justify-end gap-1.5">
                            @can('update', $employee)
                                <a href="{{ route('staff.edit', $employee) }}"
                                   aria-label="{{ __('staff.action_edit') }}"
                                   title="{{ __('staff.action_edit') }}"
                                   class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-line bg-surface hover:bg-surface-muted text-ink-muted hover:text-ink transition-colors shadow-sm focus:outline-none focus:ring-2 focus:ring-accent/20">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125" />
                                    </svg>
                                </a>
                            @endcan

                            @can('delete', $employee)
                                <button type="button"
                                        @click="openDeleteModal({{ $employee->id }}, '{{ addslashes($employee->user?->name ?? '') }}')"
                                        aria-label="{{ __('staff.action_delete') }}"
                                        title="{{ __('staff.action_delete') }}"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-line bg-surface hover:bg-red-50 dark:hover:bg-red-950/40 text-ink-muted hover:text-red-600 transition-colors shadow-sm focus:outline-none focus:ring-2 focus:ring-red-500/20">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="py-8 text-center text-ink-muted">
                        {{ __('staff.empty_directory') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>

        <!-- Modal Konfirmasi Penghapusan Staf Destruktif (<dialog>) -->
        <x-ui.modal name="delete-staff-modal" :title="__('staff.modal_delete_title')" maxWidth="md">
            <div class="space-y-4">
                <div class="flex items-start gap-3">
                    <div class="h-10 w-10 rounded-xl bg-red-500/15 text-red-600 dark:text-red-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <p class="text-xs text-ink-muted leading-relaxed"
                       x-text="deleteDescTemplate.replace('__NAME__', deleteEmployeeName)">
                        {{ __('staff.modal_delete_desc', ['name' => '...']) }}
                    </p>
                </div>

                <form method="POST" :action="deleteActionUrl" class="flex items-center justify-end gap-2 pt-3 border-t border-line">
                    @csrf
                    @method('DELETE')
                    <button type="button"
                            @click="closeDeleteModal()"
                            class="px-4 py-2 rounded-xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition-colors cursor-pointer">
                        {{ __('staff.btn_cancel') }}
                    </button>
                    <button type="submit"
                            class="px-4 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white text-xs font-semibold shadow-sm transition-colors cursor-pointer">
                        {{ __('staff.btn_confirm_delete') }}
                    </button>
                </form>
            </div>
        </x-ui.modal>
    </div>
</x-app-layout>
