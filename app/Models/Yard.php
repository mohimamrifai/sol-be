<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Yard extends Model
{
    protected $fillable = [
        'code',
        'name',
        'business_entity',
        'station_id',
        'yard_type',
        'status',
        'remark',
        'country',
        'province',
        'city',
        'district',
        'postal_code',
        'address',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function containerAssets(): HasMany
    {
        return $this->hasMany(ContainerAsset::class, 'current_yard_id');
    }

    public function isInUse(): bool
    {
        return $this->containerAssets()->exists();
    }
}
