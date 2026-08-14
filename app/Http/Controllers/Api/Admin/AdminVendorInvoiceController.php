<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AdminVendorInvoiceStatus;
use App\Enums\AdminVendorPaymentRequestStatus;
use App\Enums\VendorJobOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\CompanyActivity;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorInvoiceAttachment;
use App\Models\VendorJobOrder;
use App\Models\VendorPaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;

class AdminVendorInvoiceController extends Controller
{
    public function stats(): JsonResponse
    {
        $base = VendorInvoice::query()->where('source', 'admin');
        $counts = (clone $base)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'data' => [
                'received' => (int) ($counts[AdminVendorInvoiceStatus::Received->value] ?? 0),
                'under_verification' => (int) ($counts[AdminVendorInvoiceStatus::UnderVerification->value] ?? 0),
                'ready_for_payment' => (int) ($counts[AdminVendorInvoiceStatus::ReadyForPayment->value] ?? 0),
                'paid' => (int) ($counts[AdminVendorInvoiceStatus::Paid->value] ?? 0),
                'rejected' => (int) ($counts[AdminVendorInvoiceStatus::Rejected->value] ?? 0),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = VendorInvoice::query()
            ->where('source', 'admin')
            ->with('vendor:id,name,code');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('vendor_external_number', 'like', "%{$s}%")
                    ->orWhere('invoice_number', 'like', "%{$s}%")
                    ->orWhereHas('vendor', fn ($vq) => $vq->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('jobOrders', fn ($jq) => $jq
                        ->where('job_order_number', 'like', "%{$s}%")
                        ->orWhereHas('shipment', fn ($sq) => $sq->where('shipment_number', 'like', "%{$s}%")));
            });
        }
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('invoice_date_from')) {
            $query->whereDate('invoice_date', '>=', $request->invoice_date_from);
        }
        if ($request->filled('invoice_date_to')) {
            $query->whereDate('invoice_date', '<=', $request->invoice_date_to);
        }
        if ($request->filled('receive_date_from')) {
            $query->whereDate('receive_date', '>=', $request->receive_date_from);
        }
        if ($request->filled('receive_date_to')) {
            $query->whereDate('receive_date', '<=', $request->receive_date_to);
        }

        $paginated = $query->orderByDesc('created_at')->paginate($request->per_page ?? 15);
        $paginated->getCollection()->transform(fn (VendorInvoice $inv) => $this->transformListRow($inv));

        return response()->json($paginated);
    }

    public function eligibleJobOrders(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|exists:vendors,id']);

        $rows = VendorJobOrder::query()
            ->where('vendor_id', $request->vendor_id)
            ->where('status', VendorJobOrderStatus::Completed->value)
            ->whereDoesntHave('vendorInvoices', fn ($q) => $q->where('source', 'admin')->whereNotIn('status', [AdminVendorInvoiceStatus::Rejected->value]))
            ->with('shipment:id,shipment_number')
            ->orderByDesc('completed_at')
            ->get()
            ->map(fn (VendorJobOrder $jo) => [
                'id' => $jo->id,
                'job_order_number' => $jo->job_order_number,
                'shipment_number' => $jo->shipment?->shipment_number,
                'completion_date' => $jo->completed_at?->toDateString(),
                'amount' => (float) $jo->total_cost,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function show(VendorInvoice $vendorInvoice): JsonResponse
    {
        abort_unless($vendorInvoice->source === 'admin', 404);
        $vendorInvoice->load(['vendor', 'jobOrders.shipment', 'reviewedByUser:id,name', 'attachments']);

        return response()->json(['data' => $this->transformDetail($vendorInvoice)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'vendor_external_number' => 'required|string|max:80',
            'invoice_date' => 'required|date',
            'invoice_amount' => 'required|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'remark' => 'nullable|string|max:2000',
            'job_order_ids' => 'required|array|min:1',
            'job_order_ids.*' => 'integer|exists:vendor_job_orders,id',
            'invoice_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $vendor = Vendor::active()->findOrFail($data['vendor_id']);

        $duplicate = VendorInvoice::query()
            ->where('vendor_id', $vendor->id)
            ->where('vendor_external_number', $data['vendor_external_number'])
            ->exists();
        if ($duplicate) {
            return response()->json(['message' => 'Nomor invoice vendor sudah digunakan untuk vendor ini.'], 422);
        }

        $jobOrders = VendorJobOrder::query()
            ->whereIn('id', $data['job_order_ids'])
            ->where('vendor_id', $vendor->id)
            ->where('status', VendorJobOrderStatus::Completed->value)
            ->whereDoesntHave('vendorInvoices', fn ($q) => $q->where('source', 'admin')->whereNotIn('status', [AdminVendorInvoiceStatus::Rejected->value]))
            ->get();

        if ($jobOrders->count() !== count($data['job_order_ids'])) {
            return response()->json(['message' => 'Job Order tidak valid, belum completed, atau sudah ditagihkan.'], 422);
        }

        $tax = (float) ($data['tax_amount'] ?? 0);
        $total = (float) $data['invoice_amount'] + $tax;
        $path = $request->file('invoice_file')->store('vendor-invoices/admin', 'public');
        $dueDate = $this->calculateDueDate($vendor, $data['invoice_date']);

        $invoice = DB::transaction(function () use ($request, $data, $vendor, $jobOrders, $tax, $total, $path, $dueDate) {
            $invoice = VendorInvoice::create([
                'vendor_id' => $vendor->id,
                'vendor_company_id' => null,
                'shipment_id' => $jobOrders->first()->shipment_id,
                'vendor_external_number' => $data['vendor_external_number'],
                'invoice_date' => $data['invoice_date'],
                'receive_date' => now()->toDateString(),
                'due_date' => $dueDate,
                'invoice_amount' => $data['invoice_amount'],
                'tax_amount' => $tax,
                'total_amount' => $total,
                'currency' => $data['currency'] ?? 'IDR',
                'source' => 'admin',
                'status' => AdminVendorInvoiceStatus::Received->value,
                'notes' => $data['remark'] ?? null,
                'file_path' => $path,
                'created_by' => $request->user()->id,
            ]);

            foreach ($jobOrders as $jo) {
                $invoice->jobOrders()->attach($jo->id, ['amount' => $jo->total_cost]);
                $this->logActivity($invoice, 'Job Order '.$jo->job_order_number.' ditambahkan.', $request->user()->id);
            }

            $this->logActivity($invoice, 'Vendor Invoice diterima.', $request->user()->id);
            $this->logActivity($invoice, 'Invoice PDF diunggah.', $request->user()->id);

            return $invoice;
        });

        return response()->json(['message' => 'Invoice vendor diterima.', 'data' => $this->transformDetail($invoice->fresh(['vendor', 'jobOrders']))], 201);
    }

    public function startVerification(Request $request, VendorInvoice $vendorInvoice): JsonResponse
    {
        abort_unless($vendorInvoice->source === 'admin', 404);
        if ($vendorInvoice->statusValue() !== AdminVendorInvoiceStatus::Received->value) {
            return response()->json(['message' => 'Invoice tidak dapat memulai verifikasi.'], 422);
        }

        $vendorInvoice->update(['status' => AdminVendorInvoiceStatus::UnderVerification->value]);
        $this->logActivity($vendorInvoice, 'Invoice masuk Under Verification.', $request->user()->id);

        return response()->json(['message' => 'Verifikasi dimulai.', 'data' => $this->transformDetail($vendorInvoice->fresh())]);
    }

    public function verify(Request $request, VendorInvoice $vendorInvoice): JsonResponse
    {
        abort_unless($vendorInvoice->source === 'admin', 404);
        if (! in_array($vendorInvoice->statusValue(), [AdminVendorInvoiceStatus::Received->value, AdminVendorInvoiceStatus::UnderVerification->value], true)) {
            return response()->json(['message' => 'Invoice tidak dapat diverifikasi.'], 422);
        }

        $data = $request->validate(['verification_notes' => 'nullable|string|max:2000']);

        DB::transaction(function () use ($request, $vendorInvoice, $data) {
            $vendorInvoice->update([
                'status' => AdminVendorInvoiceStatus::ReadyForPayment->value,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'notes' => $data['verification_notes'] ?? $vendorInvoice->notes,
            ]);

            VendorPaymentRequest::create([
                'vendor_invoice_id' => $vendorInvoice->id,
                'status' => AdminVendorPaymentRequestStatus::WaitingApproval->value,
                'approval_status' => AdminVendorPaymentRequestStatus::WaitingApproval->value,
                'invoice_amount' => $vendorInvoice->total_amount,
                'approved_amount' => $vendorInvoice->total_amount,
                'vendor_snapshot' => $this->vendorSnapshot($vendorInvoice->vendor),
                'created_by' => $request->user()->id,
            ]);

            $this->logActivity($vendorInvoice, 'Invoice diverifikasi.', $request->user()->id);
            $this->logActivity($vendorInvoice, 'Status menjadi Ready for Payment.', $request->user()->id);
        });

        return response()->json(['message' => 'Invoice diverifikasi.', 'data' => $this->transformDetail($vendorInvoice->fresh())]);
    }

    public function reject(Request $request, VendorInvoice $vendorInvoice): JsonResponse
    {
        abort_unless($vendorInvoice->source === 'admin', 404);
        $data = $request->validate(['rejection_reason' => 'required|string|max:2000']);

        $vendorInvoice->update([
            'status' => AdminVendorInvoiceStatus::Rejected->value,
            'rejection_reason' => $data['rejection_reason'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        $this->logActivity($vendorInvoice, 'Invoice ditolak.', $request->user()->id);

        return response()->json(['message' => 'Invoice ditolak.', 'data' => $this->transformDetail($vendorInvoice->fresh())]);
    }

    public function storeAttachment(Request $request, VendorInvoice $vendorInvoice): JsonResponse
    {
        abort_unless($vendorInvoice->source === 'admin', 404);
        $data = $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'document_type' => 'nullable|string|max:40',
        ]);

        $file = $request->file('file');
        $path = $file->store('vendor-invoices/admin/attachments/'.$vendorInvoice->id, 'public');

        $attachment = VendorInvoiceAttachment::create([
            'vendor_invoice_id' => $vendorInvoice->id,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'kind' => $data['document_type'] ?? 'supporting',
        ]);

        $this->logActivity($vendorInvoice, 'Supporting Document diunggah.', $request->user()->id);

        return response()->json(['message' => 'Dokumen diunggah.', 'data' => [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'url' => Storage::disk('public')->url($attachment->file_path),
        ]], 201);
    }

    private function calculateDueDate(Vendor $vendor, string $invoiceDate): string
    {
        $days = match ($vendor->payment_terms) {
            'cod' => 0,
            '7_days' => 7,
            '14_days' => 14,
            '30_days' => 30,
            '45_days' => 45,
            default => 30,
        };

        return Carbon::parse($invoiceDate)->addDays($days)->toDateString();
    }

    private function transformListRow(VendorInvoice $inv): array
    {
        return [
            'id' => $inv->id,
            'vendor_invoice_no' => $inv->vendor_external_number ?? $inv->invoice_number,
            'vendor' => $inv->vendor?->name,
            'vendor_id' => $inv->vendor_id,
            'invoice_date' => $inv->invoice_date?->toDateString(),
            'receive_date' => $inv->receive_date?->toDateString(),
            'total_amount' => $inv->total_amount,
            'status' => $inv->statusValue(),
        ];
    }

    private function transformDetail(VendorInvoice $inv): array
    {
        $joTotal = (float) $inv->jobOrders->sum(fn ($jo) => (float) $jo->pivot->amount);
        $status = $inv->statusValue();
        $activities = CompanyActivity::query()
            ->where('subject_type', VendorInvoice::class)
            ->where('subject_id', $inv->id)
            ->orderByDesc('occurred_at')
            ->limit(30)
            ->get()
            ->map(fn ($a) => [
                'activity' => $a->description,
                'created_at' => $a->occurred_at?->toIso8601String(),
                'created_by' => $a->actor?->name ?? null,
            ]);

        return array_merge($this->transformListRow($inv), [
            'invoice_number' => $inv->invoice_number,
            'invoice_amount' => $inv->invoice_amount,
            'tax_amount' => $inv->tax_amount,
            'currency' => $inv->currency,
            'remark' => $inv->notes,
            'file_path' => $inv->file_path ? Storage::disk('public')->url($inv->file_path) : null,
            'verified_by' => $inv->reviewedByUser?->name,
            'verified_at' => $inv->reviewed_at?->toIso8601String(),
            'due_date' => $inv->due_date?->toDateString(),
            'receive_date' => $inv->receive_date?->toDateString(),
            'verification_status' => $status,
            'can_verify' => in_array($status, [AdminVendorInvoiceStatus::Received->value, AdminVendorInvoiceStatus::UnderVerification->value], true),
            'can_start_verification' => $status === AdminVendorInvoiceStatus::Received->value,
            'attachments' => $inv->attachments->map(fn ($a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'url' => Storage::disk('public')->url($a->file_path),
                'document_type' => $a->kind ?? 'supporting',
            ]),
            'rejection_reason' => $inv->rejection_reason,
            'job_orders' => $inv->jobOrders->map(fn ($jo) => [
                'id' => $jo->id,
                'job_order_number' => $jo->job_order_number,
                'shipment_number' => $jo->shipment?->shipment_number,
                'amount' => $jo->pivot->amount,
            ]),
            'selected_job_order_count' => $inv->jobOrders->count(),
            'total_job_order_amount' => $joTotal,
            'difference' => (float) $inv->total_amount - $joTotal,
            'activity_log' => $activities,
            'is_readonly' => in_array($status, [AdminVendorInvoiceStatus::ReadyForPayment->value, AdminVendorInvoiceStatus::Paid->value], true),
        ]);
    }

    private function vendorSnapshot(?Vendor $vendor): ?array
    {
        if (! $vendor) {
            return null;
        }

        $types = is_array($vendor->vendor_types) ? $vendor->vendor_types : [];

        return [
            'code' => $vendor->code,
            'name' => $vendor->name,
            'vendor_category' => $vendor->vendor_category,
            'vendor_types' => $types,
            'payment_terms' => $vendor->payment_terms,
            'bank_name' => $vendor->bank_name,
            'bank_account_number' => $vendor->bank_account_number,
            'account_holder' => $vendor->account_holder,
        ];
    }

    private function logActivity(VendorInvoice $invoice, string $description, int $userId): void
    {
        CompanyActivity::create([
            'subject_type' => VendorInvoice::class,
            'subject_id' => $invoice->id,
            'event_key' => 'admin_vendor_invoice',
            'description' => $description,
            'actor_user_id' => $userId,
            'occurred_at' => now(),
        ]);
    }
}
