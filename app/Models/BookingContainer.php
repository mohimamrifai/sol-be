<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingContainer extends Model
{
    use HasFactory;

    protected $table = 'booking_containers';

    protected $fillable = [
        'booking_id',
        'container_type_id',
        'sequence',
        'container_number',
        'seal_number',
        'gross_weight_kg',
        'volume_cbm',
        'equipment_condition',
        'temperature',
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
            'gross_weight_kg' => 'decimal:4',
            'volume_cbm' => 'decimal:4',
            'temperature' => 'decimal:2',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function containerType(): BelongsTo
    {
        return $this->belongsTo(ContainerType::class);
    }

    public function dgClass(): BelongsTo
    {
        return $this->belongsTo(DgClass::class);
    }
}
