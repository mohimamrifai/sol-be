<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceActivity;
use App\Models\Shipment;
use App\Services\InvoiceGenerationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            ->where('status', 'completed')
            ->whereDoesntHave('invoice')
            ->whereDoesntHave('booking', fn ($q) => $q->where('status', 'cancelled'));

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
            $subtotal = array_sum(array_map(fn ($i) => $i['quantity'] * $i['unit_price'], $items));

            return array_merge($shipment->toArray(), [
                'estimated_amount' => round(max(0, $subtotal) * 1.11, 2),
            ]);
        });

        return response()->json($paginated);
    }

    public function previewLineItems(Shipment $shipment): JsonResponse
    {
        if ($shipment->invoice()->exists()) {
            return response()->json(['message' => 'Shipment sudah memiliki invoice.'], 422);
        }

        $items = $this->invoiceGeneration->buildLineItemsFromShipment($shipment);
        $subtotal = array_sum(array_map(fn ($i) => $i['quantity'] * $i['unit_price'], $items));

        return response()->json([
            'data' => [
                'items' => $items,
                'subtotal' => max(0, $subtotal),
                'tax_amount' => round(max(0, $subtotal) * 0.11, 2),
                'total_amount' => round(max(0, $subtotal) * 1.11, 2),
            ],
        ]);
    }

    public function generateFromShipment(Request $request, Shipment $shipment): JsonResponse
    {
        if ($shipment->status !== 'completed') {
            return response()->json(['message' => 'Hanya shipment completed yang dapat di-invoice.'], 422);
        }

        if ($shipment->invoice()->exists()) {
            return response()->json(['message' => 'Shipment sudah memiliki invoice.'], 422);
        }

        try {
            $invoice = $this->invoiceGeneration->generateDraftInvoice(
                $shipment,
                $request->user(),
                $request->input('status', 'draft')
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

        $data = $request->validate([
            'issued_date' => 'nullable|date',
            'due_date' => 'nullable|date',
        ]);

        $issuedDate = $data['issued_date'] ?? now()->toDateString();
        $dueDate = $data['due_date'] ?? ($invoice->due_date?->toDateString() ?? now()->addDays(30)->toDateString());

        $invoice->update([
            'status' => 'issued',
            'issued_date' => $issuedDate,
            'due_date' => $dueDate,
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
                        ->orWhere('waybill_number', 'like', "%{$s}%"));
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
        $invoice->load(['company', 'shipment', 'items', 'payments', 'createdByUser:id,name', 'activities.actorUser:id,name']);

        return response()->json(['data' => $invoice]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shipment_id' => [
                'required',
                'exists:shipments,id',
                Rule::unique('invoices', 'shipment_id')->whereNull('deleted_at'),
            ],
            'company_id' => 'required|exists:companies,id',
            'status' => 'nullable|in:draft,issued,partially_paid,paid,cancelled',
            'issued_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $status = $data['status'] ?? 'issued';

        if ($status !== 'draft') {
            $request->validate([
                'issued_date' => 'required|date',
                'due_date' => 'required|date|after_or_equal:issued_date',
            ]);
        }

        $subtotal = 0;
        foreach ($data['items'] as $item) {
            $subtotal += $item['quantity'] * $item['unit_price'];
        }

        $taxAmount = $subtotal * 0.11; // PPN 11%
        $totalAmount = $subtotal + $taxAmount;

        $invoice = Invoice::create([
            'shipment_id' => $data['shipment_id'],
            'company_id' => $data['company_id'],
            'issued_date' => $status === 'draft' ? null : $data['issued_date'],
            'due_date' => $status === 'draft' ? null : $data['due_date'],
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
            'status' => $status,
        ]);

        $invoice->load([
            'company:id,name,npwp,address,postpaid_term_days',
            'shipment:id,shipment_number,waybill_number,booking_id,origin_location_id,destination_location_id,service_type_id,shipment_coverage',
            'shipment.booking:id,booking_number',
            'shipment.originLocation:id,name',
            'shipment.destinationLocation:id,name',
            'shipment.serviceType:id,name',
        ]);

        $paymentTerms = $invoice->company?->postpaid_term_days !== null
            ? ((string) $invoice->company->postpaid_term_days.' days')
            : null;

        $invoice->update([
            'company_snapshot' => [
                'name' => $invoice->company?->name,
                'npwp' => $invoice->company?->npwp,
                'address' => $invoice->company?->address,
                'payment_terms' => $paymentTerms,
            ],
            'shipment_snapshot' => [
                'shipment_no' => $invoice->shipment?->shipment_number,
                'booking_no' => $invoice->shipment?->booking?->booking_number,
                'cn_no' => $invoice->shipment?->waybill_number,
                'origin' => $invoice->shipment?->originLocation?->name,
                'destination' => $invoice->shipment?->destinationLocation?->name,
                'service_type' => $invoice->shipment?->serviceType?->name,
                'shipment_coverage' => $invoice->shipment?->shipment_coverage,
            ],
        ]);

        foreach ($data['items'] as $item) {
            $invoice->items()->create([
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['quantity'] * $item['unit_price'],
            ]);
        }

        if ($status === 'issued') {
            $this->logInvoiceActivity($invoice, 'invoice_issued', 'Invoice diterbitkan.', $request->user()?->id);
        } else {
            $this->logInvoiceActivity($invoice, 'invoice_created', 'Invoice draft dibuat.', $request->user()?->id);
        }

        return response()->json([
            'message' => 'Invoice berhasil dibuat.',
            'data' => $invoice->load('items'),
        ], 201);
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'due_date' => 'sometimes|date',
            'notes' => 'nullable|string',
            'status' => 'sometimes|in:draft,issued,partially_paid,paid,cancelled',
        ]);

        $invoice->update($data);

        return response()->json(['message' => 'Invoice diperbarui.', 'data' => $invoice]);
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        if ($invoice->payments()->where('status', 'success')->exists()) {
            return response()->json([
                'message' => 'Invoice yang sudah memiliki pembayaran sukses tidak dapat dihapus.',
            ], 422);
        }

        $invoice->delete();

        return response()->json(['message' => 'Invoice berhasil dihapus.']);
    }

    public function downloadPdf(Invoice $invoice)
    {
        $invoice->load(['company', 'shipment', 'items']);

        $pdf = Pdf::loadView('pdf.invoice', ['invoice' => $invoice]);

        return $pdf->download('invoice-'.$invoice->invoice_number.'.pdf');
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
}
