<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Container extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_id', 'container_type_id', 'container_number', 'seal_number',
        'ownership', 'assignment_status', 'container_asset_id', 'slot_sequence', 'remark',
    ];

    public function containerAsset(): BelongsTo
    {
        return $this->belongsTo(ContainerAsset::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function containerType(): BelongsTo
    {
        return $this->belongsTo(ContainerType::class);
    }

    public function racks(): HasMany
    {
        return $this->hasMany(Rack::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }
}
