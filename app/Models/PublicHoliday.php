<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicHoliday extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'company_id',
        'holiday_date',
        'name',
        'is_recurring',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'is_recurring' => 'boolean',
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
     * Set the holiday_date attribute as a pure Y-m-d date string.
     */
    public function setHolidayDateAttribute($value): void
    {
        $this->attributes['holiday_date'] = ! empty($value)
            ? Carbon::parse($value, 'Asia/Jakarta')->toDateString()
            : null;
    }

    /**
     * Get the company that owns the holiday (null if national holiday).
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope a query to only include upcoming public holidays from today (Asia/Jakarta).
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        return $query->where('holiday_date', '>=', $today)->orderBy('holiday_date');
    }

    /**
     * Scope a query to only include public holidays for a specific calendar year.
     */
    public function scopeForYear(Builder $query, int $year): Builder
    {
        $start = sprintf('%04d-01-01', $year);
        $end = sprintf('%04d-12-31', $year);

        return $query->whereBetween('holiday_date', [$start, $end])->orderBy('holiday_date');
    }

    /**
     * Determine whether a given date is a public holiday (national or company-specific).
     */
    public static function isHoliday(CarbonInterface|string $date, ?int $companyId = null): bool
    {
        return static::findHolidayForDate($date, $companyId) !== null;
    }

    /**
     * Retrieve the matching public holiday record (company-specific prioritized, or national) for a given date.
     */
    public static function findHolidayForDate(CarbonInterface|string $date, ?int $companyId = null): ?self
    {
        $dateStr = $date instanceof CarbonInterface
            ? $date->copy()->timezone('Asia/Jakarta')->toDateString()
            : Carbon::parse($date, 'Asia/Jakarta')->toDateString();

        return static::withoutGlobalScopes()
            ->whereDate('holiday_date', $dateStr)
            ->where(function (Builder $query) use ($companyId): void {
                $query->whereNull('company_id');
                if ($companyId !== null) {
                    $query->orWhere('company_id', $companyId);
                }
            })
            ->orderByRaw('CASE WHEN company_id IS NOT NULL THEN 0 ELSE 1 END')
            ->first();
    }
}
