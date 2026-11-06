<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * Show the form for editing the active company's settings and geofence parameters.
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

        return view('settings.company', compact('company', 'tenants', 'allCompanies', 'isSuperAdmin'));
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

    /**
     * Alias for store() when registering a new tenant.
     */
    public function storeTenant(Request $request): RedirectResponse|JsonResponse
    {
        return $this->store($request);
    }
}
