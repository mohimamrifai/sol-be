<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pricing extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_service_id', 'service_category', 'pricing_basis', 'container_type_id',
        'vehicle_type', 'price_type', 'price_per_kg', 'price_per_cbm', 'price_per_container',
        'unit_price', 'minimum_charge', 'min_kg', 'remark', 'effective_from', 'effective_to',
        'is_active', 'created_by_id', 'pricing_group_id',
    ];

    protected function casts(): array
    {
        return [
            'price_per_kg' => 'decimal:2',
            'price_per_cbm' => 'decimal:2',
            'price_per_container' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'minimum_charge' => 'decimal:2',
            'min_kg' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function vendorService(): BelongsTo
    {
        return $this->belongsTo(VendorService::class);
    }

    public function containerType(): BelongsTo
    {
        return $this->belongsTo(ContainerType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function pricingGroup(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pricing_group_id');
    }

    public function groupMembers(): HasMany
    {
        return $this->hasMany(self::class, 'pricing_group_id', 'pricing_group_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCurrentlyEffective($query)
    {
        $today = now()->toDateString();

        return $query->where(function ($q) use ($today) {
            $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today);
        })->where(function ($q) use ($today) {
            $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
        });
    }

    public function displayUnitPrice(): ?string
    {
        if ($this->unit_price !== null) {
            return (string) $this->unit_price;
        }

        if ($this->price_per_container > 0) {
            return (string) $this->price_per_container;
        }
        if ($this->price_per_kg > 0) {
            return (string) $this->price_per_kg;
        }
        if ($this->price_per_cbm > 0) {
            return (string) $this->price_per_cbm;
        }

        return null;
    }
}
