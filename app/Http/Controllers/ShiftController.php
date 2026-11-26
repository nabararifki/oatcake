<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{
    /**
     * Determine whether the authenticated actor is permitted to manage master shifts.
     */
    protected function canManageShifts(?User $actor): bool
    {
        return $actor !== null
            && $actor->is_active
            && in_array($actor->role, ['super_admin', 'super_user', 'admin_hr', 'manager', 'admin_staff'], true);
    }

    /**
     * Resolve the active tenant company for the current request actor.
     */
    protected function resolveCompany(User $actor): ?Company
    {
        if ($actor->isSuperAdmin()) {
            if (session()->has('active_company_id') && session('active_company_id') !== null) {
                return Company::find(session('active_company_id'));
            }

            return Company::orderBy('id')->first();
        }

        return $actor->company;
    }

    /**
     * Enforce strict tenant isolation for a master shift mutation.
     */
    protected function authorizeShiftTenant(User $actor, Shift $shiftModel): void
    {
        if ($actor->isSuperAdmin()) {
            if (session()->has('active_company_id') && session('active_company_id') !== null) {
                if ((int) $shiftModel->company_id !== (int) session('active_company_id')) {
                    abort(403, 'Master shift berada di luar konteks tenant aktif.');
                }
            }

            return;
        }

        if ($actor->company_id === null || (int) $shiftModel->company_id !== (int) $actor->company_id) {
            abort(403, 'Akses lintas perusahaan (cross-tenant) pada master shift ditolak.');
        }
    }

    /**
     * Reject manual shift payloads attempting to spoof the reserved LEAVE: system prefix.
     *
     * @throws ValidationException
     */
    protected function rejectReservedLeaveNotes(?string $value, string $field = 'notes'): void
    {
        if ($value !== null && preg_match('/^\s*LEAVE:/i', $value) === 1) {
            throw ValidationException::withMessages([
                $field => ['Label sistem LEAVE: hanya dikelola otomatis oleh alur persetujuan cuti.'],
            ]);
        }
    }

    /**
     * Format a Shift model into the normalized JSON payload expected by the Roster frontend.
     *
     * @return array<string, mixed>
     */
    protected function formatShiftPayload(Shift $shiftModel): array
    {
        return [
            'id' => $shiftModel->id,
            'uuid' => $shiftModel->uuid,
            'code' => $shiftModel->code,
            'name' => $shiftModel->name,
            'start_time' => Carbon::parse($shiftModel->start_time, 'Asia/Jakarta')->format('H:i:s'),
            'end_time' => Carbon::parse($shiftModel->end_time, 'Asia/Jakarta')->format('H:i:s'),
            'cross_day' => (bool) $shiftModel->cross_day,
            'grace_period_minutes' => (int) $shiftModel->grace_period_minutes,
            'duration_hours' => $shiftModel->getDurationInHours(),
            'color_badge' => $shiftModel->color_badge,
        ];
    }

    /**
     * Create a new master shift scoped to the active tenant company.
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->canManageShifts($actor)) {
            abort(403, 'Anda tidak memiliki izin untuk menambahkan master shift.');
        }

        $company = $this->resolveCompany($actor);
        if (! $company) {
            abort(404, 'Tenant perusahaan belum tersedia.');
        }

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('shifts', 'code')
                    ->where(fn ($q) => $q->where('company_id', $company->id)->whereNull('deleted_at')),
            ],
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'color_badge' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $normalizedCode = strtoupper(trim($validated['code']));
        $this->rejectReservedLeaveNotes($normalizedCode, 'code');
        $this->rejectReservedLeaveNotes($validated['name'], 'name');

        $duplicateExists = Shift::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('company_id', $company->id)
            ->where('code', $normalizedCode)
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'code' => [__('roster.err_shift_code_duplicate')],
            ]);
        }

        $shiftModel = Shift::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => $normalizedCode,
            'name' => trim($validated['name']),
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'grace_period_minutes' => array_key_exists('grace_period_minutes', $validated) && $validated['grace_period_minutes'] !== null
                ? (int) $validated['grace_period_minutes']
                : 15,
            'color_badge' => $validated['color_badge'],
            'is_active' => true,
        ]);

        return response()->json([
            'message' => __('roster.shift_created_successfully'),
            'shift' => $this->formatShiftPayload($shiftModel),
        ], 201);
    }

    /**
     * Update master shift configuration (code, name, hours, grace period, color badge).
     * Previously assigned work_schedules remain immutable via their snapshotted custom_start_time / custom_end_time.
     */
    public function update(Request $request, string $shift): JsonResponse
    {
        $actor = $request->user();
        if (! $this->canManageShifts($actor)) {
            abort(403, 'Anda tidak memiliki izin untuk mengubah pengaturan master shift.');
        }

        $shiftModel = Shift::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where(is_numeric($shift) ? 'id' : 'uuid', $shift)
            ->firstOrFail();

        $this->authorizeShiftTenant($actor, $shiftModel);

        if ($request->has('notes')) {
            $this->rejectReservedLeaveNotes(
                is_string($request->input('notes')) ? $request->input('notes') : null,
                'notes'
            );
        }

        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:20'],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'start_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'color_badge' => ['nullable', 'string', 'max:25'],
        ]);

        if (isset($validated['name'])) {
            $this->rejectReservedLeaveNotes($validated['name'], 'name');
        }

        $newCode = $shiftModel->code;
        if (array_key_exists('code', $validated)) {
            $newCode = strtoupper(trim($validated['code']));
            $this->rejectReservedLeaveNotes($newCode, 'code');

            $duplicateExists = Shift::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('company_id', $shiftModel->company_id)
                ->where('code', $newCode)
                ->where('id', '!=', $shiftModel->id)
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'code' => [__('roster.err_shift_code_duplicate')],
                ]);
            }
        }

        $shiftModel->fill([
            'code' => $newCode,
            'name' => isset($validated['name']) ? trim($validated['name']) : $shiftModel->name,
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'grace_period_minutes' => array_key_exists('grace_period_minutes', $validated) && $validated['grace_period_minutes'] !== null
                ? (int) $validated['grace_period_minutes']
                : $shiftModel->grace_period_minutes,
            'color_badge' => $validated['color_badge'] ?? $shiftModel->color_badge,
        ]);

        $shiftModel->save();

        return response()->json([
            'message' => __('roster.shift_updated_successfully'),
            'shift' => $this->formatShiftPayload($shiftModel),
        ]);
    }

    /**
     * Soft-delete a master shift safely.
     * Blocks deletion with HTTP 422 if the shift is still referenced by any draft work schedule in the active month.
     */
    public function destroy(Request $request, string $shift): JsonResponse
    {
        $actor = $request->user();
        if (! $this->canManageShifts($actor)) {
            abort(403, 'Anda tidak memiliki izin untuk menghapus master shift.');
        }

        $shiftModel = Shift::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where(is_numeric($shift) ? 'id' : 'uuid', $shift)
            ->firstOrFail();

        $this->authorizeShiftTenant($actor, $shiftModel);

        $now = Carbon::now('Asia/Jakarta');
        $month = (int) $request->input('month', $now->month);
        $year = (int) $request->input('year', $now->year);

        if ($month < 1 || $month > 12) {
            $month = $now->month;
        }
        if ($year < 2020 || $year > 2035) {
            $year = $now->year;
        }

        $startOfMonth = Carbon::createFromDate($year, $month, 1, 'Asia/Jakarta')->startOfMonth()->toDateString();
        $endOfMonth = Carbon::createFromDate($year, $month, 1, 'Asia/Jakarta')->endOfMonth()->toDateString();

        $hasActiveMonthDraft = WorkSchedule::withoutGlobalScopes()
            ->where('company_id', $shiftModel->company_id)
            ->where('shift_id', $shiftModel->id)
            ->where('status', 'draft')
            ->whereBetween('schedule_date', [$startOfMonth, $endOfMonth])
            ->exists();

        if ($hasActiveMonthDraft) {
            throw ValidationException::withMessages([
                'shift' => [__('roster.err_shift_in_active_draft')],
            ]);
        }

        $deletedId = $shiftModel->id;
        $shiftModel->delete();

        return response()->json([
            'message' => __('roster.shift_deleted_successfully'),
            'deleted_id' => $deletedId,
        ]);
    }
}
