<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\CompanyBackupService;
use App\Services\CompanyRestoreService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class CompanyBackupController extends Controller
{
    public function __construct(
        private readonly CompanyBackupService $backupService,
        private readonly CompanyRestoreService $restoreService
    ) {}

    /**
     * Resolve target tenant company from explicit company_id, super_admin session context, or user tenant.
     */
    protected function resolveTargetCompany(Request $request): ?Company
    {
        $user = $request->user();

        if ($request->filled('company_id')) {
            return Company::find((int) $request->input('company_id'));
        }

        if ($user->isSuperAdmin() && session('active_company_id')) {
            return Company::find((int) session('active_company_id'));
        }

        return $user->company ?? ($user->isSuperAdmin() ? Company::orderBy('id')->first() : null);
    }

    /**
     * List stored .zip backup archives for the target company.
     */
    public function index(Request $request): JsonResponse
    {
        $company = $this->resolveTargetCompany($request);
        if ($company === null) {
            abort(403);
        }

        Gate::authorize('manage-company-backups', $company);

        $backups = array_map(function (array $item): array {
            $item['download_url'] = route('settings.company.backups.download', ['filename' => $item['filename']]);
            $item['delete_url'] = route('settings.company.backups.destroy', ['filename' => $item['filename']]);

            return $item;
        }, $this->backupService->listBackups($company));

        return response()->json([
            'success' => true,
            'company' => [
                'id' => (int) $company->id,
                'uuid' => (string) $company->uuid,
                'name' => (string) $company->name,
            ],
            'backups' => $backups,
        ]);
    }

    /**
     * Trigger streaming JSONL + physical storage .zip backup creation.
     */
    public function store(Request $request): JsonResponse
    {
        $company = $this->resolveTargetCompany($request);
        if ($company === null) {
            abort(403);
        }

        Gate::authorize('manage-company-backups', $company);

        $backup = $this->backupService->createBackup($company);
        $backup['download_url'] = route('settings.company.backups.download', ['filename' => $backup['filename']]);
        $backup['delete_url'] = route('settings.company.backups.destroy', ['filename' => $backup['filename']]);

        return response()->json([
            'success' => true,
            'message' => __('company.backup_created_successfully'),
            'backup' => $backup,
        ], 201);
    }

    /**
     * Download a stored company backup archive.
     */
    public function download(Request $request, string $filename): BinaryFileResponse
    {
        $company = $this->resolveTargetCompany($request);
        if ($company === null) {
            abort(403);
        }

        Gate::authorize('manage-company-backups', $company);

        $path = $this->backupService->resolveBackupFilePath($company, $filename);
        if ($path === null) {
            abort(404);
        }

        return response()->download($path, $filename, [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * Permanently delete a stored company backup archive.
     */
    public function destroy(Request $request, string $filename): JsonResponse
    {
        $company = $this->resolveTargetCompany($request);
        if ($company === null) {
            abort(403);
        }

        Gate::authorize('manage-company-backups', $company);

        if (! $this->backupService->deleteBackup($company, $filename)) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'message' => __('company.backup_deleted_successfully'),
        ]);
    }

    /**
     * Upload a .zip backup bundle and execute transactional DAG restore + atomic storage swap.
     */
    public function restore(Request $request): JsonResponse
    {
        $company = $this->resolveTargetCompany($request);

        Gate::authorize('restore-company-backups', $company);

        $validated = $request->validate([
            'backup_file' => ['required', 'file', 'mimes:zip', 'max:102400'],
        ]);

        try {
            $summary = $this->restoreService->restore(
                $validated['backup_file'],
                $request->user(),
                $company
            );

            return response()->json([
                'success' => true,
                'message' => __('company.backup_restored_successfully'),
                'summary' => $summary,
            ]);
        } catch (AuthorizationException|ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'message' => __('company.backup_err_restore_failed'),
            ], 422);
        }
    }
}
