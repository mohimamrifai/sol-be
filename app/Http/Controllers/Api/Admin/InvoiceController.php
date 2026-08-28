<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceActivity;
use App\Models\InvoiceDocument;
use App\Models\Shipment;
use App\Services\DocumentPdfService;
use App\Services\InvoiceGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceGenerationService $invoiceGeneration,
    ) {}

    public function stats(): JsonResponse
    {
        $counts = Invoice::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => [
                'draft' => (int) ($counts['draft'] ?? 0),
                'issued' => (int) ($counts['issued'] ?? 0),
                'partially_paid' => (int) ($counts['partially_paid'] ?? 0),
                'paid' => (int) ($counts['paid'] ?? 0),
                'cancelled' => (int) ($counts['cancelled'] ?? 0),
            ],
        ]);
    }

    public function eligibleShipments(Request $request): JsonResponse
    {
        $query = Shipment::query()
            ->with(['company:id,name', 'booking:id,booking_number', 'serviceType:id,name,code'])
            ->where('shipments.status', '=', 'completed')
            ->whereDoesntHave('invoice', fn ($q) => $q->withTrashed());

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('shipment_number', 'like', "%{$s}%")
                    ->orWhere('waybill_number', 'like', "%{$s}%")
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$s}%"));
            });
        }

        $paginated = $query->orderByDesc('updated_at')->paginate($request->per_page ?? 15);
        $paginated->getCollection()->transform(function (Shipment $shipment) {
            $items = $this->invoiceGeneration->buildLineItemsFromShipment($shipment);
            $totals = $this->invoiceGeneration->calculateTotals($items);
            $completionDate = Schema::hasColumn('shipments', 'completion_verified_at')
                ? $shipment->completion_verified_at?->toIso8601String()
                : $shipment->updated_at?->toIso8601String();

            return array_merge($shipment->toArray(), [
                'completion_date' => $completionDate,
                'estimated_amount' => $totals['grand_total'],
            ]);
        });

        return response()->json($paginated);
    }

    public function previewLineItems(Shipment $shipment): JsonResponse
    {
        if ($shipment->invoice()->withTrashed()->exists()) {
            return response()->json(['message' => 'Shipment sudah memiliki invoice.'], 422);
        }

        $items = $this->invoiceGeneration->buildLineItemsFromShipment($shipment);
        $subtotal = array_sum(array_map(fn ($i) => $i['quantity'] * $i['unit_price'], $items));
        $taxBreakdown = SystemConfig::applyTax(max(0, $subtotal));

        return response()->json([
            'data' => [
                'items' => $items,
                'subtotal' => $taxBreakdown['subtotal'],
                'tax_amount' => $taxBreakdown['tax_amount'],
                'total_amount' => $taxBreakdown['total_amount'],
            ],
        ]);
    }

    public function generateFromShipment(Request $request, Shipment $shipment): JsonResponse
    {
        $data = $request->validate([
            'invoice_date' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:5000'],
            'status' => ['prohibited'],
        ]);

        try {
            $invoice = $this->invoiceGeneration->generateDraftInvoice(
                $shipment,
                $request->user(),
                [
                    'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                    'notes' => $data['remark'] ?? null,
                ],
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Invoice berhasil dibuat dari shipment.',
            'data' => $invoice,
        ], 201);
    }

    public function issue(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status !== 'draft') {
            return response()->json(['message' => 'Hanya invoice draft yang dapat diterbitkan.'], 422);
        }

        $request->validate([
            'status' => ['prohibited'],
            'issued_date' => ['prohibited'],
            'due_date' => ['prohibited'],
        ]);

        $invoiceDate = $invoice->issued_date ?? now()->startOfDay();
        $snapshot = $invoice->company_snapshot ?? [];
        $term = $this->invoiceGeneration->resolvePaymentTerm(
            $snapshot['payment_term'] ?? $invoice->company?->payment_term,
            $snapshot['payment_term_days'] ?? $invoice->company?->postpaid_term_days,
        );
        $invoice->update([
            'status' => 'issued',
            'issued_date' => $invoiceDate->toDateString(),
            'due_date' => $invoice->due_date?->toDateString()
                ?? $invoiceDate->copy()->addDays($term['days'])->toDateString(),
        ]);

        $this->logInvoiceActivity(
            $invoice,
            'invoice_issued',
            'Invoice diterbitkan.',
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Invoice berhasil diterbitkan.',
            'data' => $invoice->fresh(['company', 'shipment', 'items']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with(['company:id,name', 'shipment:id,shipment_number,waybill_number']);

        if ($request->filled('status')) {
            $st = (string) $request->status;
            if ($st === 'overdue') {
                $query
                    ->whereIn('status', ['issued', 'partially_paid'])
                    ->whereNotNull('due_date')
                    ->where('due_date', '<', now()->toDateString());
            } else {
                $query->where('status', $st);
            }
        }
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('invoice_number', 'like', "%{$s}%")
                    ->orWhereHas('shipment', fn ($sq) => $sq->where('shipment_number', 'like', "%{$s}%")
                        ->orWhere('waybill_number', 'like', "%{$s}%"))
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$s}%"));
            });
        }
        if ($request->filled('date_from')) {
            $query->whereDate('issued_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('issued_date', '<=', $request->date_to);
        }
        if ($request->filled('due_from')) {
            $query->whereDate('due_date', '>=', $request->due_from);
        }
        if ($request->filled('due_to')) {
            $query->whereDate('due_date', '<=', $request->due_to);
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 15));
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return response()->json(['data' => $this->detailPayload($invoice)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shipment_id' => [
                'required',
                'exists:shipments,id',
                Rule::unique('invoices', 'shipment_id')->whereNull('deleted_at'),
            ],
            'company_id' => 'nullable|exists:companies,id',
            'status' => 'prohibited',
            'issued_date' => 'prohibited',
            'due_date' => 'prohibited',
            'invoice_date' => 'nullable|date',
            'remark' => 'nullable|string|max:5000',
            'notes' => 'nullable|string|max:5000',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1|max:1000000',
            'items.*.unit_price' => 'required|numeric',
        ]);

        $shipment = Shipment::findOrFail($data['shipment_id']);
        if (isset($data['company_id']) && $shipment->company_id != $data['company_id']) {
            return response()->json(['message' => 'Company tidak sesuai dengan shipment.'], 422);
        }

        try {
            $invoice = $this->invoiceGeneration->generateDraftInvoice($shipment, $request->user(), [
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'notes' => $data['remark'] ?? $data['notes'] ?? null,
                'items' => $data['items'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Invoice draft berhasil dibuat.',
            'data' => $invoice,
        ], 201);
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status !== 'draft') {
            return response()->json(['message' => 'Data bisnis invoice readonly setelah diterbitkan.'], 422);
        }

        $data = $request->validate([
            'invoice_date' => 'sometimes|date',
            'remark' => 'sometimes|nullable|string|max:5000',
            'notes' => 'sometimes|nullable|string|max:5000',
            'items' => 'sometimes|array|min:1',
            'items.*.description' => 'required_with:items|string|max:255',
            'items.*.quantity' => 'required_with:items|integer|min:1|max:1000000',
            'items.*.unit_price' => 'required_with:items|numeric',
            'status' => 'prohibited',
            'due_date' => 'prohibited',
            'issued_date' => 'prohibited',
            'company_id' => 'prohibited',
            'shipment_id' => 'prohibited',
        ]);

        DB::transaction(function () use ($invoice, $data): void {
            $invoiceDate = isset($data['invoice_date'])
                ? Carbon::parse($data['invoice_date'])
                : $invoice->issued_date;
            $snapshot = $invoice->company_snapshot ?? [];
            $term = $this->invoiceGeneration->resolvePaymentTerm(
                $snapshot['payment_term'] ?? $invoice->company?->payment_term,
                $snapshot['payment_term_days'] ?? $invoice->company?->postpaid_term_days,
            );
            $changes = [
                'issued_date' => $invoiceDate?->toDateString(),
                'due_date' => $invoiceDate?->copy()->addDays($term['days'])->toDateString(),
            ];

            if (array_key_exists('remark', $data) || array_key_exists('notes', $data)) {
                $changes['notes'] = $data['remark'] ?? $data['notes'] ?? null;
            }

            if (isset($data['items'])) {
                $totals = $this->invoiceGeneration->calculateTotals($data['items']);
                $changes += [
                    'subtotal' => $totals['subtotal'],
                    'tax_amount' => $totals['tax_amount'],
                    'total_amount' => $totals['grand_total'],
                ];
                $invoice->items()->delete();
                foreach ($data['items'] as $item) {
                    $invoice->items()->create([
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => round($item['quantity'] * $item['unit_price'], 2),
                    ]);
                }
            }

            $invoice->update($changes);
        });

        $this->logInvoiceActivity($invoice, 'invoice_edited', 'Invoice draft diperbarui.', $request->user()?->id);

        return response()->json([
            'message' => 'Invoice diperbarui.',
            'data' => $invoice->fresh(['items']),
        ]);
    }

    public function destroy(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status !== 'draft') {
            return response()->json(['message' => 'Hanya invoice Draft yang dapat dibatalkan.'], 422);
        }

        $invoice->update(['status' => 'cancelled']);
        $this->logInvoiceActivity($invoice, 'invoice_cancelled', 'Invoice dibatalkan.', $request->user()?->id);

        return response()->json([
            'message' => 'Invoice berhasil dibatalkan.',
            'data' => $invoice->fresh(),
        ]);
    }

    public function downloadPdf(Request $request, Invoice $invoice): SymfonyResponse
    {
        if (! in_array($invoice->status, ['issued', 'unpaid', 'partially_paid', 'paid'], true)) {
            return response()->json([
                'message' => 'Invoice PDF hanya tersedia setelah invoice diterbitkan.',
            ], 422);
        }

        $pdf = app(DocumentPdfService::class)->renderInvoice($invoice);
        $content = $pdf->output();
        $inline = $request->boolean('view') || $request->boolean('inline');
        $this->logInvoiceActivity(
            $invoice,
            'invoice_document_printed',
            $inline ? 'Invoice PDF dilihat.' : 'Invoice PDF dicetak.',
            $request->user()?->id,
        );

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                .'; filename="invoice-'.addslashes($invoice->invoice_number).'.pdf"',
        ]);
    }

    public function storeDocument(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['tax_invoice', 'supporting'])],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx,xls,doc,docx', 'max:10240'],
        ]);

        $file = $request->file('file');
        $path = $file->store("invoice-documents/{$invoice->id}/{$data['kind']}", 'public');
        $document = $invoice->documents()->create([
            'kind' => $data['kind'],
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize() ?: 0,
            'uploaded_by' => $request->user()?->id,
        ]);

        $this->logInvoiceActivity(
            $invoice,
            'invoice_document_uploaded',
            $data['kind'] === 'tax_invoice' ? 'Tax Invoice diunggah.' : 'Dokumen pendukung diunggah.',
            $request->user()?->id,
        );

        return response()->json(['message' => 'Dokumen berhasil diunggah.', 'data' => $document], 201);
    }

    public function downloadDocument(Request $request, Invoice $invoice, InvoiceDocument $document): SymfonyResponse
    {
        abort_unless($document->invoice_id === $invoice->id, 404);
        abort_unless(Storage::disk('public')->exists($document->file_path), 404, 'Dokumen tidak ditemukan.');

        $content = Storage::disk('public')->get($document->file_path);
        $inline = $request->boolean('view') || $request->boolean('inline');

        return response($content, 200, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                .'; filename="'.addslashes($document->original_name).'"',
        ]);
    }

    private function logInvoiceActivity(Invoice $invoice, string $eventKey, string $description, ?int $actorUserId): void
    {
        InvoiceActivity::create([
            'invoice_id' => $invoice->id,
            'actor_user_id' => $actorUserId,
            'event_key' => $eventKey,
            'description' => $description,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(Invoice $invoice): array
    {
        $invoice->load([
            'company',
            'shipment.booking',
            'shipment.originLocation',
            'shipment.destinationLocation',
            'shipment.serviceType',
            'items',
            'documents.uploadedBy:id,name',
            'payments' => fn ($query) => $query
                ->whereIn('status', ['success', 'settlement'])
                ->orderByDesc('paid_at')
                ->orderByDesc('id'),
            'createdByUser:id,name',
            'activities.actorUser:id,name',
        ]);

        $company = $invoice->company_snapshot ?? [];
        $shipment = $invoice->shipment_snapshot ?? [];
        $summary = $this->persistedSummary($invoice);
        $paidAmount = $invoice->paidAmount();

        return [
            'id' => $invoice->id,
            'header' => [
                'invoice_number' => $invoice->invoice_number,
                'customer' => $company['name'] ?? $invoice->company?->name,
                'status' => $invoice->status,
                'invoice_date' => $invoice->issued_date?->toDateString(),
                'created_at' => $invoice->created_at?->toIso8601String(),
            ],
            'invoice_info' => [
                'invoice_number' => $invoice->invoice_number,
                'customer' => $company,
                'invoice_date' => $invoice->issued_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'currency' => $company['currency'] ?? 'IDR',
                'payment_term' => $company['payment_term'] ?? null,
                'payment_terms' => $company['payment_terms'] ?? null,
                'remark' => $invoice->notes,
            ],
            'shipment' => [
                'id' => $invoice->shipment_id,
                'shipment_no' => $shipment['shipment_no'] ?? $invoice->shipment?->shipment_number,
                'cn_no' => $shipment['cn_no'] ?? $invoice->shipment?->waybill_number,
                'route' => ($shipment['origin'] ?? $invoice->shipment?->originLocation?->name)
                    .' → '.($shipment['destination'] ?? $invoice->shipment?->destinationLocation?->name),
                'service' => $shipment['service_type'] ?? $invoice->shipment?->serviceType?->name,
                'shipment_coverage' => $shipment['shipment_coverage'] ?? $invoice->shipment?->shipment_coverage,
            ],
            'items' => $invoice->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'qty' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'amount' => (float) $item->total_price,
            ])->values(),
            'summary' => $summary,
            'documents' => [
                'invoice_pdf' => [
                    'available' => in_array($invoice->status, ['issued', 'unpaid', 'partially_paid', 'paid'], true),
                    'view_url' => "/api/admin/invoices/{$invoice->id}/pdf?view=1",
                    'download_url' => "/api/admin/invoices/{$invoice->id}/pdf",
                ],
                'tax_invoices' => $this->documentRows($invoice, 'tax_invoice'),
                'supporting_documents' => $this->documentRows($invoice, 'supporting'),
                'upload_url' => "/api/admin/invoices/{$invoice->id}/documents",
            ],
            'payment_summary' => [
                'invoice_amount' => (float) $invoice->total_amount,
                'paid_amount' => $paidAmount,
                'outstanding_amount' => max((float) $invoice->total_amount - $paidAmount, 0),
            ],
            'payment_history' => $invoice->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'payment_date' => ($payment->paid_at ?? $payment->created_at)?->toIso8601String(),
                'amount' => (float) $payment->amount,
                'method' => $payment->method ?? $payment->payment_type,
                'reference_number' => $payment->manual_reference_number
                    ?? $payment->midtrans_transaction_id
                    ?? $payment->midtrans_order_id,
                'status' => $payment->status,
            ])->values(),
            'activity_log' => $invoice->activities
                ->sortByDesc('occurred_at')
                ->values()
                ->map(fn ($activity) => [
                    'id' => $activity->id,
                    'event' => $activity->event_key,
                    'description' => $activity->description,
                    'actor' => $activity->actorUser?->name,
                    'occurred_at' => $activity->occurred_at?->toIso8601String(),
                ]),
            'actions' => [
                'can_edit' => $invoice->status === 'draft',
                'can_issue' => $invoice->status === 'draft',
                'can_cancel' => $invoice->status === 'draft',
                'can_print' => $invoice->status === 'paid',
            ],
        ];
    }

    private function documentRows(Invoice $invoice, string $kind): mixed
    {
        return $invoice->documents
            ->where('kind', $kind)
            ->values()
            ->map(fn ($document) => [
                'id' => $document->id,
                'name' => $document->original_name,
                'mime_type' => $document->mime_type,
                'size' => $document->size,
                'uploaded_by' => $document->uploadedBy?->name,
                'uploaded_at' => $document->created_at?->toIso8601String(),
                'view_url' => "/api/admin/invoices/{$invoice->id}/documents/{$document->id}?view=1",
                'download_url' => "/api/admin/invoices/{$invoice->id}/documents/{$document->id}",
            ]);
    }

    /**
     * Derive every summary value from the persisted monetary snapshot.
     *
     * @return array{subtotal: float, discount: float, ppn: float, grand_total: float}
     */
    private function persistedSummary(Invoice $invoice): array
    {
        $subtotal = round((float) $invoice->subtotal, 2);
        $tax = round((float) $invoice->tax_amount, 2);
        $grandTotal = round((float) $invoice->total_amount, 2);
        $netBeforeTax = max(0.0, round($grandTotal - $tax, 2));

        return [
            'subtotal' => $subtotal,
            'discount' => max(0.0, round($subtotal - $netBeforeTax, 2)),
            'ppn' => $tax,
            'grand_total' => $grandTotal,
        ];
    }
}
