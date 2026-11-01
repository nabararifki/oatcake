@php
    $currentLocale = app()->getLocale();
    $supportedLocales = config('locales.supported', [
        'id' => ['name' => 'Bahasa Indonesia', 'label' => 'Indonesia'],
        'en' => ['name' => 'English', 'label' => 'English'],
    ]);
    $localeKeys = array_keys($supportedLocales);
    $targetLocale = collect($localeKeys)->first(fn (string $code) => $code !== $currentLocale) ?? ($currentLocale === 'id' ? 'en' : 'id');
    $targetMeta = $supportedLocales[$targetLocale] ?? ['name' => strtoupper($targetLocale)];
@endphp

<form method="POST" action="{{ route('locale.update') }}" class="inline-flex items-center m-0 shrink-0" @submit.stop>
    @csrf
    <input type="hidden" name="locale" value="{{ $targetLocale }}">
    <button type="submit"
            @click.stop
            title="{{ __('nav.language') }}: {{ $targetMeta['name'] }}"
            aria-label="{{ __('nav.language') }} ({{ strtoupper($currentLocale) }} → {{ strtoupper($targetLocale) }})"
            class="group inline-flex items-center gap-0.5 rounded-xl bg-surface-muted p-0.5 ring-1 ring-inset ring-line hover:ring-line-strong transition-all cursor-pointer select-none focus:outline-none focus:ring-2 focus:ring-accent">
        @foreach ($supportedLocales as $code => $meta)
            <span aria-current="{{ $currentLocale === $code ? 'true' : 'false' }}"
                  class="px-1.5 py-0.5 sm:px-2 sm:py-1 text-[10px] sm:text-2xs font-semibold uppercase tracking-wider rounded-lg transition-all {{ $currentLocale === $code ? 'bg-surface text-ink shadow-sm ring-1 ring-line' : 'text-ink-muted group-hover:text-ink' }}">
                {{ strtoupper($code) }}
            </span>
        @endforeach
    </button>
</form>
