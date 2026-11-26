<x-ui.modal name="role-hierarchy-guide-modal" :title="__('staff.role_guide_modal_title')" maxWidth="lg">
    <div class="space-y-3.5">
        <p class="text-xs text-ink-muted leading-relaxed">
            {{ __('staff.role_guide_modal_intro') }}
        </p>

        <div class="space-y-2.5">
            @foreach ($roleHierarchyGuide as $roleItem)
                <div class="rounded-xl border border-line bg-surface-muted/40 p-3.5 space-y-1">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-ink">
                            {{ $roleItem['label'] }}
                        </span>
                        <span class="inline-flex items-center rounded-md bg-surface px-2 py-0.5 text-2xs font-mono font-medium text-ink-muted border border-line">
                            {{ $roleItem['key'] }}
                        </span>
                    </div>
                    <p class="text-xs text-ink-muted leading-relaxed">
                        {{ $roleItem['description'] }}
                    </p>
                </div>
            @endforeach
        </div>
    </div>

    <x-slot name="footer">
        <button type="button"
                @click="$dispatch('close-modal', 'role-hierarchy-guide-modal')"
                class="px-4 py-2 rounded-xl border border-line bg-surface hover:bg-surface-muted text-ink text-xs font-semibold transition-colors cursor-pointer">
            {{ __('staff.role_guide_btn_close') }}
        </button>
    </x-slot>
</x-ui.modal>
