<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'code',
        'name',
        'address',
        'latitude',
        'longitude',
        'geofence_radius_meters',
        'geofence_radius',
        'timezone',
        'allowed_email_domain',
        'logo_url',
        'accent_color',
        'photo_retention_months',
        'storage_limit_mb',
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
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geofence_radius_meters' => 'integer',
            'geofence_radius' => 'integer',
            'photo_retention_months' => 'integer',
            'storage_limit_mb' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Synchronize geofence_radius and geofence_radius_meters on save.
     */
    protected static function booted(): void
    {
        static::saving(function (Company $company): void {
            if ($company->isDirty('geofence_radius') && ! $company->isDirty('geofence_radius_meters')) {
                $company->attributes['geofence_radius_meters'] = (int) $company->attributes['geofence_radius'];
            } elseif ($company->isDirty('geofence_radius_meters') && ! $company->isDirty('geofence_radius')) {
                $company->attributes['geofence_radius'] = (int) $company->attributes['geofence_radius_meters'];
            }

            if (empty($company->attributes['timezone'])) {
                $company->attributes['timezone'] = 'Asia/Jakarta';
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
     * Get the users for the company.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the departments for the company.
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * Get the employees for the company.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Get the tags for the company.
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * Get the master shifts for the company.
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /**
     * Get the work schedules for the company.
     */
    public function workSchedules(): HasMany
    {
        return $this->hasMany(WorkSchedule::class);
    }

    /**
     * Get the attendance records for the company.
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Get the company-specific public holidays.
     */
    public function publicHolidays(): HasMany
    {
        return $this->hasMany(PublicHoliday::class);
    }

    /**
     * Accessor alias for geofence_radius.
     */
    public function getGeofenceRadiusAttribute(): int
    {
        return (int) ($this->attributes['geofence_radius'] ?? $this->attributes['geofence_radius_meters'] ?? 100);
    }

    /**
     * Mutator alias for geofence_radius.
     */
    public function setGeofenceRadiusAttribute($value): void
    {
        $intVal = (int) $value;
        $this->attributes['geofence_radius'] = $intVal;
        $this->attributes['geofence_radius_meters'] = $intVal;
    }

    /**
     * Accessor for geofence_radius_meters.
     */
    public function getGeofenceRadiusMetersAttribute(): int
    {
        return (int) ($this->attributes['geofence_radius_meters'] ?? $this->attributes['geofence_radius'] ?? 100);
    }

    /**
     * Mutator for geofence_radius_meters.
     */
    public function setGeofenceRadiusMetersAttribute($value): void
    {
        $intVal = (int) $value;
        $this->attributes['geofence_radius_meters'] = $intVal;
        $this->attributes['geofence_radius'] = $intVal;
    }

    /**
     * Calculate total physical storage usage in bytes for attendance proof photos.
     */
    public function getStorageUsageBytes(): int
    {
        $dir = "attendance_proofs/{$this->id}";
        if (! Storage::disk('local')->exists($dir)) {
            return 0;
        }

        $totalBytes = 0;
        foreach (Storage::disk('local')->allFiles($dir) as $file) {
            $totalBytes += (int) Storage::disk('local')->size($file);
        }

        return $totalBytes;
    }

    /**
     * Calculate total storage usage in Megabytes.
     */
    public function getStorageUsageMb(): float
    {
        return round($this->getStorageUsageBytes() / 1048576, 2);
    }

    /**
     * Check if adding incoming bytes would exceed the company's storage limit.
     */
    public function isStorageLimitExceeded(int $incomingBytes = 0): bool
    {
        $limitBytes = (int) ($this->storage_limit_mb ?? 1024) * 1048576;

        return ($this->getStorageUsageBytes() + $incomingBytes) > $limitBytes;
    }
}
