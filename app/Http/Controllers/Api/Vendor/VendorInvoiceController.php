<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Vendor;

use App\Enums\VendorInvoiceStatus;
use App\Enums\VendorJobStatus;
use App\Enums\VendorUserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Vendor\VendorInvoiceResource;
use App\Models\CompanyActivity;
use App\Models\Shipment;
use App\Models\VendorInvoice;
use App\Models\VendorInvoiceAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class VendorInvoiceController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $vendorCompanyId = $request->user()->company_id;

        return response()->json([
            'data' => [
                'draft' => VendorInvoice::where('vendor_company_id', $vendorCompanyId)->where('status', VendorInvoiceStatus::Draft->value)->count(),
                'submitted' => VendorInvoice::where('vendor_company_id', $vendorCompanyId)->where('status', VendorInvoiceStatus::Submitted->value)->count(),
                'approved' => VendorInvoice::where('vendor_company_id', $vendorCompanyId)->where('status', VendorInvoiceStatus::Approved->value)->count(),
                'rejected' => VendorInvoice::where('vendor_company_id', $vendorCompanyId)->where('status', VendorInvoiceStatus::Rejected->value)->count(),
                'paid' => VendorInvoice::where('vendor_company_id', $vendorCompanyId)->where('status', VendorInvoiceStatus::Paid->value)->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $vendorCompanyId = $request->user()->company_id;
        $query = VendorInvoice::where('vendor_company_id', $vendorCompanyId)
            ->with(['shipment:id,shipment_number', 'attachments']);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('shipment', fn ($s) => $s->where('shipment_number', 'like', "%{$search}%"));
            });
        }

        if (($status = $request->string('status')->toString()) && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($from = $request->date('from')) {
            $query->whereDate('invoice_date', '>=', $from);
        }
        if ($to = $request->date('to')) {
            $query->whereDate('invoice_date', '<=', $to);
        }

        $page = $query->orderByDesc('created_at')->paginate(min((int) $request->integer('per_page', 15) ?: 15, 100));

        return response()->json([
            'data' => VendorInvoiceResource::collection($page->getCollection())->resolve(),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, VendorInvoice $invoice): JsonResponse
    {
        $this->authorizeVendorAccess($request, $invoice);
        $invoice->load(['shipment', 'attachments', 'reviewedByUser:id,name']);

        $activities = CompanyActivity::where('subject_type', VendorInvoice::class)
            ->where('subject_id', $invoice->id)
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

        return response()->json([
            'data' => array_merge(
                (new VendorInvoiceResource($invoice))->resolve($request),
                ['activities' => $activities]
            ),
        ]);
    }

    public function eligibleJobOrders(Request $request): JsonResponse
    {
        $vendorCompanyId = $request->user()->company_id;
        $shipments = Shipment::forVendor($vendorCompanyId)
            ->where('vendor_status', VendorJobStatus::Completed->value)
            ->whereDoesntHave('vendorInvoice')
            ->with('company:id,name,company_code')
            ->orderBy('id')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'shipment_number' => $s->shipment_number,
                'jo_number' => 'JO-'.str_pad((string) $s->id, 5, '0', STR_PAD_LEFT),
                'customer_name' => $s->company?->name,
                'completion_verified_at' => $s->completion_verified_at?->toDateString(),
            ])
            ->values();

        return response()->json(['data' => $shipments]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeWriteAccess($request);

        $request->validate([
            'shipment_id' => 'required|integer|exists:shipments,id',
            'invoice_number' => 'nullable|string|max:60|unique:vendor_invoices,invoice_number',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:invoice_date',
            'invoice_amount' => 'required|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
            'invoice_file' => 'required|file|mimes:pdf|max:10240',
            'tax_invoice_file' => 'nullable|file|mimes:pdf|max:10240',
            'supporting_files' => 'nullable|array',
            'supporting_files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $vendorCompanyId = $request->user()->company_id;
        $shipment = Shipment::forVendor($vendorCompanyId)->find($request->integer('shipment_id'));
        if (! $shipment) {
            return response()->json(['message' => 'Job order tidak ditemukan atau tidak eligible.'], 404);
        }
        if ($shipment->vendor_status !== VendorJobStatus::Completed->value) {
            return response()->json(['message' => 'Job order belum completed.'], 422);
        }
        if ($shipment->vendorInvoice()->exists()) {
            return response()->json(['message' => 'Job order sudah memiliki invoice.'], 422);
        }

        $invoice = DB::transaction(function () use ($request, $shipment) {
            $invoice = VendorInvoice::create([
                'vendor_company_id' => $shipment->vendor_company_id,
                'shipment_id' => $shipment->id,
                'invoice_number' => $request->input('invoice_number') ?: VendorInvoice::generateInvoiceNumber(),
                'invoice_date' => $request->date('invoice_date'),
                'due_date' => $request->date('due_date'),
                'invoice_amount' => $request->input('invoice_amount'),
                'tax_amount' => $request->input('tax_amount', 0),
                'notes' => $request->input('notes'),
                'status' => VendorInvoiceStatus::Draft->value,
                'created_by' => $request->user()->id,
            ]);

            if ($request->hasFile('invoice_file')) {
                $path = $request->file('invoice_file')->store("vendor-invoices/{$invoice->id}", 'public');
                $invoice->update(['file_path' => $path]);
            }
            if ($request->hasFile('tax_invoice_file')) {
                $path = $request->file('tax_invoice_file')->store("vendor-invoices/{$invoice->id}/tax", 'public');
                $invoice->update(['tax_invoice_path' => $path]);
            }
            if ($request->hasFile('supporting_files')) {
                foreach ($request->file('supporting_files') as $file) {
                    $path = $file->store("vendor-invoices/{$invoice->id}/supporting", 'public');
                    VendorInvoiceAttachment::create([
                        'vendor_invoice_id' => $invoice->id,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                        'kind' => 'supporting',
                    ]);
                }
            }

            CompanyActivity::create([
                'subject_type' => VendorInvoice::class,
                'subject_id' => $invoice->id,
                'event_key' => 'vendor_invoice_created',
                'description' => 'Vendor invoice dibuat (draft).',
                'actor_user_id' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            return $invoice->fresh(['attachments']);
        });

        return response()->json([
            'message' => 'Invoice berhasil dibuat sebagai draft.',
            'data' => (new VendorInvoiceResource($invoice))->resolve($request),
        ], 201);
    }

    public function update(Request $request, VendorInvoice $invoice): JsonResponse
    {
        $this->authorizeWriteAccess($request);
        $this->authorizeVendorAccess($request, $invoice);
        if (! $invoice->isEditable()) {
            return response()->json(['message' => 'Invoice tidak dapat diedit.'], 422);
        }

        $request->validate([
            'invoice_date' => 'sometimes|date',
            'due_date' => 'sometimes|date|after_or_equal:invoice_date',
            'invoice_amount' => 'sometimes|numeric|min:0',
            'tax_amount' => 'sometimes|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
            'invoice_file' => 'nullable|file|mimes:pdf|max:10240',
            'tax_invoice_file' => 'nullable|file|mimes:pdf|max:10240',
        ]);

        $invoice = DB::transaction(function () use ($request, $invoice) {
            $data = $request->only(['invoice_date', 'due_date', 'invoice_amount', 'tax_amount', 'notes']);
            $invoice->update($data);

            if ($request->hasFile('invoice_file')) {
                $path = $request->file('invoice_file')->store("vendor-invoices/{$invoice->id}", 'public');
                $invoice->update(['file_path' => $path]);
            }
            if ($request->hasFile('tax_invoice_file')) {
                $path = $request->file('tax_invoice_file')->store("vendor-invoices/{$invoice->id}/tax", 'public');
                $invoice->update(['tax_invoice_path' => $path]);
            }

            CompanyActivity::create([
                'subject_type' => VendorInvoice::class,
                'subject_id' => $invoice->id,
                'event_key' => 'vendor_invoice_updated',
                'description' => 'Vendor invoice diperbarui.',
                'actor_user_id' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            return $invoice->fresh();
        });

        return response()->json([
            'message' => 'Invoice berhasil diperbarui.',
            'data' => (new VendorInvoiceResource($invoice))->resolve($request),
        ]);
    }

    public function submit(Request $request, VendorInvoice $invoice): JsonResponse
    {
        $this->authorizeWriteAccess($request);
        $this->authorizeVendorAccess($request, $invoice);
        if (! $invoice->isSubmittable()) {
            return response()->json(['message' => 'Invoice belum bisa disubmit. Pastikan file invoice terupload dan status draft/rejected.'], 422);
        }

        $invoice = DB::transaction(function () use ($request, $invoice) {
            $invoice->update([
                'status' => VendorInvoiceStatus::Submitted->value,
                'submitted_at' => now(),
                'rejection_reason' => null,
            ]);

            CompanyActivity::create([
                'subject_type' => VendorInvoice::class,
                'subject_id' => $invoice->id,
                'event_key' => 'vendor_invoice_submitted',
                'description' => 'Vendor invoice disubmit untuk review.',
                'actor_user_id' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            return $invoice->fresh();
        });

        return response()->json([
            'message' => 'Invoice berhasil disubmit.',
            'data' => (new VendorInvoiceResource($invoice))->resolve($request),
        ]);
    }

    public function download(Request $request, VendorInvoice $invoice): Response
    {
        $this->authorizeVendorAccess($request, $invoice);
        if (! $invoice->file_path || ! Storage::disk('public')->exists($invoice->file_path)) {
            return response()->json(['message' => 'File invoice tidak ditemukan.'], 404);
        }

        $content = Storage::disk('public')->get($invoice->file_path);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => 'attachment; filename="'.addslashes($invoice->invoice_number.'.pdf').'"',
        ]);
    }

    private function authorizeVendorAccess(Request $request, VendorInvoice $invoice): void
    {
        if ($invoice->vendor_company_id !== $request->user()->company_id) {
            abort(response()->json(['message' => 'Resource tidak ditemukan.'], 404));
        }
    }

    /**
     * Restrict write actions to Company Admin, Ops PIC, and Finance PIC.
     * Viewer is read-only per FSD company.md L176.
     */
    private function authorizeWriteAccess(Request $request): void
    {
        $user = $request->user();
        if ($user->hasRole(VendorUserRole::VendorViewer->value)) {
            abort(response()->json(['message' => 'Anda tidak memiliki akses untuk aksi ini.'], 403));
        }
    }
}
