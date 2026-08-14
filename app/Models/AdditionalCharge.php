<?php

namespace App\Models;

use App\Enums\ChargeCategory;
use App\Enums\PricingBasis;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdditionalCharge extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'charge_category',
        'pricing_basis',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'charge_category' => ChargeCategory::class,
            'pricing_basis' => PricingBasis::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AdditionalCharge $charge) {
            if (empty($charge->code)) {
                $charge->code = self::generateCode();
            }
        });
    }

    public static function generateCode(): string
    {
        $next = (int) (self::max('id') ?? 0) + 1;

        return 'ADC'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
