<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContainerType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'size', 'capacity_weight', 'capacity_cbm',
        'length', 'width', 'height', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capacity_weight' => 'decimal:2',
            'capacity_cbm' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
