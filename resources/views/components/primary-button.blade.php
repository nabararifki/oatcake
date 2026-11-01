<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-accent border border-transparent rounded-xl font-semibold text-xs text-accent-fg uppercase tracking-widest hover:opacity-95 active:opacity-90 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 transition ease-in-out duration-150 cursor-pointer shadow-sm']) }}>
    {{ $slot }}
</button>
