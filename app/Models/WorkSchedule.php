<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkSchedule extends Model
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
        'employee_id',
        'shift_id',
        'schedule_date',
        'custom_start_time',
        'custom_end_time',
        'published_start_time',
        'published_end_time',
        'duty_tag_id',
        'status',
        'notes',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schedule_date' => 'date',
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
            return $this->where($field, $value)->first();
        }

        if (is_numeric($value)) {
            return $this->where('id', (int) $value)->first();
        }

        return $this->where('uuid', (string) $value)->first();
    }

    /**
     * Set the schedule_date attribute as a pure Y-m-d date string.
     */
    public function setScheduleDateAttribute($value): void
    {
        $this->attributes['schedule_date'] = ! empty($value)
            ? Carbon::parse($value, 'Asia/Jakarta')->toDateString()
            : null;
    }

    /**
     * Set the custom_start_time attribute as a pure H:i:s time string.
     */
    public function setCustomStartTimeAttribute($value): void
    {
        $this->attributes['custom_start_time'] = ! empty($value)
            ? Carbon::parse($value, 'Asia/Jakarta')->format('H:i:s')
            : null;
    }

    /**
     * Set the custom_end_time attribute as a pure H:i:s time string.
     */
    public function setCustomEndTimeAttribute($value): void
    {
        $this->attributes['custom_end_time'] = ! empty($value)
            ? Carbon::parse($value, 'Asia/Jakarta')->format('H:i:s')
            : null;
    }

    /**
     * Set the published_start_time attribute as a pure H:i:s time string.
     */
    public function setPublishedStartTimeAttribute($value): void
    {
        $this->attributes['published_start_time'] = ! empty($value)
            ? Carbon::parse($value, 'Asia/Jakarta')->format('H:i:s')
            : null;
    }

    /**
     * Set the published_end_time attribute as a pure H:i:s time string.
     */
    public function setPublishedEndTimeAttribute($value): void
    {
        $this->attributes['published_end_time'] = ! empty($value)
            ? Carbon::parse($value, 'Asia/Jakarta')->format('H:i:s')
            : null;
    }

    /**
     * Get the effective start time (custom_start_time override or shift start_time).
     */
    public function getEffectiveStartTime(): string
    {
        if (! empty($this->custom_start_time)) {
            return Carbon::parse($this->custom_start_time, 'Asia/Jakarta')->format('H:i:s');
        }

        if ($this->shift !== null && ! empty($this->shift->start_time)) {
            return Carbon::parse($this->shift->start_time, 'Asia/Jakarta')->format('H:i:s');
        }

        return '';
    }

    /**
     * Get the effective end time (custom_end_time override or shift end_time).
     */
    public function getEffectiveEndTime(): string
    {
        if (! empty($this->custom_end_time)) {
            return Carbon::parse($this->custom_end_time, 'Asia/Jakarta')->format('H:i:s');
        }

        if ($this->shift !== null && ! empty($this->shift->end_time)) {
            return Carbon::parse($this->shift->end_time, 'Asia/Jakarta')->format('H:i:s');
        }

        return '';
    }

    /**
     * Determine if the schedule represents a Day Off / Libur (shift_id is null).
     */
    public function isOff(): bool
    {
        return $this->shift_id === null;
    }

    /**
     * Determine if the schedule status is published.
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Determine if the schedule is marked for pending deletion upon next publish.
     */
    public function isPendingDelete(): bool
    {
        return $this->status === 'pending_delete';
    }

    /**
     * Scope a query to only include pending_delete schedules.
     */
    public function scopePendingDelete(Builder $query): Builder
    {
        return $query->where('status', 'pending_delete');
    }

    /**
     * Scope a query to schedules between the given start and end dates (inclusive).
     */
    public function scopeBetweenDates(Builder $query, CarbonInterface|string $start, CarbonInterface|string $end): Builder
    {
        $startDate = Carbon::parse($start, 'Asia/Jakarta')->toDateString();
        $endDate = Carbon::parse($end, 'Asia/Jakarta')->toDateString();

        return $query->whereBetween('schedule_date', [$startDate, $endDate]);
    }

    /**
     * Scope a query to schedules belonging to employees in a specific department.
     */
    public function scopeByDepartment(Builder $query, int $departmentId): Builder
    {
        return $query->whereHas('employee', function (Builder $employeeQuery) use ($departmentId): void {
            $employeeQuery->where('department_id', $departmentId);
        });
    }

    /**
     * Scope a query to only include published schedules.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope a query to only include draft schedules.
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /**
     * Get the company that owns the work schedule.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the employee assigned to the work schedule.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the master shift assigned to the work schedule (nullable for Day Off).
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the functional duty tag assigned to the work schedule (e.g. MOD_ELIGIBLE).
     */
    public function dutyTag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'duty_tag_id');
    }

    /**
     * Get the user who created the work schedule.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attendance records linked to this work schedule.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Get the exact start Carbon datetime of the schedule in Asia/Jakarta.
     */
    public function getEffectiveStartDateTime(): ?Carbon
    {
        if ($this->isOff()) {
            return null;
        }

        $dateStr = $this->schedule_date instanceof CarbonInterface
            ? $this->schedule_date->toDateString()
            : (string) $this->schedule_date;
        $startStr = $this->getEffectiveStartTime();

        if ($dateStr === '' || $startStr === '') {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d H:i:s', "{$dateStr} {$startStr}", 'Asia/Jakarta');
    }

    /**
     * Get the exact end Carbon datetime of the schedule in Asia/Jakarta (adds 1 day if overnight).
     */
    public function getEffectiveEndDateTime(): ?Carbon
    {
        $startDt = $this->getEffectiveStartDateTime();
        if (! $startDt) {
            return null;
        }

        $dateStr = $startDt->toDateString();
        $endStr = $this->getEffectiveEndTime();

        if ($endStr === '') {
            return null;
        }

        $endDt = Carbon::createFromFormat('Y-m-d H:i:s', "{$dateStr} {$endStr}", 'Asia/Jakarta');
        if ($endDt->lessThanOrEqualTo($startDt)) {
            $endDt->addDay();
        }

        return $endDt;
    }

    /**
     * Calculate the effective work duration in hours (0.0 if Day Off).
     */
    public function getEffectiveDurationInHours(): float
    {
        if ($this->isOff()) {
            return 0.0;
        }

        $startStr = $this->getEffectiveStartTime();
        $endStr = $this->getEffectiveEndTime();

        if ($startStr === '' || $endStr === '') {
            return 0.0;
        }

        $start = Carbon::createFromFormat('H:i:s', $startStr, 'Asia/Jakarta');
        $end = Carbon::createFromFormat('H:i:s', $endStr, 'Asia/Jakarta');

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return round($start->diffInMinutes($end) / 60, 2);
    }

    /**
     * Alias helper for net shift duration in hours (e.g. 8, 7.5, 12).
     */
    public function getDurationInHours(): float
    {
        return $this->getEffectiveDurationInHours();
    }

    /**
     * Transform the schedule into a normalized matrix payload for frontend & JSON API.
     *
     * @return array<string, mixed>
     */
    public function toMatrixPayload(): array
    {
        $shift = $this->shift;
        $dutyTag = $this->dutyTag;
        $startStr = $this->getEffectiveStartTime();
        $endStr = $this->getEffectiveEndTime();
        $isCrossDay = $startStr !== '' && $endStr !== '' && $endStr <= $startStr;
        $hasCustomTimes = ! empty($this->custom_start_time) && ! empty($this->custom_end_time);
        $durationHours = $this->getDurationInHours();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'company_id' => $this->company_id,
            'employee_id' => $this->employee_id,
            'schedule_date' => $this->schedule_date instanceof CarbonInterface
                ? $this->schedule_date->toDateString()
                : (string) $this->schedule_date,
            'shift_id' => $this->shift_id,
            'shift_code' => $shift ? $shift->code : 'OFF',
            'shift_name' => $shift ? $shift->name : 'Day Off (DP)',
            'color_badge' => $shift ? $shift->color_badge : '#71717A',
            'effective_start_time' => $startStr,
            'effective_end_time' => $endStr,
            'custom_start_time' => $this->custom_start_time,
            'custom_end_time' => $this->custom_end_time,
            'published_start_time' => $this->published_start_time,
            'published_end_time' => $this->published_end_time,
            'cross_day' => $hasCustomTimes ? $isCrossDay : ($shift ? (bool) ($shift->cross_day || $isCrossDay) : false),
            'is_off' => $this->isOff(),
            'duration_hours' => $durationHours,
            'duration_label' => $durationHours . 'h',
            'duty_tag_id' => $this->duty_tag_id,
            'duty_tag_code' => $dutyTag?->code,
            'status' => $this->status,
            'notes' => $this->notes,
        ];
    }
}
