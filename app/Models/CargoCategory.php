<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CargoCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'pricing_multiplier',
        'is_active',
        'requires_temperature',
        'is_project_cargo',
        'is_liquid',
        'is_food',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pricing_multiplier' => 'decimal:4',
            'requires_temperature' => 'boolean',
            'is_project_cargo' => 'boolean',
            'is_liquid' => 'boolean',
            'is_food' => 'boolean',
        ];
    }
}
