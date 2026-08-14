<?php

declare(strict_types=1);

namespace App\Enums;

enum PodStatus: string
{
    case WaitingPod = 'waiting_pod';
    case Received = 'received';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::WaitingPod => 'Waiting POD',
            self::Received => 'POD Received',
            self::Verified => 'POD Verified',
            self::Rejected => 'Rejected',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
