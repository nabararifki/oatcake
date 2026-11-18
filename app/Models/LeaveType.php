<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use HasFactory, HasUuids;

    /**
     * Standard system leave type definitions.
     *
     * @var array<string, array<string, mixed>>
     */
    public const STANDARD_TYPES = [
        'AL' => [
            'code' => 'AL',
            'name' => 'Cuti Tahunan / Annual Leave',
            'is_paid' => true,
            'requires_quota' => true,
            'requires_attachment' => false,
            'default_annual_quota' => 12,
            'validity_months' => 12,
            'is_active' => true,
        ],
        'DP' => [
            'code' => 'DP',
            'name' => 'Day of Payment (DP)',
            'is_paid' => true,
            'requires_quota' => true,
            'requires_attachment' => false,
            'default_annual_quota' => null,
            'validity_months' => 3,
            'is_active' => true,
        ],
        'SL' => [
            'code' => 'SL',
            'name' => 'Cuti Sakit / Sick Leave',
            'is_paid' => true,
            'requires_quota' => false,
            'requires_attachment' => true,
            'default_annual_quota' => null,
            'validity_months' => null,
            'is_active' => true,
        ],
        'SPL' => [
            'code' => 'SPL',
            'name' => 'Izin Khusus / Special Leave',
            'is_paid' => true,
            'requires_quota' => false,
            'requires_attachment' => true,
            'default_annual_quota' => null,
            'validity_months' => null,
            'is_active' => true,
        ],
        'UL' => [
            'code' => 'UL',
            'name' => 'Izin Tanpa Bayar / Unpaid Leave',
            'is_paid' => false,
            'requires_quota' => false,
            'requires_attachment' => false,
            'default_annual_quota' => null,
            'validity_months' => null,
            'is_active' => true,
        ],
    ];

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
        'is_paid',
        'requires_quota',
        'requires_attachment',
        'default_annual_quota',
        'validity_months',
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
            'is_paid' => 'boolean',
            'requires_quota' => 'boolean',
            'requires_attachment' => 'boolean',
            'default_annual_quota' => 'integer',
            'validity_months' => 'integer',
            'is_active' => 'boolean',
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
     * Get the company that owns the leave type (null if global system default).
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the ledger entries associated with this leave type.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }

    /**
     * Get the per-company settings overrides for this leave type.
     */
    public function companySettings(): HasMany
    {
        return $this->hasMany(CompanyLeaveTypeSetting::class);
    }

    /**
     * Resolve the effective validity months for a given company (falling back to leave_types.validity_months).
     */
    public function getEffectiveValidityMonths(?int $companyId = null): ?int
    {
        if ($companyId !== null) {
            $setting = $this->relationLoaded('companySettings')
                ? $this->companySettings->firstWhere('company_id', $companyId)
                : CompanyLeaveTypeSetting::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('leave_type_id', $this->id)
                    ->first();

            if ($setting !== null) {
                return $setting->validity_months !== null ? (int) $setting->validity_months : null;
            }
        }

        return $this->validity_months !== null ? (int) $this->validity_months : null;
    }

    /**
     * Resolve the effective default annual quota for a given company (falling back to leave_types.default_annual_quota).
     */
    public function getEffectiveAnnualQuota(?int $companyId = null): ?int
    {
        if ($companyId !== null) {
            $setting = $this->relationLoaded('companySettings')
                ? $this->companySettings->firstWhere('company_id', $companyId)
                : CompanyLeaveTypeSetting::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('leave_type_id', $this->id)
                    ->first();

            if ($setting !== null) {
                return $setting->default_annual_quota !== null
                    ? (int) round((float) $setting->default_annual_quota)
                    : ($this->default_annual_quota !== null ? (int) round((float) $this->default_annual_quota) : null);
            }
        }

        return $this->default_annual_quota !== null ? (int) round((float) $this->default_annual_quota) : null;
    }

    /**
     * Find a leave type by code, prioritizing tenant-specific over global defaults.
     * Automatically provisions standard system leave types (AL, DP, SL, SPL, UL) if not yet seeded.
     */
    public static function findByCode(string $code, ?int $companyId = null): ?self
    {
        $normalized = strtoupper(trim($code));

        $found = static::withoutGlobalScopes()
            ->where('code', $normalized)
            ->where(function (Builder $query) use ($companyId): void {
                $query->whereNull('company_id');
                if ($companyId !== null) {
                    $query->orWhere('company_id', $companyId);
                }
            })
            ->orderByRaw('CASE WHEN company_id IS NOT NULL THEN 0 ELSE 1 END')
            ->first();

        if ($found !== null) {
            return $found;
        }

        if (isset(self::STANDARD_TYPES[$normalized])) {
            return static::withoutGlobalScopes()->create(array_merge(
                ['company_id' => null],
                self::STANDARD_TYPES[$normalized]
            ));
        }

        return null;
    }
}
