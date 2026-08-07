<?php

declare(strict_types=1);

namespace App\Enums;

enum VendorPaymentStatus: string
{
    case PendingPayment = 'pending_payment';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Pending Payment',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PendingPayment => 'bg-amber-100 text-amber-700 border-amber-200',
            self::PartiallyPaid => 'bg-blue-100 text-blue-700 border-blue-200',
            self::Paid => 'bg-emerald-100 text-emerald-700 border-emerald-200',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
