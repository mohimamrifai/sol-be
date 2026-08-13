<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContainerAsset extends Model
{
    protected $fillable = [
        'container_number',
        'container_type_id',
        'ownership',
        'vendor_id',
        'current_yard_id',
        'status',
        'max_payload_kg',
        'max_capacity_cbm',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'max_payload_kg' => 'decimal:2',
            'max_capacity_cbm' => 'decimal:2',
        ];
    }

    public function containerType(): BelongsTo
    {
        return $this->belongsTo(ContainerType::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function currentYard(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_yard_id');
    }

    public function shipmentContainers(): HasMany
    {
        return $this->hasMany(Container::class, 'container_asset_id');
    }
}
