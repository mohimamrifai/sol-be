<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VendorJobOrderService;
use App\Enums\VendorJobOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorJobOrder extends Model
{
    protected $fillable = [
        'job_order_number', 'shipment_id', 'vendor_id', 'service_type', 'status',
        'pricing_id', 'vendor_rate', 'additional_cost', 'total_cost',
        'vendor_snapshot', 'shipment_snapshot',
        'pickup_address', 'pickup_date', 'pickup_time', 'pickup_cargo_info', 'pickup_remark',
        'delivery_address', 'delivery_date', 'delivery_time', 'delivery_cargo_info', 'delivery_remark',
        'origin_yard_id', 'destination_yard_id', 'train_id', 'departure_at',
        'vehicle_type', 'vehicle_plate', 'driver_name', 'driver_mobile', 'vehicle_remark',
        'sent_at', 'completed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'vendor_rate' => 'decimal:2',
            'additional_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'vendor_snapshot' => 'array',
            'shipment_snapshot' => 'array',
            'pickup_date' => 'datetime',
            'delivery_date' => 'datetime',
            'departure_at' => 'datetime',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'status' => VendorJobOrderStatus::class,
            'service_type' => VendorJobOrderService::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VendorJobOrder $jo) {
            if (empty($jo->job_order_number)) {
                $jo->job_order_number = self::generateNumber();
            }
            $jo->total_cost = (float) ($jo->vendor_rate ?? 0) + (float) ($jo->additional_cost ?? 0);
        });

        static::updating(function (VendorJobOrder $jo) {
            if ($jo->isDirty(['vendor_rate', 'additional_cost'])) {
                $jo->total_cost = (float) $jo->vendor_rate + (float) $jo->additional_cost;
            }
        });
    }

    public static function generateNumber(): string
    {
        $max = (int) (self::max('id') ?? 0) + 1;

        return 'JO-'.str_pad((string) $max, 5, '0', STR_PAD_LEFT);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function pricing(): BelongsTo
    {
        return $this->belongsTo(Pricing::class);
    }

    public function originYard(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_yard_id');
    }

    public function destinationYard(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_yard_id');
    }

    public function train(): BelongsTo
    {
        return $this->belongsTo(Train::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VendorJobOrderDocument::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(VendorJobOrderActivity::class);
    }

    public function vendorInvoices(): BelongsToMany
    {
        return $this->belongsToMany(VendorInvoice::class, 'vendor_invoice_job_orders')
            ->withPivot('amount')
            ->withTimestamps();
    }

    public function isEditable(): bool
    {
        return $this->status !== VendorJobOrderStatus::Completed
            && $this->status !== VendorJobOrderStatus::Cancelled;
    }
}
