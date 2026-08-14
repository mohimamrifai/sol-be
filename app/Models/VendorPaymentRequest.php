<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminVendorPaymentRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorPaymentRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vendor_invoice_id', 'payment_number', 'status', 'approval_status',
        'approved_by', 'approved_at', 'approval_remark', 'vendor_snapshot',
        'invoice_amount', 'approved_amount', 'paid_amount', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'vendor_snapshot' => 'array',
            'approved_at' => 'datetime',
            'status' => AdminVendorPaymentRequestStatus::class,
            'approval_status' => AdminVendorPaymentRequestStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VendorPaymentRequest $req) {
            if (empty($req->payment_number)) {
                $req->payment_number = self::generateNumber();
            }
        });
    }

    public static function generateNumber(): string
    {
        $max = (int) (self::withTrashed()->max('id') ?? 0) + 1;

        return 'VPAY-'.str_pad((string) $max, 6, '0', STR_PAD_LEFT);
    }

    public function vendorInvoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorPayment::class);
    }

    public function outstandingAmount(): float
    {
        return max((float) $this->approved_amount - (float) $this->paid_amount, 0);
    }
}
