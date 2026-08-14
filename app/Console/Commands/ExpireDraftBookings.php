<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BookingDraftExpiryService;
use Illuminate\Console\Command;

class ExpireDraftBookings extends Command
{
    protected $signature = 'bookings:expire-drafts';

    protected $description = 'Batalkan booking draft yang sudah melewati batas waktu system configuration';

    public function handle(): int
    {
        $count = BookingDraftExpiryService::expireDueDrafts();
        $this->info("Expired {$count} draft booking(s).");

        return self::SUCCESS;
    }
}
