<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private MidtransService $midtransService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Payment::query()
            ->whereHas('invoice', fn ($q) => $q->where('company_id', $user->company_id))
            ->with('invoice:id,invoice_number,shipment_id,total_amount,status');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('midtrans_order_id', 'like', "%{$s}%")
                    ->orWhereHas('invoice', fn ($iq) => $iq->where('invoice_number', 'like', "%{$s}%"));
            });
        }

        $payments = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 15);

        return response()->json($payments);
    }

    /**
     * Create payment for an invoice (init Midtrans Snap). Returns token and redirect_url for frontend.
     */
    public function pay(Request $request, Invoice $invoice): JsonResponse
    {
        $user = $request->user();

        if ($invoice->company_id !== $user->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'Invoice ini sudah dibayar.'], 422);
        }

        $customerDetails = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
        ]);

        if (empty(array_filter($customerDetails))) {
            $company = $user->company;
            $customerDetails = [
                'first_name' => $user->name,
                'name' => $user->name,
                'email' => $user->email ?? $company?->email,
                'phone' => $user->phone ?? $company?->phone,
            ];
        }

        try {
            $result = $this->midtransService->createSnapTransaction($invoice, $customerDetails);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Gagal membuat transaksi pembayaran.',
                'error' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'message' => 'Silakan selesaikan pembayaran.',
            'data' => $result,
        ], 201);
    }
}
