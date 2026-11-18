<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AuditTrailService
{
    /**
     * Attributes strictly redacted from all JSONB audit payloads (credentials + zero-NIK).
     *
     * @var list<string>
     */
    public const REDACTED_KEYS = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'auth_token',
        'public_key',
        'nik',
    ];

    /**
     * Technical columns ignored when computing business-attribute diffs.
     *
     * @var list<string>
     */
    public const IGNORED_DIFF_KEYS = [
        'id',
        'uuid',
        'created_at',
        'updated_at',
    ];

    /**
     * Record an immutable append-only audit log entry with sanitized JSONB payloads and HTTP metadata.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $extraMetadata
     */
    public function record(
        string $eventType,
        Model|string $auditable,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        array $extraMetadata = [],
        ?User $actor = null,
        ?int $companyId = null
    ): ?AuditLog {
        try {
            if (! Schema::hasTable('audit_logs')) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $auditableType = $this->resolveAuditableType($auditable);
        $auditableId = $auditable instanceof Model ? $auditable->getKey() : null;

        $resolvedActor = $actor ?? (auth()->check() ? auth()->user() : null);

        $resolvedCompanyId = $companyId;
        if ($resolvedCompanyId === null && $auditable instanceof Company) {
            $resolvedCompanyId = $auditable->id ? (int) $auditable->id : null;
        } elseif ($resolvedCompanyId === null && $auditable instanceof Model) {
            $rawCompanyId = $auditable->getAttribute('company_id');
            if ($rawCompanyId !== null) {
                $resolvedCompanyId = (int) $rawCompanyId;
            }
        }
        if ($resolvedCompanyId === null && $resolvedActor !== null && $resolvedActor->company_id !== null) {
            $resolvedCompanyId = (int) $resolvedActor->company_id;
        }

        $sanitizedOld = $this->sanitizePayload($oldValues, $eventType === 'updated');
        $sanitizedNew = $this->sanitizePayload($newValues, $eventType === 'updated');

        $requestMetadata = $this->collectRequestMetadata();
        $mergedMetadata = $this->sanitizePayload(array_merge($requestMetadata, $extraMetadata), false) ?? [];

        $resolvedDescription = $description !== null && trim($description) !== ''
            ? Str::limit(trim($description), 255, '')
            : Str::limit($this->buildDefaultDescription($eventType, $auditableType, $auditableId), 255, '');

        try {
            return AuditLog::withoutGlobalScopes()->create([
                'company_id' => $resolvedCompanyId,
                'actor_id' => $resolvedActor?->id,
                'actor_name' => $resolvedActor?->name ?? 'System',
                'actor_role' => $resolvedActor?->role ?? 'system',
                'event_type' => $eventType,
                'auditable_type' => $auditableType,
                'auditable_id' => is_numeric($auditableId) ? (int) $auditableId : null,
                'description' => $resolvedDescription,
                'old_values' => $sanitizedOld === [] ? null : $sanitizedOld,
                'new_values' => $sanitizedNew === [] ? null : $sanitizedNew,
                'metadata' => $mergedMetadata === [] ? null : $mergedMetadata,
                'created_at' => Carbon::now('Asia/Jakarta'),
            ]);
        } catch (Throwable $e) {
            Log::warning('AuditTrailService failed to persist audit log: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Resolve a clean, consistent module slug for the auditable entity.
     */
    public function resolveAuditableType(Model|string $auditable): string
    {
        if (is_string($auditable)) {
            return Str::snake(trim($auditable));
        }

        if (method_exists($auditable, 'getAuditableType')) {
            return (string) $auditable->getAuditableType();
        }

        return Str::snake(class_basename($auditable));
    }

    /**
     * Recursively sanitize sensitive keys (passwords, tokens, nik) and normalize scalar/date values.
     *
     * @param  array<mixed>|null  $payload
     * @return array<mixed>|null
     */
    public function sanitizePayload(?array $payload, bool $stripIgnoredDiffKeys = false): ?array
    {
        if ($payload === null) {
            return null;
        }

        $clean = [];
        foreach ($payload as $key => $value) {
            $keyStr = (string) $key;
            $lowerKey = strtolower($keyStr);

            if (in_array($lowerKey, self::REDACTED_KEYS, true)) {
                continue;
            }

            if ($stripIgnoredDiffKeys && in_array($lowerKey, self::IGNORED_DIFF_KEYS, true)) {
                continue;
            }

            $clean[$keyStr] = $this->normalizeValue($value);
        }

        return $clean;
    }

    /**
     * Normalize any attribute value into a deterministic JSON-serializable value.
     */
    public function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_array($value)) {
            return $this->sanitizePayload($value, false);
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $this->sanitizePayload($value->toArray(), false);
        }

        return $value;
    }

    /**
     * Collect HTTP request forensics metadata safely (works across HTTP requests, CLI, and tests).
     *
     * @return array<string, mixed>
     */
    private function collectRequestMetadata(): array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return [
                'source' => 'console',
            ];
        }

        try {
            $req = request();
            $routeName = $req->route()?->getName();

            return array_filter([
                'ip_address' => $req->ip(),
                'user_agent' => $req->userAgent() ? Str::limit((string) $req->userAgent(), 255, '') : null,
                'route_name' => is_string($routeName) ? $routeName : null,
                'http_method' => $req->method(),
                'url' => Str::limit((string) $req->path(), 255, ''),
            ], static fn ($v): bool => $v !== null && $v !== '');
        } catch (Throwable) {
            return [];
        }
    }

    private function buildDefaultDescription(string $eventType, string $auditableType, mixed $auditableId): string
    {
        $idSuffix = $auditableId !== null ? " #{$auditableId}" : '';

        return sprintf('%s %s%s', ucfirst(str_replace('_', ' ', $eventType)), str_replace('_', ' ', $auditableType), $idSuffix);
    }
}
