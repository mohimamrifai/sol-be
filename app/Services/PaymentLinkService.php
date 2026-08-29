<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\URL;

/**
 * Builds the URL that is handed to a customer for a Midtrans Payment Link.
 *
 * FSD Customer/customer-payment.md §5.5 requires "Customer membuka Payment Link" on the
 * activity log, so the shared link points at our own signed redirect instead of the raw
 * Snap URL. The signature expires together with the payment link itself.
 */
class PaymentLinkService
{
    public function snapUrl(Payment $payment): ?string
    {
        $url = $payment->midtrans_response['redirect_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function trackedUrl(Payment $payment): ?string
    {
        if ($this->snapUrl($payment) === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'payments.link.open',
            $payment->expired_at ?? now()->addDay(),
            ['payment' => $payment->id],
        );
    }
}
