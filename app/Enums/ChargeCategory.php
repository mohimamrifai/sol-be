<?php

declare(strict_types=1);

namespace App\Enums;

enum ChargeCategory: string
{
    case Handling = 'handling';
    case Storage = 'storage';
    case Documentation = 'documentation';
    case Container = 'container';
    case Trucking = 'trucking';
    case Rail = 'rail';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Handling => 'Handling',
            self::Storage => 'Storage',
            self::Documentation => 'Documentation',
            self::Container => 'Container',
            self::Trucking => 'Trucking',
            self::Rail => 'Rail',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
