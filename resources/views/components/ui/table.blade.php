@props([
    'striped' => false,
    'maxHeight' => 'calc(100dvh - 18rem)',
])

<div {{ $attributes->merge(['class' => 'rounded-lg border border-line bg-surface overflow-hidden']) }}>
    <div class="overflow-auto max-h-[var(--table-max-h)]" style="--table-max-h: {{ $maxHeight }};">
        <table class="o-table @if($striped) o-table--zebra @endif">
            @isset($header)
                <thead>
                    @if (str_contains((string) $header, '<tr'))
                        {{ $header }}
                    @else
                        <tr>{{ $header }}</tr>
                    @endif
                </thead>
            @endisset

            <tbody>
                @if (trim((string) $slot) !== '')
                    {{ $slot }}
                @elseif (isset($empty))
                    <tr>
                        <td colspan="100" class="p-6 text-center whitespace-normal">
                            {{ $empty }}
                        </td>
                    </tr>
                @else
                    <tr>
                        <td colspan="100" class="p-6 text-center whitespace-normal">
                            <x-ui.empty-state />
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    @isset($footer)
        <div class="border-t border-line bg-surface-muted/40 px-4 py-2.5 text-2xs text-ink-muted flex items-center justify-between gap-4">
            {{ $footer }}
        </div>
    @endisset
</div>
