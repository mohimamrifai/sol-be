<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Vendor;

use App\Enums\VendorInvoiceStatus;
use App\Enums\VendorPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Vendor\VendorPaymentResource;
use App\Models\CompanyActivity;
use App\Models\VendorInvoice;
use App\Models\VendorPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class VendorPaymentController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $vendorCompanyId = $request->user()->company_id;
        $invoiceIds = VendorInvoice::where('vendor_company_id', $vendorCompanyId)->pluck('id');

        $pendingAmount = VendorInvoice::where('vendor_company_id', $vendorCompanyId)
            ->whereIn('status', [VendorInvoiceStatus::Submitted->value, VendorInvoiceStatus::Approved->value])
            ->where('status', '!=', VendorInvoiceStatus::Paid->value)
            ->sum('total_amount');

        $partialCount = VendorInvoice::where('vendor_company_id', $vendorCompanyId)
            ->whereHas('payments', function ($q) {
                $q->where('status', VendorPaymentStatus::Paid->value);
            })
            ->where('status', VendorInvoiceStatus::Approved->value)
            ->count();

        $paidCount = VendorPayment::whereIn('vendor_invoice_id', $invoiceIds)
            ->where('status', VendorPaymentStatus::Paid->value)
            ->count();

        return response()->json([
            'data' => [
                'pending_payment' => $pendingAmount,
                'partially_paid' => $partialCount,
                'paid' => $paidCount,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $vendorCompanyId = $request->user()->company_id;
        $query = VendorPayment::whereHas('vendorInvoice', fn ($q) => $q->where('vendor_company_id', $vendorCompanyId))
            ->with(['vendorInvoice:id,invoice_number,total_amount,shipment_id', 'vendorInvoice.shipment:id,shipment_number', 'paidByUser:id,name']);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('payment_number', 'like', "%{$search}%")
                    ->orWhere('reference_no', 'like', "%{$search}%")
                    ->orWhereHas('vendorInvoice', fn ($v) => $v->where('invoice_number', 'like', "%{$search}%"));
            });
        }

        if (($status = $request->string('status')->toString()) && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($from = $request->date('from')) {
            $query->whereDate('payment_date', '>=', $from);
        }
        if ($to = $request->date('to')) {
            $query->whereDate('payment_date', '<=', $to);
        }

        $page = $query->orderByDesc('payment_date')->paginate(min((int) $request->integer('per_page', 15) ?: 15, 100));

        return response()->json([
            'data' => VendorPaymentResource::collection($page->getCollection())->resolve(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, VendorPayment $payment): JsonResponse
    {
        $this->authorizeVendorAccess($request, $payment);
        $payment->load(['vendorInvoice.shipment', 'paidByUser']);

        $activities = CompanyActivity::where('subject_type', VendorPayment::class)
            ->where('subject_id', $payment->id)
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'event_key' => $a->event_key,
                'description' => $a->description,
                'actor_name' => $a->actor?->name,
                'occurred_at' => $a->occurred_at?->toIso8601String(),
            ]);

        // Payment history: all payments for the related invoice (chronological)
        $history = VendorPayment::where('vendor_invoice_id', $payment->vendor_invoice_id)
            ->orderBy('payment_date')
            ->get()
            ->map(fn (VendorPayment $p) => [
                'id' => $p->id,
                'payment_number' => $p->payment_number,
                'payment_date' => $p->payment_date?->toDateString(),
                'amount' => (float) $p->amount,
                'status' => $p->status?->value,
                'status_label' => $p->status?->label(),
            ]);

        return response()->json([
            'data' => array_merge(
                (new VendorPaymentResource($payment))->resolve($request),
                ['activities' => $activities, 'history' => $history]
            ),
        ]);
    }

    public function receipt(Request $request, VendorPayment $payment): Response
    {
        $this->authorizeVendorAccess($request, $payment);
        if (! $payment->receipt_path || ! Storage::disk('public')->exists($payment->receipt_path)) {
            return response()->json(['message' => 'Bukti pembayaran tidak ditemukan.'], 404);
        }

        $content = Storage::disk('public')->get($payment->receipt_path);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => 'attachment; filename="'.addslashes($payment->payment_number.'-receipt.pdf').'"',
        ]);
    }

    private function authorizeVendorAccess(Request $request, VendorPayment $payment): void
    {
        if ($payment->vendorInvoice?->vendor_company_id !== $request->user()->company_id) {
            abort(response()->json(['message' => 'Resource tidak ditemukan.'], 404));
        }
    }
}
