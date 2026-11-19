<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

class CompanyRestoreService
{
    /**
     * Boolean columns per table for deterministic casting across PostgreSQL 18 and SQLite.
     *
     * @var array<string, list<string>>
     */
    private const BOOLEAN_COLUMNS = [
        'companies' => ['is_active', 'auto_assign_shift_on_clock_in'],
        'leave_types' => ['is_paid', 'requires_quota', 'requires_attachment', 'is_active'],
        'departments' => ['is_operational', 'is_executive_oversight', 'is_24_hours'],
        'shifts' => ['cross_day', 'is_active'],
        'public_holidays' => ['is_recurring', 'is_national'],
        'users' => ['is_active'],
        'attendances' => ['is_outside_geofence', 'is_provisional_dp'],
    ];

    /**
     * JSON/JSONB columns per table that must serialize cleanly.
     *
     * @var array<string, list<string>>
     */
    private const JSON_COLUMNS = [
        'leave_requests' => ['smart_metrics'],
        'user_notifications' => ['meta'],
        'audit_logs' => ['old_values', 'new_values', 'metadata'],
    ];

    /**
     * Map auditable_type slugs to backup/restore table keys for audit_logs auditable_id remapping.
     *
     * @var array<string, string>
     */
    private const AUDITABLE_TABLE_MAP = [
        'company' => 'companies',
        'leave_type' => 'leave_types',
        'department' => 'departments',
        'shift' => 'shifts',
        'tag' => 'tags',
        'public_holiday' => 'public_holidays',
        'user' => 'users',
        'company_leave_type_setting' => 'company_leave_type_settings',
        'smart_department_weight' => 'smart_department_weights',
        'employee' => 'employees',
        'work_schedule' => 'work_schedules',
        'leave_ledger_entry' => 'leave_ledger_entries',
        'attendance' => 'attendances',
        'leave_request' => 'leave_requests',
        'leave_request_approval' => 'leave_request_approvals',
        'push_subscription' => 'push_subscriptions',
        'user_notification' => 'user_notifications',
    ];

    /**
     * Cache of actual database column names per table during a restore run.
     *
     * @var array<string, list<string>>
     */
    private array $tableColumnsCache = [];

