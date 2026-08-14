<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerMovement extends Model
{
    protected $fillable = [
        'container_asset_id',
        'shipment_id',
        'activity',
        'location_from',
        'location_to',
        'yard_id',
        'created_by_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function containerAsset(): BelongsTo
    {
        return $this->belongsTo(ContainerAsset::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function yard(): BelongsTo
    {
        return $this->belongsTo(Yard::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
