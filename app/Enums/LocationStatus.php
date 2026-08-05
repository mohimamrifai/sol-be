<?php

declare(strict_types=1);

namespace App\Enums;

enum LocationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Inactive => 'Tidak Aktif',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'bg-emerald-100 text-emerald-700 border-emerald-200',
            self::Inactive => 'bg-neutral-100 text-neutral-700 border-neutral-200',
        };
    }
}
