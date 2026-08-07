<?php

declare(strict_types=1);

namespace App\Enums;

enum VendorJobStatus: string
{
    case PendingAcceptance = 'pending_acceptance';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case WaitingVerification = 'waiting_verification';
    case Completed = 'completed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingAcceptance => 'Pending Acceptance',
            self::Accepted => 'Accepted',
            self::InProgress => 'In Progress',
            self::WaitingVerification => 'Waiting Internal Verification',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PendingAcceptance => 'bg-amber-100 text-amber-700 border-amber-200',
            self::Accepted => 'bg-blue-100 text-blue-700 border-blue-200',
            self::InProgress => 'bg-indigo-100 text-indigo-700 border-indigo-200',
            self::WaitingVerification => 'bg-purple-100 text-purple-700 border-purple-200',
            self::Completed => 'bg-emerald-100 text-emerald-700 border-emerald-200',
            self::Rejected => 'bg-red-100 text-red-700 border-red-200',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
