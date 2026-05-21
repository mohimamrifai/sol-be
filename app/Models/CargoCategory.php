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
            'requires_temperature' => 'boolean',
            'is_project_cargo' => 'boolean',
            'is_liquid' => 'boolean',
            'is_food' => 'boolean',
        ];
    }
}
