<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'booking_number', 'company_id', 'user_id',
        'origin_location_id', 'destination_location_id',
        'transport_mode_id', 'service_type_id',
        'container_type_id', 'container_count',
        'cargo_category_id', 'estimated_weight', 'estimated_cbm',
        'length', 'width', 'height',
        'cargo_description', 'departure_date',
        'is_dangerous_goods', 'dg_class_id', 'un_number', 'msds_file',
        'equipment_condition', 'temperature',
        'shipper_name', 'shipper_address', 'shipper_phone',
        'consignee_name', 'consignee_address', 'consignee_phone',
        'estimated_price', 'status',
        'rejection_reason', 'notes',
        'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'approved_at' => 'datetime',
            'estimated_weight' => 'decimal:2',
            'estimated_cbm' => 'decimal:2',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'estimated_price' => 'decimal:2',
            'container_count' => 'integer',
            'is_dangerous_goods' => 'boolean',
            'temperature' => 'decimal:2',
        ];
    }

    // ── Auto-generate booking number ──
    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            if (empty($booking->booking_number)) {
                $booking->booking_number = 'BK-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
            }
        });
    }

    // ── Relationships ──
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function originLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function transportMode(): BelongsTo
    {
        return $this->belongsTo(TransportMode::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function containerType(): BelongsTo
    {
        return $this->belongsTo(ContainerType::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cargoCategory(): BelongsTo
    {
        return $this->belongsTo(CargoCategory::class);
    }

    public function additionalServices(): BelongsToMany
    {
        return $this->belongsToMany(AdditionalService::class, 'booking_additional_service')
            ->withPivot(['notes', 'price'])
            ->withTimestamps();
    }

    public function additionalCharges(): BelongsToMany
    {
        return $this->belongsToMany(AdditionalCharge::class, 'booking_additional_charge')
            ->withPivot(['amount', 'is_auto_triggered'])
            ->withTimestamps();
    }

    public function dgClass(): BelongsTo
    {
        return $this->belongsTo(DgClass::class);
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }
}
