<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuditTrail;
use App\Traits\BelongsToCompany;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicHoliday extends Model
{
    use BelongsToCompany, HasFactory, HasUuids, RecordsAuditTrail;

    protected $fillable = [
        'uuid',
        'company_id',
        'holiday_date',
        'name',
        'is_national',
        'is_recurring',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'is_national' => 'boolean',
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
     * Set the holiday_date attribute as a pure Y-m-d date string.
     */
    public function setHolidayDateAttribute($value): void
    {
        $this->attributes['holiday_date'] = ! empty($value)
            ? Carbon::parse($value, 'Asia/Jakarta')->toDateString()
            : null;
    }

    /**
     * Resolve route model binding without global scopes so the controller can
     * explicitly enforce RBAC and company ownership checks (HTTP 403).
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
     * Get the company that owns this holiday override.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Resolve target company_id from explicit parameter or current auth session.
     */
    public static function resolveCompanyId(?int $companyId = null): ?int
    {
        if ($companyId !== null) {
            return $companyId;
        }

        if (auth()->check()) {
            $user = auth()->user();

            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() && session()->has('active_company_id')) {
                $activeId = session('active_company_id');
                if ($activeId !== null) {
                    return (int) $activeId;
                }
            }

            if ($user->company_id !== null) {
                return (int) $user->company_id;
            }

            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                $firstId = Company::query()->orderBy('id')->value('id');

                return $firstId !== null ? (int) $firstId : null;
            }
        }

        if (app()->runningInConsole()) {
            $firstId = Company::query()->orderBy('id')->value('id');

            return $firstId !== null ? (int) $firstId : null;
        }

        return null;
    }

    /**
     * Scope query to upcoming holidays from a reference date for the company.
     */
    public function scopeUpcoming(Builder $query, ?Carbon $from = null, ?int $companyId = null): Builder
    {
        $resolvedCompanyId = self::resolveCompanyId($companyId);
        $fromDate = ($from ?? Carbon::now('Asia/Jakarta'))->toDateString();

        return $query
            ->when($resolvedCompanyId !== null, fn (Builder $q) => $q->where('company_id', $resolvedCompanyId))
            ->whereDate('holiday_date', '>=', $fromDate)
            ->orderBy('holiday_date', 'asc');
    }

    /**
     * Scope query to a specific year for the company.
     */
    public function scopeForYear(Builder $query, int $year, ?int $companyId = null): Builder
    {
        $resolvedCompanyId = self::resolveCompanyId($companyId);

        return $query
            ->when($resolvedCompanyId !== null, fn (Builder $q) => $q->where('company_id', $resolvedCompanyId))
            ->whereYear('holiday_date', $year)
            ->orderBy('holiday_date', 'asc');
    }

    /**
     * Determine whether a given date is a public holiday or special event for the company.
     */
    public static function isHoliday(CarbonInterface|string $date, ?int $companyId = null): bool
    {
        return self::findHolidayForDate($date, $companyId) !== null;
    }

    /**
     * Retrieve the matching PublicHoliday record for a given date and company.
     */
    public static function findHolidayForDate(CarbonInterface|string $date, ?int $companyId = null): ?self
    {
        $resolvedCompanyId = self::resolveCompanyId($companyId);
        if ($resolvedCompanyId === null) {
            return null;
        }

        $dateStr = $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date)->toDateString();

        return static::withoutGlobalScopes()
            ->where('company_id', $resolvedCompanyId)
            ->whereDate('holiday_date', $dateStr)
            ->first();
    }
}
