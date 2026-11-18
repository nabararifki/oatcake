<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * Disable automatic Eloquent timestamps; created_at is managed append-only.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'company_id',
        'actor_id',
        'actor_name',
        'actor_role',
        'event_type',
        'auditable_type',
        'auditable_id',
        'description',
        'old_values',
        'new_values',
        'metadata',
        'created_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Enforce Strict Append-Only Immutability at the Eloquent lifecycle level.
     */
    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            if (empty($log->created_at)) {
                $log->created_at = Carbon::now('Asia/Jakarta');
            }
        });

        static::updating(function (): void {
            throw new LogicException('Audit logs are strictly immutable and cannot be modified or deleted.');
        });

        static::deleting(function (): void {
            throw new LogicException('Audit logs are strictly immutable and cannot be modified or deleted.');
        });
    }

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Resolve route model binding without global scopes so policy/controller can return HTTP 403 cross-tenant.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return static::withoutGlobalScopes()->where($field, $value)->first();
        }

        return static::withoutGlobalScopes()
            ->where(fn (Builder $q) => is_numeric($value) ? $q->where('id', (int) $value) : $q->where('uuid', (string) $value))
            ->first();
    }

    /**
     * Get the user actor who triggered the audited action.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Return all attribute keys present in either old_values or new_values.
     *
     * @return list<string>
     */
    public function getChangedKeys(): array
    {
        $oldKeys = is_array($this->old_values) ? array_keys($this->old_values) : [];
        $newKeys = is_array($this->new_values) ? array_keys($this->new_values) : [];

        return array_values(array_unique(array_merge($oldKeys, $newKeys)));
    }

    /**
     * Build structured side-by-side diff rows for forensic inspection.
     *
     * @return list<array{key: string, old: mixed, new: mixed}>
     */
    public function getDiffRows(): array
    {
        $old = is_array($this->old_values) ? $this->old_values : [];
        $new = is_array($this->new_values) ? $this->new_values : [];

        $rows = [];
        foreach ($this->getChangedKeys() as $key) {
            $rows[] = [
                'key' => (string) $key,
                'old' => array_key_exists($key, $old) ? $old[$key] : null,
                'new' => array_key_exists($key, $new) ? $new[$key] : null,
            ];
        }

        return $rows;
    }
}
