@props([
    'title' => null,
    'description' => null,
    'icon' => null,
])

@php
    $resolvedTitle = $title ?? __('table.no_data');
@endphp

<div {{ $attributes->merge(['class' => 'border-dashed border-2 border-line rounded-lg p-8 text-center flex flex-col items-center justify-center gap-2']) }}>
    <div class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-surface-muted text-ink-muted">
        @if ($icon)
            {{ $icon }}
        @else
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
            </svg>
        @endif
    </div>

    <h3 class="text-sm font-semibold text-ink">
        {{ $resolvedTitle }}
    </h3>

    @if ($description)
        <p class="text-xs text-ink-muted max-w-sm">
            {{ $description }}
        </p>
    @endif

    @if (trim((string) $slot) !== '')
        <div class="mt-2">
            {{ $slot }}
        </div>
    @endif
</div>
