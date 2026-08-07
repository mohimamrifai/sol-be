<?php

declare(strict_types=1);

namespace App\Enums;

enum VendorInvoiceStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Paid => 'Paid',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-neutral-100 text-neutral-700 border-neutral-200',
            self::Submitted => 'bg-blue-100 text-blue-700 border-blue-200',
            self::Approved => 'bg-emerald-100 text-emerald-700 border-emerald-200',
            self::Rejected => 'bg-red-100 text-red-700 border-red-200',
            self::Paid => 'bg-indigo-100 text-indigo-700 border-indigo-200',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
