<?php

declare(strict_types=1);

namespace App\Enums;

enum VendorJobOrderService: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';
    case Rail = 'rail';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Pickup',
            self::Delivery => 'Delivery',
            self::Rail => 'Rail',
        };
    }
}
