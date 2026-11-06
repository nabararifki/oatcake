<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="text-lg sm:text-xl font-bold text-ink tracking-tight">
                    {{ __('attendance.verifications_title') }}
                </h1>
                <p class="text-xs text-ink-muted mt-0.5">
                    {{ __('attendance.verifications_subtitle') }}
                </p>
            </div>

            <!-- Statistik Ringkas Verifikasi -->
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <div class="inline-flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-1.5 text-xs">
                    <span class="font-medium text-amber-700 dark:text-amber-300">{{ __('attendance.stat_pending') }}:</span>
                    <span class="font-mono font-bold text-amber-700 dark:text-amber-300">{{ $stats['pending'] ?? 0 }}</span>
                </div>
                <div class="inline-flex items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-1.5 text-xs">
                    <span class="font-medium text-emerald-700 dark:text-emerald-300">{{ __('attendance.stat_approved_today') }}:</span>
                    <span class="font-mono font-bold text-emerald-700 dark:text-emerald-300">{{ $stats['approved_today'] ?? 0 }}</span>
                </div>
                <div class="inline-flex items-center gap-2 rounded-xl border border-red-500/30 bg-red-500/10 px-3 py-1.5 text-xs">
                    <span class="font-medium text-red-700 dark:text-red-300">{{ __('attendance.stat_rejected_today') }}:</span>
                    <span class="font-mono font-bold text-red-700 dark:text-red-300">{{ $stats['rejected_today'] ?? 0 }}</span>
                </div>
            </div>
        </div>
    </x-slot>

    @php
        $companyLat = (float) ($currentCompany?->latitude ?? -7.9048250);
        $companyLon = (float) ($currentCompany?->longitude ?? 110.0572110);
        $radiusLimit = (int) ($currentCompany?->geofence_radius ?? 100);
        $companyName = $currentCompany?->name ?? 'Novotel YIA';

        $categoryLabels = [
            'client_visit' => __('attendance.cat_client_visit'),
            'airport_pickup' => __('attendance.cat_airport_pickup'),
            'procurement' => __('attendance.cat_procurement'),
            'event' => __('attendance.cat_event'),
            'other' => __('attendance.cat_other'),
        ];
    @endphp

    <div x-data="attendanceVerificationConsole({
            companyLat: {{ Js::from($companyLat) }},
            companyLon: {{ Js::from($companyLon) }},
            radiusLimit: {{ Js::from($radiusLimit) }},
            companyName: {{ Js::from($companyName) }},
            csrfToken: {{ Js::from(csrf_token()) }},
            hotelCenterLabel: {{ Js::from(__('attendance.hotel_center_label')) }},
            staffLocationLabel: {{ Js::from(__('attendance.staff_location_label')) }},
            errVerificationFailed: {{ Js::from(__('attendance.err_verification_failed')) }},
            errValidation: {{ Js::from(__('attendance.err_validation')) }},
            errNetwork: {{ Js::from(__('attendance.err_network')) }},
        })"
        class="space-y-5">

        <!-- Feedback Alert Banner -->
        <div x-show="feedbackMessage !== ''"
             x-cloak
             x-transition
             :class="feedbackSuccess
                 ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                 : 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300'"
             class="rounded-xl border px-4 py-3 text-xs sm:text-sm font-medium flex items-center justify-between gap-3">
            <span x-text="feedbackMessage"></span>
            <button type="button" @click="feedbackMessage = ''" class="text-ink-muted hover:text-ink">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Bilah Filter Antrean Verifikasi -->
        <div class="rounded-2xl border border-line bg-surface p-4 shadow-2xs">
            <form method="GET" action="{{ route('attendance.verifications.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
                <div>
                    <label for="status" class="block text-2xs font-semibold text-ink-muted uppercase tracking-wider mb-1">
                        {{ __('attendance.filter_status') }}
                    </label>
                    <select id="status" name="status" class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-1 focus:ring-accent">
                        <option value="pending_verification" @selected(($filters['status'] ?? '') === 'pending_verification')>
                            {{ __('attendance.status_pending_verification') }}
                        </option>
                        <option value="all" @selected(($filters['status'] ?? '') === 'all')>
                            {{ __('attendance.filter_status_all') }}
                        </option>
                        <option value="rejected" @selected(($filters['status'] ?? '') === 'rejected')>
                            {{ __('attendance.status_rejected') }}
                        </option>
                    </select>
                </div>

                @if (! auth()->user()->isManager())
                    <div>
                        <label for="department_id" class="block text-2xs font-semibold text-ink-muted uppercase tracking-wider mb-1">
                            {{ __('attendance.filter_department') }}
                        </label>
                        <select id="department_id" name="department_id" class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-1 focus:ring-accent">
                            <option value="">{{ __('attendance.filter_all_departments') }}</option>
                            @foreach ($departments as $dept)
                                <option value="{{ $dept->id }}" @selected((int) ($filters['department_id'] ?? 0) === (int) $dept->id)>
                                    {{ $dept->code }} — {{ $dept->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <label for="date_from" class="block text-2xs font-semibold text-ink-muted uppercase tracking-wider mb-1">
                        {{ __('attendance.filter_date_from') }}
                    </label>
                    <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                           class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                </div>

                <div>
                    <label for="date_to" class="block text-2xs font-semibold text-ink-muted uppercase tracking-wider mb-1">
                        {{ __('attendance.filter_date_to') }}
                    </label>
                    <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                           class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                </div>

                <div>
                    <button type="submit" class="w-full rounded-xl bg-accent px-4 py-2 text-xs font-bold text-accent-fg shadow-2xs hover:opacity-95 transition cursor-pointer">
                        {{ __('attendance.btn_filter') }}
                    </button>
                </div>
            </form>
        </div>

        <!-- Tabel Antrean Presensi Dinas Luar -->
        <div class="rounded-2xl border border-line bg-surface p-4 sm:p-5 shadow-2xs">
            <x-ui.table :striped="true">
                <x-slot name="header">
                    <th>{{ __('attendance.col_photo') }}</th>
                    <th>{{ __('attendance.col_employee') }}</th>
                    <th>{{ __('attendance.col_department_shift') }}</th>
                    <th>{{ __('attendance.col_submitted_at') }}</th>
                    <th>{{ __('attendance.col_outside_assignment') }}</th>
                    <th>{{ __('attendance.col_geodesic_deviation') }}</th>
                    <th>{{ __('attendance.col_status') }}</th>
                    <th>{{ __('attendance.col_actions') }}</th>
                </x-slot>

                @forelse ($attendances as $item)
                    @php
                        $empName = $item->employee?->user?->name ?? '—';
                        $empPos = $item->employee?->position ?? '';
                        $deptName = $item->employee?->department?->name ?? '—';
                        $deptCode = $item->employee?->department?->code ?? '';
                        $shiftLabel = $item->workSchedule?->shift
                            ? $item->workSchedule->shift->code . ' (' . substr($item->workSchedule->getEffectiveStartTime(), 0, 5) . '–' . substr($item->workSchedule->getEffectiveEndTime(), 0, 5) . ')'
                            : __('attendance.no_shift');
                        $submittedAtFormatted = $item->clock_in_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s') ?? '—';
                        $categoryLabel = $categoryLabels[$item->outside_category] ?? $item->outside_category ?? '—';
                        $distMeters = (int) ($item->clock_in_distance_meters ?? 0);
                        $distFormatted = $distMeters >= 1000
                            ? number_format($distMeters / 1000, 1) . ' km ' . __('attendance.from_hotel_label')
                            : $distMeters . ' m ' . __('attendance.from_hotel_label');
                        $proofUrl = route('attendance.proof', $item);
                        $approveUrl = route('attendance.verifications.approve', $item);
                        $rejectUrl = route('attendance.verifications.reject', $item);

                        $modalPayload = [
                            'id' => $item->id,
                            'uuid' => $item->uuid,
                            'employee_name' => $empName,
                            'employee_position' => $empPos,
                            'department_label' => $deptCode ? "{$deptCode} — {$deptName}" : $deptName,
                            'shift_label' => $shiftLabel,
                            'submitted_at' => $submittedAtFormatted,
                            'category_label' => $categoryLabel,
                            'location_name' => $item->outside_location_name ?? '—',
                            'notes' => $item->notes ?? '—',
                            'latitude' => (float) ($item->clock_in_latitude ?? $companyLat),
                            'longitude' => (float) ($item->clock_in_longitude ?? $companyLon),
                            'distance_meters' => $distMeters,
                            'distance_label' => $distFormatted,
                            'late_minutes' => (int) $item->late_minutes,
                            'status' => $item->status,
                            'rejection_reason' => $item->rejection_reason,
                            'verifier_name' => $item->verifier?->name,
                            'proof_url' => $proofUrl,
                            'approve_url' => $approveUrl,
                            'reject_url' => $rejectUrl,
                        ];
                    @endphp
                    <tr>
                        <td>
                            <button type="button"
                                    @click="openSpatialModal({{ Js::from($modalPayload) }})"
                                    class="block w-11 h-11 rounded-lg overflow-hidden border border-line bg-surface-muted hover:ring-2 hover:ring-accent transition cursor-pointer">
                                <img src="{{ $proofUrl }}"
                                     alt="{{ $empName }}"
                                     loading="lazy"
                                     class="w-full h-full object-cover" />
                            </button>
                        </td>
                        <td>
                            <div class="font-semibold text-xs text-ink">{{ $empName }}</div>
                            <div class="text-2xs text-ink-muted">{{ $empPos }}</div>
                        </td>
                        <td>
                            <div class="text-xs font-semibold text-ink">{{ $deptCode ?: $deptName }}</div>
                            <div class="text-2xs font-mono text-ink-muted">{{ $shiftLabel }}</div>
                            @if ($item->is_provisional_dp)
                                <span title="{{ __('attendance.tooltip_provisional_dp') }}"
                                      class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-3xs font-semibold bg-purple-500/10 text-purple-700 dark:text-purple-300 border border-purple-500/20 mt-1">
                                    <span>✦ {{ __('attendance.badge_provisional_dp') }}</span>
                                </span>
                            @endif
                        </td>
                        <td class="font-mono text-xs text-ink">
                            {{ $submittedAtFormatted }}
                            @if ($item->late_minutes > 0)
                                <span class="block text-2xs text-amber-600 dark:text-amber-400">
                                    (+{{ $item->late_minutes }}m)
                                </span>
                            @endif
                        </td>
                        <td>
                            <div class="text-xs font-semibold text-ink">{{ $item->outside_location_name ?? '—' }}</div>
                            <div class="text-2xs text-ink-muted">{{ $categoryLabel }}</div>
                        </td>
                        <td>
                            <span class="inline-flex items-center rounded-md bg-surface-muted px-2 py-0.5 font-mono text-2xs font-semibold text-ink border border-line">
                                {{ $distFormatted }}
                            </span>
                        </td>
                        <td>
                            @if ($item->status === 'pending_verification')
                                <span class="inline-flex items-center rounded-md bg-amber-500/10 px-2 py-0.5 text-2xs font-semibold text-amber-600 dark:text-amber-400 border border-amber-500/30">
                                    {{ __('attendance.status_pending_verification') }}
                                </span>
                            @elseif ($item->status === 'on_time')
                                <span class="inline-flex items-center rounded-md bg-emerald-500/10 px-2 py-0.5 text-2xs font-semibold text-emerald-600 dark:text-emerald-400 border border-emerald-500/30">
                                    {{ __('attendance.status_on_time') }}
                                </span>
                            @elseif ($item->status === 'late')
                                <span class="inline-flex items-center rounded-md bg-amber-500/10 px-2 py-0.5 text-2xs font-semibold text-amber-600 dark:text-amber-400 border border-amber-500/30">
                                    {{ __('attendance.status_late') }} ({{ $item->late_minutes }}m)
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-md bg-red-500/10 px-2 py-0.5 text-2xs font-semibold text-red-600 dark:text-red-400 border border-red-500/30">
                                    {{ __('attendance.status_rejected') }}
                                </span>
                            @endif
                        </td>
                        <td>
                            <button type="button"
                                    @click="openSpatialModal({{ Js::from($modalPayload) }})"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface-muted px-3 py-1.5 text-xs font-semibold text-ink hover:bg-surface hover:border-accent transition cursor-pointer">
                                <svg class="w-3.5 h-3.5 text-accent" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" />
                                </svg>
                                <span>{{ __('attendance.btn_inspect_map') }}</span>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="py-10 text-center text-xs text-ink-muted">
                            {{ __('attendance.verifications_empty') }}
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>
        </div>

        <!-- Modal Inspeksi Spasial & Bukti Swafoto (Leaflet.js) -->
        <x-ui.modal name="spatial-inspection-modal" :title="__('attendance.modal_inspect_title')" maxWidth="4xl">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
                <!-- Panel Kiri / Atas (7/12): Peta Digital Interaktif Leaflet.js -->
                <div class="lg:col-span-7 space-y-3">
                    <div id="attendance-map" class="w-full h-80 rounded-xl border border-line z-0"></div>

                    <div class="rounded-xl border border-line bg-surface-muted/40 p-3 flex flex-wrap items-center justify-between gap-2 text-2xs text-ink-muted">
                        <div class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-full bg-accent inline-block"></span>
                            <span>{{ __('attendance.hotel_center_label') }} ({{ $radiusLimit }}m)</span>
                        </div>
                        <div class="flex items-center gap-2 font-mono">
                            <span x-text="activeItem ? activeItem.latitude.toFixed(6) + ', ' + activeItem.longitude.toFixed(6) : ''"></span>
                            <span class="font-bold text-ink" x-text="activeItem ? '(' + activeItem.distance_label + ')' : ''"></span>
                        </div>
                    </div>
                </div>

                <!-- Panel Kanan / Bawah (5/12): Bukti Swafoto & Aksi Keputusan -->
                <div class="lg:col-span-5 flex flex-col justify-between space-y-4">
                    <div class="space-y-3">
                        <template x-if="activeItem">
                            <div class="space-y-3">
                                <!-- Foto Swafoto Asli Beresolusi Optimal -->
                                <div class="rounded-xl overflow-hidden border border-line bg-surface-muted aspect-4/3 flex items-center justify-center">
                                    <img :src="activeItem.proof_url"
                                         :alt="activeItem.employee_name"
                                         class="w-full h-full object-cover max-h-56" />
                                </div>

                                <!-- Rincian Penugasan Staf -->
                                <div class="rounded-xl border border-line bg-surface-muted/40 p-3.5 space-y-2 text-xs">
                                    <div class="flex items-center justify-between gap-2 border-b border-line/60 pb-2">
                                        <div>
                                            <div class="font-bold text-ink" x-text="activeItem.employee_name"></div>
                                            <div class="text-2xs text-ink-muted" x-text="activeItem.department_label"></div>
                                        </div>
                                        <span class="font-mono text-2xs text-ink-muted" x-text="activeItem.submitted_at"></span>
                                    </div>

                                    <div>
                                        <span class="text-2xs text-ink-muted block">{{ __('attendance.outside_category') }}</span>
                                        <span class="font-semibold text-ink" x-text="activeItem.category_label"></span>
                                    </div>

                                    <div>
                                        <span class="text-2xs text-ink-muted block">{{ __('attendance.outside_location_name') }}</span>
                                        <span class="font-semibold text-ink" x-text="activeItem.location_name"></span>
                                    </div>

                                    <div>
                                        <span class="text-2xs text-ink-muted block">{{ __('attendance.outside_notes_label') }}</span>
                                        <p class="text-ink whitespace-pre-line" x-text="activeItem.notes"></p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Kontrol Keputusan Manajer (Setujui / Tolak) -->
                    <template x-if="activeItem && activeItem.status === 'pending_verification'">
                        <div class="space-y-3 pt-2 border-t border-line">
                            <div x-show="showRejectInput" x-cloak class="space-y-2">
                                <label for="modal_rejection_reason" class="block text-xs font-semibold text-ink">
                                    {{ __('attendance.rejection_reason_label') }} <span class="text-red-500">*</span>
                                </label>
                                <textarea id="modal_rejection_reason"
                                          x-model="rejectionReason"
                                          rows="2"
                                          minlength="5"
                                          maxlength="500"
                                          placeholder="{{ __('attendance.rejection_reason_placeholder') }}"
                                          class="w-full rounded-xl border border-line bg-surface-muted px-3 py-2 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-red-500"></textarea>
                            </div>

                            <div class="flex items-center gap-2">
                                <button type="button"
                                        x-show="!showRejectInput"
                                        @click="approveAttendance()"
                                        :disabled="processingAction"
                                        class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-accent-fg font-semibold rounded-xl px-4 py-2 text-xs transition cursor-pointer disabled:opacity-50">
                                    {{ __('attendance.btn_approve') }}
                                </button>

                                <button type="button"
                                        x-show="!showRejectInput"
                                        @click="showRejectInput = true"
                                        :disabled="processingAction"
                                        class="flex-1 bg-red-600 hover:bg-red-700 text-accent-fg font-semibold rounded-xl px-4 py-2 text-xs transition cursor-pointer disabled:opacity-50">
                                    {{ __('attendance.btn_reject') }}
                                </button>

                                <button type="button"
                                        x-show="showRejectInput"
                                        x-cloak
                                        @click="showRejectInput = false"
                                        :disabled="processingAction"
                                        class="rounded-xl border border-line bg-surface px-3 py-2 text-xs font-semibold text-ink hover:bg-surface-muted cursor-pointer">
                                    {{ __('common.cancel') }}
                                </button>

                                <button type="button"
                                        x-show="showRejectInput"
                                        x-cloak
                                        @click="rejectAttendance()"
                                        :disabled="processingAction"
                                        class="flex-1 bg-red-600 hover:bg-red-700 text-accent-fg font-semibold rounded-xl px-4 py-2 text-xs transition cursor-pointer disabled:opacity-50">
                                    {{ __('attendance.confirm_reject') }}
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </x-ui.modal>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('attendanceVerificationConsole', (config) => ({
                companyLat: config.companyLat,
                companyLon: config.companyLon,
                radiusLimit: config.radiusLimit,
                companyName: config.companyName,
                csrfToken: config.csrfToken,
                hotelCenterLabel: config.hotelCenterLabel,
                staffLocationLabel: config.staffLocationLabel,
                errVerificationFailed: config.errVerificationFailed,
                errValidation: config.errValidation,
                errNetwork: config.errNetwork,

                activeItem: null,
                mapInstance: null,
                mapLayerGroup: null,
                showRejectInput: false,
                rejectionReason: '',
                processingAction: false,
                feedbackMessage: '',
                feedbackSuccess: false,

                openSpatialModal(item) {
                    this.activeItem = item;
                    this.showRejectInput = false;
                    this.rejectionReason = '';

                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'spatial-inspection-modal' }));

                    setTimeout(() => {
                        this.renderLeafletMap();
                    }, 120);
                },

                renderLeafletMap() {
                    if (!window.L || !this.activeItem) return;

                    const mapContainer = document.getElementById('attendance-map');
                    if (!mapContainer) return;

                    if (!this.mapInstance) {
                        this.mapInstance = window.L.map(mapContainer, {
                            zoomControl: true,
                        });

                        window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            maxZoom: 19,
                            attribution: '&copy; OpenStreetMap contributors',
                        }).addTo(this.mapInstance);

                        this.mapLayerGroup = window.L.layerGroup().addTo(this.mapInstance);
                    }

                    this.mapInstance.invalidateSize();
                    this.mapLayerGroup.clearLayers();

                    const hotelCoords = [this.companyLat, this.companyLon];
                    const staffCoords = [this.activeItem.latitude, this.activeItem.longitude];

                    // 1. Marker Pusat Hotel Novotel YIA
                    const hotelMarker = window.L.marker(hotelCoords)
                        .bindTooltip(`${this.companyName} (${this.hotelCenterLabel})`, { permanent: false, direction: 'top' });
                    this.mapLayerGroup.addLayer(hotelMarker);

                    // 2. Lingkaran Radius Aman (100 meter)
                    const geofenceCircle = window.L.circle(hotelCoords, {
                        radius: this.radiusLimit,
                        color: '#004F9F',
                        fillColor: '#004F9F',
                        fillOpacity: 0.15,
                        weight: 2,
                    });
                    this.mapLayerGroup.addLayer(geofenceCircle);

                    // 3. Marker Posisi Staf
                    const staffMarker = window.L.marker(staffCoords)
                        .bindTooltip(`${this.activeItem.employee_name} — ${this.activeItem.submitted_at}`, {
                            permanent: false,
                            direction: 'top',
                        });
                    this.mapLayerGroup.addLayer(staffMarker);

                    // 4. Garis Ukur Spasial (Polyline) antara Hotel dan Posisi Staf
                    const polyline = window.L.polyline([hotelCoords, staffCoords], {
                        color: '#D97706',
                        weight: 3,
                        dashArray: '6, 6',
                    }).bindTooltip(this.activeItem.distance_label, {
                        permanent: true,
                        direction: 'center',
                    });
                    this.mapLayerGroup.addLayer(polyline);

                    // 5. Auto-fit bounds mencakup titik hotel & posisi staf
                    const bounds = window.L.latLngBounds([hotelCoords, staffCoords]);
                    this.mapInstance.fitBounds(bounds.pad(0.25));
                },

                async approveAttendance() {
                    if (!this.activeItem) return;
                    this.processingAction = true;

                    try {
                        const response = await fetch(this.activeItem.approve_url, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': this.csrfToken,
                            },
                            body: JSON.stringify({}),
                        });

                        const contentType = response.headers.get('content-type') || '';
                        const result = contentType.includes('application/json')
                            ? await response.json()
                            : { success: false, message: this.errNetwork };

                        if (response.ok && result.success) {
                            this.feedbackSuccess = true;
                            this.feedbackMessage = result.message;
                            window.dispatchEvent(new CustomEvent('close-modal', { detail: 'spatial-inspection-modal' }));
                            setTimeout(() => window.location.reload(), 500);
                        } else {
                            this.feedbackSuccess = false;
                            this.feedbackMessage = result.message || this.errVerificationFailed;
                            this.processingAction = false;
                        }
                    } catch (error) {
                        this.feedbackSuccess = false;
                        this.feedbackMessage = error.message || this.errNetwork;
                        this.processingAction = false;
                    }
                },

                async rejectAttendance() {
                    if (!this.activeItem) return;
                    this.processingAction = true;

                    try {
                        const response = await fetch(this.activeItem.reject_url, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': this.csrfToken,
                            },
                            body: JSON.stringify({
                                rejection_reason: this.rejectionReason,
                            }),
                        });

                        const contentType = response.headers.get('content-type') || '';
                        const result = contentType.includes('application/json')
                            ? await response.json()
                            : { success: false, message: this.errNetwork };

                        if (response.ok && result.success) {
                            this.feedbackSuccess = true;
                            this.feedbackMessage = result.message;
                            window.dispatchEvent(new CustomEvent('close-modal', { detail: 'spatial-inspection-modal' }));
                            setTimeout(() => window.location.reload(), 500);
                        } else {
                            this.feedbackSuccess = false;
                            this.feedbackMessage = result.message || this.errValidation;
                            this.processingAction = false;
                        }
                    } catch (error) {
                        this.feedbackSuccess = false;
                        this.feedbackMessage = error.message || this.errNetwork;
                        this.processingAction = false;
                    }
                },
            }));
        });
    </script>
</x-app-layout>
