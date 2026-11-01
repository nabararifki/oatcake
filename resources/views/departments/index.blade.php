<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-bold text-xl text-ink leading-tight">
                    {{ __('Manajemen Struktur Departemen') }}
                </h2>
                <p class="text-xs text-ink-muted mt-0.5">
                    Unit Organisasi &amp; Kuota Operasional Perusahaan
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-surface border border-line rounded-xl shadow-sm overflow-hidden">
                <table class="w-full text-sm text-left">
                    <thead class="bg-surface-muted text-ink-muted border-b border-line text-2xs font-semibold uppercase tracking-wider">
                        <tr>
                            <th class="py-3 px-4">Kode</th>
                            <th class="py-3 px-4">Nama Departemen</th>
                            <th class="py-3 px-4">Tipe Operasional</th>
                            <th class="py-3 px-4">Min Staf / Shift</th>
                            <th class="py-3 px-4">Staf Aktif</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line text-ink">
                        @foreach ($departments as $dept)
                            <tr class="hover:bg-surface-muted/50 transition-colors">
                                <td class="py-3 px-4 font-mono font-bold">{{ $dept->code }}</td>
                                <td class="py-3 px-4 font-medium">{{ $dept->name }}</td>
                                <td class="py-3 px-4">
                                    {{ $dept->is_operational ? 'Operasional 24 Jam' : 'Back-Office Reguler' }}
                                </td>
                                <td class="py-3 px-4">{{ $dept->min_staff_per_shift }}</td>
                                <td class="py-3 px-4 font-semibold">{{ $dept->active_employees_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
