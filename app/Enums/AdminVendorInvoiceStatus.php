<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminVendorInvoiceStatus: string
{
    case Received = 'received';
    case UnderVerification = 'under_verification';
    case ReadyForPayment = 'ready_for_payment';
    case Paid = 'paid';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::UnderVerification => 'Under Verification',
            self::ReadyForPayment => 'Ready for Payment',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
