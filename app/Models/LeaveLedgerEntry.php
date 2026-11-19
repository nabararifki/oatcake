<?php

namespace App\Models;

use App\Models\Concerns\RecordsAuditTrail;
use App\Traits\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LeaveLedgerEntry extends Model
{
    use BelongsToCompany, HasFactory, HasUuids, RecordsAuditTrail;

    public const TYPE_ACCRUAL = 'accrual';
    public const TYPE_CREDIT = 'credit';
    public const TYPE_DEBIT = 'debit';
    public const TYPE_EXPIRED = 'expired';
    public const TYPE_ADJUSTMENT = 'adjustment';

    /**
     * Valid ledger transaction types.
     *
     * @var list<string>
     */
    public const TRANSACTION_TYPES = [
        self::TYPE_ACCRUAL,
        self::TYPE_CREDIT,
        self::TYPE_DEBIT,
        self::TYPE_EXPIRED,
        self::TYPE_ADJUSTMENT,
    ];

    /**
     * Columns that are permitted to mutate after creation (FIFO remaining_amount depletion only).
     *
     * @var list<string>
     */
    public const MUTABLE_COLUMNS = [
        'remaining_amount',
        'updated_at',
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
        'transaction_type',
        'amount',
        'remaining_amount',
        'balance_after',
        'source_entry_id',
        'reference_type',
        'reference_id',
        'accrual_year',
        'expires_at',
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
            'amount' => 'integer',
            'remaining_amount' => 'integer',
            'balance_after' => 'integer',
            'accrual_year' => 'integer',
            'expires_at' => 'date',
        ];
    }

    /**
     * Strict Append-Only Immutability Guard.
     */
    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Leave ledger entries are strictly immutable and cannot be deleted.');
        });

        static::updating(function (self $entry): void {
            $disallowed = array_diff(array_keys($entry->getDirty()), self::MUTABLE_COLUMNS);

            if ($disallowed !== []) {
                throw new LogicException('Leave ledger entries are strictly immutable; only remaining_amount may be updated during FIFO depletion.');
            }
        });
    }

    /**
     * Set the expires_at attribute as a pure Y-m-d date string.
     */
    public function setExpiresAtAttribute($value): void
    {
        $this->attributes['expires_at'] = ! empty($value)
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
     * Get the employee associated with this ledger entry.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the leave type associated with this ledger entry.
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * Get the source credit/accrual entry if this is a debit or expired entry.
     */
    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_entry_id');
    }

    /**
     * Get the user who created this ledger entry.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
