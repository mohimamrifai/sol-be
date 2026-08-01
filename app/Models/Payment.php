<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_SETTLEMENT = 'settlement';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    public const METHOD_MIDTRANS = 'midtrans';
    public const METHOD_TRANSFER = 'transfer';
    public const METHOD_GIRO = 'giro';
    public const METHOD_CASH = 'cash';
    public const METHOD_VIRTUAL_ACCOUNT = 'virtual_account';

    public const MANUAL_UNSUBMITTED = 'unsubmitted';
    public const MANUAL_SUBMITTED = 'submitted';
    public const MANUAL_VERIFIED = 'verified';
    public const MANUAL_REJECTED = 'rejected';

    protected $fillable = [
        'invoice_id', 'midtrans_transaction_id', 'midtrans_order_id',
        'amount', 'payment_type', 'status',
        'midtrans_response', 'paid_at',
        'payment_number', 'expired_at', 'method',
        'manual_status', 'manual_payment_date', 'manual_bank_name',
        'manual_reference_number', 'manual_remark', 'manual_submitted_at',
        'manual_verified_by', 'manual_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'midtrans_response' => 'array',
            'paid_at' => 'datetime',
            'expired_at' => 'datetime',
            'manual_payment_date' => 'date',
            'manual_submitted_at' => 'datetime',
            'manual_verified_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(PaymentActivity::class)->orderBy('occurred_at');
    }

    public function proofAttachments(): HasMany
    {
        return $this->hasMany(PaymentProofAttachment::class);
    }

    public function isSuccess(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCESS, self::STATUS_SETTLEMENT], true);
    }

    public function isExpired(): bool
    {
        if ($this->expired_at && $this->expired_at->isPast()) {
            return true;
        }

        return in_array($this->status, [self::STATUS_EXPIRED, self::STATUS_CANCELLED], true);
    }

    public function displayNumber(): string
    {
        if (! $this->payment_number) {
            return $this->midtrans_order_id ?? 'PAY-'.$this->id;
        }

        return 'PAY'.str_pad((string) $this->payment_number, 6, '0', STR_PAD_LEFT);
    }

    public function markManualSubmitted(): void
    {
        $this->forceFill([
            'manual_status' => self::MANUAL_SUBMITTED,
            'manual_submitted_at' => now(),
        ])->save();
    }

    public function markManualVerified(?User $actor = null): void
    {
        $this->forceFill([
            'manual_status' => self::MANUAL_VERIFIED,
            'manual_verified_at' => now(),
            'manual_verified_by' => $actor?->id,
        ])->save();
    }
}
