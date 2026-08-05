<?php

declare(strict_types=1);

namespace App\Enums;

enum LocationType: string
{
    case HeadOffice = 'head_office';
    case BranchOffice = 'branch_office';
    case Warehouse = 'warehouse';

    public function label(): string
    {
        return match ($this) {
            self::HeadOffice => 'Head Office',
            self::BranchOffice => 'Branch Office',
            self::Warehouse => 'Warehouse',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::HeadOffice => 'bg-indigo-100 text-indigo-700 border-indigo-200',
            self::BranchOffice => 'bg-blue-100 text-blue-700 border-blue-200',
            self::Warehouse => 'bg-amber-100 text-amber-700 border-amber-200',
        };
    }
}
