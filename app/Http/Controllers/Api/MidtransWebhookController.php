<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MidtransWebhookController extends Controller
{
    public function __construct(
        private MidtransService $midtransService
    ) {}

    /**
     * Handle Midtrans notification (callback). No auth - called by Midtrans server.
     */
    public function notification(Request $request): JsonResponse
    {
        $payload = $request->all();
        $this->midtransService->handleNotification($payload);
        return response()->json(['message' => 'OK']);
    }
}
