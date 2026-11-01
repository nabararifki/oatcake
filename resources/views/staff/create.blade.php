<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-ink leading-tight">
                    {{ __('staff.title_onboarding') }}
                </h2>
                <p class="text-xs text-ink-muted mt-1">
                    {{ $actor->company?->name ?? 'Global Tenant' }} &mdash; {{ __('staff.subtitle_onboarding') }}
                </p>
            </div>
            <a href="{{ route('staff.index') }}"
               class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-medium transition-colors shadow-sm">
                {{ __('staff.back_to_directory') }}
            </a>
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto">
        <div class="rounded-2xl border border-line bg-surface p-6 sm:p-8 shadow-sm">
            <form method="POST" action="{{ route('staff.store') }}" class="space-y-8"
                  x-data="{ autoPassword: {{ old('password') ? 'false' : 'true' }} }">
                @csrf

                @if ($errors->any())
                    <div class="rounded-xl border border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300 p-4 text-sm">
                        <div>
                            <span class="font-semibold block mb-1">{{ __('staff.validation_error_heading') }}</span>
                            <ul class="list-disc list-inside space-y-0.5">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif

                <!-- Section 1: Identitas Akun & Akses -->
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-ink border-b border-line pb-2 mb-4 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-accent"></span>
                        {{ __('staff.section_identity') }}
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <!-- Nama Lengkap -->
                        <div class="w-full space-y-1.5">
                            <label class="block text-xs font-medium text-ink" for="name">
                                {{ __('staff.field_name') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="255"
                                   placeholder="{{ __('staff.placeholder_name') }}"
                                   class="w-full rounded-xl border border-line-strong bg-canvas text-ink placeholder:text-ink-faint text-sm px-3.5 py-2.5 focus:border-accent focus:ring-2 focus:ring-accent transition-all shadow-sm" />
                            @error('name')
                                <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <!-- Username -->
                        <div class="w-full space-y-1.5">
                            <label class="block text-xs font-medium text-ink" for="username">
                                {{ __('staff.field_username') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="username" name="username" type="text" value="{{ old('username') }}" required maxlength="100"
                                   placeholder="{{ __('staff.placeholder_username') }}"
                                   class="w-full rounded-xl border border-line-strong bg-canvas text-ink placeholder:text-ink-faint text-sm px-3.5 py-2.5 focus:border-accent focus:ring-2 focus:ring-accent transition-all shadow-sm" />
                            @error('username')
                                <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <!-- Email -->
                        <div class="w-full space-y-1.5">
                            <label class="block text-xs font-medium text-ink" for="email">
                                {{ __('staff.field_email') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255"
                                   placeholder="{{ __('staff.placeholder_email') }}"
                                   class="w-full rounded-xl border border-line-strong bg-canvas text-ink placeholder:text-ink-faint text-sm px-3.5 py-2.5 focus:border-accent focus:ring-2 focus:ring-accent transition-all shadow-sm" />
                            <p class="text-xs text-ink-muted">
                                {{ __('staff.field_email_hint') }}
                            </p>
                            @error('email')
                                <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <!-- Role Sistem -->
                        <div class="w-full space-y-1.5">
                            <label class="block text-xs font-medium text-ink" for="role">
                                {{ __('staff.field_role') }} <span class="text-red-500">*</span>
                            </label>
                            <select id="role" name="role" required
                                    class="w-full rounded-xl border border-line-strong bg-canvas text-ink text-sm px-3.5 py-2.5 focus:border-accent focus:ring-2 focus:ring-accent transition-all shadow-sm">
                                @foreach ($allowedRoles as $roleOption)
                                    <option value="{{ $roleOption }}" @selected(old('role', 'user') === $roleOption)>
                                        {{ strtoupper(str_replace('_', ' ', $roleOption)) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('role')
                                <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Section 2: Profil Kepegawaian -->
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-ink border-b border-line pb-2 mb-4 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-accent"></span>
                        {{ __('staff.section_employment') }}
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <!-- Departemen -->
                        <div class="w-full space-y-1.5">
                            <label class="flex items-center justify-between text-xs font-medium text-ink" for="department_id">
                                <span>{{ __('staff.field_department') }} <span class="text-red-500">*</span></span>
                                @if ($isManager)
                                    <span class="o-badge o-badge-neutral">{{ __('staff.department_locked') }}</span>
                                @endif
                            </label>

                            @if ($isManager && $lockedDepartmentId)
                                <input type="hidden" name="department_id" value="{{ $lockedDepartmentId }}" />
                                <select id="department_id" disabled
                                        class="w-full rounded-xl border border-line-strong bg-canvas text-ink text-sm px-3.5 py-2.5 focus:border-accent focus:ring-2 focus:ring-accent transition-all shadow-sm opacity-75 cursor-not-allowed">
                                    @foreach ($departments as $dept)
                                        @if ((int) $dept->id === (int) $lockedDepartmentId)
                                            <option value="{{ $dept->id }}" selected>
                                                [{{ $dept->code }}] {{ $dept->name }}
                                            </option>
                                        @endif
                                    @endforeach
                                </select>
                            @else
                                <select id="department_id" name="department_id" required
                                        class="w-full rounded-xl border border-line-strong bg-canvas text-ink text-sm px-3.5 py-2.5 focus:border-accent focus:ring-2 focus:ring-accent transition-all shadow-sm">
                                    <option value="" disabled @selected(! old('department_id'))>{{ __('staff.field_department_select') }}</option>
                                    @foreach ($departments as $dept)
                                        <option value="{{ $dept->id }}" @selected((int) old('department_id') === (int) $dept->id)>
                                            [{{ $dept->code }}] {{ $dept->name }}
                                        </option>
                                    @endforeach
                                </select>
                            @endif

                            @error('department_id')
                                <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <!-- Jabatan (Position) -->
                        <div class="w-full space-y-1.5">
                            <label class="block text-xs font-medium text-ink" for="position">
                                {{ __('staff.field_position') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="position" name="position" type="text" value="{{ old('position') }}" required maxlength="100"
                                   placeholder="{{ __('staff.placeholder_position') }}"
                                   class="w-full rounded-xl border border-line-strong bg-canvas text-ink placeholder:text-ink-faint text-sm px-3.5 py-2.5 focus:border-accent focus:ring-2 focus:ring-accent transition-all shadow-sm" />
                            @error('position')
                                <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <!-- Tanggal Bergabung (Join Date) -->
                        <div class="w-full space-y-1.5">
                            <label class="block text-xs font-medium text-ink" for="join_date">
                                {{ __('staff.field_join_date') }} <span class="text-red-500">*</span>
                            </label>
                            <input id="join_date" name="join_date" type="date" value="{{ old('join_date', now()->format('Y-m-d')) }}" required
                                   class="w-full rounded-xl border border-line-strong bg-canvas text-ink text-sm px-3.5 py-2.5 focus:border-accent focus:ring-2 focus:ring-accent transition-all shadow-sm" />
                            @error('join_date')
                                <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <!-- Nomor Telepon -->
                        <div class="w-full space-y-1.5">
                            <label class="block text-xs font-medium text-ink" for="phone">
                                {{ __('staff.field_phone') }}
                            </label>
                            <input id="phone" name="phone" type="text" value="{{ old('phone') }}" maxlength="30"
                                   placeholder="{{ __('staff.placeholder_phone') }}"
                                   class="w-full rounded-xl border border-line-strong bg-canvas text-ink placeholder:text-ink-faint text-sm px-3.5 py-2.5 focus:border-accent focus:ring-2 focus:ring-accent transition-all shadow-sm" />
                            @error('phone')
                                <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <!-- Atasan Langsung (Manager ID) -->
                        <div class="w-full space-y-1.5">
                            <label class="block text-xs font-medium text-ink" for="manager_id">
                                {{ __('staff.field_manager') }}
                            </label>
                            <select id="manager_id" name="manager_id"
                                    class="w-full rounded-xl border border-line-strong bg-canvas text-ink text-sm px-3.5 py-2.5 focus:border-accent focus:ring-2 focus:ring-accent transition-all shadow-sm">
                                <option value="">{{ __('staff.field_manager_none') }}</option>
                                @foreach ($managers as $mgr)
                                    <option value="{{ $mgr->id }}" @selected((int) old('manager_id', $isManager ? $actor->employee?->id : null) === (int) $mgr->id)>
                                        {{ $mgr->user?->name }} ({{ $mgr->position }})
                                    </option>
                                @endforeach
                            </select>
                            @error('manager_id')
                                <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Section 3: Penugasan Tag Fungsional -->
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-ink border-b border-line pb-2 mb-4 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-accent"></span>
                        {{ __('staff.field_tags') }}
                    </h3>

                    @if ($tags->isEmpty())
                        <p class="text-sm text-ink-muted italic">{{ __('staff.empty_tags') }}</p>
                    @else
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            @foreach ($tags as $tag)
                                <label class="rounded-lg border border-line p-2.5 flex items-center gap-2 hover:bg-surface-muted cursor-pointer transition-colors">
                                    <input type="checkbox"
                                           name="tags[]"
                                           value="{{ $tag->id }}"
                                           class="rounded border-line-strong bg-canvas text-accent focus:ring-accent"
                                           @checked(in_array($tag->id, old('tags', []))) />
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-2">
                                             <span class="inline-block w-2.5 h-2.5 rounded-full" style="background-color: {{ $tag->badge_color }};"></span>
                                            <span class="text-sm font-semibold text-ink">{{ $tag->code }}</span>
                                        </div>
                                        <span class="text-xs text-ink-muted">{{ $tag->name }}</span>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    @error('tags')
                        <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span>
                    @enderror
                    @error('tags.*')
                        <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Section 4: Kredensial & Opsi Password -->
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-ink border-b border-line pb-2 mb-4 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-accent"></span>
                        {{ __('staff.section_credentials') }}
                    </h3>

                    <div class="p-4 rounded-xl bg-surface-muted/40 border border-line space-y-4">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox"
                                   x-model="autoPassword"
                                   class="rounded border-line-strong bg-canvas text-accent focus:ring-accent" />
                            <span class="text-sm font-medium text-ink">
                                {{ __('staff.field_password_auto') }}
                            </span>
                        </label>

                        <div x-show="!autoPassword" x-cloak class="pt-2 space-y-1.5">
                            <label class="block text-xs font-medium text-ink" for="password">
                                {{ __('staff.field_password_manual') }}
                            </label>
                            <input id="password"
                                   name="password"
                                   type="password"
                                   minlength="8"
                                   x-bind:disabled="autoPassword"
                                   placeholder="{{ __('staff.placeholder_password') }}"
                                   class="w-full max-w-md rounded-xl border border-line-strong bg-canvas text-ink placeholder:text-ink-faint text-sm px-3.5 py-2.5 focus:border-accent focus:ring-2 focus:ring-accent transition-all shadow-sm" />
                            @error('password')
                                <span class="text-xs text-red-600 mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <p x-show="autoPassword" class="text-xs text-ink-muted">
                            {{ __('staff.password_auto_hint') }}
                        </p>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-line">
                    <a href="{{ route('staff.index') }}"
                       class="inline-flex items-center px-4 py-2.5 rounded-xl border border-line bg-surface hover:bg-surface-muted text-ink text-sm font-medium transition-colors shadow-sm">
                        {{ __('staff.btn_cancel') }}
                    </a>
                    <button type="submit"
                            class="px-5 py-2.5 rounded-xl bg-accent text-accent-fg font-semibold shadow-md shadow-accent/25 hover:opacity-95 transition-all text-sm">
                        {{ __('staff.btn_submit') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
