<?php

namespace App\Http\Controllers\Api\Customer;

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
    public const PAYMENT_METHODS = ['transfer', 'giro', 'cash', 'virtual_account', 'midtrans'];

    public function __construct(
        private MidtransService $midtransService,
        private PaymentNumberService $paymentNumber,
        private DocumentPdfService $pdf,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = Carbon::today();

        $base = Invoice::query()->where('company_id', $user->company_id);

        $paid = (clone $base)->where('status', 'paid')->count();
        $partiallyPaid = (clone $base)
            ->where('status', 'partially_paid')
            ->where(function ($q) use ($today) {
                $q->whereNull('due_date')->orWhere('due_date', '>=', $today);
            })
            ->count();
        $unpaid = (clone $base)
            ->whereIn('status', ['issued', 'draft'])
            ->count();
        $overdue = (clone $base)
            ->whereIn('status', ['issued', 'partially_paid'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->whereRaw(
                "(invoices.total_amount - COALESCE((select sum(payments.amount) from payments where payments.invoice_id = invoices.id and payments.status in ('success','settlement')), 0)) > 0"
            )
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

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:unpaid,partially_paid,paid,overdue',
            'payment_method' => 'nullable|string|in:'.implode(',', self::PAYMENT_METHODS),
            'payment_date_from' => 'nullable|date',
            'payment_date_to' => 'nullable|date',
            'invoice_id' => 'nullable|integer',
        ]);

        $query = Payment::query()
            ->whereHas('invoice', fn ($q) => $q->where('company_id', $user->company_id))
            ->with([
                'invoice:id,invoice_number,shipment_id,total_amount,status,due_date',
                'invoice.shipment:id,shipment_number,waybill_number',
            ]);

        if (! empty($validated['status'])) {
            $st = $validated['status'];
            if ($st === 'unpaid') {
                $query->where('status', 'pending');
            } elseif ($st === 'partially_paid') {
                $query->where('status', 'success')
                    ->whereHas('invoice', fn ($q) => $q->where('status', 'partially_paid'));
            } elseif ($st === 'paid') {
                $query->where('status', 'success')
                    ->whereHas('invoice', fn ($q) => $q->where('status', 'paid'));
            } elseif ($st === 'overdue') {
                $today = Carbon::today();
                $query->whereHas('invoice', function ($q) use ($today) {
                    $q->whereIn('status', ['issued', 'partially_paid'])
                        ->whereNotNull('due_date')
                        ->where('due_date', '<', $today)
                        ->whereRaw('(invoices.total_amount - COALESCE((select sum(p.amount) from payments p where p.invoice_id = invoices.id and p.status in (\'success\',\'settlement\')), 0)) > 0');
                });
            }
        }

        if (! empty($validated['payment_method'])) {
            $query->where('method', $validated['payment_method']);
        }

        if (! empty($validated['payment_date_from'])) {
            $query->whereDate('paid_at', '>=', $validated['payment_date_from']);
        }
        if (! empty($validated['payment_date_to'])) {
            $query->whereDate('paid_at', '<=', $validated['payment_date_to']);
        }

        if (! empty($validated['invoice_id'])) {
            $query->where('invoice_id', $validated['invoice_id']);
        }

        if (! empty($validated['search'])) {
            $s = $validated['search'];
            $query->where(function ($q) use ($s) {
                $q->where('midtrans_order_id', 'like', "%{$s}%")
                    ->orWhere('midtrans_transaction_id', 'like', "%{$s}%")
                    ->orWhere('payment_number', 'like', "%{$s}%")
                    ->orWhereHas('invoice', fn ($iq) => $iq->where('invoice_number', 'like', "%{$s}%"));
            });
        }

        $paginated = $query->orderBy('created_at', 'desc')->paginate($validated['per_page'] ?? 15);

        $paginated->getCollection()->transform(function (Payment $p) {
            $invoice = $p->invoice;
            $paidAmount = $invoice ? (float) $invoice->paidAmount() : 0.0;
            $total = (float) ($invoice?->total_amount ?? 0);
            $outstanding = $invoice ? max($total - $paidAmount, 0) : 0;

            return [
                'id' => $p->id,
                'payment_no' => $p->displayNumber(),
                'payment_number' => $p->payment_number,
                'invoice_id' => $p->invoice_id,
                'invoice_number' => $invoice?->invoice_number,
                'shipment_number' => $invoice?->shipment?->shipment_number,
                'invoice_amount' => $total,
                'paid_amount' => $paidAmount,
                'outstanding_amount' => $outstanding,
                'amount' => (float) $p->amount,
                'payment_method' => $p->method ?? ($p->payment_type ?: 'midtrans'),
                'payment_type' => $p->payment_type,
                'payment_date' => $p->paid_at?->toIso8601String() ?? $p->created_at?->toIso8601String(),
                'status' => $p->status,
                'actions' => [
                    'can_view' => true,
                ],
            ];
        });

        return response()->json($paginated);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $user = $request->user();

        $payment->load(['invoice', 'invoice.company', 'invoice.shipment', 'invoice.shipment.booking', 'invoice.payments']);

        // Treat cross-tenant access as "not found" so unauthorized users cannot
        // enumerate payment ids that belong to other companies.
        if (! $payment->invoice || $payment->invoice->company_id !== $user->company_id) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        if (Schema::hasTable('payment_activities')) {
            $payment->load(['activities.actor:id,name']);
        }
        if (Schema::hasTable('payment_proof_attachments')) {
            $payment->load(['proofAttachments.uploader:id,name']);
        }

        $invoice = $payment->invoice;
        $paidAmount = (float) $invoice->paidAmount();
        $outstanding = max((float) $invoice->total_amount - $paidAmount, 0);
        $company = $invoice->company;

        $manualEnabled = (bool) ($company->manual_payment_enabled ?? false);
        $bankAccount = $manualEnabled ? [
            'bank_name' => $company->bank_name,
            'account_number' => $company->bank_account_number,
            'account_name' => $company->bank_account_name,
        ] : null;

        $midtransResponse = (array) ($payment->midtrans_response ?? []);
        $expiredAt = $payment->expired_at?->toIso8601String()
            ?? (isset($midtransResponse['expiry_time']) ? Carbon::parse($midtransResponse['expiry_time'])->toIso8601String() : null);

        $linkActive = $payment->status === 'pending' && (! $expiredAt || Carbon::parse($expiredAt)->isFuture());

        $paymentHistory = $invoice->payments
            ->sortByDesc(fn ($p) => $p->paid_at ?? $p->created_at)
            ->values()
            ->map(function (Payment $p) {
                return [
                    'id' => $p->id,
                    'payment_date' => ($p->paid_at ?? $p->created_at)?->toIso8601String(),
                    'amount' => (float) $p->amount,
                    'payment_method' => $p->method ?? $p->payment_type,
                    'reference_no' => $p->midtrans_order_id ?? $p->manual_reference_number,
                    'status' => $p->status,
                ];
            });

        $timeline = collect();
        $timeline->push([
            'occurred_at' => $payment->created_at?->toIso8601String(),
            'activity' => 'Payment dibuat',
        ]);
        if ($payment->status === 'pending' && $payment->midtrans_order_id) {
            $timeline->push([
                'occurred_at' => $payment->created_at?->toIso8601String(),
                'activity' => 'Payment Link dibuat',
            ]);
        }
        if ($payment->paid_at) {
            $timeline->push([
                'occurred_at' => $payment->paid_at->toIso8601String(),
                'activity' => 'Pembayaran diterima',
            ]);
        }
        if ($payment->isSuccess() && $invoice) {
            $timeline->push([
                'occurred_at' => $payment->paid_at?->toIso8601String() ?? $payment->created_at?->toIso8601String(),
                'activity' => 'Status Invoice menjadi '.$invoice->status,
            ]);
        }

        if (Schema::hasTable('payment_activities')) {
            foreach ($payment->activities ?? collect() as $act) {
                $timeline->push([
                    'occurred_at' => $act->occurred_at?->toIso8601String(),
                    'activity' => $act->description ?? $act->event_key,
                ]);
            }
        }

        $timeline = $timeline
            ->filter(fn ($e) => ! empty($e['occurred_at']))
            ->sortBy(fn ($e) => $e['occurred_at'])
            ->values();

        $supportingDocs = [];
        $supportingDocs[] = [
            'key' => 'payment_receipt',
            'label' => 'Payment Receipt',
            'available' => $payment->isSuccess(),
            'view_path' => "/customer/payments/{$payment->id}/receipt",
            'download_path' => "/customer/payments/{$payment->id}/receipt?download=1",
        ];
        if (Schema::hasTable('payment_proof_attachments') && $payment->proofAttachments->isNotEmpty()) {
            $attachment = $payment->proofAttachments->first();
            $supportingDocs[] = [
                'key' => 'payment_proof',
                'label' => 'Payment Proof',
                'available' => true,
                'view_path' => "/customer/payments/{$payment->id}/proof-preview",
                'download_path' => "/customer/payments/{$payment->id}/proof-download",
                'meta' => [
                    'original_name' => $attachment->original_name,
                    'file_size' => $attachment->file_size,
                    'mime_type' => $attachment->mime_type,
                ],
            ];
        }

        return response()->json([
            'data' => [
                'id' => $payment->id,
                'payment_no' => $payment->displayNumber(),
                'payment_number' => $payment->payment_number,
                'midtrans_order_id' => $payment->midtrans_order_id,
                'invoice' => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => $invoice->issued_date?->toDateString(),
                    'due_date' => $invoice->due_date?->toDateString(),
                    'currency' => 'IDR',
                    'invoice_amount' => (float) $invoice->total_amount,
                    'paid_amount' => $paidAmount,
                    'outstanding_amount' => $outstanding,
                    'status' => $invoice->status,
                ],
                'status' => $payment->status,
                'created_date' => $payment->created_at?->toIso8601String(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'payment_method' => $payment->method ?? $payment->payment_type,
                'amount' => (float) $payment->amount,
                'manual' => [
                    'status' => $payment->manual_status ?? Payment::MANUAL_UNSUBMITTED,
                    'payment_date' => $payment->manual_payment_date?->toDateString(),
                    'bank_name' => $payment->manual_bank_name,
                    'reference_number' => $payment->manual_reference_number,
                    'remark' => $payment->manual_remark,
                    'submitted_at' => $payment->manual_submitted_at?->toIso8601String(),
                    'verified_at' => $payment->manual_verified_at?->toIso8601String(),
                ],
                'payment_history' => $paymentHistory,
                'payment_summary' => [
                    'total_paid' => $paidAmount,
                    'outstanding_amount' => $outstanding,
                ],
                'online_payment' => [
                    'active' => $linkActive,
                    'link' => $midtransResponse['redirect_url'] ?? null,
                    'token' => $midtransResponse['token'] ?? null,
                    'expired_at' => $expiredAt,
                    'payment_gateway' => 'Midtrans',
                    'transaction_id' => $payment->midtrans_transaction_id,
                    'payment_status' => $payment->status,
                ],
                'manual_payment' => [
                    'enabled' => $manualEnabled,
                    'bank_account' => $bankAccount,
                ],
                'supporting_documents' => $supportingDocs,
                'activity_timeline' => $timeline,
                'actions' => [
                    'can_pay_now' => $outstanding > 0 && ! in_array($invoice->status, ['draft', 'cancelled', 'paid'], true),
                ],
            ],
        ]);
    }

    public function pay(Request $request, Invoice $invoice): JsonResponse
    {
        $user = $request->user();

        if ($invoice->company_id !== $user->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'Invoice ini sudah dibayar.'], 422);
        }

        if ($invoice->status === 'draft') {
            return response()->json(['message' => 'Invoice ini belum diterbitkan.'], 422);
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
            $outstanding = $invoice->outstandingAmount();
            if ($outstanding <= 0) {
                return response()->json(['message' => 'Invoice ini sudah lunas.'], 422);
            }

            // Idempotency guard: if there is already an active Midtrans pending payment
            // for this invoice (token still cached, not expired), reuse it. Prevents
            // double-click from creating two Snap transactions and two Payment rows.
            $existing = $this->findReusablePendingPayment($invoice);
            if ($existing !== null) {
                $cached = $existing->midtrans_response ?? [];
                $reused = [
                    'order_id' => $existing->midtrans_order_id,
                    'token' => $cached['token'] ?? null,
                    'redirect_url' => $cached['redirect_url'] ?? null,
                ];
                if (! empty($reused['token'])) {
                    return response()->json([
                        'message' => 'Silakan selesaikan pembayaran.',
                        'data' => $reused,
                    ], 200);
                }
            }

            $result = $this->midtransService->createSnapTransaction($invoice, $customerDetails, $outstanding);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Gagal membuat transaksi pembayaran.',
                'error' => $e->getMessage(),
            ], 502);
        }

        $payment = Payment::where('midtrans_order_id', $result['order_id'])->first();

        if ($payment) {
            $next = $this->paymentNumber->next((int) $invoice->company_id);

            $expiryRaw = $result['expiry_time'] ?? null;
            $expiryFromMidtrans = $expiryRaw !== null ? Carbon::parse($expiryRaw) : null;

            $payment->forceFill([
                'payment_number' => $next,
                'method' => Payment::METHOD_MIDTRANS,
                'expired_at' => $expiryFromMidtrans ?? now()->addDay(),
            ])->save();

            if (Schema::hasTable('payment_activities')) {
                PaymentActivity::create([
                    'payment_id' => $payment->id,
                    'actor_user_id' => $user->id,
                    'event_key' => 'payment_link_generated',
                    'description' => 'Payment Link dibuat via Midtrans Snap.',
                    'meta' => [
                        'order_id' => $result['order_id'],
                        'amount' => (float) $outstanding,
                    ],
                    'occurred_at' => now(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Silakan selesaikan pembayaran.',
            'data' => $result,
        ], 201);
    }

    /**
     * Find an existing Midtrans pending payment for this invoice that can be reused.
     * Only returns a row when:
     *  - the row exists, status=pending, method=midtrans;
     *  - expiry has not passed;
     *  - the cached token is still present in midtrans_response.
     */
    private function findReusablePendingPayment(Invoice $invoice): ?Payment
    {
        return Payment::query()
            ->where('invoice_id', $invoice->id)
            ->where('method', Payment::METHOD_MIDTRANS)
            ->where('status', Payment::STATUS_PENDING)
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', now())
            ->orderByDesc('id')
            ->first();
    }

    public function syncMidtrans(Request $request, Payment $payment): JsonResponse
    {
        $user = $request->user();

        $payment->loadMissing('invoice');
        if (! $payment->invoice || $payment->invoice->company_id !== $user->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if (! $payment->midtrans_order_id) {
            return response()->json(['message' => 'Pembayaran ini tidak memiliki Order ID Midtrans.'], 422);
        }

        try {
            $this->midtransService->syncPaymentFromMidtrans($payment);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payment->refresh();
        $payment->load(['invoice']);

        if (Schema::hasTable('payment_activities')) {
            PaymentActivity::create([
                'payment_id' => $payment->id,
                'actor_user_id' => $user->id,
                'event_key' => $payment->isSuccess() ? 'payment_settled' : 'midtrans_callback',
                'description' => $payment->isSuccess() ? 'Pembayaran berhasil (Settlement).' : 'Midtrans mengirim callback.',
                'meta' => [
                    'status' => $payment->status,
                    'transaction_id' => $payment->midtrans_transaction_id,
                ],
                'occurred_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Status disinkronkan dari Midtrans.',
            'data' => [
                'id' => $payment->id,
                'status' => $payment->status,
                'is_success' => $payment->isSuccess(),
            ],
        ]);
    }

    public function manualSubmit(Request $request, Payment $payment)
    {
        $user = $request->user();

        $payment->loadMissing('invoice.company');
        $company = $payment->invoice?->company;
        if (! $company || $company->id !== $user->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if (! $company->manual_payment_enabled) {
            return response()->json(['message' => 'Pembayaran manual tidak diaktifkan untuk perusahaan ini.'], 422);
        }

        if ($payment->isSuccess()) {
            return response()->json(['message' => 'Pembayaran sudah berhasil; bukti tidak dapat diunggah ulang.'], 422);
        }

        $validated = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:1'],
            'bank_name' => ['required', 'string', 'max:120'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'proof_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $file = $request->file('proof_file');
        $dir = 'payment-proofs/'.$company->id;
        $path = $file->store($dir, 'public');

        $attachment = PaymentProofAttachment::create([
            'payment_id' => $payment->id,
            'uploaded_by' => $user->id,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize() ?: 0,
            'category' => 'payment_proof',
        ]);

        $payment->forceFill([
            'manual_status' => Payment::MANUAL_SUBMITTED,
            'manual_submitted_at' => now(),
            'manual_payment_date' => $validated['payment_date'],
            'manual_bank_name' => $validated['bank_name'],
            'manual_reference_number' => $validated['reference_number'] ?? null,
            'manual_remark' => $validated['remark'] ?? null,
            'method' => $payment->method ?: Payment::METHOD_TRANSFER,
        ])->save();

        if (Schema::hasTable('payment_activities')) {
            PaymentActivity::create([
                'payment_id' => $payment->id,
                'actor_user_id' => $user->id,
                'event_key' => 'payment_proof_uploaded',
                'description' => 'Customer mengunggah bukti pembayaran manual.',
                'meta' => [
                    'attachment_id' => $attachment->id,
                    'amount' => (float) $validated['amount'],
                    'bank_name' => $validated['bank_name'],
                ],
                'occurred_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Bukti pembayaran berhasil diunggah. Menunggu verifikasi tim finance.',
            'data' => [
                'attachment_id' => $attachment->id,
                'manual_status' => $payment->manual_status,
            ],
        ], 201);
    }

    public function receipt(Request $request, Payment $payment): SymfonyResponse
    {
        $user = $request->user();
        $payment->loadMissing('invoice');
        if (! $payment->invoice || $payment->invoice->company_id !== $user->company_id) {
            abort(403, 'Akses ditolak.');
        }

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

    public function proofPreview(Request $request, Payment $payment): SymfonyResponse
    {
        return $this->streamProofAttachment($request, $payment, forceDownload: false);
    }

    public function proofDownload(Request $request, Payment $payment): SymfonyResponse
    {
        return $this->streamProofAttachment($request, $payment, forceDownload: true);
    }

    private function streamProofAttachment(Request $request, Payment $payment, bool $forceDownload): SymfonyResponse
    {
        $user = $request->user();
        $payment->loadMissing('invoice');
        if (! $payment->invoice || $payment->invoice->company_id !== $user->company_id) {
            abort(403, 'Akses ditolak.');
        }

        if (! Schema::hasTable('payment_proof_attachments')) {
            abort(404, 'Bukti pembayaran belum diunggah.');
        }

        $attachment = $payment->proofAttachments()->latest('id')->first();
        if (! $attachment || ! Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'Bukti pembayaran tidak ditemukan.');
        }

        $content = Storage::disk('public')->get($attachment->file_path);
        $filename = $attachment->original_name ?: 'payment-proof';
        $mime = $attachment->mime_type ?: $this->guessMime($attachment->file_path);

        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => ($forceDownload ? 'attachment' : 'inline')
                .'; filename="'.addslashes($filename).'"',
        ]);
    }

    private function guessMime(string $path): string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
