<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ShipmentTrackingPhoto extends Model
{
    use HasFactory;

    protected $fillable = ['shipment_tracking_id', 'path', 'caption', 'is_public'];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    protected $appends = ['url'];

    public function tracking(): BelongsTo
    {
        return $this->belongsTo(ShipmentTracking::class, 'shipment_tracking_id');
    }

    /**
     * URL publik untuk menampilkan gambar (setelah php artisan storage:link).
     */
    public function getUrlAttribute(): string
    {
        return $this->path ? Storage::disk('public')->url($this->path) : '';
    }
}
