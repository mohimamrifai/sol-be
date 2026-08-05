<?php

declare(strict_types=1);

namespace App\Enums;

enum CompanyStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Inactive => 'Tidak Aktif',
            self::Suspended => 'Ditangguhkan',
            self::Pending => 'Menunggu Persetujuan',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'bg-emerald-100 text-emerald-700 border-emerald-200',
            self::Inactive => 'bg-neutral-100 text-neutral-700 border-neutral-200',
            self::Suspended => 'bg-amber-100 text-amber-700 border-amber-200',
            self::Pending => 'bg-blue-100 text-blue-700 border-blue-200',
        };
    }
}
