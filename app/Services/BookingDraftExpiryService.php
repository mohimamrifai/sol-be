<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Booking;
use App\Support\SystemConfig;
use Illuminate\Support\Carbon;

final class BookingDraftExpiryService
{
    public static function isExpired(Booking $booking): bool
    {
        return $booking->status === Booking::STATUS_DRAFT
            && $booking->draft_expires_at instanceof Carbon
            && $booking->draft_expires_at->isPast();
    }

    public static function touchDraftExpiry(Booking $booking): void
    {
        if ($booking->status !== Booking::STATUS_DRAFT) {
            return;
        }

        $booking->forceFill([
            'draft_expires_at' => SystemConfig::draftExpiresAt(),
        ])->save();
    }

    public static function expireDueDrafts(): int
    {
        $expired = 0;

        Booking::query()
            ->where('status', Booking::STATUS_DRAFT)
            ->whereNotNull('draft_expires_at')
            ->where('draft_expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use (&$expired) {
                foreach ($bookings as $booking) {
                    $booking->update(['status' => 'cancelled']);
                    if (method_exists($booking, 'recordActivity')) {
                        $booking->recordActivity(
                            'draft_expired',
                            'Booking draft expired otomatis.',
                            'Melewati batas waktu draft ('.SystemConfig::bookingExpiredHours().' jam).',
                        );
                    }
                    $expired++;
                }
            });

        return $expired;
    }
}
