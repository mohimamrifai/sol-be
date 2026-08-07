<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VendorInvoiceStatus;
use App\Enums\VendorPaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'vendor_company_id',
        'shipment_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'invoice_amount',
        'tax_amount',
        'total_amount',
        'status',
        'notes',
        'file_path',
        'tax_invoice_path',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'submitted_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'invoice_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'status' => VendorInvoiceStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VendorInvoice $invoice) {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = self::generateInvoiceNumber();
            }
            if (empty($invoice->total_amount) && $invoice->invoice_amount) {
                $invoice->total_amount = (float) $invoice->invoice_amount + (float) ($invoice->tax_amount ?? 0);
            }
        });
    }

    public static function generateInvoiceNumber(): string
    {
        $max = (int) (self::withTrashed()->max('id') ?? 0) + 1;

        return 'INV-V-'.str_pad((string) $max, 6, '0', STR_PAD_LEFT);
    }

    public function vendorCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'vendor_company_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorPayment::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(VendorPayment::class)->latestOfMany('payment_date');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(VendorInvoiceAttachment::class);
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(CompanyActivity::class, 'subject')->orderByDesc('occurred_at');
    }

    public function paidAmount(): float
    {
        return (float) $this->payments()
            ->where('status', VendorPaymentStatus::Paid->value)
            ->sum('amount');
    }

    public function outstandingAmount(): float
    {
        return max((float) $this->total_amount - $this->paidAmount(), 0);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [VendorInvoiceStatus::Draft, VendorInvoiceStatus::Rejected], true);
    }

    public function isSubmittable(): bool
    {
        return in_array($this->status, [VendorInvoiceStatus::Draft, VendorInvoiceStatus::Rejected], true)
            && ! empty($this->file_path);
    }
}
