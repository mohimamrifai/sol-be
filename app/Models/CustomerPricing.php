<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerPricing extends Model
{
    protected $fillable = [
        'company_id',
        'origin_location_id',
        'destination_location_id',
        'cargo_category_id',
        'service_type',
        'shipment_coverage',
        'pricing_basis',
        'rate',
        'minimum_charge',
        'currency',
        'container_type_id',
        'status',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'minimum_charge' => 'decimal:2',
        ];
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

    public function cargoCategory(): BelongsTo
    {
        return $this->belongsTo(CargoCategory::class);
    }

    public function containerType(): BelongsTo
    {
        return $this->belongsTo(ContainerType::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(CustomerPricingCharge::class);
    }
}
