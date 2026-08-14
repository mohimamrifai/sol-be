<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerMaintenance extends Model
{
    protected $fillable = [
        'container_asset_id',
        'maintenance_type',
        'vendor_id',
        'remark',
        'status',
        'maintenance_date',
    ];

    protected function casts(): array
    {
        return [
            'maintenance_date' => 'date',
        ];
    }

    public function containerAsset(): BelongsTo
    {
        return $this->belongsTo(ContainerAsset::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
