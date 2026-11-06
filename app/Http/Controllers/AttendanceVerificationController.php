<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Department;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceVerificationController extends Controller
{
    /**
     * Display the manager/admin outside attendance verification console.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewVerifications', Attendance::class);

        $user = $request->user();
        $company = $user->isSuperAdmin() && session('active_company_id')
            ? Company::find(session('active_company_id'))
            : ($user->company ?? Company::first());

        $isManager = $user->isManager();
        $managerDeptId = $isManager ? $user->employee?->department_id : null;

        $departmentsQuery = Department::withoutGlobalScopes()
            ->where('company_id', $company?->id)
            ->orderBy('name');

        if ($isManager) {
            $departmentsQuery->where('id', $managerDeptId ?? 0);
        }

        $departments = $departmentsQuery->get();

        $statusFilter = (string) $request->query('status', 'pending_verification');
        $selectedDeptId = $isManager
            ? $managerDeptId
            : ($request->filled('department_id') ? (int) $request->query('department_id') : null);
        $dateFrom = $request->filled('date_from') ? (string) $request->query('date_from') : null;
        $dateTo = $request->filled('date_to') ? (string) $request->query('date_to') : null;

        $baseScopedQuery = function () use ($company, $isManager, $managerDeptId): Builder {
            $q = Attendance::withoutGlobalScopes()
                ->where('company_id', $company?->id)
                ->where('is_outside_geofence', true);

            if ($isManager) {
                $q->whereHas('employee', function (Builder $empQuery) use ($managerDeptId): void {
                    $empQuery->where('department_id', $managerDeptId ?? 0);
                });
            }

            return $q;
        };

        $todayWib = Carbon::now('Asia/Jakarta')->toDateString();

        $stats = [
            'pending' => (clone $baseScopedQuery())->where('status', 'pending_verification')->count(),
            'approved_today' => (clone $baseScopedQuery())
                ->whereIn('status', ['on_time', 'late', 'early_leave'])
                ->whereDate('verified_at', $todayWib)
                ->count(),
            'rejected_today' => (clone $baseScopedQuery())
                ->where('status', 'rejected')
                ->whereDate('verified_at', $todayWib)
                ->count(),
        ];

        $query = $baseScopedQuery()
            ->with([
                'employee.user',
                'employee.department',
                'workSchedule.shift',
                'verifier',
            ]);

        if ($statusFilter !== 'all' && $statusFilter !== '') {
            $query->where('status', $statusFilter);
        }

        if ($selectedDeptId !== null) {
            $query->whereHas('employee', function (Builder $empQuery) use ($selectedDeptId): void {
                $empQuery->where('department_id', $selectedDeptId);
            });
        }

        if ($dateFrom !== null) {
            $query->whereDate('clock_in_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->whereDate('clock_in_at', '<=', $dateTo);
        }

        $attendances = $query->orderByDesc('clock_in_at')->get();

        return view('attendance.verifications.index', [
            'currentCompany' => $company,
            'departments' => $departments,
            'attendances' => $attendances,
            'stats' => $stats,
            'filters' => [
                'status' => $statusFilter,
                'department_id' => $selectedDeptId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    /**
     * Approve an outside duty attendance submission.
     */
    public function approve(Request $request, Attendance $attendance): JsonResponse|RedirectResponse
    {
        $attendance->loadMissing('employee');

        Gate::authorize('verify', $attendance);

        $newStatus = ((int) $attendance->late_minutes > 0) ? 'late' : 'on_time';

        $attendance->update([
            'status' => $newStatus,
            'verified_by' => $request->user()->id,
            'verified_at' => Carbon::now('Asia/Jakarta'),
            'rejection_reason' => null,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('attendance.approve_success'),
                'data' => [
                    'id' => $attendance->id,
                    'uuid' => $attendance->uuid,
                    'status' => $attendance->status,
                    'verified_by' => $attendance->verified_by,
                    'verified_at' => $attendance->verified_at?->timezone('Asia/Jakarta')->toIso8601String(),
                ],
            ]);
        }

        return redirect()->back()->with('success', __('attendance.approve_success'));
    }

    /**
     * Reject an outside duty attendance submission with a mandatory reason.
     */
    public function reject(Request $request, Attendance $attendance): JsonResponse|RedirectResponse
    {
        $attendance->loadMissing('employee');

        Gate::authorize('verify', $attendance);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $attendance->update([
            'status' => 'rejected',
            'verified_by' => $request->user()->id,
            'verified_at' => Carbon::now('Asia/Jakarta'),
            'rejection_reason' => $validated['rejection_reason'],
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('attendance.reject_success'),
                'data' => [
                    'id' => $attendance->id,
                    'uuid' => $attendance->uuid,
                    'status' => $attendance->status,
                    'rejection_reason' => $attendance->rejection_reason,
                    'verified_by' => $attendance->verified_by,
                    'verified_at' => $attendance->verified_at?->timezone('Asia/Jakarta')->toIso8601String(),
                ],
            ]);
        }

        return redirect()->back()->with('success', __('attendance.reject_success'));
    }

    /**
     * Securely stream the private compressed WebP selfie proof photo for authorized users.
     */
    public function streamProofPhoto(Request $request, Attendance $attendance): BinaryFileResponse
    {
        $attendance->loadMissing('employee');

        Gate::authorize('viewProof', $attendance);

        $type = (string) $request->query('type', 'clock_in');
        $photoPath = $type === 'clock_out'
            ? $attendance->clock_out_photo_path
            : ($attendance->clock_in_photo_path ?: $attendance->proof_photo_path);

        if (empty($photoPath) || ! Storage::disk('local')->exists($photoPath)) {
            abort(404);
        }

        $fullPath = Storage::disk('local')->path($photoPath);
        $mimeType = str_ends_with(strtolower($photoPath), '.webp')
            ? 'image/webp'
            : (Storage::disk('local')->mimeType($photoPath) ?: 'image/webp');

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
