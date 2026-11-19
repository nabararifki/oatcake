<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\DpCommitService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CompanySettingsController extends Controller
{
    /**
     * Valid Indonesian operational timezones.
     *
     * @var list<string>
     */
    public const VALID_TIMEZONES = [
        'Asia/Jakarta',
        'Asia/Makassar',
        'Asia/Jayapura',
    ];

    /**
     * Resolve the active company for the authenticated user.
     */
    protected function resolveActiveCompany(Request $request): ?Company
    {
        $user = $request->user();

        if ($user->isSuperAdmin() && session('active_company_id')) {
            return Company::find(session('active_company_id'));
        }

        return $user->company ?? ($user->isSuperAdmin() ? Company::orderBy('id')->first() : null);
    }

    /**
     * Show the form for editing the active company's settings, geofence parameters, and holiday calendar.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();
        $company = $this->resolveActiveCompany($request);

        if ($company) {
            Gate::authorize('update', $company);
        } elseif (! $user->isSuperAdmin()) {
            abort(403);
        }

        $isSuperAdmin = $user->isSuperAdmin();
        $allCompanies = $isSuperAdmin
            ? Company::orderBy('name')->get()
            : collect();
        $tenants = $allCompanies;

        $selectedHolidayYear = $request->filled('holiday_year')
            ? (int) $request->query('holiday_year')
            : null;

        $holidays = $company
            ? PublicHoliday::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->when($selectedHolidayYear !== null, fn ($q) => $q->whereYear('holiday_date', $selectedHolidayYear))
                ->orderBy('holiday_date')
                ->get()
            : collect();

        return view('settings.company', compact(
            'company',
            'tenants',
            'allCompanies',
            'isSuperAdmin',
            'holidays',
            'selectedHolidayYear'
        ));
    }

    /**
     * Update the active company's profile, GPS coordinates, geofence radius, and timezone.
     */
    public function update(Request $request, ?Company $company = null): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $targetCompany = $company ?? $this->resolveActiveCompany($request);

        if (! $targetCompany) {
            abort(403);
        }

        Gate::authorize('update', $targetCompany);

        if (! $user->isSuperAdmin() && $request->filled('company_id') && (int) $request->input('company_id') !== (int) $targetCompany->id) {
            abort(403);
        }

        if (! $request->has('geofence_radius') && $request->has('geofence_radius_meters')) {
            $request->merge([
                'geofence_radius' => $request->input('geofence_radius_meters'),
            ]);
        }

        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('companies', 'code')->ignore($targetCompany->id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'geofence_radius' => ['required', 'integer', 'min:20', 'max:1000'],
            'timezone' => ['required', 'string', Rule::in(self::VALID_TIMEZONES)],
            'accent_color' => ['nullable', 'string', 'max:20'],
            'allowed_email_domain' => ['nullable', 'string', 'max:100'],
            'photo_retention_months' => ['nullable', 'integer', Rule::in([1, 3, 6, 12])],
            'auto_assign_shift_on_clock_in' => ['nullable', 'boolean'],
        ];

        if ($user->isSuperAdmin()) {
            $rules['storage_limit_mb'] = ['nullable', 'integer', 'min:10', 'max:1048576'];
        }

        $validated = $request->validate($rules);

        $radius = (int) $validated['geofence_radius'];

        $updateData = [
            'name' => trim($validated['name']),
            'code' => strtoupper(trim($validated['code'])),
            'address' => array_key_exists('address', $validated) ? $validated['address'] : $targetCompany->address,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'geofence_radius' => $radius,
            'geofence_radius_meters' => $radius,
            'timezone' => $validated['timezone'],
            'accent_color' => $validated['accent_color'] ?? $targetCompany->accent_color,
            'allowed_email_domain' => array_key_exists('allowed_email_domain', $validated)
                ? $validated['allowed_email_domain']
                : $targetCompany->allowed_email_domain,
        ];

        if (array_key_exists('photo_retention_months', $validated) && $validated['photo_retention_months'] !== null) {
            $updateData['photo_retention_months'] = (int) $validated['photo_retention_months'];
        }

        if ($user->isSuperAdmin() && array_key_exists('storage_limit_mb', $validated) && $validated['storage_limit_mb'] !== null) {
            $updateData['storage_limit_mb'] = (int) $validated['storage_limit_mb'];
        }

        if ($request->has('auto_assign_shift_on_clock_in')) {
            $updateData['auto_assign_shift_on_clock_in'] = $request->boolean('auto_assign_shift_on_clock_in');
        }

        $targetCompany->update($updateData);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('company.updated_successfully'),
                'company' => $targetCompany->fresh(),
            ]);
        }

        return redirect()
            ->route('settings.company')
            ->with('success', __('company.updated_successfully'));
    }

    /**
     * Store a new public holiday or company special event (Admin+ only).
     */
    public function storeHoliday(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdminOrAbove()) {
            abort(403);
        }

        $company = $this->resolveActiveCompany($request);
        if (! $company) {
            abort(403);
        }

        $validated = $request->validate([
            'holiday_date' => [
                'required',
                'date',
                Rule::unique('public_holidays', 'holiday_date')
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'name' => ['required', 'string', 'max:150'],
            'is_national' => ['nullable', 'boolean'],
            'is_recurring' => ['nullable', 'boolean'],
        ]);

        $dateString = Carbon::parse($validated['holiday_date'])->toDateString();
        $isNational = $request->boolean('is_national', false);
        $isRecurring = $request->has('is_recurring')
            ? $request->boolean('is_recurring')
            : $isNational;

        $holiday = PublicHoliday::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'holiday_date' => $dateString,
            'name' => trim($validated['name']),
            'is_national' => $isNational,
            'is_recurring' => $isRecurring,
        ]);

        $this->syncAttendancesForHolidayAdded($company->id, $dateString, $user);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('company.holiday_created_successfully'),
                'holiday' => $holiday,
            ], 201);
        }

        return redirect()
            ->route('settings.company')
            ->with('success', __('company.holiday_created_successfully'));
    }

    /**
     * Update an existing public holiday or company special event (Admin+ only).
     */
    public function updateHoliday(Request $request, PublicHoliday $publicHoliday): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdminOrAbove()) {
            abort(403);
        }

        $company = $this->resolveActiveCompany($request);
        if (! $company || (int) $publicHoliday->company_id !== (int) $company->id) {
            abort(403);
        }

        $validated = $request->validate([
            'holiday_date' => [
                'required',
                'date',
                Rule::unique('public_holidays', 'holiday_date')
                    ->where(fn ($query) => $query->where('company_id', $company->id))
                    ->ignore($publicHoliday->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'is_national' => ['nullable', 'boolean'],
            'is_recurring' => ['nullable', 'boolean'],
        ]);

        $oldDateString = $publicHoliday->holiday_date instanceof Carbon
            ? $publicHoliday->holiday_date->toDateString()
            : Carbon::parse($publicHoliday->holiday_date)->toDateString();

        $newDateString = Carbon::parse($validated['holiday_date'])->toDateString();

        $isNational = $request->has('is_national')
            ? $request->boolean('is_national')
            : (bool) $publicHoliday->is_national;

        $isRecurring = $request->has('is_recurring')
            ? $request->boolean('is_recurring')
            : (bool) $publicHoliday->is_recurring;

        $publicHoliday->update([
            'holiday_date' => $newDateString,
            'name' => trim($validated['name']),
            'is_national' => $isNational,
            'is_recurring' => $isRecurring,
        ]);

        if ($oldDateString !== $newDateString) {
            $this->syncAttendancesForHolidayRemoved($company->id, $oldDateString);
        }

        $this->syncAttendancesForHolidayAdded($company->id, $newDateString, $user);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('company.holiday_updated_successfully'),
                'holiday' => $publicHoliday->fresh(),
            ]);
        }

        return redirect()
            ->route('settings.company')
            ->with('success', __('company.holiday_updated_successfully'));
    }

    /**
     * Delete an existing public holiday or company special event (Admin+ only).
     */
    public function destroyHoliday(Request $request, PublicHoliday $publicHoliday): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAdminOrAbove()) {
            abort(403);
        }

        $company = $this->resolveActiveCompany($request);
        if (! $company || (int) $publicHoliday->company_id !== (int) $company->id) {
            abort(403);
        }

        $removedDateString = $publicHoliday->holiday_date instanceof Carbon
            ? $publicHoliday->holiday_date->toDateString()
            : Carbon::parse($publicHoliday->holiday_date)->toDateString();

        $publicHoliday->delete();

        $this->syncAttendancesForHolidayRemoved($company->id, $removedDateString);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('company.holiday_deleted_successfully'),
            ]);
        }

        return redirect()
            ->route('settings.company')
            ->with('success', __('company.holiday_deleted_successfully'));
    }

    /**
     * Resolve timezone-safe clock_in_at boundaries for a calendar date in the company's timezone.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveCompanyDayClockInBounds(string $dateString, int $companyId): array
    {
        $company = Company::withoutGlobalScopes()->find($companyId);
        $timezone = $company?->timezone ?: 'Asia/Jakarta';

        $startUtc = Carbon::parse($dateString, $timezone)->startOfDay()->utc();
        $endUtc = Carbon::parse($dateString, $timezone)->endOfDay()->utc();

        if (DB::connection()->getDriverName() === 'pgsql') {
            return [$startUtc, $endUtc];
        }

        $appTimezone = (string) config('app.timezone', 'Asia/Jakarta');

        return [
            $startUtc->copy()->timezone($appTimezone),
            $endUtc->copy()->timezone($appTimezone),
        ];
    }

    /**
     * Synchronize same-day or existing uncommitted attendances when a holiday is added/updated.
     */
    protected function syncAttendancesForHolidayAdded(int $companyId, string $dateString, User $actor): void
    {
        [$startBound, $endBound] = $this->resolveCompanyDayClockInBounds($dateString, $companyId);

        $attendances = Attendance::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereBetween('clock_in_at', [$startBound, $endBound])
            ->where('status', '!=', 'rejected')
            ->get();

        $dpService = app(DpCommitService::class);

        foreach ($attendances as $attendance) {
            if (! $attendance->is_provisional_dp) {
                $attendance->is_provisional_dp = true;
                $attendance->save();
            }

            if ($attendance->clock_out_at !== null && $attendance->dp_committed_at === null) {
                $dpService->attemptCommitFromAttendance($attendance->fresh(), $actor);
            }
        }
    }

    /**
     * Synchronize uncommitted attendances when a holiday is removed, preserving committed ledger entries.
     */
    protected function syncAttendancesForHolidayRemoved(int $companyId, string $dateString): void
    {
        if (PublicHoliday::isHoliday($dateString, $companyId)) {
            return;
        }

        [$startBound, $endBound] = $this->resolveCompanyDayClockInBounds($dateString, $companyId);

        Attendance::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereBetween('clock_in_at', [$startBound, $endBound])
            ->whereNull('dp_committed_at')
            ->update(['is_provisional_dp' => false]);
    }

    /**
     * Register a new tenant company (Super Admin only: POST /admin/companies).
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('create', Company::class);

        if (! $request->has('geofence_radius') && $request->has('geofence_radius_meters')) {
            $request->merge([
                'geofence_radius' => $request->input('geofence_radius_meters'),
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:20', Rule::unique('companies', 'code')],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'geofence_radius' => ['nullable', 'integer', 'min:20', 'max:1000'],
            'timezone' => ['nullable', 'string', Rule::in(self::VALID_TIMEZONES)],
            'accent_color' => ['nullable', 'string', 'max:20'],
            'allowed_email_domain' => ['nullable', 'string', 'max:100'],
            'photo_retention_months' => ['nullable', 'integer', Rule::in([1, 3, 6, 12])],
            'storage_limit_mb' => ['nullable', 'integer', 'min:10', 'max:1048576'],
        ]);

        $radius = (int) ($validated['geofence_radius'] ?? 100);
        $timezone = $validated['timezone'] ?? 'Asia/Jakarta';

        $tenant = Company::create([
            'name' => trim($validated['name']),
            'code' => strtoupper(trim($validated['code'])),
            'address' => $validated['address'] ?? null,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'geofence_radius' => $radius,
            'geofence_radius_meters' => $radius,
            'timezone' => $timezone,
            'accent_color' => $validated['accent_color'] ?? '#004F9F',
            'allowed_email_domain' => $validated['allowed_email_domain'] ?? null,
            'photo_retention_months' => (int) ($validated['photo_retention_months'] ?? 3),
            'storage_limit_mb' => (int) ($validated['storage_limit_mb'] ?? 1024),
            'is_active' => true,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('company.tenant_created_successfully'),
                'company' => $tenant,
            ], 201);
        }

        return redirect()
            ->route('settings.company')
            ->with('success', __('company.tenant_created_successfully'));
    }
}
