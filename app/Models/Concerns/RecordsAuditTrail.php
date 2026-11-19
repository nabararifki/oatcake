<?php

namespace App\Models\Concerns;

use App\Services\AuditTrailService;
use Illuminate\Database\Eloquent\Model;

trait RecordsAuditTrail
{
    /**
     * Boot Eloquent model lifecycle listeners to record immutable created, updated, and deleted audit logs.
     */
    protected static function bootRecordsAuditTrail(): void
    {
        static::created(function (Model $model): void {
            if (AuditTrailService::isSuppressed()) {
                return;
            }

            $service = app(AuditTrailService::class);
            $newValues = $service->sanitizePayload($model->getAttributes(), true);

            $service->record(
                'created',
                $model,
                null,
                $newValues === [] ? null : $newValues
            );
        });

        static::updated(function (Model $model): void {
            if (AuditTrailService::isSuppressed()) {
                return;
            }

            $changes = $model->getChanges();
            if ($changes === []) {
                return;
            }

            $service = app(AuditTrailService::class);
            $oldValues = [];
            $newValues = [];

            foreach ($changes as $key => $newValue) {
                $lowerKey = strtolower((string) $key);

                if (
                    in_array($lowerKey, AuditTrailService::REDACTED_KEYS, true)
                    || in_array($lowerKey, AuditTrailService::IGNORED_DIFF_KEYS, true)
                ) {
                    continue;
                }

                $rawOriginal = $model->getRawOriginal($key);
                $normalizedOld = $service->normalizeValue($rawOriginal);
                $normalizedNew = $service->normalizeValue($newValue);

                if ($normalizedOld === $normalizedNew) {
                    continue;
                }

                $oldValues[$key] = $normalizedOld;
                $newValues[$key] = $normalizedNew;
            }

            if ($newValues === []) {
                return;
            }

            $service->record(
                'updated',
                $model,
                $oldValues,
                $newValues
            );
        });

        static::deleted(function (Model $model): void {
            if (AuditTrailService::isSuppressed()) {
                return;
            }

            $service = app(AuditTrailService::class);
            $oldValues = $service->sanitizePayload($model->getOriginal(), true);

            $service->record(
                'deleted',
                $model,
                $oldValues === [] ? null : $oldValues,
                null
            );
        });
    }
}
