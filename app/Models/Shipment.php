<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shipment extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Customer-facing status buckets (prompt.md L21-26).
     * Mapped from the 11 operational statuses in config/shipment.php.
     */
    public const HL_PLANNING = 'planning';

    public const HL_IN_PROGRESS = 'in_progress';

    public const HL_COMPLETED = 'completed';

    public const HL_CANCELLED = 'cancelled';

    public const HIGH_LEVEL_STATUSES = [
        self::HL_PLANNING,
        self::HL_IN_PROGRESS,
        self::HL_COMPLETED,
        self::HL_CANCELLED,
    ];

    private const PLANNING_STATUSES = ['created', 'booking_created', 'survey_completed'];

    private const IN_PROGRESS_STATUSES = [
        'cargo_received', 'stuffing_container', 'container_sealed',
        'departed', 'train_departed', 'arrived', 'train_arrived',
        'unloading', 'container_unloading', 'ready_for_pickup',
    ];

    protected $fillable = [
        'shipment_no', 'shipment_number', 'waybill_number', 'booking_id',
        'company_id', 'vendor_company_id', 'origin_location_id', 'destination_location_id',
        'transport_mode_id', 'service_type_id', 'cargo_category_id',
        'shipment_coverage', 'status', 'vendor_status',
        'accepted_at', 'completion_submitted_at', 'completion_verified_at',
        'completion_remark', 'vendor_rejection_reason',
        'estimated_departure', 'estimated_arrival',
        'actual_departure', 'actual_arrival',
        'is_dangerous_goods', 'dg_class_id', 'un_number', 'msds_file',
        'equipment_condition', 'temperature',
        'notes', 'cancelled_reason', 'created_by',
        'shipper_snapshot', 'consignee_snapshot',
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
            'shipper_snapshot' => 'array',
            'consignee_snapshot' => 'array',
            'accepted_at' => 'datetime',
            'completion_submitted_at' => 'datetime',
            'completion_verified_at' => 'datetime',
        ];
    }

    protected $appends = ['display_number', 'high_level_status'];

    // ── Auto-generate shipment & waybill numbers ──
    protected static function booted(): void
    {
        static::creating(function (Shipment $shipment) {
            if (empty($shipment->shipment_no)) {
                $shipment->shipment_no = (int) (self::max('shipment_no') ?? 0) + 1;
            }
            if (empty($shipment->shipment_number)) {
                $shipment->shipment_number = self::formatShipmentNo((int) $shipment->shipment_no);
            }
            if (empty($shipment->waybill_number)) {
                $shipment->waybill_number = 'CN-'.now()->format('Ymd').'-'.strtoupper(substr(uniqid(), -5));
            }
        });
    }

    public static function formatShipmentNo(int $n): string
    {
        return 'SHP'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    public function getDisplayNumberAttribute(): string
    {
        return self::formatShipmentNo((int) ($this->shipment_no ?? 0));
    }

    /**
     * Map the operational status to one of 4 customer buckets.
     */
    public function getHighLevelStatusAttribute(): string
    {
        $raw = (string) ($this->status ?? '');
        $key = strtolower(trim($raw));

        if ($key === self::HL_CANCELLED) {
            return self::HL_CANCELLED;
        }
        if ($key === self::HL_COMPLETED) {
            return self::HL_COMPLETED;
        }
        if (in_array($key, self::PLANNING_STATUSES, true)) {
            return self::HL_PLANNING;
        }
        if (in_array($key, self::IN_PROGRESS_STATUSES, true)) {
            return self::HL_IN_PROGRESS;
        }

        return self::HL_PLANNING;
    }

    // ── Relationships ──
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function vendorCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'vendor_company_id');
    }

    public function vendorInvoice(): HasOne
    {
        return $this->hasOne(VendorInvoice::class, 'shipment_id');
    }

    public function progressUpdates(): HasMany
    {
        return $this->hasMany(VendorProgressUpdate::class)->orderByDesc('submitted_at');
    }

    public function isVendorJobOrder(): bool
    {
        return $this->vendor_company_id !== null;
    }

    public function scopeForVendor($query, int $vendorCompanyId)
    {
        return $query->where('vendor_company_id', $vendorCompanyId);
    }

    public function scopeVendorJobOrders($query)
    {
        return $query->whereNotNull('vendor_company_id');
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
