@php
    $toasts = [];

    foreach (['ok' => 'success', 'success' => 'success', 'error' => 'error', 'warning' => 'warning'] as $key => $type) {
        if (session()->has($key) && filled(session($key))) {
            $toasts[] = [
                'type' => $type,
                'message' => session($key),
            ];
        }
    }
@endphp

@if (count($toasts) > 0)
    <div class="fixed bottom-4 right-4 z-toast flex flex-col gap-2 pointer-events-none" role="status" aria-live="polite">
        @foreach ($toasts as $toast)
            @php
                $borderClass = match ($toast['type']) {
                    'success' => 'border-emerald-500/30 text-ink',
                    'error' => 'border-red-500/30 text-ink',
                    'warning' => 'border-amber-500/30 text-ink',
                    default => 'border-line text-ink',
                };
                $dotClass = match ($toast['type']) {
                    'success' => 'bg-emerald-500',
                    'error' => 'bg-red-500',
                    'warning' => 'bg-amber-500',
                    default => 'bg-zinc-500',
                };
            @endphp
            <div
                x-data="{ show: true }"
                x-init="setTimeout(() => show = false, 4000)"
                x-show="show"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-2"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-2"
                class="pointer-events-auto flex items-center gap-2.5 border {{ $borderClass }} bg-surface-raised shadow-md rounded-lg p-3 text-xs min-w-[240px] max-w-sm"
            >
                <span class="h-2 w-2 shrink-0 rounded-full {{ $dotClass }}" aria-hidden="true"></span>
                <span class="flex-1 font-medium">{{ $toast['message'] }}</span>
                <button
                    type="button"
                    @click="show = false"
                    aria-label="{{ __('common.cancel') }}"
                    class="text-ink-muted hover:text-ink transition-colors"
                >
                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        @endforeach
    </div>
@endif
