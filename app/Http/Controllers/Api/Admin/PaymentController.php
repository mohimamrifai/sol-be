<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with([
            'invoice:id,invoice_number,company_id,shipment_id,total_amount,status',
            'invoice.company:id,name',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }
        if ($request->filled('company_id')) {
            $query->whereHas('invoice', fn ($q) => $q->where('company_id', $request->company_id));
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('midtrans_order_id', 'like', "%{$s}%")
                    ->orWhere('midtrans_transaction_id', 'like', "%{$s}%")
                    ->orWhereHas('invoice', fn ($iq) => $iq->where('invoice_number', 'like', "%{$s}%"));
            });
        }

        $payments = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 15);

        return response()->json($payments);
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['invoice.company', 'invoice.shipment']);
        return response()->json(['data' => $payment]);
    }

    /**
     * Generate Midtrans Payment Link untuk Customer.
     */
    public function generatePaymentLink(Request $request, Invoice $invoice, MidtransService $midtrans): JsonResponse
    {
        if (! $request->user()->can('manage_payments')) {
            return response()->json(['message' => 'Tidak ada izin untuk mengelola pembayaran.'], 403);
        }

        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'Invoice ini sudah lunas.'], 422);
        }

        if ($invoice->status === 'cancelled') {
            return response()->json(['message' => 'Invoice ini sudah dibatalkan.'], 422);
        }

        $invoice->load('company');
        $company = $invoice->company;

        $customerDetails = [
            'first_name' => $company?->name ?? 'Customer',
            'name' => $company?->name ?? 'Customer',
            'email' => $company?->email ?? '',
            'phone' => $company?->phone ?? '',
        ];

        try {
            $result = $midtrans->createSnapTransaction($invoice, $customerDetails);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Gagal membuat link pembayaran Midtrans.',
                'error' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'message' => 'Link pembayaran berhasil dibuat.',
            'data' => [
                'payment_url' => $result['redirect_url'],
            ],
        ], 201);
    }

    /**
     * Tarik status terkini dari Midtrans Core API dan perbarui pembayaran + invoice.
     */
    public function syncMidtrans(Request $request, Payment $payment, MidtransService $midtrans): JsonResponse
    {
        if (! $request->user()->can('manage_payments')) {
            return response()->json(['message' => 'Tidak ada izin untuk mengelola pembayaran.'], 403);
        }

        try {
            $midtrans->syncPaymentFromMidtrans($payment);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payment->load(['invoice.company', 'invoice.shipment']);

        return response()->json([
            'message' => 'Status disinkronkan dari Midtrans.',
            'data' => $payment,
        ]);
    }

    /**
     * Verifikasi manual (transfer bank, koreksi webhook, dll.): tandai pembayaran sukses dan invoice lunas.
     */
    public function verifyManual(Request $request, Payment $payment): JsonResponse
    {
        if (! $request->user()->can('manage_payments')) {
            return response()->json(['message' => 'Tidak ada izin untuk mengelola pembayaran.'], 403);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment->load('invoice');
        $invoice = $payment->invoice;

        if ($invoice->status === 'cancelled') {
            return response()->json([
                'message' => 'Invoice dibatalkan; verifikasi manual tidak diizinkan.',
            ], 422);
        }

        if ($invoice->payments()->where('status', 'success')->where('id', '!=', $payment->id)->exists()) {
            return response()->json([
                'message' => 'Invoice sudah lunas melalui pembayaran lain.',
            ], 422);
        }

        if ($payment->status === 'success') {
            $payment->load(['invoice.company', 'invoice.shipment']);

            return response()->json([
                'message' => 'Pembayaran ini sudah tercatat sukses.',
                'data' => $payment,
            ]);
        }

        $payment->update([
            'status' => 'success',
            'payment_type' => $payment->payment_type ?: 'manual_confirmation',
            'paid_at' => now(),
            'midtrans_response' => array_merge($payment->midtrans_response ?? [], [
                'manual_verification' => true,
                'manual_note' => $validated['note'] ?? null,
                'verified_by_user_id' => $request->user()->id,
                'verified_at' => now()->toIso8601String(),
            ]),
        ]);

        $payment->invoice->markAsPaid();
        $payment->load(['invoice.company', 'invoice.shipment']);

        return response()->json([
            'message' => 'Pembayaran diverifikasi manual; invoice ditandai lunas.',
            'data' => $payment,
        ]);
    }

    /**
     * List invoices that are overdue (for monitoring).
     */
    public function overdueInvoices(Request $request): JsonResponse
    {
        $query = Invoice::with(['company:id,name', 'shipment:id,waybill_number'])
            ->where('status', 'overdue');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        $invoices = $query->orderBy('due_date')->paginate($request->per_page ?? 15);

        return response()->json($invoices);
    }
}
