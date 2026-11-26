<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuditTrail;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use BelongsToCompany, HasFactory, HasUuids, RecordsAuditTrail;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'company_id',
        'user_id',
        'department_id',
        'manager_id',
        'position',
        'join_date',
        'phone',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'join_date' => 'date',
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
     * Resolve route model binding by numeric ID or UUID.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return $this->withoutGlobalScopes()->where($field, $value)->first();
        }

        if (is_numeric($value)) {
            return $this->withoutGlobalScopes()->where('id', (int) $value)->first();
        }

        return $this->withoutGlobalScopes()->where('uuid', (string) $value)->first();
    }

    /**
     * Scope a query to only include active employees.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Get the company that owns the employee.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the user account associated with the employee.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the department that the employee belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the direct manager (supervisor) of the employee.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /**
     * Get the direct subordinates of the employee.
     */
    public function subordinates(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    /**
     * Get the hybrid functional tags assigned to the employee.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'employee_tags');
    }

    /**
     * Determine whether this employee holds a tag matching the given code or name (case-insensitive).
     */
    public function hasTag(string $code): bool
    {
        $needle = strtoupper(trim($code));
        if ($needle === '') {
            return false;
        }

        if ($needle === 'HOD' && array_key_exists('has_hod_tag', $this->attributes)) {
            return (bool) $this->attributes['has_hod_tag'];
        }

        if ($this->relationLoaded('tags')) {
            return $this->tags->contains(function (Tag $tag) use ($needle): bool {
                return strtoupper(trim((string) $tag->code)) === $needle
                    || strtoupper(trim((string) $tag->name)) === $needle;
            });
        }

        return $this->tags()
            ->withoutGlobalScopes()
            ->where(function (Builder $query) use ($needle): void {
                $query->whereRaw('UPPER(code) = ?', [$needle])
                    ->orWhereRaw('UPPER(name) = ?', [$needle]);
            })
            ->exists();
    }

    /**
     * Get the work schedules assigned to the employee.
     */
    public function workSchedules(): HasMany
    {
        return $this->hasMany(WorkSchedule::class);
    }

    /**
     * Get the attendance records for the employee.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Get the immutable leave ledger entries for the employee.
     */
    public function leaveLedgerEntries(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }

    /**
     * Get the leave requests submitted by the employee.
     */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
