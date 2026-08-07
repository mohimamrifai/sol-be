<?php

declare(strict_types=1);

namespace App\Enums;

enum VendorUserRole: string
{
    case VendorCompanyAdmin = 'vendor_company_admin';
    case VendorOpsPic = 'vendor_ops_pic';
    case VendorFinancePic = 'vendor_finance_pic';
    case VendorViewer = 'vendor_viewer';

    public function label(): string
    {
        return match ($this) {
            self::VendorCompanyAdmin => 'Company Admin',
            self::VendorOpsPic => 'Operational PIC',
            self::VendorFinancePic => 'Finance PIC',
            self::VendorViewer => 'Viewer',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::VendorCompanyAdmin => 'bg-indigo-100 text-indigo-700 border-indigo-200',
            self::VendorOpsPic => 'bg-blue-100 text-blue-700 border-blue-200',
            self::VendorFinancePic => 'bg-emerald-100 text-emerald-700 border-emerald-200',
            self::VendorViewer => 'bg-neutral-100 text-neutral-700 border-neutral-200',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
