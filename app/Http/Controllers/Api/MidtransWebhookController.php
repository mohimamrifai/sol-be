<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MidtransWebhookController extends Controller
{
    public function __construct(
        private MidtransService $midtransService
    ) {}

    /**
     * Handle Midtrans notification (callback). No auth - called by Midtrans server.
     *
     * Verifies the SHA-512 signature of the payload before processing. Invalid
     * signatures are logged and ignored (still returning 200 to avoid Midtrans
     * retry storms), since responding 4xx/5xx will cause Midtrans to retry.
     */
    public function notification(Request $request): JsonResponse
    {
        $payload = $request->all();

        if (! $this->midtransService->verifySignature($payload)) {
            Log::warning(MidtransService::SIGNATURE_INVALID_LOG, [
                'order_id' => $payload['order_id'] ?? null,
                'status_code' => $payload['status_code'] ?? null,
                'has_signature_key' => isset($payload['signature_key']),
                'remote_ip' => $request->ip(),
            ]);
            // Still 200 — never 4xx on a Midtrans callback, or they retry.
            return response()->json(['message' => 'OK']);
        }

        $this->midtransService->handleNotification($payload);
        return response()->json(['message' => 'OK']);
    }
}
