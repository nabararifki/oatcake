<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tag;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RosterController extends Controller
{
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
     * Determine whether the actor has mutation privileges on the roster.
     */
    protected function canMutateRoster(?User $actor): bool
    {
        return $actor !== null
            && $actor->is_active
            && in_array($actor->role, ['super_admin', 'super_user', 'admin', 'manager'], true);
    }

    /**
     * Authorize mutation against a specific target Employee.
     */
    protected function authorizeEmployeeMutation(User $actor, Employee $employee): void
    {
        if (! $this->canMutateRoster($actor)) {
            abort(403, 'Anda tidak memiliki hak akses untuk mengubah jadwal kerja.');
        }

        if ($actor->isSuperAdmin()) {
            if (session()->has('active_company_id') && session('active_company_id') !== null) {
                if ((int) $employee->company_id !== (int) session('active_company_id')) {
                    abort(403, 'Pegawai berada di luar konteks tenant aktif.');
                }
            }

            return;
        }

        if ($actor->company_id === null || (int) $employee->company_id !== (int) $actor->company_id) {
            abort(403, 'Akses lintas perusahaan (cross-tenant) ditolak.');
        }

        if ($actor->isManager()) {
            $managerDeptId = $actor->employee?->department_id;
            if ($managerDeptId === null || (int) $employee->department_id !== (int) $managerDeptId) {
                abort(403, __('roster.view_only_mutation_forbidden'));
            }
        }
    }

    /**
     * Evaluate turnaround rest period against adjacent days (D-1 and D+1).
     * Ignores schedules marked as pending_delete.
     *
     * @return array{violated: bool, rest_hours: ?float, message: ?string}
     */
    protected function evaluateTurnaround(
        Employee $employee,
        string $scheduleDate,
        ?Shift $candidateShift,
        ?int $ignoreScheduleId = null
    ): array {
        if (! $candidateShift) {
            return ['violated' => false, 'rest_hours' => null, 'message' => null];
        }

        $candDate = Carbon::parse($scheduleDate, 'Asia/Jakarta')->toDateString();
        $startStr = Carbon::parse($candidateShift->start_time, 'Asia/Jakarta')->format('H:i:s');
        $endStr = Carbon::parse($candidateShift->end_time, 'Asia/Jakarta')->format('H:i:s');

        $candStart = Carbon::createFromFormat('Y-m-d H:i:s', "{$candDate} {$startStr}", 'Asia/Jakarta');
        $candEnd = Carbon::createFromFormat('Y-m-d H:i:s', "{$candDate} {$endStr}", 'Asia/Jakarta');
        if ($candEnd->lessThanOrEqualTo($candStart)) {
            $candEnd->addDay();
        }

        $minRestHours = null;
        $violated = false;

        // 1. Evaluasi terhadap Hari Sebelumnya (D - 1)
        $prevDate = Carbon::parse($candDate, 'Asia/Jakarta')->subDay()->toDateString();
        $prevSchedules = WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('schedule_date', $prevDate)
            ->where('status', '!=', 'pending_delete')
            ->when($ignoreScheduleId !== null, fn ($q) => $q->where('id', '!=', $ignoreScheduleId))
            ->with(['shift' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        foreach ($prevSchedules as $prevSchedule) {
            if (! $prevSchedule->isOff()) {
                $prevEnd = $prevSchedule->getEffectiveEndDateTime();
                if ($prevEnd) {
                    $restHours = $prevEnd->diffInMinutes($candStart, false) / 60.0;
                    if ($restHours < 8.0) {
                        $violated = true;
                        $hoursRounded = round(max(0, $restHours), 1);
                        $minRestHours = $minRestHours === null ? $hoursRounded : min($minRestHours, $hoursRounded);
                    }
                }
            }
        }

        // 2. Evaluasi terhadap Hari Berikutnya (D + 1)
        $nextDate = Carbon::parse($candDate, 'Asia/Jakarta')->addDay()->toDateString();
        $nextSchedules = WorkSchedule::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('schedule_date', $nextDate)
            ->where('status', '!=', 'pending_delete')
            ->when($ignoreScheduleId !== null, fn ($q) => $q->where('id', '!=', $ignoreScheduleId))
            ->with(['shift' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        foreach ($nextSchedules as $nextSchedule) {
            if (! $nextSchedule->isOff()) {
                $nextStart = $nextSchedule->getEffectiveStartDateTime();
                if ($nextStart) {
                    $restHours = $candEnd->diffInMinutes($nextStart, false) / 60.0;
                    if ($restHours < 8.0) {
                        $violated = true;
                        $hoursRounded = round(max(0, $restHours), 1);
                        $minRestHours = $minRestHours === null ? $hoursRounded : min($minRestHours, $hoursRounded);
                    }
                }
            }
        }

        $message = $violated
            ? __('roster.turnaround_warning_message', ['hours' => $minRestHours ?? 0])
            : null;

        return [
            'violated' => $violated,
            'rest_hours' => $minRestHours,
            'message' => $message,
        ];
    }

    /**
     * Display the monthly Gantt Chart Roster matrix.
     */
    public function index(Request $request): View
    {
        $actor = $request->user();
        if (! $actor || ! $actor->is_active) {
            abort(403);
        }

        $actor->loadMissing(['company', 'employee.department', 'employee.tags']);
        $company = $this->resolveCompany($actor);

        if (! $company) {
            abort(404, 'Tenant perusahaan belum tersedia.');
        }

        $hasViewAllTag = $actor->employee !== null
            && $actor->employee->tags()->withoutGlobalScopes()->where('code', 'VIEW_ALL_SCHEDULES')->exists();
        $canViewAllDepartments = $actor->isSuperAdmin()
            || $actor->isSuperUser()
            || $actor->isAdmin()
            || $hasViewAllTag;

        $month = (int) $request->input('month', now('Asia/Jakarta')->month);
        $year = (int) $request->input('year', now('Asia/Jakarta')->year);

        if ($month < 1 || $month > 12) {
            $month = (int) now('Asia/Jakarta')->month;
        }
        if ($year < 2020 || $year > 2035) {
            $year = (int) now('Asia/Jakarta')->year;
        }

        $startOfMonth = Carbon::createFromDate($year, $month, 1, 'Asia/Jakarta')->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();
        $prevMonthLastDay = $startOfMonth->copy()->subDay();

        $departments = Department::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->get();

        $selectedDepartmentId = null;
        if (! $canViewAllDepartments) {
            $selectedDepartmentId = $actor->employee?->department_id;
        } elseif ($request->filled('department_id')) {
            $requestedDeptId = (int) $request->input('department_id');
            if ($departments->contains('id', $requestedDeptId)) {
                $selectedDepartmentId = $requestedDeptId;
            }
        }

        $modOnly = $request->boolean('mod_only');

        $employeesQuery = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->active()
            ->when($selectedDepartmentId !== null, fn ($q) => $q->where('department_id', $selectedDepartmentId))
            ->when(! $canViewAllDepartments && $actor->employee !== null, fn ($q) => $q->where('department_id', $actor->employee->department_id))
            ->when($modOnly, fn ($q) => $q->whereHas('tags', fn ($t) => $t->where('code', 'MOD_ELIGIBLE')))
            ->with([
                'user',
                'department',
                'tags' => fn ($tq) => $tq->withoutGlobalScopes(),
                'workSchedules' => fn ($sq) => $sq->withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->betweenDates($prevMonthLastDay, $endOfMonth)
                    ->when($actor->isUser(), fn ($q) => $q->published())
                    ->orderBy('schedule_date')
                    ->orderBy('id')
                    ->with([
                        'shift' => fn ($shq) => $shq->withoutGlobalScopes(),
                        'dutyTag' => fn ($dtq) => $dtq->withoutGlobalScopes(),
                    ]),
            ])
            ->orderBy('department_id')
            ->orderBy('id');

        $employees = $employeesQuery->get();

        $shifts = Shift::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->active()
            ->orderBy('id')
            ->get();

        $modTag = Tag::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', 'MOD_ELIGIBLE')
            ->first();

        $locale = app()->getLocale();
        $dayShortNames = $locale === 'en'
            ? [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun']
            : [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];

        $daysInMonth = [];
        $cursor = $startOfMonth->copy();
        while ($cursor->lessThanOrEqualTo($endOfMonth)) {
            $dow = $cursor->dayOfWeekIso;
            $daysInMonth[] = [
                'date' => $cursor->toDateString(),
                'day' => $cursor->day,
                'day_padded' => str_pad((string) $cursor->day, 2, '0', STR_PAD_LEFT),
                'day_name' => $dayShortNames[$dow],
                'is_weekend' => in_array($dow, [6, 7], true),
                'is_today' => $cursor->isToday(),
                'iso_week' => $cursor->isoWeek(),
            ];
            $cursor->addDay();
        }

        $schedulesMatrix = [];
        $employeesPayload = [];

        // Deteksi Celah Formasi 24 Jam & Audit MOD Harian
        $formationByDate = [];
        $modAuditByDate = [];

        $operationalDepts = $selectedDepartmentId !== null
            ? $departments->where('id', $selectedDepartmentId)->where('is_operational', true)
            : $departments->where('is_operational', true);
        $minQuota = (int) ($operationalDepts->max('min_staff_per_shift') ?? 1);

        foreach ($daysInMonth as $d) {
            $formationByDate[$d['date']] = [
                'has_gap' => false,
                'min_quota' => $minQuota,
                'counts' => ['MO' => 0, 'MD' => 0, 'AF' => 0, 'NI' => 0],
                'gaps' => [],
            ];
            $modAuditByDate[$d['date']] = [
                'count' => 0,
                'is_covered' => false,
            ];
        }

        $draftCount = 0;
        $publishedCount = 0;
        $pendingDeleteCount = 0;
        $startOfMonthStr = $startOfMonth->toDateString();
        $endOfMonthStr = $endOfMonth->toDateString();

        foreach ($employees as $emp) {
            $schedulesMatrix[$emp->id] = [];
            $isOperationalStaff = (bool) ($emp->department?->is_operational ?? false);

            foreach ($emp->workSchedules as $ws) {
                $dateKey = $ws->schedule_date->toDateString();
                $payload = $ws->toMatrixPayload();
                $inCurrentMonth = $dateKey >= $startOfMonthStr && $dateKey <= $endOfMonthStr;

                // Hitung statistik publikasi untuk bulan aktif
                if ($inCurrentMonth) {
                    if ($ws->status === 'draft') {
                        $draftCount++;
                    } elseif ($ws->status === 'published') {
                        $publishedCount++;
                    } elseif ($ws->status === 'pending_delete') {
                        $pendingDeleteCount++;
                    }
                }

                // Cek turnaround warning terhadap jadwal hari sebelumnya
                if ($ws->status !== 'pending_delete' && ! $ws->isOff()) {
                    $prevDateKey = Carbon::parse($dateKey, 'Asia/Jakarta')->subDay()->toDateString();
                    $prevSchedulesOnDay = $emp->workSchedules->filter(
                        fn ($s) => $s->schedule_date->toDateString() === $prevDateKey && $s->status !== 'pending_delete' && ! $s->isOff()
                    );
                    foreach ($prevSchedulesOnDay as $prevWs) {
                        $prevEnd = $prevWs->getEffectiveEndDateTime();
                        $currStart = $ws->getEffectiveStartDateTime();
                        if ($prevEnd && $currStart && ($prevEnd->diffInMinutes($currStart, false) / 60.0) < 8.0) {
                            $payload['has_turnaround_warning'] = true;
                            $payload['rest_hours'] = round(max(0, $prevEnd->diffInMinutes($currStart, false) / 60.0), 1);
                        }
                    }
                }

                if (! isset($schedulesMatrix[$emp->id][$dateKey])) {
                    $schedulesMatrix[$emp->id][$dateKey] = [];
                }
                $schedulesMatrix[$emp->id][$dateKey][] = $payload;

                // Agregasi formasi 24 jam & MOD (abaikan jadwal yang berstatus pending_delete)
                if ($inCurrentMonth && $ws->status !== 'pending_delete' && isset($formationByDate[$dateKey])) {
                    if ($isOperationalStaff && ! $ws->isOff() && ! empty($payload['shift_code'])) {
                        $code = $payload['shift_code'];
                        if (isset($formationByDate[$dateKey]['counts'][$code])) {
                            $formationByDate[$dateKey]['counts'][$code]++;
                        }
                    }

                    if ($payload['shift_code'] === 'MOD' || $payload['duty_tag_code'] === 'MOD_ELIGIBLE') {
                        $modAuditByDate[$dateKey]['count']++;
                        $modAuditByDate[$dateKey]['is_covered'] = true;
                    }
                }
            }

            $employeesPayload[] = [
                'id' => $emp->id,
                'uuid' => $emp->uuid,
                'name' => $emp->user?->name ?? 'Employee #'.$emp->id,
                'position' => $emp->position,
                'department_id' => $emp->department_id,
                'department_code' => $emp->department?->code ?? '-',
                'department_name' => $emp->department?->name ?? '-',
                'is_operational' => $isOperationalStaff,
                'tags' => $emp->tags->map(fn ($t) => [
                    'id' => $t->id,
                    'code' => $t->code,
                    'name' => $t->name,
                    'badge_color' => $t->badge_color,
                ])->values()->all(),
                'is_mod_eligible' => $emp->tags->contains('code', 'MOD_ELIGIBLE'),
            ];
        }

        // Finalisasi evaluasi gap formasi 24 jam (apabila ada departemen operasional)
        if ($operationalDepts->isNotEmpty()) {
            foreach ($daysInMonth as $d) {
                $dateKey = $d['date'];
                $counts = $formationByDate[$dateKey]['counts'];
                $gaps = [];

                foreach (['MO', 'AF', 'NI'] as $shiftKey) {
                    if ($counts[$shiftKey] < $minQuota) {
                        $gaps[$shiftKey] = "{$counts[$shiftKey]}/{$minQuota}";
                    }
                }

                if (! empty($gaps)) {
                    $formationByDate[$dateKey]['has_gap'] = true;
                    $formationByDate[$dateKey]['gaps'] = $gaps;
                }
            }
        }

        $shiftsPayload = $shifts->map(fn (Shift $s) => [
            'id' => $s->id,
            'uuid' => $s->uuid,
            'code' => $s->code,
            'name' => $s->name,
            'start_time' => Carbon::parse($s->start_time, 'Asia/Jakarta')->format('H:i:s'),
            'end_time' => Carbon::parse($s->end_time, 'Asia/Jakarta')->format('H:i:s'),
            'cross_day' => (bool) $s->cross_day,
            'grace_period_minutes' => (int) $s->grace_period_minutes,
            'duration_hours' => $s->getDurationInHours(),
            'color_badge' => $s->color_badge,
        ])->values()->all();

        $canEdit = $this->canMutateRoster($actor);
        if ($actor->isManager() && $selectedDepartmentId !== null && (int) $selectedDepartmentId !== (int) $actor->employee?->department_id) {
            $canEdit = false;
        }
        $rosterStatus = ($draftCount === 0 && $pendingDeleteCount === 0 && $publishedCount > 0) ? 'published' : 'draft';

        return view('roster.index', compact(
            'actor',
            'company',
            'month',
            'year',
            'startOfMonth',
            'departments',
            'selectedDepartmentId',
            'canViewAllDepartments',
            'hasViewAllTag',
            'modOnly',
            'employees',
            'employeesPayload',
            'shifts',
            'shiftsPayload',
            'modTag',
            'daysInMonth',
            'schedulesMatrix',
            'formationByDate',
            'modAuditByDate',
            'draftCount',
            'publishedCount',
            'pendingDeleteCount',
            'rosterStatus',
            'canEdit'
        ));
    }

    /**
     * Create a new shift assignment (supporting multiple shifts per day per employee)
     * or update an existing schedule when schedule_id is provided.
     * Newly created/edited assignments are always saved with 'draft' status until published.
     */
    public function assign(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->canMutateRoster($actor)) {
            abort(403, 'Anda tidak memiliki izin untuk mengubah jadwal.');
        }

        $validated = $request->validate([
            'schedule_id' => ['nullable'],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'schedule_date' => ['required', 'date_format:Y-m-d'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'duty_tag_id' => ['nullable', 'integer', 'exists:tags,id'],
            'status' => ['nullable', 'string', 'in:draft,published,pending_delete,on_leave'],
            'notes' => ['nullable', 'string', 'max:500'],
            'override_warning' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
        ]);

        $employee = Employee::withoutGlobalScopes()->findOrFail($validated['employee_id']);
        $this->authorizeEmployeeMutation($actor, $employee);

        $shift = null;
        if (! empty($validated['shift_id'])) {
            $shift = Shift::withoutGlobalScopes()->findOrFail($validated['shift_id']);
            if ((int) $shift->company_id !== (int) $employee->company_id) {
                abort(403, 'Shift tidak valid untuk tenant pegawai ini.');
            }
        }

        $existingSchedule = null;
        if (! empty($validated['schedule_id'])) {
            $schedKey = $validated['schedule_id'];
            $existingSchedule = WorkSchedule::withoutGlobalScopes()
                ->where('employee_id', $employee->id)
                ->where(is_numeric($schedKey) ? 'id' : 'uuid', $schedKey)
                ->firstOrFail();
        }

        // Soft Turnaround Evaluation (< 8h Rest Advisory)
        $overrideWarning = $request->boolean('override_warning') || $request->boolean('force');
        $turnaround = $this->evaluateTurnaround(
            $employee,
            $validated['schedule_date'],
            $shift,
            $existingSchedule?->id
        );

        if ($turnaround['violated'] && ! $overrideWarning) {
            return response()->json([
                'warning' => true,
                'requires_confirmation' => true,
                'rest_hours' => $turnaround['rest_hours'],
                'message' => $turnaround['message'],
            ], 422);
        }

        $dutyTagId = $validated['duty_tag_id'] ?? null;
        if ($dutyTagId !== null) {
            $tag = Tag::withoutGlobalScopes()->findOrFail($dutyTagId);
            if ((int) $tag->company_id !== (int) $employee->company_id) {
                abort(403, 'Tag penugasan tidak valid untuk tenant pegawai ini.');
            }
        } elseif ($shift && $shift->code === 'MOD') {
            $modTag = Tag::withoutGlobalScopes()
                ->where('company_id', $employee->company_id)
                ->where('code', 'MOD_ELIGIBLE')
                ->first();
            $dutyTagId = $modTag?->id;
        }

        $scheduleDate = Carbon::parse($validated['schedule_date'], 'Asia/Jakarta')->toDateString();

        // Snapshot (freeze) jam kerja shift pada saat penugasan untuk menjamin immutabilitas jadwal historis
        $snapshotStartTime = $shift
            ? Carbon::parse($shift->start_time, 'Asia/Jakarta')->format('H:i:s')
            : null;
        $snapshotEndTime = $shift
            ? Carbon::parse($shift->end_time, 'Asia/Jakarta')->format('H:i:s')
            : null;

        // Setiap penambahan/pengeditan jadwal berstatus 'draft' sampai tombol Publikasikan Roster ditekan
        $targetStatus = ($validated['status'] ?? null) === 'on_leave' ? 'on_leave' : 'draft';

        $attributes = [
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'schedule_date' => $scheduleDate,
            'shift_id' => $shift?->id,
            'duty_tag_id' => $dutyTagId,
            'custom_start_time' => $snapshotStartTime,
            'custom_end_time' => $snapshotEndTime,
            'status' => $targetStatus,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $actor->id,
        ];

        if ($existingSchedule) {
            $existingSchedule->update($attributes);
            $workSchedule = $existingSchedule;
        } else {
            $workSchedule = WorkSchedule::withoutGlobalScopes()->create($attributes);
        }

        $workSchedule->load([
            'shift' => fn ($q) => $q->withoutGlobalScopes(),
            'dutyTag' => fn ($q) => $q->withoutGlobalScopes(),
        ]);

        $payload = $workSchedule->toMatrixPayload();
        if ($turnaround['violated']) {
            $payload['has_turnaround_warning'] = true;
            $payload['rest_hours'] = $turnaround['rest_hours'];
        }

        return response()->json([
            'message' => __('roster.saved_successfully'),
            'schedule' => $payload,
        ]);
    }

    /**
     * Update custom_start_time and custom_end_time resulting from Gantt bar resizing.
     * Mutates only custom times and marks the schedule as 'draft'.
     */
    public function resize(Request $request, string $workSchedule): JsonResponse
    {
        $actor = $request->user();
        if (! $this->canMutateRoster($actor)) {
            abort(403, 'Anda tidak memiliki izin untuk mengubah jam shift.');
        }

        $schedule = WorkSchedule::withoutGlobalScopes()
            ->where(is_numeric($workSchedule) ? 'id' : 'uuid', $workSchedule)
            ->firstOrFail();

        $employee = Employee::withoutGlobalScopes()->findOrFail($schedule->employee_id);
        $this->authorizeEmployeeMutation($actor, $employee);

        $validated = $request->validate([
            'custom_start_time' => [
                'required',
                'string',
                'regex:/^(?:[01]\d|2[0-3]|24):[0-5]\d(?::[0-5]\d)?$/',
            ],
            'custom_end_time' => [
                'required',
                'string',
                'regex:/^(?:[01]\d|2[0-3]|24):[0-5]\d(?::[0-5]\d)?$/',
            ],
        ]);

        // Normalisasi waktu sirkular (24:00 -> 00:00:00) untuk shift lintas hari (T_end <= T_start)
        $rawStart = str_starts_with($validated['custom_start_time'], '24:')
            ? '00:' . substr($validated['custom_start_time'], 3)
            : $validated['custom_start_time'];
        $rawEnd = str_starts_with($validated['custom_end_time'], '24:')
            ? '00:' . substr($validated['custom_end_time'], 3)
            : $validated['custom_end_time'];

        $normalizedStart = Carbon::parse($rawStart, 'Asia/Jakarta')->format('H:i:s');
        $normalizedEnd = Carbon::parse($rawEnd, 'Asia/Jakarta')->format('H:i:s');

        $schedule->loadMissing([
            'shift' => fn ($q) => $q->withoutGlobalScopes(),
        ]);

        // Simpan snapshot jam terpublikasi sebelum di-resize agar saat "Batalkan Ubahan" diklik,
        // jadwal tidak ikut terhapus melainkan kembali ke ukuran sebelum di-resize.
        $publishedStart = $schedule->published_start_time;
        $publishedEnd = $schedule->published_end_time;
        if ($schedule->status === 'published' && empty($publishedStart) && empty($publishedEnd)) {
            $publishedStart = $schedule->getEffectiveStartTime() ?: null;
            $publishedEnd = $schedule->getEffectiveEndTime() ?: null;
        }

        $schedule->update([
            'custom_start_time' => $normalizedStart,
            'custom_end_time' => $normalizedEnd,
            'published_start_time' => $publishedStart,
            'published_end_time' => $publishedEnd,
            'status' => 'draft',
        ]);

        $schedule->load([
            'shift' => fn ($q) => $q->withoutGlobalScopes(),
            'dutyTag' => fn ($q) => $q->withoutGlobalScopes(),
        ]);

        return response()->json([
            'message' => __('roster.resized_successfully'),
            'schedule' => $schedule->toMatrixPayload(),
        ]);
    }

    /**
     * Remove a draft schedule immediately, or mark a published schedule as 'pending_delete' (ghost).
     */
    public function destroy(Request $request, string $workSchedule): JsonResponse
    {
        $actor = $request->user();
        if (! $this->canMutateRoster($actor)) {
            abort(403, 'Anda tidak memiliki izin untuk menghapus jadwal.');
        }

        $schedule = WorkSchedule::withoutGlobalScopes()
            ->where(is_numeric($workSchedule) ? 'id' : 'uuid', $workSchedule)
            ->firstOrFail();

        $employee = Employee::withoutGlobalScopes()->findOrFail($schedule->employee_id);
        $this->authorizeEmployeeMutation($actor, $employee);

        if ($schedule->status === 'published' || ! empty($schedule->published_start_time)) {
            $schedule->update(['status' => 'pending_delete']);
            $schedule->load([
                'shift' => fn ($q) => $q->withoutGlobalScopes(),
                'dutyTag' => fn ($q) => $q->withoutGlobalScopes(),
            ]);

            return response()->json([
                'message' => __('roster.marked_pending_delete'),
                'action' => 'pending_delete',
                'schedule' => $schedule->toMatrixPayload(),
            ]);
        }

        $deletedMeta = [
            'id' => $schedule->id,
            'uuid' => $schedule->uuid,
            'employee_id' => $schedule->employee_id,
            'schedule_date' => $schedule->schedule_date->toDateString(),
        ];

        $schedule->delete();

        return response()->json([
            'message' => __('roster.deleted_successfully'),
            'action' => 'deleted',
            'deleted' => $deletedMeta,
        ]);
    }

    /**
     * Restore a schedule from 'pending_delete' back to 'published'.
     */
    public function restore(Request $request, string $workSchedule): JsonResponse
    {
        $actor = $request->user();
        if (! $this->canMutateRoster($actor)) {
            abort(403, 'Anda tidak memiliki izin untuk memulihkan jadwal.');
        }

        $schedule = WorkSchedule::withoutGlobalScopes()
            ->where(is_numeric($workSchedule) ? 'id' : 'uuid', $workSchedule)
            ->firstOrFail();

        $employee = Employee::withoutGlobalScopes()->findOrFail($schedule->employee_id);
        $this->authorizeEmployeeMutation($actor, $employee);

        $schedule->update(['status' => 'published']);
        $schedule->load([
            'shift' => fn ($q) => $q->withoutGlobalScopes(),
            'dutyTag' => fn ($q) => $q->withoutGlobalScopes(),
        ]);

        return response()->json([
            'message' => __('roster.restored_successfully'),
            'action' => 'restored',
            'schedule' => $schedule->toMatrixPayload(),
        ]);
    }

    /**
     * Publish all draft schedules and permanently delete all pending_delete schedules
     * for the given month, year, and optional department within the active company scope.
     */
    public function publish(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->canMutateRoster($actor)) {
            abort(403, 'Anda tidak memiliki izin untuk mempublikasikan jadwal.');
        }

        $company = $this->resolveCompany($actor);
        if (! $company) {
            abort(404);
        }

        $month = (int) $request->input('month', now('Asia/Jakarta')->month);
        $year = (int) $request->input('year', now('Asia/Jakarta')->year);
        $startOfMonth = Carbon::createFromDate($year, $month, 1, 'Asia/Jakarta')->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        if ($actor->isManager()) {
            $managerDeptId = $actor->employee?->department_id;
            if ($request->filled('department_id') && (int) $request->input('department_id') !== (int) $managerDeptId) {
                abort(403, __('roster.view_only_mutation_forbidden'));
            }
            $departmentId = $managerDeptId;
        } else {
            $departmentId = $request->filled('department_id') ? (int) $request->input('department_id') : null;
        }

        $baseQuery = WorkSchedule::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->betweenDates($startOfMonth, $endOfMonth);

        if ($departmentId) {
            $baseQuery->byDepartment($departmentId);
        }

        $deletedCount = (clone $baseQuery)
            ->where('status', 'pending_delete')
            ->delete();

        $publishedCount = (clone $baseQuery)
            ->where('status', 'draft')
            ->update([
                'status' => 'published',
                'published_start_time' => null,
                'published_end_time' => null,
            ]);

        $totalAffected = $publishedCount + $deletedCount;

        return response()->json([
            'message' => __('roster.published_successfully', ['count' => $totalAffected]),
            'count' => $publishedCount,
            'published_count' => $publishedCount,
            'deleted_count' => $deletedCount,
            'status' => 'published',
        ]);
    }

    /**
     * Revert published schedules back to draft status for the given month, year, and optional department.
     */
    public function unpublish(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->canMutateRoster($actor)) {
            abort(403, 'Anda tidak memiliki izin untuk membatalkan publikasi jadwal.');
        }

        $company = $this->resolveCompany($actor);
        if (! $company) {
            abort(404);
        }

        $month = (int) $request->input('month', now('Asia/Jakarta')->month);
        $year = (int) $request->input('year', now('Asia/Jakarta')->year);
        $startOfMonth = Carbon::createFromDate($year, $month, 1, 'Asia/Jakarta')->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        if ($actor->isManager()) {
            $managerDeptId = $actor->employee?->department_id;
            if ($request->filled('department_id') && (int) $request->input('department_id') !== (int) $managerDeptId) {
                abort(403, __('roster.view_only_mutation_forbidden'));
            }
            $departmentId = $managerDeptId;
        } else {
            $departmentId = $request->filled('department_id') ? (int) $request->input('department_id') : null;
        }

        $query = WorkSchedule::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->betweenDates($startOfMonth, $endOfMonth)
            ->where('status', 'published');

        if ($departmentId) {
            $query->byDepartment($departmentId);
        }

        $count = $query->update(['status' => 'draft']);

        return response()->json([
            'message' => __('roster.unpublished_successfully', ['count' => $count]),
            'count' => $count,
            'status' => 'draft',
        ]);
    }

    /**
     * Discard all uncommitted draft changes for the active month, year, and department:
     * 1. Permanently deletes all 'draft' schedules.
     * 2. Restores all 'pending_delete' schedules back to 'published'.
     */
    public function discardDrafts(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->canMutateRoster($actor)) {
            abort(403, 'Anda tidak memiliki izin untuk membatalkan draf jadwal.');
        }

        $company = $this->resolveCompany($actor);
        if (! $company) {
            abort(404);
        }

        $month = (int) $request->input('month', now('Asia/Jakarta')->month);
        $year = (int) $request->input('year', now('Asia/Jakarta')->year);
        $startOfMonth = Carbon::createFromDate($year, $month, 1, 'Asia/Jakarta')->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();
        $startDate = $startOfMonth->toDateString();
        $endDate = $endOfMonth->toDateString();

        if ($actor->isManager()) {
            $managerDeptId = $actor->employee?->department_id;
            if ($request->filled('department_id') && (int) $request->input('department_id') !== (int) $managerDeptId) {
                abort(403, __('roster.view_only_mutation_forbidden'));
            }
            $departmentId = $managerDeptId;
        } else {
            $departmentId = $request->filled('department_id') ? (int) $request->input('department_id') : null;
        }

        $deletedDraftsCount = 0;
        $restoredCount = 0;

        DB::transaction(function () use ($company, $startDate, $endDate, $departmentId, &$deletedDraftsCount, &$restoredCount): void {
            $baseQuery = WorkSchedule::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereBetween('schedule_date', [$startDate, $endDate]);

            if ($departmentId) {
                $baseQuery->byDepartment($departmentId);
            }

            // 1. Pulihkan jadwal 'published' yang sebelumnya di-resize (status 'draft' namun memiliki published_start_time)
            //    kembali ke jam terpublikasi sebelumnya dan status 'published'.
            $resizedDrafts = (clone $baseQuery)
                ->where('status', 'draft')
                ->whereNotNull('published_start_time')
                ->get();

            foreach ($resizedDrafts as $resizedSchedule) {
                $resizedSchedule->update([
                    'custom_start_time' => $resizedSchedule->published_start_time,
                    'custom_end_time' => $resizedSchedule->published_end_time,
                    'published_start_time' => null,
                    'published_end_time' => null,
                    'status' => 'published',
                ]);
                $restoredCount++;
            }

            // 2. Hapus permanen rekod 'draft' murni baru (yang belum pernah dipublikasikan)
            $deletedDraftsCount = (clone $baseQuery)
                ->where('status', 'draft')
                ->whereNull('published_start_time')
                ->delete();

            // 3. Pulihkan rekod 'pending_delete' kembali menjadi 'published' (beserta ukuran awalnya jika sempat di-resize)
            $pendingDeletes = (clone $baseQuery)
                ->where('status', 'pending_delete')
                ->get();

            foreach ($pendingDeletes as $pendingSchedule) {
                $updates = ['status' => 'published'];
                if (! empty($pendingSchedule->published_start_time) && ! empty($pendingSchedule->published_end_time)) {
                    $updates['custom_start_time'] = $pendingSchedule->published_start_time;
                    $updates['custom_end_time'] = $pendingSchedule->published_end_time;
                    $updates['published_start_time'] = null;
                    $updates['published_end_time'] = null;
                }
                $pendingSchedule->update($updates);
                $restoredCount++;
            }
        });

        return response()->json([
            'message' => __('roster.discard_success'),
            'deleted_drafts_count' => $deletedDraftsCount,
            'restored_count' => $restoredCount,
            'status' => 'published',
        ]);
    }

    /**
     * Create a new master shift scoped to the active tenant company.
     */
    public function storeShift(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->canMutateRoster($actor)) {
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
                Rule::unique('shifts', 'code')->where(fn ($q) => $q->where('company_id', $company->id)),
            ],
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'color_badge' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $shiftModel = Shift::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => strtoupper(trim($validated['code'])),
            'name' => $validated['name'],
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
            'shift' => [
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
            ],
        ], 201);
    }

    /**
     * Update master shift configuration (hours, name, grace period, color badge).
     * Previously assigned work_schedules remain immutable via their snapshotted custom_start_time / custom_end_time.
     */
    public function updateShift(Request $request, string $shift): JsonResponse
    {
        $actor = $request->user();
        if (! $this->canMutateRoster($actor)) {
            abort(403, 'Anda tidak memiliki izin untuk mengubah pengaturan master shift.');
        }

        $shiftModel = Shift::withoutGlobalScopes()
            ->where(is_numeric($shift) ? 'id' : 'uuid', $shift)
            ->firstOrFail();

        if ($actor->isSuperAdmin()) {
            if (session()->has('active_company_id') && session('active_company_id') !== null) {
                if ((int) $shiftModel->company_id !== (int) session('active_company_id')) {
                    abort(403, 'Master shift berada di luar konteks tenant aktif.');
                }
            }
        } elseif ($actor->company_id === null || (int) $shiftModel->company_id !== (int) $actor->company_id) {
            abort(403, 'Akses lintas perusahaan (cross-tenant) pada master shift ditolak.');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'start_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'color_badge' => ['nullable', 'string', 'max:25'],
        ]);

        $shiftModel->fill([
            'name' => $validated['name'] ?? $shiftModel->name,
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
            'shift' => [
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
            ],
        ]);
    }
}
