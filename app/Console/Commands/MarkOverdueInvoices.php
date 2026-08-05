<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MarkOverdueInvoices extends Command
{
    protected $signature = 'invoices:mark-overdue';

    protected $description = 'Legacy no-op. Overdue status is computed dynamically for customer-facing views.';

    public function handle(): int
    {
        $this->info('No-op: overdue invoices are derived at query-time.');

        return self::SUCCESS;
    }
}
