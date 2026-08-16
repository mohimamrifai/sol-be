<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AdminVendorInvoiceStatus;
use App\Enums\AdminVendorPaymentRequestStatus;
use App\Enums\VendorPaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\CompanyActivity;
use App\Models\VendorInvoice;
use App\Models\VendorPayment;
use App\Models\VendorPaymentDocument;
use App\Models\VendorPaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminVendorPaymentController extends Controller
{
    public function stats(): JsonResponse
    {
        $counts = VendorPaymentRequest::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => [
                'waiting_approval' => (int) ($counts[AdminVendorPaymentRequestStatus::WaitingApproval->value] ?? 0),
                'ready_to_pay' => (int) ($counts[AdminVendorPaymentRequestStatus::ReadyToPay->value] ?? 0),
                'paid' => (int) ($counts[AdminVendorPaymentRequestStatus::Paid->value] ?? 0),
                'cancelled' => (int) ($counts[AdminVendorPaymentRequestStatus::Cancelled->value] ?? 0),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = VendorPaymentRequest::query()
            ->with(['vendorInvoice.vendor:id,name,code']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('payment_number', 'like', "%{$s}%")
                    ->orWhereHas('vendorInvoice', fn ($iq) => $iq
                        ->where('vendor_external_number', 'like', "%{$s}%")
                        ->orWhereHas('vendor', fn ($vq) => $vq->where('name', 'like', "%{$s}%")));
            });
        }
        if ($request->filled('vendor_id')) {
            $query->whereHas('vendorInvoice', fn ($q) => $q->where('vendor_id', $request->vendor_id));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('payment_method')) {
            $query->whereHas('payments', fn ($q) => $q->where('payment_method', $request->payment_method));
        }
        if ($request->filled('date_from')) {
            $query->whereHas('payments', fn ($q) => $q->whereDate('payment_date', '>=', $request->date_from));
        }
        if ($request->filled('date_to')) {
            $query->whereHas('payments', fn ($q) => $q->whereDate('payment_date', '<=', $request->date_to));
        }

        $paginated = $query->orderByDesc('created_at')->paginate($request->per_page ?? 15);
        $paginated->getCollection()->transform(fn (VendorPaymentRequest $req) => $this->transformListRow($req));

        return response()->json($paginated);
    }

    public function show(VendorPaymentRequest $vendorPaymentRequest): JsonResponse
    {
        $vendorPaymentRequest->load([
            'vendorInvoice.vendor',
            'vendorInvoice.jobOrders',
            'approvedByUser:id,name',
            'payments.paidByUser:id,name',
            'documents.uploadedBy:id,name',
        ]);

        return response()->json(['data' => $this->transformDetail($vendorPaymentRequest)]);
    }

    public function approve(Request $request, VendorPaymentRequest $vendorPaymentRequest): JsonResponse
    {
        if ($vendorPaymentRequest->status !== AdminVendorPaymentRequestStatus::WaitingApproval) {
            return response()->json(['message' => 'Payment tidak dapat diapprove.'], 422);
        }

        $data = $request->validate(['approval_remark' => 'nullable|string|max:2000']);

        $vendorPaymentRequest->update([
            'status' => AdminVendorPaymentRequestStatus::ReadyToPay->value,
            'approval_status' => AdminVendorPaymentRequestStatus::ReadyToPay->value,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'approval_remark' => $data['approval_remark'] ?? null,
        ]);

        $this->logActivity($vendorPaymentRequest, 'Payment diapprove oleh Finance Manager.', $request->user()->id);

        return response()->json(['message' => 'Payment disetujui.', 'data' => $this->transformDetail($vendorPaymentRequest->fresh())]);
    }

    public function reject(Request $request, VendorPaymentRequest $vendorPaymentRequest): JsonResponse
    {
        if ($vendorPaymentRequest->status !== AdminVendorPaymentRequestStatus::WaitingApproval) {
            return response()->json(['message' => 'Payment tidak dapat ditolak.'], 422);
        }

        $data = $request->validate(['approval_remark' => 'required|string|max:2000']);

        DB::transaction(function () use ($request, $vendorPaymentRequest, $data) {
            $vendorPaymentRequest->update([
                'status' => AdminVendorPaymentRequestStatus::Cancelled->value,
                'approval_status' => AdminVendorPaymentRequestStatus::Cancelled->value,
                'approval_remark' => $data['approval_remark'],
            ]);

            $this->logActivity($vendorPaymentRequest, 'Payment ditolak.', $request->user()->id);

            $invoice = $vendorPaymentRequest->vendorInvoice;
            if ($invoice && $invoice->statusValue() === AdminVendorInvoiceStatus::ReadyForPayment->value) {
                VendorPaymentRequest::create([
                    'vendor_invoice_id' => $invoice->id,
                    'status' => AdminVendorPaymentRequestStatus::WaitingApproval->value,
                    'approval_status' => AdminVendorPaymentRequestStatus::WaitingApproval->value,
                    'invoice_amount' => $invoice->total_amount,
                    'approved_amount' => $invoice->total_amount,
                    'vendor_snapshot' => $vendorPaymentRequest->vendor_snapshot,
                    'created_by' => $request->user()->id,
                ]);
            }
        });

        return response()->json(['message' => 'Payment ditolak.', 'data' => $this->transformDetail($vendorPaymentRequest->fresh())]);
    }

    public function companyBanks(): JsonResponse
    {
        return response()->json(['data' => config('payment.company_banks', [])]);
    }

    public function voucher(VendorPaymentRequest $vendorPaymentRequest): JsonResponse
    {
        if ($vendorPaymentRequest->status !== AdminVendorPaymentRequestStatus::Paid) {
            return response()->json(['message' => 'Voucher hanya tersedia untuk payment Paid.'], 422);
        }

        $detail = $this->transformDetail($vendorPaymentRequest->load(['vendorInvoice.vendor', 'payments.paidByUser']));
        $html = view('pdf.vendor-payment-voucher', ['payment' => $detail])->render();

        return response()->json(['data' => ['html' => $html, 'payment_number' => $detail['payment_number']]]);
    }

    public function recordPayment(Request $request, VendorPaymentRequest $vendorPaymentRequest): JsonResponse
    {
        if ($vendorPaymentRequest->status !== AdminVendorPaymentRequestStatus::ReadyToPay) {
            return response()->json(['message' => 'Payment belum siap dicatat.'], 422);
        }

        $data = $request->validate([
            'payment_method' => 'required|in:transfer,giro,cash,virtual_account,bank_transfer',
            'company_bank' => 'required_if:payment_method,transfer|nullable|string|max:120',
            'payment_date' => 'required|date',
            'payment_amount' => 'required|numeric|min:0.01',
            'reference_no' => 'nullable|string|max:100',
            'payment_remark' => 'nullable|string|max:2000',
            'payment_proof' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $outstanding = $vendorPaymentRequest->outstandingAmount();
        if ((float) $data['payment_amount'] > $outstanding + 0.01) {
            return response()->json(['message' => 'Jumlah pembayaran melebihi outstanding.'], 422);
        }

        $proofPath = $request->hasFile('payment_proof')
            ? $request->file('payment_proof')->store('vendor-payments/proofs', 'public')
            : null;

        DB::transaction(function () use ($request, $vendorPaymentRequest, $data, $proofPath) {
            $method = $data['payment_method'] === 'transfer' ? 'bank_transfer' : $data['payment_method'];

            VendorPayment::create([
                'vendor_invoice_id' => $vendorPaymentRequest->vendor_invoice_id,
                'vendor_payment_request_id' => $vendorPaymentRequest->id,
                'amount' => $data['payment_amount'],
                'payment_date' => $data['payment_date'],
                'payment_method' => $method,
                'company_bank' => $data['company_bank'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'status' => VendorPaymentStatus::Paid->value,
                'payment_proof_path' => $proofPath,
                'paid_by' => $request->user()->id,
                'notes' => $data['payment_remark'] ?? null,
            ]);

            $newPaid = (float) $vendorPaymentRequest->paid_amount + (float) $data['payment_amount'];
            $vendorPaymentRequest->update(['paid_amount' => $newPaid]);

            if ($newPaid >= (float) $vendorPaymentRequest->approved_amount) {
                $vendorPaymentRequest->update(['status' => AdminVendorPaymentRequestStatus::Paid->value]);
                $vendorPaymentRequest->vendorInvoice?->update(['status' => AdminVendorInvoiceStatus::Paid->value]);
            }

            $amountLabel = number_format((float) $data['payment_amount'], 0, ',', '.');
            $this->logActivity($vendorPaymentRequest, "Payment sebesar Rp{$amountLabel} dicatat.", $request->user()->id);
            if ($proofPath) {
                $this->logActivity($vendorPaymentRequest, 'Bukti transfer diunggah.', $request->user()->id);
            }
            if ($vendorPaymentRequest->fresh()->status === AdminVendorPaymentRequestStatus::Paid) {
                $this->logActivity($vendorPaymentRequest, 'Status berubah menjadi Paid.', $request->user()->id);
            }
        });

        return response()->json(['message' => 'Pembayaran dicatat.', 'data' => $this->transformDetail($vendorPaymentRequest->fresh())]);
    }

    public function storeDocument(Request $request, VendorPaymentRequest $vendorPaymentRequest): JsonResponse
    {
        $data = $request->validate([
            'document_type' => 'required|in:other_document,tax_invoice',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $file = $request->file('file');
        $path = $file->store('vendor-payments/'.$vendorPaymentRequest->id.'/documents', 'public');

        if ($data['document_type'] === 'tax_invoice') {
            $invoice = $vendorPaymentRequest->vendorInvoice;
            if ($invoice) {
                $invoice->update(['tax_invoice_path' => $path]);
            }
        }

        $doc = VendorPaymentDocument::create([
            'vendor_payment_request_id' => $vendorPaymentRequest->id,
            'document_type' => $data['document_type'],
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()?->id,
        ]);

        $label = $data['document_type'] === 'tax_invoice' ? 'Tax Invoice' : 'Other Document';
        $this->logActivity($vendorPaymentRequest, "{$label} diunggah.", $request->user()->id);

        return response()->json(['message' => 'Dokumen diunggah.', 'data' => $this->transformPaymentDocument($doc)], 201);
    }

    public function downloadDocument(VendorPaymentRequest $vendorPaymentRequest, VendorPaymentDocument $document): JsonResponse
    {
        abort_unless($document->vendor_payment_request_id === $vendorPaymentRequest->id, 404);

        return response()->json([
            'data' => [
                'url' => Storage::disk('public')->url($document->file_path),
                'name' => $document->original_name,
            ],
        ]);
    }

    private function transformPaymentDocument(VendorPaymentDocument $doc): array
    {
        return [
            'id' => $doc->id,
            'document_type' => $doc->document_type,
            'original_name' => $doc->original_name,
            'mime_type' => $doc->mime_type,
            'size' => $doc->size,
            'url' => Storage::disk('public')->url($doc->file_path),
            'uploaded_by' => $doc->uploadedBy?->name,
            'created_at' => $doc->created_at?->toIso8601String(),
        ];
    }

    private function transformListRow(VendorPaymentRequest $req): array
    {
        $inv = $req->vendorInvoice;
        $lastPayment = $req->payments()->orderByDesc('payment_date')->first();

        return [
            'id' => $req->id,
            'payment_number' => $req->payment_number,
            'vendor' => $inv?->vendor?->name,
            'vendor_invoice_no' => $inv?->vendor_external_number ?? $inv?->invoice_number,
            'vendor_invoice_id' => $inv?->id,
            'invoice_amount' => $req->invoice_amount,
            'paid_amount' => $req->paid_amount,
            'payment_date' => $lastPayment?->payment_date?->toDateString(),
            'status' => $req->status?->value ?? $req->status,
        ];
    }

    private function transformDetail(VendorPaymentRequest $req): array
    {
        $inv = $req->vendorInvoice;
        $snap = $req->vendor_snapshot ?? [];

        $activities = CompanyActivity::query()
            ->where('subject_type', VendorPaymentRequest::class)
            ->where('subject_id', $req->id)
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->limit(30)
            ->get()
            ->map(fn ($a) => [
                'activity' => $a->description,
                'created_at' => $a->occurred_at?->toIso8601String(),
                'created_by' => $a->actor?->name,
            ]);

        return array_merge($this->transformListRow($req), [
            'created_at' => $req->created_at?->toIso8601String(),
            'approval_status' => $req->approval_status?->value ?? $req->approval_status,
            'approved_by' => $req->approvedByUser?->name,
            'approved_at' => $req->approved_at?->toIso8601String(),
            'approval_remark' => $req->approval_remark,
            'approved_amount' => $req->approved_amount,
            'outstanding_amount' => $req->outstandingAmount(),
            'vendor_code' => $snap['code'] ?? $inv?->vendor?->code,
            'vendor_name' => $snap['name'] ?? $inv?->vendor?->name,
            'payment_terms' => $snap['payment_terms'] ?? $inv?->vendor?->payment_terms,
            'bank_account' => trim(($snap['bank_name'] ?? $inv?->vendor?->bank_name ?? '').' · '.($snap['bank_account_number'] ?? $inv?->vendor?->bank_account_number ?? '')),
            'vendor_category' => $snap['vendor_category'] ?? $inv?->vendor?->vendor_category,
            'vendor_types' => $snap['vendor_types'] ?? $inv?->vendor?->vendor_types,
            'tax_invoice_url' => $inv?->tax_invoice_path ? Storage::disk('public')->url($inv->tax_invoice_path) : null,
            'other_documents' => $req->documents
                ->where('document_type', 'other_document')
                ->map(fn (VendorPaymentDocument $d) => $this->transformPaymentDocument($d))
                ->values(),
            'can_print_voucher' => ($req->status?->value ?? $req->status) === AdminVendorPaymentRequestStatus::Paid->value,
            'invoice_date' => $inv?->invoice_date?->toDateString(),
            'due_date' => $inv?->due_date?->toDateString(),
            'invoice_file' => $inv?->file_path ? Storage::disk('public')->url($inv->file_path) : null,
            'payment_history' => $req->payments->map(fn (VendorPayment $p) => [
                'payment_date' => $p->payment_date?->toDateString(),
                'amount' => $p->amount,
                'method' => $p->payment_method,
                'reference_no' => $p->reference_no,
                'paid_by' => $p->paidByUser?->name,
                'payment_proof' => $p->payment_proof_path ? Storage::disk('public')->url($p->payment_proof_path) : null,
            ]),
            'activity_log' => $activities,
        ]);
    }

    private function logActivity(VendorPaymentRequest $req, string $description, int $userId): void
    {
        CompanyActivity::create([
            'subject_type' => VendorPaymentRequest::class,
            'subject_id' => $req->id,
            'event_key' => 'admin_vendor_payment',
            'description' => $description,
            'actor_user_id' => $userId,
            'occurred_at' => now(),
        ]);
    }
}
