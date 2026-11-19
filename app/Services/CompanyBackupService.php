<?php

namespace App\Services;

use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

class CompanyBackupService
{
    public const BACKUP_FORMAT_VERSION = '1.0';

    public const BACKUP_FORMAT_NAME = 'oatcake-jsonl-v1';

    public const FILENAME_REGEX = '/^[A-Za-z0-9._-]+\.zip$/';

    /**
     * Ordered list of tenant-scoped database tables exported in topological order.
     *
     * @var list<string>
     */
    public const EXPORT_TABLES = [
        'companies',
        'leave_types',
        'departments',
        'shifts',
        'tags',
        'public_holidays',
        'users',
        'company_leave_type_settings',
        'smart_department_weights',
        'employees',
        'employee_tags',
        'work_schedules',
        'leave_ledger_entries',
        'attendances',
        'leave_requests',
        'leave_request_approvals',
        'push_subscriptions',
        'user_notifications',
        'audit_logs',
    ];

    /**
     * Create a streaming JSONL + physical storage .zip backup archive for a company.
     *
     * @return array<string, mixed>
     */
    public function createBackup(Company $company): array
    {
        $companyId = (int) $company->id;
        $nowWib = Carbon::now('Asia/Jakarta');
        $safeCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $company->code) ?: ('company-'.$companyId);
        $safeCode = trim($safeCode, '-');
        $shortSuffix = substr((string) Str::uuid(), 0, 6);
        $filename = sprintf('backup-%s-%s-%s.zip', strtolower($safeCode), $nowWib->format('Ymd-His'), $shortSuffix);

        $backupsDir = $this->getBackupsDirectoryPath($company);
        File::ensureDirectoryExists($backupsDir, 0755, true);

        $tempDir = Storage::disk('local')->path('temp/backup_'.Str::uuid());
        $dbTempDir = $tempDir.'/database';
        File::ensureDirectoryExists($dbTempDir, 0755, true);

        $tempZipPath = $tempDir.'/'.$filename;
        $finalZipPath = $backupsDir.'/'.$filename;

