@props([
    'name',
    'options' => [],
    'selected' => null,
    'id' => null,
    'placeholder' => null,
    'searchPlaceholder' => null,
    'emptyText' => null,
    'clearLabel' => null,
    'nullable' => false,
    'required' => false,
])

@php
    $inputId = $id ?? $name;
    $resolvedPlaceholder = $placeholder ?? '-- Pilih --';
    $resolvedSearchPlaceholder = $searchPlaceholder ?? 'Cari...';
    $resolvedEmptyText = $emptyText ?? 'Tidak ada pilihan yang cocok.';
    $resolvedClearLabel = $clearLabel ?? 'Hapus pilihan';

    $normalizedOptions = collect($options)->map(function ($opt): array {
        $optArray = is_array($opt) ? $opt : (array) $opt;
        $optId = (string) ($optArray['id'] ?? $optArray['value'] ?? '');
        $optLabel = (string) ($optArray['label'] ?? $optArray['name'] ?? '');
        $optSublabel = (string) ($optArray['sublabel'] ?? '');
        $optDisplay = (string) ($optArray['displayLabel'] ?? $optLabel);
        $optSearch = (string) ($optArray['searchText'] ?? trim($optLabel . ' ' . $optSublabel));

        return [
            'id' => $optId,
            'label' => $optLabel,
            'sublabel' => $optSublabel,
            'displayLabel' => $optDisplay,
            'searchText' => $optSearch,
        ];
    })->values()->all();

    $initialSelected = ($selected !== null && $selected !== '') ? (string) $selected : null;
    $initialOption = $initialSelected !== null
        ? collect($normalizedOptions)->firstWhere('id', $initialSelected)
        : null;
@endphp

