<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPackage extends Model
{
    use HasFactory;

    protected $table = 'booking_packages';

    protected $fillable = [
        'booking_id',
        'sequence',
        'description',
        'length',
        'width',
        'height',
        'weight_kg',
        'volume_cbm',
        'piece_count',
        'package_type',
        'is_dangerous_goods',
        'dg_class_id',
        'un_number',
        'msds_file_path',
        'dg_notes',
    ];

    protected function casts(): array
    {
        return [
            'is_dangerous_goods' => 'boolean',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'weight_kg' => 'decimal:4',
            'volume_cbm' => 'decimal:4',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function dgClass(): BelongsTo
    {
        return $this->belongsTo(DgClass::class);
    }
}
