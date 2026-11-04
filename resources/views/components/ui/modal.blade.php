@props([
    'name',
    'title' => null,
    'maxWidth' => 'md',
    'persistent' => false,
])

@php
    $maxWidthClass = match ($maxWidth) {
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-md',
        'lg' => 'sm:max-w-lg',
        'xl' => 'sm:max-w-xl',
        '2xl' => 'sm:max-w-2xl',
        '3xl' => 'sm:max-w-3xl',
        '4xl' => 'sm:max-w-4xl',
        '5xl' => 'sm:max-w-5xl',
        '6xl' => 'sm:max-w-6xl',
        default => 'sm:max-w-md',
    };
    $titleId = 'modal-title-' . \Illuminate\Support\Str::slug($name);
@endphp

<dialog
    x-data="{ open: false }"
    @open-modal.window="if ($event.detail === '{{ $name }}') { $el.showModal(); open = true; }"
    @close-modal.window="if ($event.detail === '{{ $name }}') { $el.close(); open = false; }"
    @close="open = false"
    @if ($persistent)
        @cancel.prevent
    @else
        @click="if ($event.target === $el) { $el.close(); open = false; }"
    @endif
    role="dialog"
    aria-modal="true"
    @if ($title) aria-labelledby="{{ $titleId }}" @endif
    {{ $attributes->merge([
        'class' => "o-modal z-modal m-0 mt-auto w-full max-w-full p-0 bg-transparent border-0 outline-none sm:m-auto sm:modal-middle {$maxWidthClass} backdrop:bg-zinc-950/50 backdrop:backdrop-blur-[2px]",
    ]) }}
>
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="translate-y-4 sm:translate-y-0 sm:scale-95 opacity-0"
        x-transition:enter-end="translate-y-0 sm:scale-100 opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-y-0 sm:scale-100 opacity-100"
        x-transition:leave-end="translate-y-4 sm:translate-y-0 sm:scale-95 opacity-0"
        class="w-full bg-surface-raised text-ink border border-line-strong rounded-t-xl sm:rounded-xl shadow-lg p-5 flex flex-col gap-4 overflow-hidden"
    >
        @if ($title || ! $persistent)
            <div class="flex items-center justify-between gap-4 border-b border-line pb-3">
                @if ($title)
                    <h2 id="{{ $titleId }}" class="text-lg font-semibold text-ink">
                        {{ $title }}
                    </h2>
                @else
                    <span></span>
                @endif

                @if (! $persistent)
                    <button
                        type="button"
                        @click="$el.closest('dialog').close(); open = false;"
                        aria-label="{{ __('common.cancel') }}"
                        title="{{ __('common.cancel') }}"
                        class="inline-flex items-center justify-center rounded-md p-1 text-ink-muted hover:bg-surface-muted hover:text-ink transition-colors focus:outline-none"
                    >
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                @endif
            </div>
        @endif

        <div class="max-h-[70dvh] overflow-y-auto text-sm text-ink">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="border-t border-line pt-4 flex justify-end gap-2">
                {{ $footer }}
            </div>
        @endisset
    </div>
</dialog>
