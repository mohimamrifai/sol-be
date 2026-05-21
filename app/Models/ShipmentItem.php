<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_id', 'name', 'description', 'quantity',
        'gross_weight', 'length', 'width', 'height', 'cbm',
        'is_fragile', 'is_stackable', 'placement_type',
        'container_id', 'rack_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'gross_weight' => 'decimal:2',
            'cbm' => 'decimal:4',
            'is_fragile' => 'boolean',
            'is_stackable' => 'boolean',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }
}
