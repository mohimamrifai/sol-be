<?php

namespace App\Console\Commands;

use App\Services\MidtransService;
use Illuminate\Console\Command;

class MidtransSmokeTest extends Command
{
    protected $signature = 'midtrans:smoke-test';

    protected $description = 'Verify Midtrans configuration and signature handling (no live API call)';

    public function handle(MidtransService $midtrans): int
    {
        $serverKey = config('midtrans.server_key');
        $clientKey = config('midtrans.client_key');
        $isProduction = (bool) config('midtrans.is_production');
        $snapUrl = config('midtrans.snap_url');
        $apiBase = config('midtrans.api_base_url');

        $this->info('Midtrans configuration check');
        $this->table(
            ['Key', 'Status'],
            [
                ['MIDTRANS_SERVER_KEY', $serverKey ? 'set ('.strlen($serverKey).' chars)' : 'MISSING'],
                ['MIDTRANS_CLIENT_KEY', $clientKey ? 'set ('.strlen($clientKey).' chars)' : 'MISSING'],
                ['MIDTRANS_IS_PRODUCTION', $isProduction ? 'true' : 'false (sandbox)'],
                ['Snap URL', $snapUrl],
                ['Core API base', $apiBase],
            ]
        );

        if (! $serverKey) {
            $this->error('MIDTRANS_SERVER_KEY is required. Set it in .env for Snap and webhook verification.');

            return self::FAILURE;
        }

        // Signature round-trip using MidtransService (no HTTP).
        $samplePayload = [
            'order_id' => 'SMOKE-TEST-'.time(),
            'status_code' => '200',
            'gross_amount' => '100000.00',
        ];
        $signature = hash('sha512', $samplePayload['order_id'].$samplePayload['status_code'].$samplePayload['gross_amount'].$serverKey);
        $samplePayload['signature_key'] = $signature;

        $valid = $midtrans->verifySignature($samplePayload);
        if (! $valid) {
            $this->error('Signature verification failed for synthetic payload.');

            return self::FAILURE;
        }

        $this->info('Signature verification: OK');
        $this->line('Webhook URL (configure in Midtrans dashboard):');
        $this->line(rtrim(config('app.url'), '/').'/api/payments/midtrans/notification');

        return self::SUCCESS;
    }
}
