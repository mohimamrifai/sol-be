<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainCar extends Model
{
    use HasFactory;

    protected $fillable = [
        'train_id',
        'name',
        'code',
        'capacity_weight',
        'capacity_cbm',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'capacity_weight' => 'float',
            'capacity_cbm' => 'float',
        ];
    }

    public function train(): BelongsTo
    {
        return $this->belongsTo(Train::class);
    }
}

