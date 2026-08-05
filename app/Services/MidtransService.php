<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MidtransService
{
    /** Log channel constants — public so other classes (e.g. webhook controller) can reuse the same event names. */
    public const SIGNATURE_INVALID_LOG = 'midtrans.signature.invalid';

    public const NOTIFICATION_RECEIVED_LOG = 'midtrans.notification.received';

    public const NOTIFICATION_ORDER_NOT_FOUND_LOG = 'midtrans.notification.order_not_found';

    public const NOTIFICATION_TERMINAL_IGNORED_LOG = 'midtrans.notification.ignored_terminal';

    public const SNAP_CREATED_LOG = 'midtrans.snap.created';

    public const SNAP_FAILED_LOG = 'midtrans.snap.failed';

    public function createSnapTransaction(Invoice $invoice, array $customerDetails, float $amount): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Jumlah pembayaran tidak valid.');
        }

        $orderId = 'INV-'.$invoice->id.'-'.Str::random(6);
        $grossAmount = (int) round($amount);

        $payload = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $grossAmount,
            ],
            'customer_details' => [
                'first_name' => $customerDetails['first_name'] ?? $customerDetails['name'] ?? 'Customer',
                'last_name' => $customerDetails['last_name'] ?? '',
                'email' => $customerDetails['email'] ?? '',
                'phone' => $customerDetails['phone'] ?? '',
            ],
            'item_details' => [
                [
                    'id' => 'invoice-'.$invoice->id,
                    'price' => $grossAmount,
                    'quantity' => 1,
                    'name' => 'Invoice '.$invoice->invoice_number,
                ],
            ],
        ];

        try {
            $response = Http::withBasicAuth(config('midtrans.server_key'), '')
                ->post(config('midtrans.snap_url'), $payload);
        } catch (\Throwable $e) {
            Log::error(self::SNAP_FAILED_LOG, [
                'invoice_id' => $invoice->id,
                'order_id' => $orderId,
                'amount' => $grossAmount,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Gagal menghubungi Midtrans: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            Log::error(self::SNAP_FAILED_LOG, [
                'invoice_id' => $invoice->id,
                'order_id' => $orderId,
                'amount' => $grossAmount,
                'http_status' => $response->status(),
                'error_messages' => $response->json('error_messages'),
            ]);
            throw new \RuntimeException(
                'Midtrans API error: '.($response->json('error_messages.0') ?? $response->body())
            );
        }

        $body = $response->json();
        $token = $body['token'] ?? null;
        $redirectUrl = $body['redirect_url'] ?? null;

        if (! $token) {
            Log::error(self::SNAP_FAILED_LOG, [
                'invoice_id' => $invoice->id,
                'order_id' => $orderId,
                'reason' => 'missing_token',
            ]);
            throw new \RuntimeException('Midtrans did not return token.');
        }

        // Wrap payment persistence in a transaction so a failed insert never leaves
        // a Snap transaction without a local record (orphan order).
        DB::transaction(function () use ($invoice, $orderId, $amount, $body) {
            Payment::create([
                'invoice_id' => $invoice->id,
                'midtrans_order_id' => $orderId,
                'amount' => $amount,
                'status' => 'pending',
                'midtrans_response' => $body,
            ]);
        });

        Log::info(self::SNAP_CREATED_LOG, [
            'invoice_id' => $invoice->id,
            'order_id' => $orderId,
            'amount' => $grossAmount,
        ]);

        return [
            'order_id' => $orderId,
            'token' => $token,
            'redirect_url' => $redirectUrl,
        ];
    }

    /**
     * Verify Midtrans notification signature.
     *
     * Algorithm: SHA-512(order_id + status_code + gross_amount + server_key).
     * Midtrans signature_key MUST equal that hash (compared with hash_equals for timing safety).
     */
    public function verifySignature(array $payload): bool
    {
        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $signatureKey = (string) ($payload['signature_key'] ?? '');
        $serverKey = (string) (config('midtrans.server_key') ?? '');

        if ($orderId === '' || $statusCode === '' || $signatureKey === '' || $serverKey === '') {
            return false;
        }

        $expected = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);

        return hash_equals($expected, $signatureKey);
    }

    /**
     * Handle notification from Midtrans (callback). Update payment and invoice status.
     */
    public function handleNotification(array $payload): void
    {
        $orderId = $payload['order_id'] ?? null;

        Log::info(self::NOTIFICATION_RECEIVED_LOG, [
            'order_id' => $orderId,
            'transaction_status' => $payload['transaction_status'] ?? null,
            'payment_type' => $payload['payment_type'] ?? null,
            'fraud_status' => $payload['fraud_status'] ?? null,
        ]);

        if (! $orderId) {
            return;
        }

        $payment = Payment::where('midtrans_order_id', $orderId)->first();
        if (! $payment) {
            // Order not in our DB. Returning 200 prevents Midtrans from retrying, but
            // log a warning so ops can investigate (could be sandbox noise).
            Log::warning(self::NOTIFICATION_ORDER_NOT_FOUND_LOG, [
                'order_id' => $orderId,
            ]);

            return;
        }

        DB::transaction(function () use ($payment, $payload) {
            $this->applyMidtransPayloadToPayment($payment, $payload);
        });
    }

    /**
     * GET /v2/{order_id}/status — sinkron status untuk admin / recovery jika webhook tertunda.
     *
     * @return array<string, mixed>
     */
    public function fetchTransactionStatus(string $orderId): array
    {
        $key = trim(config('midtrans.server_key') ?? '');
        if ($key === '') {
            throw new \RuntimeException('MIDTRANS_SERVER_KEY belum dikonfigurasi.');
        }

        $base = rtrim((string) config('midtrans.api_base_url'), '/');
        $encoded = rawurlencode($orderId);
        $response = Http::withBasicAuth($key, '')
            ->acceptJson()
            ->get("{$base}/{$encoded}/status");

        if ($response->status() === 404) {
            throw new \RuntimeException('Order ID tidak ditemukan di Midtrans (sandbox/production harus sesuai konfigurasi).');
        }

        if (! $response->successful()) {
            $msg = $response->json('status_message')
                ?? $response->json('error_messages.0')
                ?? $response->body();

            throw new \RuntimeException('Midtrans status API: '.$msg);
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new \RuntimeException('Respons status Midtrans tidak valid.');
        }

        return $body;
    }

    public function syncPaymentFromMidtrans(Payment $payment): void
    {
        $orderId = $payment->midtrans_order_id;
        if ($orderId === null || $orderId === '') {
            throw new \RuntimeException('Pembayaran tidak memiliki Order ID Midtrans.');
        }

        $body = $this->fetchTransactionStatus($orderId);
        $this->applyMidtransPayloadToPayment($payment, $body);
    }

    /**
     * Terapkan payload notifikasi atau respons GET status ke satu baris Payment.
     *
     * Monotonic state machine (Midtrans best practice): terminal states (success,
     * refunded) MUST NOT be downgraded by late notifications. `expired`/`failed`
     * from Midtrans are treated as terminal for Midtrans-driven flows; manual
     * verification is the only path that can override.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyMidtransPayloadToPayment(Payment $payment, array $payload): void
    {
        $transactionStatus = $payload['transaction_status'] ?? null;
        $fraudStatus = $payload['fraud_status'] ?? null;
        $incomingStatus = $this->mapTransactionStatus($transactionStatus, $fraudStatus);

        // Monotonic guard: if payment is already in a success or refunded state,
        // do not let a late/cached notification downgrade it.
        $terminal = [Payment::STATUS_SUCCESS, Payment::STATUS_SETTLEMENT, Payment::STATUS_REFUNDED];
        if (in_array($payment->status, $terminal, true) && $incomingStatus !== $payment->status) {
            Log::info(self::NOTIFICATION_TERMINAL_IGNORED_LOG, [
                'order_id' => $payment->midtrans_order_id,
                'current_status' => $payment->status,
                'incoming_status' => $incomingStatus,
            ]);
            // Still record the raw payload for audit, but do not mutate status/paid_at.
            $payment->forceFill([
                'midtrans_response' => array_merge($payment->midtrans_response ?? [], $payload),
            ])->save();

            return;
        }

        $update = [
            'midtrans_transaction_id' => $payload['transaction_id'] ?? $payment->midtrans_transaction_id,
            'payment_type' => $payload['payment_type'] ?? $payment->payment_type,
            'status' => $incomingStatus,
            'midtrans_response' => array_merge($payment->midtrans_response ?? [], $payload),
        ];

        // Only set paid_at on the first successful settlement. Late duplicate
        // notifications should not shift the original settlement timestamp.
        if (in_array($transactionStatus, ['capture', 'settlement'], true) && $payment->paid_at === null) {
            $update['paid_at'] = now();
        }

        $payment->update($update);
        $payment->refresh();

        if ($payment->isSuccess()) {
            $payment->invoice->syncStatusFromPayments();
        }
    }

    private function mapTransactionStatus(?string $status, ?string $fraudStatus): string
    {
        if (in_array($status, ['capture', 'settlement'], true)) {
            return 'success';
        }
        if (in_array($status, ['pending'], true)) {
            return 'pending';
        }
        if (in_array($status, ['deny', 'cancel', 'expire'], true)) {
            return $status === 'expire' ? 'expired' : 'failed';
        }
        if ($status === 'authorize' && $fraudStatus === 'accept') {
            return 'success';
        }

        return 'pending';
    }
}
