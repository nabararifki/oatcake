<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    /**
     * Resolve the active tenant company for the current user.
     */
    protected function resolveActiveCompany(Request $request): ?Company
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            if (session('active_company_id')) {
                return Company::find(session('active_company_id'));
            }

            return $user->company ?? Company::orderBy('id')->first();
        }

        return $user->company;
    }

    /**
     * Display a listing of the departments for the active company.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Department::class);

        $user = $request->user();
        $company = $this->resolveActiveCompany($request);
        $canManage = $user->can('create', Department::class);

        $departments = Department::withoutGlobalScopes()
            ->when($company, fn ($q) => $q->where('company_id', $company->id))
            ->withCount([
                'employees as active_employees_count' => fn ($query) => $query->withoutGlobalScopes()->active(),
                'employees as employees_count' => fn ($query) => $query->withoutGlobalScopes(),
                'workSchedules as work_schedules_count' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->orderBy('code')
            ->get();

        return view('departments.index', compact('departments', 'company', 'canManage'));
    }

    /**
     * Store a newly created department in storage.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('create', Department::class);

        $company = $this->resolveActiveCompany($request);
        $companyId = $company?->id ?? $request->user()->company_id;

        if (! $companyId) {
            abort(422, __('departments.err_no_active_company'));
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:10',
                Rule::unique('departments', 'code')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'is_24_hours' => ['nullable', 'boolean'],
            'is_operational' => ['nullable', 'boolean'],
            'min_staff_per_shift' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $is24Hours = $request->has('is_24_hours')
            ? $request->boolean('is_24_hours')
            : ($request->has('is_operational') ? $request->boolean('is_operational') : false);

        $minStaff = (int) ($validated['min_staff_per_shift'] ?? 1);

        $department = Department::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'name' => trim($validated['name']),
            'code' => strtoupper(trim($validated['code'])),
            'is_24_hours' => $is24Hours,
            'is_operational' => $is24Hours,
            'min_staff_per_shift' => $minStaff,
            'description' => $validated['description'] ?? null,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('departments.created_successfully'),
                'department' => $department,
            ], 201);
        }

        return redirect()
            ->route('departments.index')
            ->with('success', __('departments.created_successfully'));
    }

    /**
     * Update the specified department in storage.
     */
    public function update(Request $request, Department $department): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $department);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:10',
                Rule::unique('departments', 'code')
                    ->where(fn ($q) => $q->where('company_id', $department->company_id))
                    ->ignore($department->id),
            ],
            'is_24_hours' => ['nullable', 'boolean'],
            'is_operational' => ['nullable', 'boolean'],
            'min_staff_per_shift' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $is24Hours = $request->has('is_24_hours')
            ? $request->boolean('is_24_hours')
            : ($request->has('is_operational') ? $request->boolean('is_operational') : (bool) $department->is_24_hours);

        $minStaff = (int) ($validated['min_staff_per_shift'] ?? $department->min_staff_per_shift ?? 1);

        $department->update([
            'name' => trim($validated['name']),
            'code' => strtoupper(trim($validated['code'])),
            'is_24_hours' => $is24Hours,
            'is_operational' => $is24Hours,
            'min_staff_per_shift' => $minStaff,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $department->description,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('departments.updated_successfully'),
                'department' => $department->fresh(),
            ]);
        }

        return redirect()
            ->route('departments.index')
            ->with('success', __('departments.updated_successfully'));
    }

    /**
     * Remove the specified department from storage with Safe-Delete Guard.
     */
    public function destroy(Request $request, Department $department): RedirectResponse|JsonResponse
    {
        Gate::authorize('delete', $department);

        if ($department->employees()->exists()) {
            $errorMessage = __('departments.err_has_employees');

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                    'errors' => [
                        'department' => [$errorMessage],
                        'error' => [$errorMessage],
                    ],
                ], 422);
            }

            return redirect()
                ->back(fallback: route('departments.index'))
                ->with('error', $errorMessage)
                ->withErrors(['error' => $errorMessage, 'department' => $errorMessage]);
        }

        if ($department->workSchedules()->exists()) {
            $errorMessage = __('departments.err_has_schedules');

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                    'errors' => [
                        'department' => [$errorMessage],
                        'error' => [$errorMessage],
                    ],
                ], 422);
            }

            return redirect()
                ->back(fallback: route('departments.index'))
                ->with('error', $errorMessage)
                ->withErrors(['error' => $errorMessage, 'department' => $errorMessage]);
        }

        $department->delete();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('departments.deleted_successfully'),
            ]);
        }

        return redirect()
            ->route('departments.index')
            ->with('success', __('departments.deleted_successfully'));
    }
}
