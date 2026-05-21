<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shipment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'shipment_number', 'waybill_number', 'booking_id',
        'company_id', 'origin_location_id', 'destination_location_id',
        'transport_mode_id', 'service_type_id', 'cargo_category_id',
        'status',
        'estimated_departure', 'estimated_arrival',
        'actual_departure', 'actual_arrival',
        'is_dangerous_goods', 'dg_class_id', 'un_number', 'msds_file',
        'equipment_condition', 'temperature',
        'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'estimated_departure' => 'date',
            'estimated_arrival' => 'date',
            'actual_departure' => 'date',
            'actual_arrival' => 'date',
            'is_dangerous_goods' => 'boolean',
            'temperature' => 'decimal:2',
        ];
    }

    // ── Auto-generate shipment & waybill numbers ──
    protected static function booted(): void
    {
        static::creating(function (Shipment $shipment) {
            if (empty($shipment->shipment_number)) {
                $shipment->shipment_number = 'SH-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
            }
            if (empty($shipment->waybill_number)) {
                $shipment->waybill_number = 'CN-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
            }
        });
    }

    // ── Relationships ──
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function containers(): HasMany
    {
        return $this->hasMany(Container::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function trackings(): HasMany
    {
        return $this->hasMany(ShipmentTracking::class)->orderBy('tracked_at', 'desc');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function additionalCharges()
    {
        return $this->belongsToMany(AdditionalCharge::class, 'shipment_additional_charge')
            ->withPivot(['amount', 'is_auto_triggered'])
            ->withTimestamps();
    }

    public function cargoCategory(): BelongsTo
    {
        return $this->belongsTo(CargoCategory::class);
    }

    public function dgClass(): BelongsTo
    {
        return $this->belongsTo(DgClass::class);
    }

    public function latestTracking()
    {
        return $this->hasOne(ShipmentTracking::class)->latestOfMany('tracked_at');
    }
}
