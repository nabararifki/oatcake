<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-surface border border-line rounded-xl font-semibold text-xs text-ink uppercase tracking-widest shadow-sm hover:bg-surface-muted focus:outline-none focus:ring-2 focus:ring-accent disabled:opacity-25 transition ease-in-out duration-150 cursor-pointer']) }}>
    {{ $slot }}
</button>