    /**
     * Restore a portable tenant backup (.zip) atomically with DAG ID remapping and storage swap.
     *
     * @return array<string, mixed>
     */
    public function restore(UploadedFile|string $zipSource, ?User $actor = null, ?\App\Models\Company $expectedCompany = null): array
    {
        $zipPath = $zipSource instanceof UploadedFile ? $zipSource->getRealPath() : $zipSource;
        if (! is_string($zipPath) || $zipPath === '' || ! is_file($zipPath)) {
            throw ValidationException::withMessages([
                'backup_file' => [__('company.backup_err_invalid_zip')],
            ]);
        }

        $tempDir = Storage::disk('local')->path('temp/restore_'.Str::uuid());
        File::ensureDirectoryExists($tempDir, 0755, true);

        try {
            $this->extractZipSafely($zipPath, $tempDir);
            $manifest = $this->validateManifestAndChecksums($tempDir);

            if ($actor !== null && $actor->role === 'super_user') {
                if (
                    $expectedCompany === null
                    || (string) $manifest['company_uuid'] !== (string) $expectedCompany->uuid
                ) {
                    throw new AuthorizationException('Super Users may only restore backup archives matching their assigned company UUID.');
                }
            }

            $dbResult = AuditTrailService::withoutRecording(function () use ($tempDir, $manifest): array {
                return DB::transaction(function () use ($tempDir, $manifest): array {
                    return $this->importDatabaseDag($tempDir, $manifest);
                });
            });

            $storageFilesRestored = $this->performAtomicStorageSwap(
                $tempDir.'/storage',
                (int) $dbResult['new_company_id']
            );

            return [
                'company_id' => (int) $dbResult['new_company_id'],
                'company_uuid' => (string) $manifest['company_uuid'],
                'company_name' => (string) $dbResult['company_name'],
                'in_place_overwrite' => (bool) $dbResult['in_place_overwrite'],
                'imported_counts' => $dbResult['counts'],
                'id_map_counts' => $dbResult['id_map_counts'],
                'storage_files_restored' => $storageFilesRestored,
            ];
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    /**
     * Safely extract a .zip archive into an isolated temp directory with Zip-Slip path traversal protection.
     */
    protected function extractZipSafely(string $zipPath, string $destDir): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw ValidationException::withMessages([
                'backup_file' => [__('company.backup_err_invalid_zip')],
            ]);
        }

        try {
            $realDest = realpath($destDir);
            if ($realDest === false) {
                throw new RuntimeException('Temporary restore directory does not exist.');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $rawName = $zip->getNameIndex($i);
                if (! is_string($rawName) || $rawName === '') {
                    continue;
                }

                $normalized = str_replace('\\', '/', $rawName);
                if (
                    str_contains($normalized, "\0")
                    || str_starts_with($normalized, '/')
                    || preg_match('/^[A-Za-z]:/', $normalized) === 1
                ) {
                    throw ValidationException::withMessages([
                        'backup_file' => [__('company.backup_err_invalid_zip')],
                    ]);
                }

                $segments = array_filter(explode('/', $normalized), static fn (string $s): bool => $s !== '');
                foreach ($segments as $segment) {
                    if ($segment === '.' || $segment === '..') {
                        throw ValidationException::withMessages([
                            'backup_file' => [__('company.backup_err_invalid_zip')],
                        ]);
                    }
                }

                $targetPath = $realDest.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);

                if (str_ends_with($normalized, '/')) {
                    File::ensureDirectoryExists($targetPath, 0755, true);

                    continue;
                }

                File::ensureDirectoryExists(dirname($targetPath), 0755, true);
                $inStream = $zip->getStream($rawName);
                if ($inStream === false) {
                    throw ValidationException::withMessages([
                        'backup_file' => [__('company.backup_err_invalid_zip')],
                    ]);
                }

                $outStream = fopen($targetPath, 'wb');
                if ($outStream === false) {
                    fclose($inStream);
                    throw new RuntimeException("Unable to extract backup entry [{$normalized}].");
                }

                try {
                    stream_copy_to_stream($inStream, $outStream);
                } finally {
                    fclose($inStream);
                    fclose($outStream);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Validate presence, schema, and SHA-256 checksums in manifest.json.
     *
     * @return array<string, mixed>
     */
    protected function validateManifestAndChecksums(string $tempDir): array
    {
        $manifestPath = $tempDir.'/manifest.json';
        if (! is_file($manifestPath)) {
            throw ValidationException::withMessages([
                'backup_file' => [__('company.backup_err_invalid_manifest')],
            ]);
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'backup_file' => [__('company.backup_err_invalid_manifest')],
            ]);
        }

        if (
            ! is_array($manifest)
            || empty($manifest['version'])
            || empty($manifest['company_uuid'])
            || empty($manifest['company_name'])
            || empty($manifest['created_at'])
            || ! isset($manifest['tables'])
            || ! is_array($manifest['tables'])
        ) {
            throw ValidationException::withMessages([
                'backup_file' => [__('company.backup_err_invalid_manifest')],
            ]);
        }

        if (! is_file($tempDir.'/database/companies.jsonl')) {
            throw ValidationException::withMessages([
                'backup_file' => [__('company.backup_err_invalid_manifest')],
            ]);
        }

        if (isset($manifest['checksums']) && is_array($manifest['checksums'])) {
            foreach ($manifest['checksums'] as $relPath => $expectedHash) {
                $relPathStr = str_replace('\\', '/', (string) $relPath);
                if (str_contains($relPathStr, '..') || str_starts_with($relPathStr, '/')) {
                    throw ValidationException::withMessages([
                        'backup_file' => [__('company.backup_err_checksum_mismatch')],
                    ]);
                }

                $fullPath = $tempDir.'/'.$relPathStr;
                if (! is_file($fullPath)) {
                    throw ValidationException::withMessages([
                        'backup_file' => [__('company.backup_err_checksum_mismatch')],
                    ]);
                }

                $actualHash = hash_file('sha256', $fullPath);
                if ($actualHash === false || ! hash_equals((string) $expectedHash, $actualHash)) {
                    throw ValidationException::withMessages([
                        'backup_file' => [__('company.backup_err_checksum_mismatch')],
                    ]);
                }
            }
        }

        return $manifest;
    }

    /**
     * Execute full topological DAG import inside a transaction using dynamic ID remapping.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    protected function importDatabaseDag(string $tempDir, array $manifest): array
    {
        $dbDir = $tempDir.'/database';
        $idMap = [];
        $counts = [];

        // 1. Root: companies (match UUID: overwrite existing company children or insert new)
        $companyRow = null;
        $this->streamJsonl($dbDir.'/companies.jsonl', function (array $row) use (&$companyRow): void {
            if ($companyRow === null) {
                $companyRow = $row;
            }
        });

        if ($companyRow === null || empty($companyRow['uuid']) || (string) $companyRow['uuid'] !== (string) $manifest['company_uuid']) {
            throw ValidationException::withMessages([
                'backup_file' => [__('company.backup_err_invalid_manifest')],
            ]);
        }

        $oldCompanyId = isset($companyRow['id']) ? (int) $companyRow['id'] : (int) ($manifest['company_id'] ?? 0);
        $companyData = $this->prepareRowForInsert('companies', $companyRow, ['id']);
        $companyData['uuid'] = (string) $companyRow['uuid'];

        $existingCompany = DB::table('companies')->where('uuid', $companyData['uuid'])->first();
        $inPlaceOverwrite = false;

        if ($existingCompany !== null) {
            $newCompanyId = (int) $existingCompany->id;
            $inPlaceOverwrite = true;

            $updatePayload = $companyData;
            unset($updatePayload['uuid']);
            DB::table('companies')->where('id', $newCompanyId)->update($updatePayload);

            $this->purgeExistingCompanyChildren($newCompanyId);
        } else {
            $newCompanyId = (int) DB::table('companies')->insertGetId($companyData);
        }

        $idMap['companies'][$oldCompanyId] = $newCompanyId;
        $counts['companies'] = 1;

        // 2. Leave types (map global leave types by code, restore/upsert company-scoped leave types)
        foreach (DB::table('leave_types')->get(['id', 'company_id', 'code']) as $existingLt) {
            if ($existingLt->company_id === null || (int) $existingLt->company_id === $newCompanyId) {
                $idMap['leave_types'][(int) $existingLt->id] = (int) $existingLt->id;
            }
        }

        $counts['leave_types'] = $this->streamJsonl($dbDir.'/leave_types.jsonl', function (array $row) use (&$idMap, $newCompanyId): void {
            $oldId = isset($row['id']) ? (int) $row['id'] : null;
            $code = (string) ($row['code'] ?? '');
            $isGlobal = ($row['company_id'] ?? null) === null;

            $existing = $isGlobal
                ? DB::table('leave_types')->whereNull('company_id')->where('code', $code)->first()
                : DB::table('leave_types')->where('company_id', $newCompanyId)->where('code', $code)->first();

            if ($existing !== null) {
                if ($oldId !== null) {
                    $idMap['leave_types'][$oldId] = (int) $existing->id;
                }

                return;
            }

            $data = $this->prepareRowForInsert('leave_types', $row, ['id']);
            $data['company_id'] = $isGlobal ? null : $newCompanyId;
            $newId = (int) DB::table('leave_types')->insertGetId($data);
            if ($oldId !== null) {
                $idMap['leave_types'][$oldId] = $newId;
            }
        });

        // 3. Master tables: departments, shifts, tags, public_holidays
        foreach (['departments', 'shifts', 'tags', 'public_holidays'] as $masterTable) {
            $counts[$masterTable] = $this->streamJsonl($dbDir.'/'.$masterTable.'.jsonl', function (array $row) use (&$idMap, $masterTable, $newCompanyId): void {
                $oldId = isset($row['id']) ? (int) $row['id'] : null;
                $data = $this->prepareRowForInsert($masterTable, $row, ['id']);
                $data['company_id'] = $newCompanyId;

                $newId = (int) DB::table($masterTable)->insertGetId($data);
                if ($oldId !== null) {
                    $idMap[$masterTable][$oldId] = $newId;
                }
            });
        }

        // 4. Users (match by email; preserve super_admin accounts untouched, block cross-tenant collision)
        $counts['users'] = $this->streamJsonl($dbDir.'/users.jsonl', function (array $row) use (&$idMap, $newCompanyId): void {
            $oldId = isset($row['id']) ? (int) $row['id'] : null;
            $email = (string) ($row['email'] ?? '');
            if ($email === '') {
                return;
            }

            $existing = DB::table('users')->where('email', $email)->first();

            if ($existing !== null) {
                if ($existing->role === 'super_admin') {
                    if ($oldId !== null) {
                        $idMap['users'][$oldId] = (int) $existing->id;
                    }

                    return;
                }

                if ($existing->company_id !== null && (int) $existing->company_id !== $newCompanyId) {
                    throw new RuntimeException("User email [{$email}] belongs to another company tenant.");
                }

                $data = $this->prepareRowForInsert('users', $row, ['id', 'email', 'uuid', 'remember_token', 'google_id']);
                $data['company_id'] = $newCompanyId;
                if (($data['role'] ?? null) === 'super_admin') {
                    $data['role'] = 'super_user';
                }
                if (empty($data['password'])) {
                    unset($data['password']);
                }

                DB::table('users')->where('id', $existing->id)->update($data);

                if ($oldId !== null) {
                    $idMap['users'][$oldId] = (int) $existing->id;
                }

                return;
            }

            $data = $this->prepareRowForInsert('users', $row, ['id']);
            $data['company_id'] = $newCompanyId;
            if (($data['role'] ?? null) === 'super_admin') {
                $data['role'] = 'super_user';
            }

            $newId = (int) DB::table('users')->insertGetId($data);
            if ($oldId !== null) {
                $idMap['users'][$oldId] = $newId;
            }
        });

        // 5. Company leave type settings
        $counts['company_leave_type_settings'] = $this->streamJsonl(
            $dbDir.'/company_leave_type_settings.jsonl',
            function (array $row) use ($idMap, $newCompanyId): void {
                $leaveTypeId = $this->mapRequired($idMap, 'leave_types', $row['leave_type_id'] ?? null, 'company_leave_type_settings.leave_type_id');
                $data = $this->prepareRowForInsert('company_leave_type_settings', $row, ['id']);
                $data['company_id'] = $newCompanyId;
                $data['leave_type_id'] = $leaveTypeId;
                $data['updated_by'] = $this->mapOptional($idMap, 'users', $row['updated_by'] ?? null);

                DB::table('company_leave_type_settings')->insert($data);
            }
        );

        // 6. SMART department weights
        $counts['smart_department_weights'] = $this->streamJsonl(
            $dbDir.'/smart_department_weights.jsonl',
            function (array $row) use (&$idMap, $newCompanyId): void {
                $oldId = isset($row['id']) ? (int) $row['id'] : null;
                $data = $this->prepareRowForInsert('smart_department_weights', $row, ['id']);
                $data['company_id'] = $newCompanyId;
                $data['department_id'] = $this->mapRequired($idMap, 'departments', $row['department_id'] ?? null, 'smart_department_weights.department_id');
                $data['updated_by'] = $this->mapOptional($idMap, 'users', $row['updated_by'] ?? null);

                $newId = (int) DB::table('smart_department_weights')->insertGetId($data);
                if ($oldId !== null) {
                    $idMap['smart_department_weights'][$oldId] = $newId;
                }
            }
        );

        // 7a. Employees (insert with manager_id = null first to handle arbitrary hierarchy ordering)
        $pendingManagers = [];
        $counts['employees'] = $this->streamJsonl(
            $dbDir.'/employees.jsonl',
            function (array $row) use (&$idMap, &$pendingManagers, $newCompanyId): void {
                $oldId = isset($row['id']) ? (int) $row['id'] : null;
                $oldManagerId = isset($row['manager_id']) && $row['manager_id'] !== null ? (int) $row['manager_id'] : null;

                $data = $this->prepareRowForInsert('employees', $row, ['id']);
                $data['company_id'] = $newCompanyId;
                $data['user_id'] = $this->mapOptional($idMap, 'users', $row['user_id'] ?? null);
                $data['department_id'] = $this->mapRequired($idMap, 'departments', $row['department_id'] ?? null, 'employees.department_id');
                $data['manager_id'] = null;

                $newId = (int) DB::table('employees')->insertGetId($data);
                if ($oldId !== null) {
                    $idMap['employees'][$oldId] = $newId;
                }
                if ($oldManagerId !== null) {
                    $pendingManagers[$newId] = $oldManagerId;
                }
            }
        );

        // 7b. Employees second pass: remap manager_id
        foreach ($pendingManagers as $newEmpId => $oldMgrId) {
            $newMgrId = $idMap['employees'][$oldMgrId] ?? null;
            if ($newMgrId !== null) {
                DB::table('employees')->where('id', $newEmpId)->update(['manager_id' => $newMgrId]);
            }
        }

        // 7c. Employee tags pivot (supports employee_tags.jsonl or employee_tag_relations.jsonl)
        $empTagsFile = is_file($dbDir.'/employee_tags.jsonl')
            ? $dbDir.'/employee_tags.jsonl'
            : $dbDir.'/employee_tag_relations.jsonl';
        $counts['employee_tags'] = $this->streamJsonl($empTagsFile, function (array $row) use ($idMap): void {
            $empId = $this->mapOptional($idMap, 'employees', $row['employee_id'] ?? null);
            $tagId = $this->mapOptional($idMap, 'tags', $row['tag_id'] ?? null);
            if ($empId === null || $tagId === null) {
                return;
            }

            $exists = DB::table('employee_tags')
                ->where('employee_id', $empId)
                ->where('tag_id', $tagId)
                ->exists();
            if ($exists) {
                return;
            }

            $data = $this->prepareRowForInsert('employee_tags', $row, ['id']);
            $data['employee_id'] = $empId;
            $data['tag_id'] = $tagId;
            DB::table('employee_tags')->insert($data);
        });

        // 8. Work schedules
        $pendingLeaveNotes = [];
        $counts['work_schedules'] = $this->streamJsonl(
            $dbDir.'/work_schedules.jsonl',
            function (array $row) use (&$idMap, &$pendingLeaveNotes, $newCompanyId): void {
                $oldId = isset($row['id']) ? (int) $row['id'] : null;
                $data = $this->prepareRowForInsert('work_schedules', $row, ['id']);
                $data['company_id'] = $newCompanyId;
                $data['employee_id'] = $this->mapRequired($idMap, 'employees', $row['employee_id'] ?? null, 'work_schedules.employee_id');
                $data['shift_id'] = $this->mapOptional($idMap, 'shifts', $row['shift_id'] ?? null);
                $data['duty_tag_id'] = $this->mapOptional($idMap, 'tags', $row['duty_tag_id'] ?? null);
                $data['created_by'] = $this->mapOptional($idMap, 'users', $row['created_by'] ?? null);

                $newId = (int) DB::table('work_schedules')->insertGetId($data);
                if ($oldId !== null) {
                    $idMap['work_schedules'][$oldId] = $newId;
                }

                if (isset($data['notes']) && is_string($data['notes']) && preg_match('/^LEAVE:\d+:/', $data['notes']) === 1) {
                    $pendingLeaveNotes[$newId] = $data['notes'];
                }
            }
        );

        // 9. Leave ledger entries (ordered ascending by old id so FIFO source_entry_id maps inline)
        $pendingLedgerSources = [];
        $pendingLedgerRefs = [];
        $counts['leave_ledger_entries'] = $this->streamJsonl(
            $dbDir.'/leave_ledger_entries.jsonl',
            function (array $row) use (&$idMap, &$pendingLedgerSources, &$pendingLedgerRefs, $newCompanyId): void {
                $oldId = isset($row['id']) ? (int) $row['id'] : null;
                $oldSourceId = isset($row['source_entry_id']) && $row['source_entry_id'] !== null ? (int) $row['source_entry_id'] : null;

                $data = $this->prepareRowForInsert('leave_ledger_entries', $row, ['id']);
                $data['company_id'] = $newCompanyId;
                $data['employee_id'] = $this->mapRequired($idMap, 'employees', $row['employee_id'] ?? null, 'leave_ledger_entries.employee_id');
                $data['leave_type_id'] = $this->mapRequired($idMap, 'leave_types', $row['leave_type_id'] ?? null, 'leave_ledger_entries.leave_type_id');
                $data['created_by'] = $this->mapOptional($idMap, 'users', $row['created_by'] ?? null);
                $data['source_entry_id'] = $oldSourceId !== null ? ($idMap['leave_ledger_entries'][$oldSourceId] ?? null) : null;

                $newId = (int) DB::table('leave_ledger_entries')->insertGetId($data);
                if ($oldId !== null) {
                    $idMap['leave_ledger_entries'][$oldId] = $newId;
                }

                if ($oldSourceId !== null && $data['source_entry_id'] === null) {
                    $pendingLedgerSources[$newId] = $oldSourceId;
                }

                if (! empty($data['reference_type']) && isset($row['reference_id']) && $row['reference_id'] !== null) {
                    $pendingLedgerRefs[$newId] = [
                        'type' => (string) $data['reference_type'],
                        'old_id' => (int) $row['reference_id'],
                    ];
                }
            }
        );

        foreach ($pendingLedgerSources as $newLedgerId => $oldSourceId) {
            $newSourceId = $idMap['leave_ledger_entries'][$oldSourceId] ?? null;
            if ($newSourceId !== null) {
                DB::table('leave_ledger_entries')->where('id', $newLedgerId)->update(['source_entry_id' => $newSourceId]);
            }
        }

        // 10. Attendances & Leave Requests (with path rewriting + FK remapping)
        $counts['attendances'] = $this->streamJsonl(
            $dbDir.'/attendances.jsonl',
            function (array $row) use (&$idMap, $oldCompanyId, $newCompanyId): void {
                $oldId = isset($row['id']) ? (int) $row['id'] : null;
                $data = $this->prepareRowForInsert('attendances', $row, ['id']);
                $data['company_id'] = $newCompanyId;
                $data['employee_id'] = $this->mapRequired($idMap, 'employees', $row['employee_id'] ?? null, 'attendances.employee_id');
                $data['work_schedule_id'] = $this->mapOptional($idMap, 'work_schedules', $row['work_schedule_id'] ?? null);
                $data['verified_by'] = $this->mapOptional($idMap, 'users', $row['verified_by'] ?? null);
                $data['dp_ledger_entry_id'] = $this->mapOptional($idMap, 'leave_ledger_entries', $row['dp_ledger_entry_id'] ?? null);

                foreach (['proof_photo_path', 'clock_in_photo_path', 'clock_out_photo_path'] as $pathCol) {
                    if (array_key_exists($pathCol, $data)) {
                        $data[$pathCol] = $this->rewriteCompanyPath($data[$pathCol], $oldCompanyId, $newCompanyId);
                    }
                }

                $newId = (int) DB::table('attendances')->insertGetId($data);
                if ($oldId !== null) {
                    $idMap['attendances'][$oldId] = $newId;
                }
            }
        );

        $counts['leave_requests'] = $this->streamJsonl(
            $dbDir.'/leave_requests.jsonl',
            function (array $row) use (&$idMap, $oldCompanyId, $newCompanyId): void {
                $oldId = isset($row['id']) ? (int) $row['id'] : null;
                $data = $this->prepareRowForInsert('leave_requests', $row, ['id']);
                $data['company_id'] = $newCompanyId;
                $data['employee_id'] = $this->mapRequired($idMap, 'employees', $row['employee_id'] ?? null, 'leave_requests.employee_id');
                $data['leave_type_id'] = $this->mapRequired($idMap, 'leave_types', $row['leave_type_id'] ?? null, 'leave_requests.leave_type_id');
                $data['ledger_entry_id'] = $this->mapOptional($idMap, 'leave_ledger_entries', $row['ledger_entry_id'] ?? null);
                $data['manager_approved_by'] = $this->mapOptional($idMap, 'users', $row['manager_approved_by'] ?? null);
                $data['hr_approved_by'] = $this->mapOptional($idMap, 'users', $row['hr_approved_by'] ?? null);
                $data['rejected_by'] = $this->mapOptional($idMap, 'users', $row['rejected_by'] ?? null);

                if (array_key_exists('attachment_path', $data)) {
                    $data['attachment_path'] = $this->rewriteCompanyPath($data['attachment_path'], $oldCompanyId, $newCompanyId);
                }

                $newId = (int) DB::table('leave_requests')->insertGetId($data);
                if ($oldId !== null) {
                    $idMap['leave_requests'][$oldId] = $newId;
                }
            }
        );

        // Second pass for polymorphic reference_id on ledger entries and LEAVE:{id}: notes on work schedules
        foreach ($pendingLedgerRefs as $newLedgerId => $ref) {
            $targetTable = match ($ref['type']) {
                'leave_request', 'leave_requests' => 'leave_requests',
                'attendance', 'attendances' => 'attendances',
                default => null,
            };

            if ($targetTable !== null && isset($idMap[$targetTable][$ref['old_id']])) {
                DB::table('leave_ledger_entries')
                    ->where('id', $newLedgerId)
                    ->update(['reference_id' => $idMap[$targetTable][$ref['old_id']]]);
            }
        }

        foreach ($pendingLeaveNotes as $newScheduleId => $noteText) {
            if (preg_match('/^LEAVE:(\d+):(.*)$/s', $noteText, $m) === 1) {
                $oldLeaveId = (int) $m[1];
                $newLeaveId = $idMap['leave_requests'][$oldLeaveId] ?? null;
                if ($newLeaveId !== null) {
                    DB::table('work_schedules')
                        ->where('id', $newScheduleId)
                        ->update(['notes' => "LEAVE:{$newLeaveId}:{$m[2]}"]);
                }
            }
        }

        // Leave request approvals
        $counts['leave_request_approvals'] = $this->streamJsonl(
            $dbDir.'/leave_request_approvals.jsonl',
            function (array $row) use (&$idMap, $newCompanyId): void {
                $oldId = isset($row['id']) ? (int) $row['id'] : null;
                $data = $this->prepareRowForInsert('leave_request_approvals', $row, ['id']);
                $data['company_id'] = $newCompanyId;
                $data['leave_request_id'] = $this->mapRequired($idMap, 'leave_requests', $row['leave_request_id'] ?? null, 'leave_request_approvals.leave_request_id');
                $data['approver_id'] = $this->mapOptional($idMap, 'users', $row['approver_id'] ?? null);

                $newId = (int) DB::table('leave_request_approvals')->insertGetId($data);
                if ($oldId !== null) {
                    $idMap['leave_request_approvals'][$oldId] = $newId;
                }
            }
        );

        // 11. History & Subscriptions: push_subscriptions, user_notifications, audit_logs
        $counts['push_subscriptions'] = $this->streamJsonl(
            $dbDir.'/push_subscriptions.jsonl',
            function (array $row) use ($idMap, $newCompanyId): void {
                $userId = $this->mapOptional($idMap, 'users', $row['user_id'] ?? null);
                if ($userId === null) {
                    return;
                }

                $endpointHash = (string) ($row['endpoint_hash'] ?? '');
                if ($endpointHash !== '' && DB::table('push_subscriptions')->where('endpoint_hash', $endpointHash)->exists()) {
                    return;
                }

                $data = $this->prepareRowForInsert('push_subscriptions', $row, ['id']);
                $data['company_id'] = $newCompanyId;
                $data['user_id'] = $userId;

                DB::table('push_subscriptions')->insert($data);
            }
        );

        $counts['user_notifications'] = $this->streamJsonl(
            $dbDir.'/user_notifications.jsonl',
            function (array $row) use ($idMap, $newCompanyId): void {
                $userId = $this->mapOptional($idMap, 'users', $row['user_id'] ?? null);
                if ($userId === null) {
                    return;
                }

                $data = $this->prepareRowForInsert('user_notifications', $row, ['id']);
                $data['company_id'] = $newCompanyId;
                $data['user_id'] = $userId;

                DB::table('user_notifications')->insert($data);
            }
        );

        $counts['audit_logs'] = $this->streamJsonl(
            $dbDir.'/audit_logs.jsonl',
            function (array $row) use ($idMap, $newCompanyId): void {
                $data = $this->prepareRowForInsert('audit_logs', $row, ['id']);
                $data['company_id'] = $newCompanyId;
                $data['actor_id'] = $this->mapOptional($idMap, 'users', $row['actor_id'] ?? null);

                if (isset($data['auditable_type'], $row['auditable_id']) && $row['auditable_id'] !== null) {
                    $auditableTable = self::AUDITABLE_TABLE_MAP[(string) $data['auditable_type']] ?? null;
                    if ($auditableTable !== null && isset($idMap[$auditableTable][(int) $row['auditable_id']])) {
                        $data['auditable_id'] = $idMap[$auditableTable][(int) $row['auditable_id']];
                    }
                }

                DB::table('audit_logs')->insert($data);
            }
        );

        $idMapCounts = [];
        foreach ($idMap as $table => $map) {
            $idMapCounts[$table] = count($map);
        }

        return [
            'new_company_id' => $newCompanyId,
            'company_name' => (string) $companyData['name'],
            'in_place_overwrite' => $inPlaceOverwrite,
            'counts' => $counts,
            'id_map_counts' => $idMapCounts,
        ];
    }

    /**
     * Reverse-DAG deletion of all existing tenant child rows prior to in-place restore.
     * Respects ON DELETE RESTRICT FK constraints on leave_ledger_entries, employees, and audit_logs.
     */
    protected function purgeExistingCompanyChildren(int $companyId): void
    {
        DB::table('leave_request_approvals')->where('company_id', $companyId)->delete();
        DB::table('leave_requests')->where('company_id', $companyId)->delete();
        DB::table('attendances')->where('company_id', $companyId)->delete();

        DB::table('leave_ledger_entries')->where('company_id', $companyId)->update(['source_entry_id' => null]);
        DB::table('leave_ledger_entries')->where('company_id', $companyId)->delete();

        DB::table('work_schedules')->where('company_id', $companyId)->delete();

        DB::table('employee_tags')
            ->whereIn('employee_id', function ($sub) use ($companyId): void {
                $sub->select('id')->from('employees')->where('company_id', $companyId);
            })
            ->delete();

        DB::table('employees')->where('company_id', $companyId)->update(['manager_id' => null]);
        DB::table('employees')->where('company_id', $companyId)->delete();

        DB::table('smart_department_weights')->where('company_id', $companyId)->delete();
        DB::table('company_leave_type_settings')->where('company_id', $companyId)->delete();
        DB::table('public_holidays')->where('company_id', $companyId)->delete();
        DB::table('tags')->where('company_id', $companyId)->delete();
        DB::table('shifts')->where('company_id', $companyId)->delete();
        DB::table('departments')->where('company_id', $companyId)->delete();
        DB::table('push_subscriptions')->where('company_id', $companyId)->delete();
        DB::table('user_notifications')->where('company_id', $companyId)->delete();
        DB::table('audit_logs')->where('company_id', $companyId)->delete();
    }

    /**
     * Perform atomic directory swaps from temp storage into private tenant directories
     * while preserving any existing .zip archives inside companies/{id}/backups.
     */
    protected function performAtomicStorageSwap(string $extractedStorageDir, int $newCompanyId): int
    {
        if (! is_dir($extractedStorageDir)) {
            return 0;
        }

        $restoredCount = 0;

        if (is_dir($extractedStorageDir.'/attendance_proofs')) {
            $restoredCount += $this->atomicSwapDirectory(
                $extractedStorageDir.'/attendance_proofs',
                Storage::disk('local')->path("attendance_proofs/{$newCompanyId}")
            );
        }

        if (is_dir($extractedStorageDir.'/leave_attachments')) {
            $restoredCount += $this->atomicSwapDirectory(
                $extractedStorageDir.'/leave_attachments',
                Storage::disk('local')->path("leave_attachments/{$newCompanyId}")
            );
        }

        $companiesSource = $extractedStorageDir.'/companies';
        $hasDirectCustomDirs = false;
        foreach (File::directories($extractedStorageDir) as $dir) {
            $base = basename($dir);
            if (! in_array($base, ['attendance_proofs', 'leave_attachments', 'companies'], true)) {
                $hasDirectCustomDirs = true;
                break;
            }
        }

        if (is_dir($companiesSource) || $hasDirectCustomDirs) {
            $mergedCompaniesSource = $extractedStorageDir.'/_merged_companies';
            File::ensureDirectoryExists($mergedCompaniesSource, 0755, true);

            if (is_dir($companiesSource)) {
                File::copyDirectory($companiesSource, $mergedCompaniesSource);
            }

            foreach (File::directories($extractedStorageDir) as $dir) {
                $base = basename($dir);
                if (! in_array($base, ['attendance_proofs', 'leave_attachments', 'companies', '_merged_companies'], true)) {
                    File::copyDirectory($dir, $mergedCompaniesSource.'/'.$base);
                }
            }

            $restoredCount += $this->atomicSwapDirectory(
                $mergedCompaniesSource,
                Storage::disk('local')->path("companies/{$newCompanyId}"),
                ['backups']
            );
        }

        return $restoredCount;
    }

    /**
     * Atomically swap a target directory via sibling rename staging with rollback safety.
     *
     * @param  list<string>  $preserveSubdirs
     */
    protected function atomicSwapDirectory(string $sourceDir, string $targetDir, array $preserveSubdirs = []): int
    {
        $parentDir = dirname($targetDir);
        File::ensureDirectoryExists($parentDir, 0755, true);

        $suffix = (string) Str::uuid();
        $stagingDir = $parentDir.'/.swap_stage_'.basename($targetDir).'_'.$suffix;
        $prevDir = $parentDir.'/.swap_prev_'.basename($targetDir).'_'.$suffix;

        File::ensureDirectoryExists($stagingDir, 0755, true);
        File::copyDirectory($sourceDir, $stagingDir);

        $copiedFiles = $this->countFilesInDirectory($sourceDir);

        foreach ($preserveSubdirs as $subdir) {
            $existingSubdir = $targetDir.DIRECTORY_SEPARATOR.$subdir;
            $stagedSubdir = $stagingDir.DIRECTORY_SEPARATOR.$subdir;

            if (is_dir($existingSubdir)) {
                File::ensureDirectoryExists(dirname($stagedSubdir), 0755, true);
                if (is_dir($stagedSubdir)) {
                    File::deleteDirectory($stagedSubdir);
                }
                if (! @rename($existingSubdir, $stagedSubdir)) {
                    File::copyDirectory($existingSubdir, $stagedSubdir);
                }
            }
        }

        $movedPrev = false;

        try {
            if (is_dir($targetDir)) {
                if (! @rename($targetDir, $prevDir)) {
                    throw new RuntimeException("Failed to rotate existing storage directory [{$targetDir}].");
                }
                $movedPrev = true;
            }

            if (! @rename($stagingDir, $targetDir)) {
                throw new RuntimeException("Failed to swap restored storage into [{$targetDir}].");
            }

            if ($movedPrev && is_dir($prevDir)) {
                File::deleteDirectory($prevDir);
            }
        } catch (Throwable $e) {
            if ($movedPrev && ! is_dir($targetDir) && is_dir($prevDir)) {
                @rename($prevDir, $targetDir);
            }
            if (is_dir($stagingDir)) {
                File::deleteDirectory($stagingDir);
            }

            throw $e;
        }

        return $copiedFiles;
    }

    /**
     * Stream a JSONL file line-by-line in O(1) memory and invoke callback per decoded object row.
     */
    protected function streamJsonl(string $filePath, callable $callback): int
    {
        if (! is_file($filePath)) {
            return 0;
        }

        $fp = fopen($filePath, 'rb');
        if ($fp === false) {
            throw new RuntimeException("Unable to open JSONL restore file [{$filePath}].");
        }

        $count = 0;

        try {
            while (($line = fgets($fp)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                try {
                    $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new RuntimeException("Corrupted JSONL line in [".basename($filePath)."]: {$e->getMessage()}");
                }

                if (! is_array($decoded)) {
                    throw new RuntimeException('Invalid JSONL record structure in ['.basename($filePath).'].');
                }

                $callback($decoded);
                $count++;
            }
        } finally {
            fclose($fp);
        }

        return $count;
    }

    /**
     * Filter row attributes to existing table columns, drop omitted keys, and cast booleans/JSON safely.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $omit
     * @return array<string, mixed>
     */
    protected function prepareRowForInsert(string $table, array $row, array $omit = ['id']): array
    {
        $columns = $this->getTableColumns($table);
        $allowedLookup = array_flip($columns);
        $omitLookup = array_flip($omit);

        $boolSet = array_flip(self::BOOLEAN_COLUMNS[$table] ?? []);
        $jsonSet = array_flip(self::JSON_COLUMNS[$table] ?? []);

        $clean = [];
        foreach ($row as $key => $value) {
            $col = (string) $key;
            if (! isset($allowedLookup[$col]) || isset($omitLookup[$col])) {
                continue;
            }

            if (isset($boolSet[$col]) && $value !== null) {
                $clean[$col] = (bool) $value;
            } elseif (isset($jsonSet[$col]) && is_array($value)) {
                $clean[$col] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } else {
                $clean[$col] = $value;
            }
        }

        return $clean;
    }

    /**
     * Resolve and cache column listings for a table.
     *
     * @return list<string>
     */
    protected function getTableColumns(string $table): array
    {
        if (! isset($this->tableColumnsCache[$table])) {
            $this->tableColumnsCache[$table] = Schema::getColumnListing($table);
        }

        return $this->tableColumnsCache[$table];
    }

    /**
     * @param  array<string, array<int, int>>  $idMap
     */
    protected function mapRequired(array $idMap, string $table, mixed $oldId, string $context): int
    {
        if ($oldId === null) {
            throw new RuntimeException("Missing required foreign key [{$context}] in backup archive.");
        }

        $oldKey = (int) $oldId;
        if (! isset($idMap[$table][$oldKey])) {
            throw new RuntimeException("Unresolvable foreign key [{$context}={$oldKey}] in backup archive.");
        }

        return $idMap[$table][$oldKey];
    }

    /**
     * @param  array<string, array<int, int>>  $idMap
     */
    protected function mapOptional(array $idMap, string $table, mixed $oldId): ?int
    {
        if ($oldId === null || $oldId === '') {
            return null;
        }

        $oldKey = (int) $oldId;

        return $idMap[$table][$oldKey] ?? null;
    }

    protected function rewriteCompanyPath(mixed $path, int $oldCompanyId, int $newCompanyId): ?string
    {
        if (! is_string($path) || trim($path) === '' || $oldCompanyId <= 0) {
            return is_string($path) ? $path : null;
        }

        foreach (['attendance_proofs/', 'leave_attachments/', 'companies/'] as $prefix) {
            $oldPrefix = $prefix.$oldCompanyId.'/';
            if (str_starts_with($path, $oldPrefix)) {
                return $prefix.$newCompanyId.'/'.substr($path, strlen($oldPrefix));
            }
        }

        return $path;
    }

    protected function countFilesInDirectory(string $directory): int
    {
        if (! is_dir($directory)) {
            return 0;
        }

        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $count++;
            }
        }

        return $count;
    }
}
