<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Department extends Model
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
        'code',
        'name',
        'is_operational',
        'is_24_hours',
        'min_staff_per_shift',
        'description',
        'is_executive_oversight',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_operational' => 'boolean',
            'is_24_hours' => 'boolean',
            'min_staff_per_shift' => 'integer',
            'is_executive_oversight' => 'boolean',
        ];
    }

    /**
     * Synchronize is_operational and is_24_hours on save.
     */
    protected static function booted(): void
    {
        static::saving(function (Department $dept): void {
            if ($dept->isDirty('is_24_hours') && ! $dept->isDirty('is_operational')) {
                $dept->attributes['is_operational'] = (bool) $dept->attributes['is_24_hours'];
            } elseif ($dept->isDirty('is_operational') && ! $dept->isDirty('is_24_hours')) {
                $dept->attributes['is_24_hours'] = (bool) $dept->attributes['is_operational'];
            }
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
     * Resolve route model binding by numeric ID or UUID.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return $this->where($field, $value)->first();
        }

        if (is_numeric($value)) {
            return $this->where('id', (int) $value)->first();
        }

        return $this->where('uuid', (string) $value)->first();
    }

    /**
     * Get the is_24_hours attribute.
     */
    public function getIs24HoursAttribute(): bool
    {
        return (bool) ($this->attributes['is_24_hours'] ?? $this->attributes['is_operational'] ?? false);
    }

    /**
     * Set the is_24_hours attribute and sync is_operational.
     */
    public function setIs24HoursAttribute(mixed $value): void
    {
        $bool = (bool) $value;
        $this->attributes['is_24_hours'] = $bool;
        $this->attributes['is_operational'] = $bool;
    }

    /**
     * Set the is_operational attribute and sync is_24_hours.
     */
    public function setIsOperationalAttribute(mixed $value): void
    {
        $bool = (bool) $value;
        $this->attributes['is_operational'] = $bool;
        $this->attributes['is_24_hours'] = $bool;
    }

    /**
     * Get the company that owns the department.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the employees belonging to the department.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Get the work schedules belonging to employees in the department.
     */
    public function workSchedules(): HasManyThrough
    {
        return $this->hasManyThrough(
            WorkSchedule::class,
            Employee::class,
            'department_id',
            'employee_id',
            'id',
            'id'
        );
    }
}
