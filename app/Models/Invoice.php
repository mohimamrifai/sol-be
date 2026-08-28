<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'invoice_number', 'shipment_id', 'company_id',
        'subtotal', 'tax_amount', 'total_amount',
        'issued_date', 'due_date', 'status',
        'notes', 'created_by',
        'company_snapshot', 'shipment_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'issued_date' => 'date',
            'due_date' => 'date',
            'company_snapshot' => 'array',
            'shipment_snapshot' => 'array',
        ];
    }

    // ── Auto-generate invoice number ──
    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = 'INV-'.now()->format('Ymd').'-'.strtoupper(substr(uniqid(), -5));
            }
        });
    }

    // ── Relationships ──
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(InvoiceActivity::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(InvoiceDocument::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    // ── Helpers ──
    public function isOverdue(): bool
    {
        return $this->due_date !== null && $this->due_date->isPast();
    }

    public function paidAmount(): float
    {
        $sum = $this->payments()
            ->whereIn('status', ['success', 'settlement'])
            ->sum('amount');

        return (float) $sum;
    }

    public function outstandingAmount(): float
    {
        return max((float) $this->total_amount - $this->paidAmount(), 0);
    }

    /**
     * Invoice statuses that represent an open/unpaid issued invoice (legacy + current).
     *
     * @return list<string>
     */
    public static function openIssuedStatuses(): array
    {
        return ['issued', 'unpaid'];
    }

    public function assignOpenIssuedStatus(): void
    {
        $status = DB::connection()->getDriverName() === 'sqlite'
            ? 'unpaid'
            : 'issued';
        $this->update(['status' => $status]);
    }

    public function syncStatusFromPayments(?int $actorUserId = null): void
    {
        if ($this->status === 'cancelled') {
            return;
        }

        if ($this->status === 'draft') {
            return;
        }

        $outstanding = $this->outstandingAmount();
        $paid = $this->paidAmount();

        $nextStatus = $outstanding <= 0
            ? 'paid'
            : ($paid > 0 ? 'partially_paid' : 'issued');
        $previousStatus = (string) $this->status;

        if ($previousStatus !== $nextStatus) {
            $this->update(['status' => $nextStatus]);
            $statusLabel = match ($nextStatus) {
                'partially_paid' => 'Partially Paid',
                'paid' => 'Paid',
                'issued' => 'Issued',
                default => str_replace('_', ' ', ucfirst($nextStatus)),
            };
            InvoiceActivity::create([
                'invoice_id' => $this->id,
                'actor_user_id' => $actorUserId,
                'event_key' => 'status_changed',
                'description' => "Status menjadi {$statusLabel}.",
                'meta' => ['from' => $previousStatus, 'to' => $nextStatus],
                'occurred_at' => now(),
            ]);
        }
    }
}
