<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveRequest extends Model
{
    use BelongsToCompany, HasFactory, HasUuids;

    public const STATUS_PENDING_MANAGER = 'pending_manager';
    public const STATUS_PENDING_HR = 'pending_hr';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Valid leave request lifecycle statuses.
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING_MANAGER,
        self::STATUS_PENDING_HR,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
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
        'leave_type_id',
        'start_date',
        'end_date',
        'total_days',
        'reason',
        'attachment_path',
        'status',
        'smart_score',
        'smart_metrics',
        'ledger_entry_id',
        'manager_approved_by',
        'manager_approved_at',
        'hr_approved_by',
        'hr_approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'cancelled_at',
        'cancellation_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'total_days' => 'decimal:2',
            'smart_score' => 'decimal:2',
            'smart_metrics' => 'array',
            'manager_approved_at' => 'datetime',
            'hr_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Normalize start_date to a pure Y-m-d string in Asia/Jakarta.
     */
    public function setStartDateAttribute($value): void
    {
        $this->attributes['start_date'] = ! empty($value)
            ? Carbon::parse($value, 'Asia/Jakarta')->toDateString()
            : null;
    }

    /**
     * Normalize end_date to a pure Y-m-d string in Asia/Jakarta.
     */
    public function setEndDateAttribute($value): void
    {
        $this->attributes['end_date'] = ! empty($value)
            ? Carbon::parse($value, 'Asia/Jakarta')->toDateString()
            : null;
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
     * Resolve route model binding by numeric ID or UUID without global scopes so policy checks return 403 on cross-tenant access.
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
     * Get the company that owns the leave request.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the employee who submitted the leave request.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the leave type of the request.
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * Get the multi-tier approval and rejection audit trail records.
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(LeaveRequestApproval::class);
    }

    /**
     * Get the ledger debit entry associated with this approved leave request.
     */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LeaveLedgerEntry::class, 'ledger_entry_id');
    }

    /**
     * Get the manager who approved Tier 1.
     */
    public function managerApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_approved_by');
    }

    /**
     * Get the HR/Admin user who approved Tier 2.
     */
    public function hrApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_approved_by');
    }

    /**
     * Get the user who rejected the request.
     */
    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Determine whether the leave request is currently pending approval.
     */
    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING_MANAGER, self::STATUS_PENDING_HR], true);
    }

    /**
     * Determine whether the leave request can be cancelled by the applicant or admin
     * (allowed for pending_manager, pending_hr, or approved).
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_MANAGER,
            self::STATUS_PENDING_HR,
            self::STATUS_APPROVED,
        ], true);
    }

    /**
     * Determine whether the leave request can be edited by the applicant
     * (allowed for pending_manager, pending_hr, or approved).
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_MANAGER,
            self::STATUS_PENDING_HR,
            self::STATUS_APPROVED,
        ], true);
    }

    /**
     * Determine whether the leave request can be approved at Tier 1 (Manager).
     */
    public function canBeApprovedTier1(): bool
    {
        return $this->status === self::STATUS_PENDING_MANAGER;
    }

    /**
     * Determine whether the leave request can be approved at Tier 2 (Final HR/Admin).
     */
    public function canBeApprovedTier2(): bool
    {
        return $this->status === self::STATUS_PENDING_HR;
    }

    /**
     * Determine whether the leave request can be rejected.
     */
    public function canBeRejected(): bool
    {
        return $this->isPending();
    }

    /**
     * Determine whether the leave request has a private attachment file path.
     */
    public function hasAttachment(): bool
    {
        return ! empty($this->attachment_path);
    }
}
