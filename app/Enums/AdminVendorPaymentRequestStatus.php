<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminVendorPaymentRequestStatus: string
{
    case WaitingApproval = 'waiting_approval';
    case ReadyToPay = 'ready_to_pay';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::WaitingApproval => 'Waiting Approval',
            self::ReadyToPay => 'Ready to Pay',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
