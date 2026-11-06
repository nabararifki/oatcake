<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneAttendancePhotos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:prune-photos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune old attendance photos exceeding the company retention policy';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $companies = Company::where('is_active', true)->get();
        $totalPruned = 0;

        foreach ($companies as $company) {
            $retentionMonths = (int) ($company->photo_retention_months ?? 3);
            if ($retentionMonths <= 0) {
                $retentionMonths = 3;
            }

            $cutoffDate = Carbon::now()->subMonths($retentionMonths);

            $attendances = Attendance::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where(function ($q) use ($cutoffDate) {
                    $q->where('clock_in_at', '<', $cutoffDate)
                        ->orWhere(function ($q2) use ($cutoffDate) {
                            $q2->whereNull('clock_in_at')
                                ->where('created_at', '<', $cutoffDate);
                        });
                })
                ->where(function ($query) {
                    $query->whereNotNull('clock_in_photo_path')
                        ->orWhereNotNull('clock_out_photo_path')
                        ->orWhereNotNull('proof_photo_path');
                })
                ->get();

            foreach ($attendances as $attendance) {
                $paths = array_unique(array_filter([
                    $attendance->clock_in_photo_path,
                    $attendance->clock_out_photo_path,
                    $attendance->proof_photo_path,
                ]));

                foreach ($paths as $path) {
                    if (Storage::disk('local')->exists($path)) {
                        Storage::disk('local')->delete($path);
                    }
                }

                $attendance->update([
                    'clock_in_photo_path' => null,
                    'clock_out_photo_path' => null,
                    'proof_photo_path' => null,
                ]);

                $totalPruned++;
            }
        }

        $this->info("Successfully pruned photos for {$totalPruned} attendance records.");

        return self::SUCCESS;
    }
}
