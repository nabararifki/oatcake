@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'rounded-xl border-line-strong bg-canvas text-ink placeholder:text-ink-faint focus:border-accent focus:ring-2 focus:ring-accent shadow-sm transition-colors']) }}>
