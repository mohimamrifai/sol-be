<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends Model
{
    protected $fillable = [
        'code',
        'business_entity',
        'origin_station_id',
        'destination_station_id',
        'distance_km',
        'transit_days',
        'status',
        'remark',
        'service_types',
        'shipment_coverages',
    ];

    protected function casts(): array
    {
        return [
            'distance_km' => 'decimal:2',
            'service_types' => 'array',
            'shipment_coverages' => 'array',
        ];
    }

    public function originStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'origin_station_id');
    }

    public function destinationStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'destination_station_id');
    }

    public function trainSchedules(): HasMany
    {
        return $this->hasMany(TrainSchedule::class);
    }
}
