<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentActivity;
use App\Services\PaymentLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PaymentLinkController extends Controller
{
    public function __construct(private PaymentLinkService $paymentLink) {}

    /**
     * Record that the customer opened the Payment Link, then hand them over to Midtrans.
     *
     * FSD Customer/customer-payment.md §5.6: the link must stop working once the payment
     * succeeded or the link expired.
     */
    public function open(Request $request, Payment $payment): RedirectResponse|JsonResponse
    {
        $snapUrl = $this->paymentLink->snapUrl($payment);
        if ($snapUrl === null) {
            return response()->json(['message' => 'Payment Link tidak tersedia.'], 404);
        }

        if ($payment->isSuccess()) {
            return response()->json(['message' => 'Pembayaran untuk invoice ini sudah berhasil.'], 410);
        }

        if ($payment->isExpired()) {
            return response()->json(['message' => 'Payment Link sudah kedaluwarsa.'], 410);
        }

        if (Schema::hasTable('payment_activities')) {
            PaymentActivity::create([
                'payment_id' => $payment->id,
                'event_key' => 'payment_link_opened',
                'description' => 'Customer membuka Payment Link.',
                'meta' => [
                    'order_id' => $payment->midtrans_order_id,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
                'occurred_at' => now(),
            ]);
        }

        return redirect()->away($snapUrl);
    }
}
