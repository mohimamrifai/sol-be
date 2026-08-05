<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentActivity;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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

        if ($invoice->status === 'draft') {
            return response()->json(['message' => 'Invoice ini belum diterbitkan.'], 422);
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
            $outstanding = $invoice->outstandingAmount();
            if ($outstanding <= 0) {
                return response()->json(['message' => 'Invoice ini sudah lunas.'], 422);
            }

            $result = $midtrans->createSnapTransaction($invoice, $customerDetails, $outstanding);
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
            'decision' => ['nullable', 'string', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $decision = $validated['decision'] ?? 'approve';
        $isApproved = $decision !== 'reject';

        $payment->load('invoice');
        $invoice = $payment->invoice;

        if ($invoice->status === 'cancelled') {
            return response()->json([
                'message' => 'Invoice dibatalkan; verifikasi manual tidak diizinkan.',
            ], 422);
        }

        if ($payment->status === 'success' && $payment->manual_status === Payment::MANUAL_VERIFIED) {
            $payment->load(['invoice.company', 'invoice.shipment']);

            return response()->json([
                'message' => 'Pembayaran ini sudah diverifikasi.',
                'data' => $payment,
            ]);
        }

        $update = [
            'manual_status' => $isApproved ? Payment::MANUAL_VERIFIED : Payment::MANUAL_REJECTED,
            'manual_verified_at' => now(),
            'manual_verified_by' => $request->user()->id,
        ];

        if ($isApproved) {
            $update['status'] = 'success';
            $update['payment_type'] = $payment->payment_type ?: 'manual_confirmation';
            $update['paid_at'] = $payment->paid_at ?: now();
            $update['method'] = $payment->method ?: Payment::METHOD_TRANSFER;
            $update['midtrans_response'] = array_merge($payment->midtrans_response ?? [], [
                'manual_verification' => true,
                'manual_note' => $validated['note'] ?? null,
                'verified_by_user_id' => $request->user()->id,
                'verified_at' => now()->toIso8601String(),
            ]);
        } else {
            $update['midtrans_response'] = array_merge($payment->midtrans_response ?? [], [
                'manual_rejected' => true,
                'manual_note' => $validated['note'] ?? null,
                'verified_by_user_id' => $request->user()->id,
                'verified_at' => now()->toIso8601String(),
            ]);
        }

        $payment->update($update);

        if ($isApproved) {
            $payment->invoice->syncStatusFromPayments();
        }

        $payment->load(['invoice.company', 'invoice.shipment']);

        if (Schema::hasTable('payment_activities')) {
            PaymentActivity::create([
                'payment_id' => $payment->id,
                'actor_user_id' => $request->user()->id,
                'event_key' => $isApproved ? 'payment_proof_verified' : 'payment_proof_rejected',
                'description' => $isApproved
                    ? 'Bukti pembayaran diverifikasi oleh tim finance.'
                    : 'Bukti pembayaran ditolak oleh tim finance.',
                'meta' => [
                    'note' => $validated['note'] ?? null,
                    'invoice_status' => $payment->invoice?->status,
                ],
                'occurred_at' => now(),
            ]);
        }

        return response()->json([
            'message' => $isApproved ? 'Pembayaran diverifikasi manual.' : 'Bukti pembayaran ditolak.',
            'data' => $payment,
        ]);
    }

    /**
     * List invoices that are overdue (for monitoring).
     */
    public function overdueInvoices(Request $request): JsonResponse
    {
        $query = Invoice::with(['company:id,name', 'shipment:id,waybill_number'])
            ->whereIn('status', ['issued', 'partially_paid'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString());

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        $invoices = $query->orderBy('due_date')->paginate($request->per_page ?? 15);

        return response()->json($invoices);
    }
}
