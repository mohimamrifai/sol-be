<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentActivity;
use App\Models\PaymentProofAttachment;
use App\Services\DocumentPdfService;
use App\Services\MidtransService;
use App\Services\PaymentNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentNumberService $paymentNumber,
        private DocumentPdfService $pdf,
    ) {}

    public function stats(): JsonResponse
    {
        $today = Carbon::today();
        $base = Invoice::query();

        $paid = (clone $base)->where('status', 'paid')->count();
        $partiallyPaid = (clone $base)->where('status', 'partially_paid')->count();
        $unpaid = (clone $base)->whereIn('status', Invoice::openIssuedStatuses())->count();
        $overdue = (clone $base)
            ->whereIn('status', array_merge(Invoice::openIssuedStatuses(), ['partially_paid']))
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->count();

        return response()->json([
            'data' => [
                'unpaid' => $unpaid,
                'partially_paid' => $partiallyPaid,
                'paid' => $paid,
                'overdue' => $overdue,
            ],
        ]);
    }

    public function eligibleInvoices(Request $request): JsonResponse
    {
        $query = Invoice::query()
            ->with(['company:id,name,company_code'])
            ->whereIn('status', array_merge(Invoice::openIssuedStatuses(), ['partially_paid']))
            ->where('status', '!=', 'cancelled');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where('invoice_number', 'like', "%{$s}%");
        }

        $paginated = $query->orderBy('due_date')->paginate($request->per_page ?? 15);
        $paginated->getCollection()->transform(function (Invoice $invoice) {
            $outstanding = $invoice->outstandingAmount();
            if ($outstanding <= 0) {
                return null;
            }

            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'company' => $invoice->company,
                'due_date' => $invoice->due_date?->toDateString(),
                'total_amount' => (float) $invoice->total_amount,
                'outstanding_amount' => $outstanding,
                'paid_amount' => $invoice->paidAmount(),
                'status' => $invoice->status,
            ];
        });
        $paginated->setCollection($paginated->getCollection()->filter()->values());

        return response()->json($paginated);
    }

    public function recordPayment(Request $request, Invoice $invoice): JsonResponse
    {
        if (! $request->user()?->can('manage_payments')) {
            return response()->json(['message' => 'Tidak ada izin untuk mencatat pembayaran.'], 403);
        }

        if (! in_array($invoice->status, array_merge(Invoice::openIssuedStatuses(), ['partially_paid']), true)) {
            return response()->json(['message' => 'Invoice tidak dapat dibayar.'], 422);
        }

        $data = $request->validate([
            'payment_method' => 'required|in:transfer,giro,cash,virtual_account,midtrans',
            'company_bank' => 'required_if:payment_method,transfer|nullable|string|max:120',
            'account' => 'nullable|string|max:120',
            'payment_date' => 'required|date',
            'payment_amount' => 'required|numeric|min:0.01',
            'payment_reference_no' => 'required|string|max:120',
            'payment_remark' => 'nullable|string|max:2000',
        ]);

        $outstanding = $invoice->outstandingAmount();
        if ($data['payment_amount'] > $outstanding) {
            return response()->json(['message' => 'Jumlah pembayaran melebihi outstanding.'], 422);
        }

        $paymentNumber = $this->paymentNumber->next((int) $invoice->company_id);

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'payment_number' => $paymentNumber,
            'amount' => $data['payment_amount'],
            'method' => $data['payment_method'],
            'payment_type' => 'manual_admin',
            'status' => 'success',
            'paid_at' => Carbon::parse($data['payment_date']),
            'manual_reference_number' => $data['payment_reference_no'],
            'manual_status' => Payment::MANUAL_VERIFIED,
            'manual_verified_at' => now(),
            'manual_verified_by' => $request->user()->id,
            'midtrans_response' => [
                'company_bank' => $data['company_bank'] ?? null,
                'account' => $data['account'] ?? null,
                'remark' => $data['payment_remark'] ?? null,
                'recorded_by_admin' => true,
            ],
        ]);

        $invoice->syncStatusFromPayments();

        if (Schema::hasTable('payment_activities')) {
            PaymentActivity::create([
                'payment_id' => $payment->id,
                'actor_user_id' => $request->user()->id,
                'event_key' => 'payment_recorded',
                'description' => 'Pembayaran dicatat oleh admin.',
                'meta' => ['amount' => (float) $data['payment_amount']],
                'occurred_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Pembayaran berhasil dicatat.',
            'data' => $payment->load(['invoice.company']),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->input('view') === 'ar') {
            return $this->indexAr($request);
        }

        $query = Payment::with([
            'invoice:id,invoice_number,company_id,shipment_id,total_amount,status,due_date',
            'invoice.company:id,name,company_code',
        ]);

        if ($request->filled('invoice_status')) {
            $invoiceStatus = $request->string('invoice_status')->toString();
            $today = Carbon::today();
            $query->whereHas('invoice', function ($q) use ($invoiceStatus, $today) {
                if ($invoiceStatus === 'unpaid') {
                    $q->whereIn('status', Invoice::openIssuedStatuses());
                } elseif ($invoiceStatus === 'partially_paid') {
                    $q->where('status', 'partially_paid');
                } elseif ($invoiceStatus === 'paid') {
                    $q->where('status', 'paid');
                } elseif ($invoiceStatus === 'overdue') {
                    $q->whereIn('status', array_merge(Invoice::openIssuedStatuses(), ['partially_paid']))
                        ->whereNotNull('due_date')
                        ->where('due_date', '<', $today);
                }
            });
        } elseif ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_method')) {
            $query->where('method', $request->payment_method);
        }
        if ($request->filled('payment_date_from')) {
            $query->whereDate('paid_at', '>=', $request->payment_date_from);
        }
        if ($request->filled('payment_date_to')) {
            $query->whereDate('paid_at', '<=', $request->payment_date_to);
        }
        if ($request->filled('link_status') && $request->string('link_status')->toString() === 'expired') {
            $query->where('status', Payment::STATUS_EXPIRED);
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
                $q->where('payment_number', 'like', "%{$s}%")
                    ->orWhere('midtrans_order_id', 'like', "%{$s}%")
                    ->orWhere('midtrans_transaction_id', 'like', "%{$s}%")
                    ->orWhereHas('invoice', fn ($iq) => $iq->where('invoice_number', 'like', "%{$s}%"))
                    ->orWhereHas('invoice.company', fn ($cq) => $cq->where('name', 'like', "%{$s}%"));
            });
        }

        $payments = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 15);

        $payments->getCollection()->transform(function (Payment $payment) {
            $invoice = $payment->invoice;
            $invoiceAmount = $invoice ? (float) $invoice->total_amount : null;
            $invoicePaidAmount = $invoice ? $invoice->paidAmount() : null;

            return array_merge($payment->toArray(), [
                'invoice_amount' => $invoiceAmount,
                'invoice_paid_amount' => $invoicePaidAmount,
                'invoice_ar_status' => $invoice ? $this->resolveInvoiceArStatus($invoice) : null,
            ]);
        });

        return response()->json($payments);
    }

    private function resolveInvoiceArStatus(Invoice $invoice): string
    {
        $today = Carbon::today();
        if (
            in_array($invoice->status, array_merge(Invoice::openIssuedStatuses(), ['partially_paid']), true)
            && $invoice->due_date !== null
            && $invoice->due_date->lt($today)
        ) {
            return 'overdue';
        }

        return match ($invoice->status) {
            'issued', 'unpaid' => 'unpaid',
            'partially_paid' => 'partially_paid',
            'paid' => 'paid',
            default => (string) $invoice->status,
        };
    }

    public function paymentOptions(): JsonResponse
    {
        return response()->json([
            'data' => [
                'company_banks' => config('payment.company_banks', []),
                'vehicle_types' => config('payment.vehicle_types', []),
            ],
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load([
            'invoice.company',
            'invoice.shipment',
            'invoice.items',
            'invoice.payments' => fn ($q) => $q->orderByDesc('paid_at'),
        ]);

        if (Schema::hasTable('payment_activities')) {
            $payment->load(['activities.actor:id,name']);
        }
        if (Schema::hasTable('payment_proof_attachments')) {
            $payment->load(['proofAttachments.uploader:id,name']);
        }

        $invoice = $payment->invoice;
        if (! $invoice) {
            return response()->json(['message' => 'Invoice tidak ditemukan.'], 404);
        }

        $paidAmount = (float) $invoice->paidAmount();
        $outstanding = $invoice->outstandingAmount();
        $company = $invoice->company;

        $midtransResponse = (array) ($payment->midtrans_response ?? []);
        $expiredAt = $payment->expired_at?->toIso8601String()
            ?? (isset($midtransResponse['expiry_time']) ? Carbon::parse($midtransResponse['expiry_time'])->toIso8601String() : null);

        $linkActive = $payment->status === 'pending' && (! $expiredAt || Carbon::parse($expiredAt)->isFuture());
        $linkExpired = $expiredAt && Carbon::parse($expiredAt)->isPast();

        $paymentHistory = $invoice->payments
            ->sortByDesc(fn (Payment $p) => $p->paid_at ?? $p->created_at)
            ->values()
            ->map(function (Payment $p) {
                $recordedBy = $p->midtrans_response['recorded_by_admin'] ?? false
                    ? 'Admin'
                    : ($p->manual_verified_by ? 'Finance' : 'System');

                return [
                    'id' => $p->id,
                    'payment_date' => ($p->paid_at ?? $p->created_at)?->toIso8601String(),
                    'amount' => (float) $p->amount,
                    'payment_method' => $p->method ?? $p->payment_type,
                    'reference_no' => $p->manual_reference_number ?? $p->midtrans_transaction_id ?? $p->midtrans_order_id,
                    'recorded_by' => $recordedBy,
                    'status' => $p->status,
                ];
            });

        $timeline = collect();
        if (Schema::hasTable('payment_activities')) {
            foreach ($payment->activities ?? collect() as $act) {
                $timeline->push([
                    'occurred_at' => $act->occurred_at?->toIso8601String(),
                    'activity' => $act->description ?? $act->event_key,
                    'user' => $act->actor?->name,
                ]);
            }
        }
        if ($timeline->isEmpty()) {
            $timeline->push([
                'occurred_at' => $payment->created_at?->toIso8601String(),
                'activity' => 'Payment dibuat.',
                'user' => null,
            ]);
            if ($payment->status === 'pending' && $payment->midtrans_order_id) {
                $timeline->push([
                    'occurred_at' => $payment->created_at?->toIso8601String(),
                    'activity' => 'Payment Link dibuat.',
                    'user' => null,
                ]);
            }
            if ($payment->isSuccess()) {
                $timeline->push([
                    'occurred_at' => $payment->paid_at?->toIso8601String() ?? $payment->created_at?->toIso8601String(),
                    'activity' => 'Pembayaran berhasil (Settlement).',
                    'user' => null,
                ]);
                $timeline->push([
                    'occurred_at' => $payment->paid_at?->toIso8601String() ?? $payment->created_at?->toIso8601String(),
                    'activity' => 'Status Invoice menjadi '.$invoice->status.'.',
                    'user' => null,
                ]);
            }
        }

        $timeline = $timeline
            ->filter(fn ($e) => ! empty($e['occurred_at']))
            ->sortBy(fn ($e) => $e['occurred_at'])
            ->values();

        $invoiceArStatus = $this->resolveInvoiceArStatus($invoice);
        $isMidtrans = ($payment->method === 'midtrans') || str_contains((string) $payment->payment_type, 'midtrans')
            || ! empty($payment->midtrans_order_id);

        $canRegenerate = $isMidtrans
            && $outstanding > 0
            && in_array($payment->status, ['pending', 'expired', 'failed', 'cancelled'], true)
            && ! in_array($invoiceArStatus, ['paid'], true);

        $supportingDocs = [];
        $supportingDocs[] = [
            'key' => 'payment_receipt',
            'label' => 'Payment Receipt',
            'available' => $payment->isSuccess(),
        ];
        if (Schema::hasTable('payment_proof_attachments')) {
            foreach ($payment->proofAttachments as $attachment) {
                $supportingDocs[] = [
                    'key' => $attachment->category === 'other' ? 'other_document' : 'payment_proof',
                    'label' => $attachment->category === 'other' ? 'Other Documents' : 'Payment Proof',
                    'available' => true,
                    'meta' => [
                        'id' => $attachment->id,
                        'original_name' => $attachment->original_name,
                        'file_size' => $attachment->file_size,
                        'mime_type' => $attachment->mime_type,
                        'category' => $attachment->category,
                    ],
                ];
            }
        }

        $manualMeta = (array) ($midtransResponse);
        $payload = [
            'id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'payment_no' => $payment->displayNumber(),
            'midtrans_order_id' => $payment->midtrans_order_id,
            'midtrans_transaction_id' => $payment->midtrans_transaction_id,
            'status' => $payment->status,
            'invoice_ar_status' => $invoiceArStatus,
            'created_at' => $payment->created_at?->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'amount' => (float) $payment->amount,
            'method' => $payment->method,
            'payment_type' => $payment->payment_type,
            'outstanding_amount' => $outstanding,
            'invoice_paid_amount' => $paidAmount,
            'customer_info' => [
                'customer_code' => $company?->company_code,
                'customer_name' => $company?->name,
                'payment_terms' => $company?->payment_term ?? $company?->payment_type,
            ],
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->issued_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'total_amount' => (float) $invoice->total_amount,
                'paid_amount' => $paidAmount,
                'outstanding_amount' => $outstanding,
                'status' => $invoice->status,
                'company' => $company,
                'shipment' => $invoice->shipment,
            ],
            'payment_info' => [
                'payment_method' => $payment->method ?? $payment->payment_type,
                'company_bank' => $manualMeta['company_bank'] ?? null,
                'account' => $manualMeta['account'] ?? null,
                'payment_date' => $payment->paid_at?->toDateString(),
                'payment_amount' => (float) $payment->amount,
                'payment_reference_no' => $payment->manual_reference_number ?? $payment->midtrans_transaction_id,
                'payment_remark' => $manualMeta['remark'] ?? null,
            ],
            'invoice_payments' => $invoice->payments,
            'payment_history' => $paymentHistory,
            'online_payment' => $isMidtrans ? [
                'payment_link' => $midtransResponse['redirect_url'] ?? null,
                'link_status' => $linkActive ? 'active' : ($linkExpired ? 'expired' : 'inactive'),
                'expired_at' => $expiredAt,
                'midtrans_order_id' => $payment->midtrans_order_id,
                'midtrans_transaction_id' => $payment->midtrans_transaction_id,
                'midtrans_status' => $payment->status,
                'can_regenerate' => $canRegenerate,
            ] : null,
            'supporting_documents' => $supportingDocs,
            'activity_timeline' => $timeline,
            'actions' => [
                'can_record_payment' => in_array($invoiceArStatus, ['unpaid', 'partially_paid', 'overdue'], true),
                'can_print_receipt' => $invoiceArStatus === 'paid' || $payment->isSuccess(),
                'can_copy_link' => $isMidtrans && ! empty($midtransResponse['redirect_url']),
                'can_regenerate_link' => $canRegenerate,
            ],
        ];

        return response()->json(['data' => $payload]);
    }

    public function receipt(Request $request, Payment $payment): SymfonyResponse
    {
        $payment->loadMissing('invoice.company');

        if (! $payment->isSuccess()) {
            abort(422, 'Payment receipt tersedia setelah pembayaran berhasil.');
        }

        $pdf = $this->pdf->renderPaymentReceipt($payment);
        $content = $pdf->output();
        $filename = 'payment-receipt-'.$payment->displayNumber().'.pdf';
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => $disposition.'; filename="'.addslashes($filename).'"',
        ]);
    }

    private function indexAr(Request $request): JsonResponse
    {
        $today = Carbon::today();
        $query = Invoice::query()
            ->with(['company:id,name,company_code', 'payments' => fn ($q) => $q->orderByDesc('paid_at')->limit(1)])
            ->whereIn('status', array_merge(Invoice::openIssuedStatuses(), ['partially_paid', 'paid']))
            ->where('status', '!=', 'cancelled');

        if ($request->filled('invoice_status')) {
            $st = $request->string('invoice_status')->toString();
            if ($st === 'unpaid') {
                $query->whereIn('status', Invoice::openIssuedStatuses());
            } elseif ($st === 'partially_paid') {
                $query->where('status', 'partially_paid');
            } elseif ($st === 'paid') {
                $query->where('status', 'paid');
            } elseif ($st === 'overdue') {
                $query->whereIn('status', array_merge(Invoice::openIssuedStatuses(), ['partially_paid']))
                    ->whereNotNull('due_date')
                    ->where('due_date', '<', $today);
            }
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('payment_method')) {
            $query->whereHas('payments', fn ($pq) => $pq->where('method', $request->payment_method));
        }
        if ($request->filled('payment_date_from')) {
            $query->whereHas('payments', fn ($pq) => $pq->whereDate('paid_at', '>=', $request->payment_date_from));
        }
        if ($request->filled('payment_date_to')) {
            $query->whereHas('payments', fn ($pq) => $pq->whereDate('paid_at', '<=', $request->payment_date_to));
        }
        if ($request->filled('link_status') && $request->string('link_status')->toString() === 'expired') {
            $query->whereHas('payments', fn ($pq) => $pq->where('status', Payment::STATUS_EXPIRED));
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('invoice_number', 'like', "%{$s}%")
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('payments', fn ($pq) => $pq->where('payment_number', 'like', "%{$s}%"));
            });
        }

        $paginated = $query->orderByDesc('updated_at')->paginate($request->per_page ?? 15);
        $paginated->getCollection()->transform(function (Invoice $invoice) {
            $latestPayment = $invoice->payments->first();
            $paidAmount = $invoice->paidAmount();
            $outstanding = $invoice->outstandingAmount();

            return [
                'id' => $latestPayment?->id ?? $invoice->id,
                'invoice_id' => $invoice->id,
                'payment_number' => $latestPayment?->payment_number,
                'midtrans_order_id' => $latestPayment?->midtrans_order_id,
                'method' => $latestPayment?->method,
                'amount' => $latestPayment?->amount ?? 0,
                'paid_at' => $latestPayment?->paid_at,
                'invoice' => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'total_amount' => (float) $invoice->total_amount,
                    'status' => $invoice->status,
                    'due_date' => $invoice->due_date?->toDateString(),
                    'company' => $invoice->company,
                ],
                'invoice_amount' => (float) $invoice->total_amount,
                'invoice_paid_amount' => $paidAmount,
                'outstanding_amount' => $outstanding,
                'invoice_ar_status' => $this->resolveInvoiceArStatus($invoice),
                'is_ar_only' => $latestPayment === null,
            ];
        });

        return response()->json($paginated);
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

        $payment = Payment::where('midtrans_order_id', $result['order_id'])->first();
        if ($payment) {
            $midtrans->finalizeSnapPaymentRecord(
                $payment,
                $invoice,
                (int) $request->user()->id,
                'Payment Link dibuat oleh admin.'
            );
        }

        return response()->json([
            'message' => 'Link pembayaran berhasil dibuat.',
            'data' => [
                'payment_url' => $result['redirect_url'],
                'payment_id' => $payment?->id,
                'order_id' => $result['order_id'],
            ],
        ], 201);
    }

    /**
     * Regenerate Midtrans Payment Link when previous link is pending/expired/cancelled.
     */
    public function regeneratePaymentLink(Request $request, Payment $payment, MidtransService $midtrans): JsonResponse
    {
        if (! $request->user()->can('manage_payments')) {
            return response()->json(['message' => 'Tidak ada izin untuk mengelola pembayaran.'], 403);
        }

        $payment->load('invoice.company');
        $invoice = $payment->invoice;

        if (! $invoice) {
            return response()->json(['message' => 'Invoice tidak ditemukan.'], 404);
        }

        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'Invoice ini sudah lunas.'], 422);
        }

        $outstanding = $invoice->outstandingAmount();
        if ($outstanding <= 0) {
            return response()->json(['message' => 'Invoice ini sudah lunas.'], 422);
        }

        if (! in_array($payment->status, ['pending', 'expired', 'failed', 'cancelled'], true)) {
            return response()->json(['message' => 'Payment Link hanya dapat di-regenerate jika status Pending, Expired, atau Cancel.'], 422);
        }

        $company = $invoice->company;
        $customerDetails = [
            'first_name' => $company?->name ?? 'Customer',
            'name' => $company?->name ?? 'Customer',
            'email' => $company?->email ?? '',
            'phone' => $company?->phone ?? '',
        ];

        try {
            $result = $midtrans->createSnapTransaction($invoice, $customerDetails, $outstanding);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Gagal membuat ulang link pembayaran Midtrans.',
                'error' => $e->getMessage(),
            ], 502);
        }

        $newPayment = Payment::where('midtrans_order_id', $result['order_id'])->first();
        if ($newPayment) {
            $midtrans->finalizeSnapPaymentRecord(
                $newPayment,
                $invoice,
                (int) $request->user()->id,
                'Payment Link di-regenerate oleh admin.'
            );
        }

        return response()->json([
            'message' => 'Payment Link berhasil di-regenerate.',
            'data' => [
                'payment_url' => $result['redirect_url'],
                'payment_id' => $newPayment?->id,
                'order_id' => $result['order_id'],
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

        $payment->refresh();

        if (Schema::hasTable('payment_activities')) {
            PaymentActivity::create([
                'payment_id' => $payment->id,
                'actor_user_id' => $request->user()->id,
                'event_key' => $payment->isSuccess() ? 'payment_settled' : 'midtrans_sync',
                'description' => $payment->isSuccess()
                    ? 'Pembayaran berhasil (Settlement).'
                    : 'Status disinkronkan dari Midtrans.',
                'meta' => [
                    'status' => $payment->status,
                    'transaction_id' => $payment->midtrans_transaction_id,
                ],
                'occurred_at' => now(),
            ]);
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
            ->whereIn('status', array_merge(Invoice::openIssuedStatuses(), ['partially_paid']))
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString());

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        $invoices = $query->orderBy('due_date')->paginate($request->per_page ?? 15);

        return response()->json($invoices);
    }

    public function proofPreview(Request $request, Payment $payment): SymfonyResponse
    {
        return $this->streamProofAttachment($request, $payment, forceDownload: false);
    }

    public function proofDownload(Request $request, Payment $payment): SymfonyResponse
    {
        return $this->streamProofAttachment($request, $payment, forceDownload: true);
    }

    public function storeProof(Request $request, Payment $payment): JsonResponse
    {
        if (! $request->user()?->can('manage_payments')) {
            return response()->json(['message' => 'Tidak ada izin untuk mengelola pembayaran.'], 403);
        }

        if (! Schema::hasTable('payment_proof_attachments')) {
            return response()->json(['message' => 'Fitur dokumen pendukung belum tersedia.'], 422);
        }

        $validated = $request->validate([
            'proof_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'category' => ['nullable', 'string', 'in:payment_proof,other'],
        ]);

        $payment->loadMissing('invoice.company');
        $companyId = $payment->invoice?->company_id;
        if (! $companyId) {
            return response()->json(['message' => 'Invoice tidak ditemukan.'], 404);
        }

        $file = $request->file('proof_file');
        $category = $validated['category'] ?? 'payment_proof';
        $path = $file->store('payment-proofs/'.$companyId, 'public');

        $attachment = PaymentProofAttachment::create([
            'payment_id' => $payment->id,
            'uploaded_by' => $request->user()->id,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize() ?: 0,
            'category' => $category,
        ]);

        if (Schema::hasTable('payment_activities')) {
            PaymentActivity::create([
                'payment_id' => $payment->id,
                'actor_user_id' => $request->user()->id,
                'event_key' => 'document_uploaded',
                'description' => $category === 'other'
                    ? 'Dokumen pendukung lain diunggah.'
                    : 'Bukti pembayaran diunggah.',
                'meta' => ['attachment_id' => $attachment->id],
                'occurred_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Dokumen berhasil diunggah.',
            'data' => $attachment,
        ], 201);
    }

    private function streamProofAttachment(Request $request, Payment $payment, bool $forceDownload): SymfonyResponse
    {
        if (! Schema::hasTable('payment_proof_attachments')) {
            abort(404, 'Bukti pembayaran belum diunggah.');
        }

        $attachmentId = $request->integer('attachment_id') ?: null;
        $query = $payment->proofAttachments();
        $attachment = $attachmentId
            ? $query->where('id', $attachmentId)->first()
            : $query->latest('id')->first();

        if (! $attachment || ! Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'Bukti pembayaran tidak ditemukan.');
        }

        $content = Storage::disk('public')->get($attachment->file_path);
        $filename = $attachment->original_name ?: 'payment-proof';
        $mime = $attachment->mime_type ?: 'application/octet-stream';

        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => ($forceDownload ? 'attachment' : 'inline')
                .'; filename="'.addslashes($filename).'"',
        ]);
    }
}
