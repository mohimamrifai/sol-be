<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VendorPaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorPayment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'vendor_invoice_id',
        'vendor_payment_request_id',
        'payment_number',
        'amount',
        'payment_date',
        'payment_method',
        'company_bank',
        'reference_no',
        'status',
        'receipt_path',
        'transfer_receipt_path',
        'payment_proof_path',
        'withholding_tax_path',
        'paid_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'status' => VendorPaymentStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VendorPayment $payment) {
            if (empty($payment->payment_number)) {
                $payment->payment_number = self::generatePaymentNumber();
            }
        });
    }

    public static function generatePaymentNumber(): string
    {
        $max = (int) (self::withTrashed()->max('id') ?? 0) + 1;

        return 'PAY-V-'.str_pad((string) $max, 6, '0', STR_PAD_LEFT);
    }

    public function vendorInvoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class);
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(VendorPaymentRequest::class, 'vendor_payment_request_id');
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(CompanyActivity::class, 'subject')->orderByDesc('occurred_at');
    }
}
