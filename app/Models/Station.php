<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    protected $fillable = [
        'code',
        'name',
        'business_entity',
        'city',
        'province',
        'address',
        'status',
        'remark',
    ];

    public function yards(): HasMany
    {
        return $this->hasMany(Yard::class);
    }

    public function originRoutes(): HasMany
    {
        return $this->hasMany(Route::class, 'origin_station_id');
    }

    public function destinationRoutes(): HasMany
    {
        return $this->hasMany(Route::class, 'destination_station_id');
    }

    public function isInUse(): bool
    {
        return $this->originRoutes()->exists() || $this->destinationRoutes()->exists() || $this->yards()->exists();
    }
}
