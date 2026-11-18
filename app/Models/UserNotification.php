<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'company_id',
        'user_id',
        'category',
        'title',
        'body',
        'action_url',
        'meta',
        'read_at',
        'pushed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'read_at' => 'datetime',
            'pushed_at' => 'datetime',
        ];
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
     * Resolve route model binding without global scopes so controllers can enforce HTTP 403 cross-tenant/user checks.
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
     * Scope a query to only include unread notifications.
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Get the user that owns this notification.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
