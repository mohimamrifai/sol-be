<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Status values allowed in this system (spec L27: Draft, Submitted, Approved, Rejected).
     * `cancelled` and `confirmed` are kept here only as historical labels for
     * legacy data; new writes go through the migration that collapsed them.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    public const SHIPMENT_COVERAGES = [
        'port_to_port',
        'door_to_port',
        'port_to_door',
        'door_to_door',
    ];

    protected $fillable = [
        'booking_number', 'company_id', 'user_id',
        'origin_location_id', 'destination_location_id',
        'transport_mode_id', 'service_type_id', 'shipment_coverage',
        'container_type_id', 'container_count',
        'cargo_category_id', 'estimated_weight', 'estimated_cbm',
        'length', 'width', 'height',
        'total_volume_cbm', 'volume_weight_kg', 'chargeable_weight_kg',
        'cargo_description', 'departure_date',
        'is_dangerous_goods', 'dg_class_id', 'un_number', 'msds_file',
        'equipment_condition', 'temperature',
        'shipper_name', 'shipper_address', 'shipper_phone',
        'shipper_branch_id', 'shipper_snapshot',
        'consignee_type', 'consignee_branch_id', 'consignee_snapshot',
        'consignee_name', 'consignee_address', 'consignee_phone',
        'pickup_date', 'pickup_time', 'pickup_notes', 'delivery_notes',
        'container_responsibility',
        'confirmed_terms_at',
        'estimated_price', 'status', 'draft_expires_at',
        'rejection_reason', 'notes',
        'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'pickup_date' => 'date',
            'approved_at' => 'datetime',
            'draft_expires_at' => 'datetime',
            'confirmed_terms_at' => 'datetime',
            'estimated_weight' => 'decimal:2',
            'estimated_cbm' => 'decimal:2',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'total_volume_cbm' => 'decimal:4',
            'volume_weight_kg' => 'decimal:4',
            'chargeable_weight_kg' => 'decimal:4',
            'estimated_price' => 'decimal:2',
            'container_count' => 'integer',
            'is_dangerous_goods' => 'boolean',
            'temperature' => 'decimal:2',
            'shipper_snapshot' => 'array',
            'consignee_snapshot' => 'array',
        ];
    }

    // ── Auto-generate booking number ──
    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            if (empty($booking->booking_number)) {
                $booking->booking_number = 'BK-'.now()->format('Ymd').'-'.strtoupper(substr(uniqid(), -5));
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

    public function activities(): HasMany
    {
        return $this->hasMany(BookingActivity::class)->orderBy('occurred_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(BookingAttachment::class)->orderBy('created_at');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(BookingPackage::class)->orderBy('sequence');
    }

    public function containers(): HasMany
    {
        return $this->hasMany(BookingContainer::class)->orderBy('sequence');
    }

    // ── Domain helpers ──

    /**
     * True when the booking can still be edited by the customer.
     * Spec L9: only Draft is editable; Submitted is locked.
     */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT
            && ($this->draft_expires_at === null || $this->draft_expires_at->isFuture());
    }

    /**
     * True when the booking can still be cancelled.
     * Spec L10: Submitted is cancellable until internal processing begins.
     */
    public function isCancellable(): bool
    {
        if (! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SUBMITTED], true)) {
            return false;
        }

        // Once a shipment exists we no longer cancel; ops handles it.
        return ! $this->shipment()->exists();
    }

    /**
     * Record an activity row for the timeline / log (spec L70-83).
     */
    public function recordActivity(
        string $type,
        string $title,
        ?string $description = null,
        ?array $payload = null,
        ?User $actor = null,
        ?string $actorRole = null
    ): BookingActivity {
        return $this->activities()->create([
            'actor_id' => $actor?->id,
            'actor_role' => $actorRole ?? ($actor?->hasAnyRole(['super_admin', 'operations', 'finance', 'sales']) ? 'internal' : 'customer'),
            'activity_type' => $type,
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'occurred_at' => Carbon::now(),
        ]);
    }

    /**
     * Auto-calc CBM, volume weight, and chargeable weight (spec L13).
     *
     * Volume weight factor: 1 CBM ≈ 1000 kg (industry standard for LCL ocean).
     * Chargeable weight = max(weight, volume_weight).
     *
     * For FCL: chargeable uses the heavier of cargo gross weight or
     * volume weight (vendor services may have their own overrides; this
     * is the default the system will surface to the customer).
     */
    public function recalculateCargoMetrics(): void
    {
        $totalVolume = 0.0;
        $grossWeight = 0.0;

        $packages = $this->packages()->get(['volume_cbm', 'weight_kg']);
        if ($packages->isNotEmpty()) {
            $totalVolume = (float) $packages->sum(fn ($p) => (float) ($p->volume_cbm ?? 0));
            $grossWeight = (float) $packages->sum(fn ($p) => (float) ($p->weight_kg ?? 0));
        } else {
            $containers = $this->containers()->get(['volume_cbm', 'gross_weight_kg', 'quantity']);
            if ($containers->isNotEmpty()) {
                $totalVolume = (float) $containers->sum(
                    fn ($c) => ((float) ($c->volume_cbm ?? 0)) * ((int) ($c->quantity ?? 1))
                );
                $grossWeight = (float) $containers->sum(
                    fn ($c) => ((float) ($c->gross_weight_kg ?? 0)) * ((int) ($c->quantity ?? 1))
                );
            } else {
                $length = (float) ($this->length ?? 0);
                $width = (float) ($this->width ?? 0);
                $height = (float) ($this->height ?? 0);

                $totalVolume = $length > 0 && $width > 0 && $height > 0
                    ? round(($length * $width * $height) / 1_000_000, 4)
                    : (float) ($this->estimated_cbm ?? 0);
                $grossWeight = (float) ($this->estimated_weight ?? 0);
            }
        }

        $this->total_volume_cbm = round($totalVolume, 4);
        $this->volume_weight_kg = round(((float) $this->total_volume_cbm) * 1000, 4);
        $this->chargeable_weight_kg = round(max($grossWeight, (float) $this->volume_weight_kg), 4);
    }
}
