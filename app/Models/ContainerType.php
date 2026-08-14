<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContainerType extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'size', 'category', 'iso_code', 'capacity_weight', 'capacity_cbm',
        'length', 'width', 'height', 'is_active', 'remark',
    ];

    protected static function booted(): void
    {
        static::creating(function (ContainerType $type) {
            if (empty($type->code)) {
                $type->code = self::generateCode();
            }
        });
    }

    public static function generateCode(): string
    {
        $next = self::count() + 1;

        return 'CT'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

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
