<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * Valid outside duty attendance categories.
     *
     * @var list<string>
     */
    public const OUTSIDE_CATEGORIES = [
        'client_visit',
        'airport_pickup',
        'procurement',
        'event',
        'other',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'company_id',
        'employee_id',
        'work_schedule_id',
        'clock_in_at',
        'clock_out_at',
        'clock_in_latitude',
        'clock_in_longitude',
        'clock_out_latitude',
        'clock_out_longitude',
        'clock_in_distance_meters',
        'clock_out_distance_meters',
        'status',
        'late_minutes',
        'early_leave_minutes',
        'is_outside_geofence',
        'is_provisional_dp',
        'proof_photo_path',
        'clock_in_photo_path',
        'clock_out_photo_path',
        'outside_category',
        'outside_location_name',
        'verified_by',
        'verified_at',
        'rejection_reason',
        'device_info',
        'ip_address',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'verified_at' => 'datetime',
            'clock_in_latitude' => 'decimal:7',
            'clock_in_longitude' => 'decimal:7',
            'clock_out_latitude' => 'decimal:7',
            'clock_out_longitude' => 'decimal:7',
            'clock_in_distance_meters' => 'integer',
            'clock_out_distance_meters' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'is_outside_geofence' => 'boolean',
            'is_provisional_dp' => 'boolean',
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
     * Get the company that owns the attendance record.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the employee associated with the attendance record.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the work schedule associated with the attendance record.
     */
    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    /**
     * Get the manager/admin user who verified this attendance record.
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Determine if the attendance record was clocked in on time.
     */
    public function isOnTime(): bool
    {
        return $this->status === 'on_time';
    }

    /**
     * Determine if the attendance record was clocked in late.
     */
    public function isLate(): bool
    {
        return $this->status === 'late' || ((int) $this->late_minutes > 0);
    }

    /**
     * Determine if the attendance record is pending manager verification.
     */
    public function isPendingVerification(): bool
    {
        return $this->status === 'pending_verification';
    }

    /**
     * Determine if the attendance record has been approved/verified.
     */
    public function isApproved(): bool
    {
        return in_array($this->status, ['on_time', 'late', 'early_leave'], true);
    }

    /**
     * Determine if the attendance record was rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Calculate the actual work duration in hours between clock_in_at and clock_out_at.
     */
    public function getWorkDurationInHours(): float
    {
        if ($this->clock_in_at === null || $this->clock_out_at === null) {
            return 0.0;
        }

        $clockIn = Carbon::parse($this->clock_in_at)->timezone('Asia/Jakarta');
        $clockOut = Carbon::parse($this->clock_out_at)->timezone('Asia/Jakarta');

        if ($clockOut->lessThanOrEqualTo($clockIn)) {
            return 0.0;
        }

        return round($clockIn->diffInMinutes($clockOut) / 60, 2);
    }
}