<div x-data="{
        open: false,
        search: '',
        selectedValue: {{ Js::from($initialSelected) }},
        highlightedIndex: -1,
        placeholderText: {{ Js::from($resolvedPlaceholder) }},
        options: {{ Js::from($normalizedOptions) }},
        get selectedOption() {
            if (this.selectedValue === null || this.selectedValue === '') {
                return null;
            }
            return this.options.find((o) => String(o.id) === String(this.selectedValue)) || null;
        },
        get filteredOptions() {
            const q = (this.search || '').trim().toLowerCase();
            if (q === '') {
                return this.options;
            }
            return this.options.filter((o) =>
                (o.label && o.label.toLowerCase().includes(q)) ||
                (o.sublabel && o.sublabel.toLowerCase().includes(q)) ||
                (o.searchText && o.searchText.toLowerCase().includes(q))
            );
        },
        openDropdown() {
            this.open = true;
            this.highlightedIndex = -1;
            this.$nextTick(() => {
                if (this.$refs.searchInput) {
                    this.$refs.searchInput.focus();
                }
            });
        },
        toggle() {
            if (this.open) {
                this.open = false;
            } else {
                this.openDropdown();
            }
        },
        select(val) {
            this.selectedValue = (val === null || val === '') ? null : String(val);
            this.open = false;
            this.search = '';
            this.highlightedIndex = -1;
        },
        clear() {
            this.selectedValue = null;
            this.search = '';
            this.highlightedIndex = -1;
        },
        moveHighlight(delta) {
            const list = this.filteredOptions;
            if (!list.length) {
                this.highlightedIndex = -1;
                return;
            }
            let next = this.highlightedIndex + delta;
            if (next < 0) next = list.length - 1;
            if (next >= list.length) next = 0;
            this.highlightedIndex = next;
        },
        selectHighlighted() {
            const list = this.filteredOptions;
            if (this.highlightedIndex >= 0 && this.highlightedIndex < list.length) {
                this.select(list[this.highlightedIndex].id);
            }
        }
    }"
     @click.outside="open = false"
     @keydown.escape.window="open = false"
     class="relative w-full">

    <input type="hidden" id="{{ $inputId }}" name="{{ $name }}" :value="selectedValue ?? ''" @if($required) required @endif />

    <div class="relative flex items-center">
        <button type="button"
                @click="toggle()"
                aria-haspopup="listbox"
                :aria-expanded="open.toString()"
                class="w-full flex items-center justify-between gap-2 rounded-xl border border-line-strong bg-canvas text-ink text-sm px-3.5 py-2.5 text-left focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent transition-all shadow-sm cursor-pointer {{ $nullable ? 'pr-14' : '' }}">
            <span class="truncate"
                  :class="selectedOption ? 'text-ink' : 'text-ink-muted'"
                  x-text="selectedOption ? selectedOption.displayLabel : placeholderText">
                {{ $initialOption['displayLabel'] ?? $resolvedPlaceholder }}
            </span>
            <span class="flex items-center gap-1 shrink-0 text-ink-muted">
                <svg class="w-4 h-4 transition-transform duration-150"
                     :class="open ? 'rotate-180' : ''"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m6 9 6 6 6-6" />
                </svg>
            </span>
        </button>

        @if ($nullable)
            <button type="button"
                    x-show="selectedOption !== null"
                    x-cloak
                    @click.stop="clear()"
                    title="{{ $resolvedClearLabel }}"
                    aria-label="{{ $resolvedClearLabel }}"
                    class="absolute right-8 p-1 rounded-lg text-ink-muted hover:text-ink hover:bg-surface-muted transition-colors cursor-pointer">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M18 6 6 18M6 6l12 12" />
                </svg>
            </button>
        @endif
    </div>

    <div x-show="open"
         x-cloak
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-75"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="absolute z-40 mt-1.5 w-full rounded-xl border border-line-strong bg-surface shadow-xl overflow-hidden">

        <div class="p-2 border-b border-line bg-surface-muted/40">
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-ink-muted">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.3-4.3" />
                    </svg>
                </span>
                <input type="text"
                       x-ref="searchInput"
                       x-model="search"
                       @keydown.arrow-down.prevent="moveHighlight(1)"
                       @keydown.arrow-up.prevent="moveHighlight(-1)"
                       @keydown.enter.prevent="selectHighlighted()"
                       placeholder="{{ $resolvedSearchPlaceholder }}"
                       class="w-full rounded-lg border border-line bg-canvas text-ink placeholder:text-ink-faint text-xs pl-8 pr-3 py-2 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
            </div>
        </div>

        <ul role="listbox" class="max-h-60 overflow-y-auto divide-y divide-line/40 py-1 text-xs">
            @if ($nullable)
                <li>
                    <button type="button"
                            @click="select(null)"
                            :class="selectedValue === null ? 'bg-accent/10 text-accent font-semibold' : 'text-ink-muted hover:bg-surface-muted hover:text-ink'"
                            class="w-full flex items-center justify-between px-3.5 py-2 text-left transition-colors cursor-pointer">
                        <span>{{ $resolvedPlaceholder }}</span>
                        <svg x-show="selectedValue === null" class="w-4 h-4 text-accent shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 6 9 17l-5-5" />
                        </svg>
                    </button>
                </li>
            @endif

            <template x-for="(opt, idx) in filteredOptions" :key="opt.id">
                <li>
                    <button type="button"
                            @click="select(opt.id)"
                            :class="{
                                'bg-accent/10 text-accent font-semibold': String(selectedValue) === String(opt.id),
                                'bg-surface-muted': highlightedIndex === idx && String(selectedValue) !== String(opt.id),
                                'text-ink hover:bg-surface-muted': String(selectedValue) !== String(opt.id)
                            }"
                            class="w-full flex items-center justify-between gap-2 px-3.5 py-2 text-left transition-colors cursor-pointer">
                        <div class="min-w-0">
                            <div class="truncate font-medium" x-text="opt.label"></div>
                            <template x-if="opt.sublabel">
                                <div class="truncate text-2xs text-ink-muted" x-text="opt.sublabel"></div>
                            </template>
                        </div>
                        <svg x-show="String(selectedValue) === String(opt.id)" class="w-4 h-4 text-accent shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 6 9 17l-5-5" />
                        </svg>
                    </button>
                </li>
            </template>

            <li x-show="filteredOptions.length === 0" class="px-3.5 py-4 text-center text-ink-muted italic">
                {{ $resolvedEmptyText }}
            </li>
        </ul>
    </div>
</div>
