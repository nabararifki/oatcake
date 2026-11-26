<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\LeaveLedgerService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class PwaController extends Controller
{
    public function __construct(
        private readonly LeaveLedgerService $leaveLedgerService
    ) {}

    /**
     * Serve the Web App Manifest with the standard application/manifest+json Content-Type.
     */
    public function manifest(): Response
    {
        $manifestPath = public_path('manifest.webmanifest');
        $content = File::exists($manifestPath)
            ? File::get($manifestPath)
            : json_encode($this->defaultManifestPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return response($content, 200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Display the read-only offline fallback shell (accessible to both guests and authenticated users).
     */
    public function offline(): View
    {
        return view('pwa.offline');
    }

    /**
     * Return a tenant-isolated, read-only 14-day schedule & leave balance snapshot for the authenticated user.
     */
    public function offlineSnapshot(Request $request): JsonResponse
    {
        $user = User::with([
            'company',
            'employee.company',
            'employee.department' => fn ($q) => $q->withoutGlobalScopes(),
        ])->findOrFail($request->user()->id);

        $employee = $user->employee;

        $activeCompany = $user->isSuperAdmin() && session('active_company_id')
            ? Company::find(session('active_company_id'))
            : $user->company;

        $company = $employee?->company ?? $activeCompany ?? $user->company;
        $companyId = $employee?->company_id ?? $company?->id;

        $nowWib = Carbon::now('Asia/Jakarta');
        $startDate = $nowWib->copy()->startOfDay();
        $endDate = $startDate->copy()->addDays(13);

        $leaveBalances = [
            'AL' => 0,
            'DP' => 0,
            'EO' => 0,
        ];

        if ($employee) {
            $leaveBalances['AL'] = (int) $this->leaveLedgerService->getBalance($employee, 'AL');
            $leaveBalances['DP'] = (int) $this->leaveLedgerService->getBalance($employee, 'DP');
            $leaveBalances['EO'] = (int) $this->leaveLedgerService->getBalance($employee, 'EO');
        }

        $holidays = collect();
        if ($companyId !== null) {
            $holidays = PublicHoliday::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereBetween('holiday_date', [$startDate->toDateString(), $endDate->toDateString()])
                ->orderBy('holiday_date')
                ->get();
        }

        $holidaysByDate = $holidays->keyBy(fn (PublicHoliday $holiday) => $holiday->holiday_date instanceof CarbonInterface
            ? $holiday->holiday_date->toDateString()
            : (string) $holiday->holiday_date);

        $upcomingSchedules = [];
        if ($employee) {
            $schedules = WorkSchedule::withoutGlobalScopes()
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)
                ->published()
                ->whereBetween('schedule_date', [$startDate->toDateString(), $endDate->toDateString()])
                ->with([
                    'shift' => fn ($q) => $q->withoutGlobalScopes()->withTrashed(),
                    'dutyTag' => fn ($q) => $q->withoutGlobalScopes(),
                ])
                ->orderBy('schedule_date')
                ->orderBy('custom_start_time')
                ->orderBy('id')
                ->get();

            $locale = app()->getLocale();

            $upcomingSchedules = $schedules->map(function (WorkSchedule $ws) use ($holidaysByDate, $locale): array {
                $dateStr = $ws->schedule_date instanceof CarbonInterface
                    ? $ws->schedule_date->toDateString()
                    : (string) $ws->schedule_date;
                $dateCarbon = Carbon::parse($dateStr, 'Asia/Jakarta')->locale($locale);

                $leaveMeta = $ws->parseLeaveMetadata();
                $isOff = $ws->isOff();
                $startStr = $isOff ? '' : $ws->getEffectiveStartTime();
                $endStr = $isOff ? '' : $ws->getEffectiveEndTime();
                $holiday = $holidaysByDate->get($dateStr);

                return [
                    'date' => $dateStr,
                    'date_formatted' => $dateCarbon->translatedFormat('D, d M Y'),
                    'shift_code' => $leaveMeta !== null ? 'OFF' : ($ws->shift ? $ws->shift->code : 'OFF'),
                    'shift_name' => $leaveMeta !== null ? $leaveMeta['remarks'] : ($ws->shift ? $ws->shift->name : __('roster.day_off_dp')),
                    'color_badge' => $leaveMeta !== null ? '#52525B' : ($ws->shift ? $ws->shift->color_badge : '#71717A'),
                    'start_time' => $startStr !== '' ? substr($startStr, 0, 5) : null,
                    'end_time' => $endStr !== '' ? substr($endStr, 0, 5) : null,
                    'duration_hours' => $ws->getDurationInHours(),
                    'is_off' => $isOff,
                    'is_leave' => $leaveMeta !== null,
                    'leave_code' => $leaveMeta['code'] ?? null,
                    'leave_label' => $leaveMeta['label'] ?? null,
                    'is_holiday' => $holiday !== null,
                    'holiday_name' => $holiday?->name,
                    'duty_tag_code' => $ws->dutyTag?->code,
                ];
            })->values()->all();
        }

        $publicHolidaysPayload = $holidays->map(fn (PublicHoliday $holiday): array => [
            'date' => $holiday->holiday_date instanceof CarbonInterface
                ? $holiday->holiday_date->toDateString()
                : (string) $holiday->holiday_date,
            'name' => $holiday->name,
            'is_national' => (bool) $holiday->is_national,
        ])->values()->all();

        return response()->json([
            'generated_at' => $nowWib->toIso8601String(),
            'generated_at_formatted' => $nowWib->copy()->locale(app()->getLocale())->translatedFormat('d M Y, H:i').' WIB',
            'window' => [
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
            ],
            'user' => [
                'name' => $user->name,
                'role' => $user->role,
                'company_name' => $company?->name ?? 'OATCAKE Platform',
                'company_code' => $company?->code,
                'department_name' => $employee?->department?->name,
                'department_code' => $employee?->department?->code,
                'position' => $employee?->position,
            ],
            'leave_balances' => $leaveBalances,
            'upcoming_schedules' => $upcomingSchedules,
            'public_holidays' => $publicHolidaysPayload,
        ])->header('Cache-Control', 'private, no-cache');
    }

    /**
     * Fallback manifest payload if public/manifest.webmanifest is absent.
     *
     * @return array<string, mixed>
     */
    private function defaultManifestPayload(): array
    {
        return [
            'id' => '/',
            'name' => 'OATCAKE — Hospitality HRIS & Roster Management',
            'short_name' => 'OATCAKE',
            'description' => 'Sistem Informasi Manajemen SDM, Jadwal Kerja 24 Jam, Presensi Geospasial & Buku Besar Cuti Perhotelan',
            'start_url' => '/dashboard?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#ffffff',
            'theme_color' => '#ffffff',
            'icons' => [
                ['src' => '/icons/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512x512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-maskable-512x512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                [
                    'name' => 'Presensi & Dashboard',
                    'short_name' => 'Dashboard',
                    'url' => '/dashboard',
                    'icons' => [['src' => '/icons/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png']],
                ],
                [
                    'name' => 'Jadwal Kerja',
                    'short_name' => 'Roster',
                    'url' => '/roster',
                    'icons' => [['src' => '/icons/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png']],
                ],
                [
                    'name' => 'Cuti & Izin',
                    'short_name' => 'Cuti',
                    'url' => '/leave',
                    'icons' => [['src' => '/icons/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png']],
                ],
            ],
        ];
    }
}
