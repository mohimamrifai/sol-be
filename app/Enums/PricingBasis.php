<?php

declare(strict_types=1);

namespace App\Enums;

enum PricingBasis: string
{
    case PerShipment = 'per_shipment';
    case PerContainer = 'per_container';
    case PerTrip = 'per_trip';
    case PerTon = 'per_ton';
    case PerKg = 'per_kg';
    case PerCbm = 'per_cbm';
    case PerDay = 'per_day';
    case PerHour = 'per_hour';
    case PerSeal = 'per_seal';
    case PerDocument = 'per_document';

    public function label(): string
    {
        return match ($this) {
            self::PerShipment => 'Per Shipment',
            self::PerContainer => 'Per Container',
            self::PerTrip => 'Per Trip',
            self::PerTon => 'Per Ton',
            self::PerKg => 'Per Kg',
            self::PerCbm => 'Per CBM',
            self::PerDay => 'Per Day',
            self::PerHour => 'Per Hour',
            self::PerSeal => 'Per Seal',
            self::PerDocument => 'Per Document',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