        try {
            $rowCounts = [];
            $checksums = [];
            $totalRows = 0;

            foreach ($this->buildExportQueries($companyId) as $table => $query) {
                $jsonlPath = $dbTempDir.'/'.$table.'.jsonl';
                $stats = $this->exportTableToJsonl($table, $query, $jsonlPath);
                $rowCounts[$table] = $stats['rows'];
                $checksums["database/{$table}.jsonl"] = $stats['sha256'];
                $totalRows += $stats['rows'];
            }

            $storageSources = $this->resolvePhysicalStorageSources($companyId);
            $storageFiles = $this->collectPhysicalFiles($storageSources);

            $manifest = [
                'version' => self::BACKUP_FORMAT_VERSION,
                'format' => self::BACKUP_FORMAT_NAME,
                'company_id' => $companyId,
                'company_uuid' => (string) $company->uuid,
                'company_code' => (string) $company->code,
                'company_name' => (string) $company->name,
                'created_at' => $nowWib->toIso8601String(),
                'timezone' => 'Asia/Jakarta',
                'tables' => self::EXPORT_TABLES,
                'row_counts' => $rowCounts,
                'checksums' => $checksums,
                'storage_files_count' => count($storageFiles),
            ];

            $manifestPath = $tempDir.'/manifest.json';
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            file_put_contents($manifestPath, $manifestJson);

            $zip = new ZipArchive;
            $openResult = $zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($openResult !== true) {
                throw new RuntimeException('Unable to initialize ZIP archive for company backup.');
            }

            try {
                $zip->addFile($manifestPath, 'manifest.json');

                foreach (self::EXPORT_TABLES as $table) {
                    $zip->addFile($dbTempDir.'/'.$table.'.jsonl', "database/{$table}.jsonl");
                }

                if ($storageFiles === []) {
                    $zip->addEmptyDir('storage');
                } else {
                    foreach ($storageFiles as $fileEntry) {
                        $zip->addFile($fileEntry['abs_path'], $fileEntry['zip_path']);
                    }
                }
            } finally {
                $zip->close();
            }

            if (! is_file($tempZipPath)) {
                throw new RuntimeException('Generated backup archive is missing after close.');
            }

            if (! @rename($tempZipPath, $finalZipPath)) {
                File::copy($tempZipPath, $finalZipPath);
                @unlink($tempZipPath);
            }

            $sizeBytes = (int) filesize($finalZipPath);

            return [
                'filename' => $filename,
                'size_bytes' => $sizeBytes,
                'size_mb' => round($sizeBytes / 1048576, 4),
                'created_at' => $nowWib->toIso8601String(),
                'created_at_formatted' => $nowWib->format('d M Y H:i:s').' WIB',
                'tables_count' => count(self::EXPORT_TABLES),
                'total_rows' => $totalRows,
                'storage_files_count' => count($storageFiles),
            ];
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    /**
     * List existing .zip backup archives stored for the given company, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listBackups(Company $company): array
    {
        $backupsDir = $this->getBackupsDirectoryPath($company);
        if (! is_dir($backupsDir)) {
            return [];
        }

        $items = [];
        $files = File::files($backupsDir);

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }
            $name = $file->getFilename();
            if (preg_match(self::FILENAME_REGEX, $name) !== 1) {
                continue;
            }

            $mtime = (int) $file->getMTime();
            $createdAtWib = Carbon::createFromTimestamp($mtime, 'Asia/Jakarta');
            $sizeBytes = (int) $file->getSize();

            $items[] = [
                'filename' => $name,
                'mtime' => $mtime,
                'size_bytes' => $sizeBytes,
                'size_mb' => round($sizeBytes / 1048576, 4),
                'created_at' => $createdAtWib->toIso8601String(),
                'created_at_formatted' => $createdAtWib->format('d M Y H:i:s').' WIB',
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $cmp = $b['mtime'] <=> $a['mtime'];

            return $cmp !== 0 ? $cmp : strcmp($b['filename'], $a['filename']);
        });

        return array_values(array_map(static function (array $item): array {
            unset($item['mtime']);

            return $item;
        }, $items));
    }

    /**
     * Resolve and validate a backup archive path inside the company's private backup directory.
     */
    public function resolveBackupFilePath(Company $company, string $filename): ?string
    {
        if (preg_match(self::FILENAME_REGEX, $filename) !== 1) {
            return null;
        }

        $backupsDir = $this->getBackupsDirectoryPath($company);
        $realBackupsDir = realpath($backupsDir);
        if ($realBackupsDir === false) {
            return null;
        }

        $candidate = $backupsDir.'/'.$filename;
        $realCandidate = realpath($candidate);
        if ($realCandidate === false || ! is_file($realCandidate)) {
            return null;
        }

        if (! str_starts_with($realCandidate, $realBackupsDir.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realCandidate;
    }

    /**
     * Permanently delete a backup archive belonging to the given company.
     */
    public function deleteBackup(Company $company, string $filename): bool
    {
        $path = $this->resolveBackupFilePath($company, $filename);
        if ($path === null) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * Get the private backup storage directory path for a company.
     */
    public function getBackupsDirectoryPath(Company $company): string
    {
        return Storage::disk('local')->path('companies/'.((int) $company->id).'/backups');
    }

    /**
     * Stream a query builder result row-by-row into a JSONL file in O(1) memory while hashing with SHA-256.
     *
     * @return array{rows: int, sha256: string}
     */
    protected function exportTableToJsonl(string $table, Builder $query, string $destFile): array
    {
        $fp = fopen($destFile, 'wb');
        if ($fp === false) {
            throw new RuntimeException("Unable to open JSONL stream file for table [{$table}].");
        }

        $hashCtx = hash_init('sha256');
        $count = 0;

        try {
            foreach ($query->cursor() as $row) {
                $encoded = json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $line = $encoded."\n";
                fwrite($fp, $line);
                hash_update($hashCtx, $line);
                $count++;
            }
        } finally {
            fclose($fp);
        }

        return [
            'rows' => $count,
            'sha256' => hash_final($hashCtx),
        ];
    }

    /**
     * Build tenant-isolated Query Builder definitions in topological order.
     *
     * @return array<string, Builder>
     */
    protected function buildExportQueries(int $companyId): array
    {
        return [
            'companies' => DB::table('companies')
                ->where('id', $companyId)
                ->orderBy('id'),

            'leave_types' => DB::table('leave_types')
                ->where(function (Builder $q) use ($companyId): void {
                    $q->whereNull('company_id')
                        ->orWhere('company_id', $companyId);
                })
                ->orderBy('id'),

            'departments' => DB::table('departments')
                ->where('company_id', $companyId)
                ->orderBy('id'),

            'shifts' => DB::table('shifts')
                ->where('company_id', $companyId)
                ->orderBy('id'),

            'tags' => DB::table('tags')
                ->where('company_id', $companyId)
                ->orderBy('id'),

            'public_holidays' => DB::table('public_holidays')
                ->where('company_id', $companyId)
                ->orderBy('id'),

            'users' => DB::table('users')
                ->where(function (Builder $q) use ($companyId): void {
                    $q->where('company_id', $companyId)
                        ->orWhereIn('id', function (Builder $sub) use ($companyId): void {
                            $sub->select('user_id')
                                ->from('employees')
                                ->where('company_id', $companyId)
                                ->whereNotNull('user_id');
                        });
                })
                ->where('role', '!=', 'super_admin')
                ->orderBy('id'),

            'company_leave_type_settings' => DB::table('company_leave_type_settings')
                ->where('company_id', $companyId)
                ->orderBy('id'),

            'smart_department_weights' => DB::table('smart_department_weights')
                ->where('company_id', $companyId)
                ->orderBy('id'),

            'employees' => DB::table('employees')
                ->where('company_id', $companyId)
                ->orderBy('id'),

            'employee_tags' => DB::table('employee_tags')
                ->join('employees', 'employee_tags.employee_id', '=', 'employees.id')
                ->where('employees.company_id', $companyId)
                ->select('employee_tags.*')
                ->orderBy('employee_tags.id'),

            'work_schedules' => DB::table('work_schedules')
                ->where('company_id', $companyId)
                ->orderBy('id'),

            'leave_ledger_entries' => DB::table('leave_ledger_entries')
                ->where('company_id', $companyId)
                ->orderBy('id', 'asc'),

            'attendances' => DB::table('attendances')
                ->where('company_id', $companyId)
                ->orderBy('id'),

            'leave_requests' => DB::table('leave_requests')
                ->where('company_id', $companyId)
                ->orderBy('id'),

            'leave_request_approvals' => DB::table('leave_request_approvals')
                ->where('company_id', $companyId)
                ->orderBy('id'),

            'push_subscriptions' => DB::table('push_subscriptions')
                ->where('company_id', $companyId)
                ->orderBy('id'),

            'user_notifications' => DB::table('user_notifications')
                ->where('company_id', $companyId)
                ->orderBy('id'),

            'audit_logs' => DB::table('audit_logs')
                ->where('company_id', $companyId)
                ->orderBy('id'),
        ];
    }

    /**
     * Resolve physical storage source directories for the tenant company.
     *
     * @return list<array{dir: string, zip_prefix: string, skip_top_dir: string|null}>
     */
    protected function resolvePhysicalStorageSources(int $companyId): array
    {
        return [
            [
                'dir' => Storage::disk('local')->path("attendance_proofs/{$companyId}"),
                'zip_prefix' => 'storage/attendance_proofs',
                'skip_top_dir' => null,
            ],
            [
                'dir' => Storage::disk('local')->path("leave_attachments/{$companyId}"),
                'zip_prefix' => 'storage/leave_attachments',
                'skip_top_dir' => null,
            ],
            [
                'dir' => Storage::disk('local')->path("companies/{$companyId}"),
                'zip_prefix' => 'storage/companies',
                'skip_top_dir' => 'backups',
            ],
        ];
    }

    /**
     * Recursively enumerate tenant physical storage files for streaming ZIP addition.
     *
     * @param  list<array{dir: string, zip_prefix: string, skip_top_dir: string|null}>  $sources
     * @return list<array{abs_path: string, zip_path: string}>
     */
    protected function collectPhysicalFiles(array $sources): array
    {
        $entries = [];

        foreach ($sources as $source) {
            $root = $source['dir'];
            if (! is_dir($root)) {
                continue;
            }

            $realRoot = realpath($root);
            if ($realRoot === false) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($realRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $absPath = $file->getPathname();
                $relative = ltrim(str_replace('\\', '/', substr($absPath, strlen($realRoot))), '/');

                if ($source['skip_top_dir'] !== null) {
                    $skip = $source['skip_top_dir'];
                    if ($relative === $skip || str_starts_with($relative, $skip.'/')) {
                        continue;
                    }
                }

                $entries[] = [
                    'abs_path' => $absPath,
                    'zip_path' => $source['zip_prefix'].'/'.$relative,
                ];
            }
        }

        return $entries;
    }
}
