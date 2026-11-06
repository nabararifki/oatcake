<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="font-bold text-xl text-ink leading-tight">
                    {{ __('departments.title') }}
                </h2>
                <p class="text-xs text-ink-muted mt-0.5">
                    {{ $company?->name ?? __('common.global_root_all_tenants') }} &mdash; {{ __('departments.subtitle') }}
                </p>
            </div>

            @if ($canManage)
                <button type="button"
                        @click="$dispatch('open-modal', 'create-department-modal')"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-accent text-accent-fg font-semibold shadow-md shadow-accent/20 hover:opacity-95 transition-all text-xs cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    <span>{{ __('departments.add_department') }}</span>
                </button>
            @endif
        </div>
    </x-slot>

    <div class="space-y-4"
         x-data="{
             editDepartment: {
                 id: null,
                 name: '',
                 code: '',
                 is_24_hours: false,
                 min_staff_per_shift: 1,
                 description: ''
             },
             editActionUrl: '',
             updateUrlTemplate: '{{ route('departments.update', '__ID__') }}',
             deleteDepartmentId: null,
             deleteDepartmentName: '',
             deleteActionUrl: '',
             deleteUrlTemplate: '{{ route('departments.destroy', '__ID__') }}',
             openEditModal(dept) {
                 this.editDepartment = {
                     id: dept.id,
                     name: dept.name || '',
                     code: dept.code || '',
                     is_24_hours: Boolean(dept.is_24_hours || dept.is_operational),
                     min_staff_per_shift: dept.min_staff_per_shift || 1,
                     description: dept.description || ''
                 };
                 this.editActionUrl = this.updateUrlTemplate.replace('__ID__', dept.id);
                 window.dispatchEvent(new CustomEvent('open-modal', { detail: 'edit-department-modal' }));
             },
             openDeleteModal(id, name) {
                 this.deleteDepartmentId = id;
                 this.deleteDepartmentName = name;
                 this.deleteActionUrl = this.deleteUrlTemplate.replace('__ID__', id);
                 window.dispatchEvent(new CustomEvent('open-modal', { detail: 'delete-department-modal' }));
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

        @if (session('error'))
            <div class="rounded-2xl border border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300 p-4 text-sm flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-red-500"></span>
                    <span class="font-medium">{{ session('error') }}</span>
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

        @if (! $canManage)
            <div class="rounded-2xl border border-line bg-surface-muted/60 px-4 py-3 text-xs text-ink-muted flex items-center gap-2">
                <svg class="w-4 h-4 shrink-0 text-ink-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                </svg>
                <span>{{ __('departments.read_only_notice') }}</span>
            </div>
        @endif

        <x-ui.table :striped="true" class="rounded-2xl border border-line bg-surface shadow-sm overflow-hidden">
            <x-slot name="header">
                <tr class="bg-surface-muted border-b border-line">
                    <th class="py-3 px-4 bg-surface-muted text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('departments.code') }}</th>
                    <th class="py-3 px-4 bg-surface-muted text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('departments.name') }}</th>
                    <th class="py-3 px-4 bg-surface-muted text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('departments.is_24_hours') }}</th>
                    <th class="py-3 px-4 bg-surface-muted text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('departments.min_staff_per_shift') }}</th>
                    <th class="py-3 px-4 bg-surface-muted text-2xs font-semibold uppercase tracking-wider text-ink-muted">{{ __('departments.active_staff') }}</th>
                    @if ($canManage)
                        <th class="py-3 px-4 bg-surface-muted text-2xs font-semibold uppercase tracking-wider text-ink-muted text-right">{{ __('departments.actions') }}</th>
                    @endif
                </tr>
            </x-slot>

            @forelse ($departments as $dept)
                <tr class="hover:bg-surface-muted/50 transition-colors">
                    <td class="py-3 px-4">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-surface-muted border border-line font-mono text-xs font-bold text-ink">
                            {{ $dept->code }}
                        </span>
                    </td>
                    <td class="py-3 px-4">
                        <div class="font-semibold text-ink">{{ $dept->name }}</div>
                        @if ($dept->description)
                            <div class="text-xs text-ink-muted mt-0.5">{{ $dept->description }}</div>
                        @endif
                    </td>
                    <td class="py-3 px-4">
                        @if ($dept->is_24_hours || $dept->is_operational)
                            <span class="o-badge o-badge-success">
                                {{ __('departments.status_24_hours') }}
                            </span>
                        @else
                            <span class="o-badge o-badge-neutral">
                                {{ __('departments.status_regular') }}
                            </span>
                        @endif
                    </td>
                    <td class="py-3 px-4 font-semibold text-ink">
                        {{ $dept->min_staff_per_shift }}
                    </td>
                    <td class="py-3 px-4">
                        <span class="font-semibold text-ink">{{ $dept->active_employees_count ?? $dept->employees_count ?? 0 }}</span>
                    </td>
                    @if ($canManage)
                        @php
                            $deptEditPayload = [
                                'id' => $dept->id,
                                'name' => $dept->name,
                                'code' => $dept->code,
                                'is_24_hours' => (bool) ($dept->is_24_hours || $dept->is_operational),
                                'min_staff_per_shift' => (int) $dept->min_staff_per_shift,
                                'description' => $dept->description,
                            ];
                        @endphp
                        <td class="py-3 px-4 text-right">
                            <div class="inline-flex items-center justify-end gap-1.5">
                                <button type="button"
                                        data-dept="{{ json_encode($deptEditPayload) }}"
                                        @click="openEditModal(JSON.parse($el.dataset.dept))"
                                        aria-label="{{ __('departments.edit_department') }}"
                                        title="{{ __('departments.edit_department') }}"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-xl border border-line bg-surface hover:bg-surface-muted text-ink-muted hover:text-ink transition-colors shadow-sm cursor-pointer">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125" />
                                    </svg>
                                </button>

                                <button type="button"
                                        @click="openDeleteModal({{ $dept->id }}, '{{ addslashes($dept->name) }}')"
                                        aria-label="{{ __('departments.delete_department') }}"
                                        title="{{ __('departments.delete_department') }}"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-xl border border-line bg-surface hover:bg-red-500/10 text-ink-muted hover:text-red-600 transition-colors shadow-sm cursor-pointer">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $canManage ? 6 : 5 }}" class="py-8 text-center text-ink-muted">
                        {{ __('departments.empty') }}
                    </td>
                </tr>
            @endforelse
        </x-ui.table>

        @if ($canManage)
            <!-- Modal Tambah Departemen -->
            <x-ui.modal name="create-department-modal" :title="__('departments.add_department')" maxWidth="lg">
                <form method="POST" action="{{ route('departments.store') }}" class="space-y-4">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2">
                            <label for="create-dept-name" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('departments.name') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="create-dept-name"
                                   type="text"
                                   name="name"
                                   required
                                   maxlength="100"
                                   placeholder="{{ __('departments.name_placeholder') }}"
                                   class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                        </div>

                        <div>
                            <label for="create-dept-code" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('departments.code') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="create-dept-code"
                                   type="text"
                                   name="code"
                                   required
                                   maxlength="10"
                                   placeholder="{{ __('departments.code_placeholder') }}"
                                   class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono uppercase text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-center">
                        <div>
                            <label for="create-dept-min-staff" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('departments.min_staff_per_shift') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="create-dept-min-staff"
                                   type="number"
                                   name="min_staff_per_shift"
                                   min="1"
                                   value="1"
                                   required
                                   class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                            <p class="text-2xs text-ink-muted mt-1">{{ __('departments.min_staff_hint') }}</p>
                        </div>

                        <div class="pt-2">
                            <input type="hidden" name="is_24_hours" value="0" />
                            <label class="inline-flex items-start gap-2.5 cursor-pointer select-none">
                                <input type="checkbox"
                                       name="is_24_hours"
                                       value="1"
                                       checked
                                       class="mt-0.5 rounded border-line text-accent focus:ring-accent" />
                                <span class="text-xs">
                                    <span class="font-semibold text-ink block">{{ __('departments.is_24_hours') }}</span>
                                    <span class="text-ink-muted text-2xs">{{ __('departments.is_24_hours_hint') }}</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label for="create-dept-description" class="block text-xs font-semibold text-ink mb-1">
                            {{ __('departments.description') }}
                        </label>
                        <textarea id="create-dept-description"
                                  name="description"
                                  rows="2"
                                  maxlength="255"
                                  placeholder="{{ __('departments.description_placeholder') }}"
                                  class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent"></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-line">
                        <button type="button"
                                @click="$dispatch('close-modal', 'create-department-modal')"
                                class="px-4 py-2 rounded-2xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition-colors cursor-pointer">
                            {{ __('departments.btn_cancel') }}
                        </button>
                        <button type="submit"
                                class="px-4 py-2 rounded-2xl bg-accent text-accent-fg text-xs font-semibold shadow-sm hover:opacity-95 transition-all cursor-pointer">
                            {{ __('departments.btn_save') }}
                        </button>
                    </div>
                </form>
            </x-ui.modal>

            <!-- Modal Edit Departemen -->
            <x-ui.modal name="edit-department-modal" :title="__('departments.edit_department')" maxWidth="lg">
                <form method="POST" :action="editActionUrl" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2">
                            <label for="edit-dept-name" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('departments.name') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="edit-dept-name"
                                   type="text"
                                   name="name"
                                   x-model="editDepartment.name"
                                   required
                                   maxlength="100"
                                   class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                        </div>

                        <div>
                            <label for="edit-dept-code" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('departments.code') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="edit-dept-code"
                                   type="text"
                                   name="code"
                                   x-model="editDepartment.code"
                                   required
                                   maxlength="10"
                                   class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm font-mono uppercase text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-center">
                        <div>
                            <label for="edit-dept-min-staff" class="block text-xs font-semibold text-ink mb-1">
                                {{ __('departments.min_staff_per_shift') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="edit-dept-min-staff"
                                   type="number"
                                   name="min_staff_per_shift"
                                   min="1"
                                   x-model="editDepartment.min_staff_per_shift"
                                   required
                                   class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent" />
                        </div>

                        <div class="pt-2">
                            <input type="hidden" name="is_24_hours" value="0" />
                            <label class="inline-flex items-start gap-2.5 cursor-pointer select-none">
                                <input type="checkbox"
                                       name="is_24_hours"
                                       value="1"
                                       x-model="editDepartment.is_24_hours"
                                       class="mt-0.5 rounded border-line text-accent focus:ring-accent" />
                                <span class="text-xs">
                                    <span class="font-semibold text-ink block">{{ __('departments.is_24_hours') }}</span>
                                    <span class="text-ink-muted text-2xs">{{ __('departments.is_24_hours_hint') }}</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label for="edit-dept-description" class="block text-xs font-semibold text-ink mb-1">
                            {{ __('departments.description') }}
                        </label>
                        <textarea id="edit-dept-description"
                                  name="description"
                                  rows="2"
                                  maxlength="255"
                                  x-model="editDepartment.description"
                                  class="w-full rounded-2xl border border-line bg-surface px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-accent"></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-line">
                        <button type="button"
                                @click="$dispatch('close-modal', 'edit-department-modal')"
                                class="px-4 py-2 rounded-2xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition-colors cursor-pointer">
                            {{ __('departments.btn_cancel') }}
                        </button>
                        <button type="submit"
                                class="px-4 py-2 rounded-2xl bg-accent text-accent-fg text-xs font-semibold shadow-sm hover:opacity-95 transition-all cursor-pointer">
                            {{ __('departments.btn_update') }}
                        </button>
                    </div>
                </form>
            </x-ui.modal>

            <!-- Modal Hapus Departemen -->
            <x-ui.modal name="delete-department-modal" :title="__('departments.delete_department')" maxWidth="md">
                <div class="space-y-4">
                    <div class="flex items-start gap-3">
                        <div class="h-10 w-10 rounded-2xl bg-red-500/15 text-red-600 dark:text-red-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <div class="space-y-1">
                            <p class="text-sm font-semibold text-ink" x-text="deleteDepartmentName"></p>
                            <p class="text-xs text-ink-muted leading-relaxed">
                                {{ __('departments.confirm_delete') }}
                            </p>
                        </div>
                    </div>

                    <form method="POST" :action="deleteActionUrl" class="flex items-center justify-end gap-2 pt-3 border-t border-line">
                        @csrf
                        @method('DELETE')
                        <button type="button"
                                @click="$dispatch('close-modal', 'delete-department-modal')"
                                class="px-4 py-2 rounded-2xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition-colors cursor-pointer">
                            {{ __('departments.btn_cancel') }}
                        </button>
                        <button type="submit"
                                class="px-4 py-2 rounded-2xl bg-red-600 hover:bg-red-700 text-accent-fg text-xs font-semibold shadow-sm transition-colors cursor-pointer">
                            {{ __('departments.delete_department') }}
                        </button>
                    </form>
                </div>
            </x-ui.modal>
        @endif
    </div>
</x-app-layout>
