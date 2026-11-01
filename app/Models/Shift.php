<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
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
        'start_time',
        'end_time',
        'cross_day',
        'grace_period_minutes',
        'color_badge',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cross_day' => 'boolean',
            'grace_period_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Perform any actions required after the model boots.
     */
    protected static function booted(): void
    {
        static::saving(function (self $shift): void {
            if (! empty($shift->start_time) && ! empty($shift->end_time)) {
                $start = Carbon::parse($shift->start_time, 'Asia/Jakarta')->format('H:i:s');
                $end = Carbon::parse($shift->end_time, 'Asia/Jakarta')->format('H:i:s');

                $shift->start_time = $start;
                $shift->end_time = $end;
                $shift->cross_day = $end <= $start;
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
     * Scope a query to only include active shifts.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Determine whether the shift crosses midnight (overnight shift).
     */
    public function isOvernight(): bool
    {
        if (! empty($this->start_time) && ! empty($this->end_time)) {
            $start = Carbon::parse($this->start_time, 'Asia/Jakarta')->format('H:i:s');
            $end = Carbon::parse($this->end_time, 'Asia/Jakarta')->format('H:i:s');

            return (bool) ($this->cross_day ?? ($end <= $start));
        }

        return (bool) $this->cross_day;
    }

    /**
     * Calculate the shift duration in hours.
     */
    public function getDurationInHours(): float
    {
        $startFormatted = Carbon::parse($this->start_time, 'Asia/Jakarta')->format('H:i:s');
        $endFormatted = Carbon::parse($this->end_time, 'Asia/Jakarta')->format('H:i:s');

        $start = Carbon::createFromFormat('H:i:s', $startFormatted, 'Asia/Jakarta');
        $end = Carbon::createFromFormat('H:i:s', $endFormatted, 'Asia/Jakarta');

        if ($this->isOvernight() || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return round($start->diffInMinutes($end) / 60, 2);
    }

    /**
     * Get the company that owns the shift.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the work schedules using this shift.
     */
    public function workSchedules(): HasMany
    {
        return $this->hasMany(WorkSchedule::class);
    }
}
