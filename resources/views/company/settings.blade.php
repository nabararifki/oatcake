<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-bold text-xl text-ink leading-tight">
                {{ __('Pengaturan Perusahaan & Parameter Geofence') }}
            </h2>
            <p class="text-xs text-ink-muted mt-0.5">
                {{ $company?->name ?? 'Global Tenant Configuration' }}
            </p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-surface border border-line rounded-xl p-6 shadow-sm space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-xs text-ink-muted uppercase block">Nama Perusahaan</span>
                        <span class="font-semibold text-ink">{{ $company?->name ?? '-' }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-ink-muted uppercase block">Kode Tenant</span>
                        <span class="font-mono font-bold text-ink">{{ $company?->code ?? '-' }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-ink-muted uppercase block">Koordinat Pusat Geofence</span>
                        <span class="font-mono text-ink">{{ $company?->latitude }}, {{ $company?->longitude }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-ink-muted uppercase block">Radius Presensi</span>
                        <span class="font-semibold text-ink">{{ $company?->geofence_radius_meters }} Meter</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
