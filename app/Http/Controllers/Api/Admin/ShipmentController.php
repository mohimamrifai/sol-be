<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Container;
use App\Models\Invoice;
use App\Models\Rack;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Shipment::with([
            'company:id,name', 'originLocation:id,name,code',
            'destinationLocation:id,name,code', 'serviceType:id,name,code',
        ]);

        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('company_id')) $query->where('company_id', $request->company_id);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('shipment_number', 'like', "%{$s}%")
                ->orWhere('waybill_number', 'like', "%{$s}%"));
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 15));
    }

    public function show(Shipment $shipment): JsonResponse
    {
        $shipment->load([
            'booking.cargoCategory', 'booking.dgClass',
            'company', 'originLocation', 'destinationLocation',
            'transportMode', 'serviceType', 'createdByUser:id,name',
            'containers.containerType', 'containers.racks.items',
            'items', 'trackings.photos', 'trackings.updatedByUser:id,name',
            'invoice',
        ]);

        return response()->json(['data' => $shipment]);
    }

    public function update(Request $request, Shipment $shipment): JsonResponse
    {
        $data = $request->validate([
            'cargo_category_id' => 'sometimes|exists:cargo_categories,id',
            'is_dangerous_goods' => 'sometimes|boolean',
            'dg_class_id' => 'sometimes|nullable|exists:dg_classes,id',
            'un_number' => 'sometimes|nullable|string|max:50',
            'equipment_condition' => 'sometimes|nullable|in:CLEAN,RESIDUAL',
            'temperature' => 'sometimes|nullable|numeric',
            'estimated_departure' => 'nullable|date',
            'estimated_arrival' => 'nullable|date',
            'actual_departure' => 'nullable|date',
            'actual_arrival' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $shipment->update($data);

        return response()->json(['message' => 'Shipment diperbarui.', 'data' => $shipment]);
    }

    // ── STATUS TRACKING ──
    public function updateTracking(Request $request, Shipment $shipment): JsonResponse
    {
        $statuses = config('shipment.tracking_statuses', []);
        $statusRule = empty($statuses) ? 'required|string' : 'required|string|in:' . implode(',', $statuses);
        $data = $request->validate([
            'status' => $statusRule,
            'notes' => 'nullable|string',
            'location' => 'nullable|string',
            'tracked_at' => 'nullable|date',
            'photos' => 'nullable|array',
            'photos.*' => 'file|image|max:5120',
        ]);

        $tracking = $shipment->trackings()->create([
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
            'location' => $data['location'] ?? null,
            'tracked_at' => $data['tracked_at'] ?? now(),
            'updated_by' => $request->user()->id,
        ]);

        // Upload foto
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $path = $photo->store("tracking/{$shipment->id}", 'public');
                $tracking->photos()->create(['path' => $path]);
            }
        }

        // Update status shipment
        $statusMapping = [
            'booking_created' => 'created',
            'train_departed' => 'departed',
            'train_arrived' => 'arrived',
            'container_unloading' => 'unloading',
        ];
        $shipmentStatus = $statusMapping[$data['status']] ?? $data['status'];
        $shipment->update(['status' => $shipmentStatus]);

        // Jika shipment selesai dan company menggunakan post-paid, auto-generate invoice jika belum ada.
        $shipment->loadMissing('company', 'booking', 'invoice', 'additionalCharges');
        if ($data['status'] === 'completed'
            && $shipment->company
            && $shipment->company->payment_type === 'postpaid'
            && ! $shipment->invoice
        ) {
            $booking = $shipment->booking;
            
            // Re-calculate from booking if exists, else fallback to 0
            $baseFreight = 0.0;
            $discount = 0.0;
            $additionalDetail = [];

            if ($booking) {
                // If we need the actual breakdown, we could re-run Estimate Service or just read the total.
                // Since estimate service returns breakdown but isn't saved as JSON, we can fetch services manually.
                $booking->loadMissing('additionalServices');
                $additionalTotal = 0;
                foreach ($booking->additionalServices as $svc) {
                    $price = (float) ($svc->pivot->price ?? $svc->base_price ?? 0);
                    $additionalDetail[] = ['name' => $svc->name, 'price' => $price];
                    $additionalTotal += $price;
                }
                
                // Approximate base freight: (estimated_price - additionalTotal) / 1.11 ? No, estimated_price is before tax.
                // Wait, in BookingController, subtotal = estimated_price (baseFreight - discount + additionalTotal).
                // Let's assume no discount saved for now, just:
                $subtotalBooking = (float) ($booking->estimated_price ?? 0);
                $baseFreight = max(0, $subtotalBooking - $additionalTotal);
            }

            $issuedDate = now();
            $termDays = (int) ($shipment->company->postpaid_term_days ?? 0);
            $dueDate = $termDays > 0 ? (clone $issuedDate)->addDays($termDays) : $issuedDate;

            // Calculate total from base + booking additional services + shipment additional charges
            $shipmentChargesTotal = 0;
            $shipmentChargesDetail = [];
            foreach ($shipment->additionalCharges as $charge) {
                $price = (float) ($charge->pivot->amount ?? $charge->base_amount ?? 0);
                $shipmentChargesDetail[] = ['name' => $charge->name, 'price' => $price];
                $shipmentChargesTotal += $price;
            }

            $subtotal = $baseFreight + $additionalTotal + $shipmentChargesTotal;
            $taxAmount = $subtotal * 0.11;
            $totalAmount = $subtotal + $taxAmount;

            $invoice = Invoice::create([
                'shipment_id' => $shipment->id,
                'company_id' => $shipment->company_id,
                'issued_date' => $issuedDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'status' => 'unpaid',
                'notes' => null,
                'created_by' => $request->user()->id,
            ]);

            if ($baseFreight > 0) {
                $invoice->items()->create([
                    'description' => 'Freight / Tarif Pengiriman',
                    'quantity' => 1,
                    'unit_price' => $baseFreight,
                    'total_price' => $baseFreight,
                ]);
            }

            foreach ($additionalDetail as $addSvc) {
                if ($addSvc['price'] > 0) {
                    $invoice->items()->create([
                        'description' => 'Layanan Tambahan: ' . $addSvc['name'],
                        'quantity' => 1,
                        'unit_price' => $addSvc['price'],
                        'total_price' => $addSvc['price'],
                    ]);
                }
            }

            foreach ($shipmentChargesDetail as $charge) {
                if ($charge['price'] > 0) {
                    $invoice->items()->create([
                        'description' => 'Biaya Tambahan (Shipment): ' . $charge['name'],
                        'quantity' => 1,
                        'unit_price' => $charge['price'],
                        'total_price' => $charge['price'],
                    ]);
                }
            }

            if ($taxAmount > 0) {
                $invoice->items()->create([
                    'description' => 'PPN (11%)',
                    'quantity' => 1,
                    'unit_price' => $taxAmount,
                    'total_price' => $taxAmount,
                ]);
            }
        }

        return response()->json([
            'message' => 'Tracking berhasil diperbarui.',
            'data' => $tracking->load('photos'),
        ], 201);
    }

    // ── CONTAINER MANAGEMENT ──
    public function addContainer(Request $request, Shipment $shipment): JsonResponse
    {
        $data = $request->validate([
            'container_type_id' => 'required|exists:container_types,id',
            'container_number' => 'nullable|string|max:255',
            'seal_number' => 'nullable|string|max:255',
        ]);

        $container = $shipment->containers()->create($data);

        return response()->json([
            'message' => 'Container ditambahkan.',
            'data' => $container->load('containerType'),
        ], 201);
    }

    public function updateContainer(Request $request, Container $container): JsonResponse
    {
        $data = $request->validate([
            'container_type_id' => 'sometimes|exists:container_types,id',
            'container_number' => 'nullable|string|max:255',
            'seal_number' => 'nullable|string|max:255',
        ]);

        $container->update($data);

        return response()->json(['message' => 'Container diperbarui.', 'data' => $container->load('containerType')]);
    }

    public function destroyContainer(Container $container): JsonResponse
    {
        $container->delete();
        return response()->json(['message' => 'Container dihapus.']);
    }

    // ── RACK MANAGEMENT ──
    public function addRack(Request $request, Container $container): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'length' => 'nullable|numeric',
            'width' => 'nullable|numeric',
            'height' => 'nullable|numeric',
        ]);

        $rack = $container->racks()->create($data);

        return response()->json(['message' => 'Rack ditambahkan.', 'data' => $rack], 201);
    }

    public function updateRack(Request $request, Rack $rack): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'length' => 'nullable|numeric',
            'width' => 'nullable|numeric',
            'height' => 'nullable|numeric',
        ]);

        $rack->update($data);

        return response()->json(['message' => 'Rack diperbarui.', 'data' => $rack]);
    }

    public function destroyRack(Rack $rack): JsonResponse
    {
        $rack->delete();
        return response()->json(['message' => 'Rack dihapus.']);
    }

    // ── SHIPMENT ITEMS ──
    public function addItem(Request $request, Shipment $shipment): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'quantity' => 'required|integer|min:1',
            'gross_weight' => 'required|numeric|min:0',
            'length' => 'nullable|numeric',
            'width' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'cbm' => 'nullable|numeric',
            'is_fragile' => 'boolean',
            'is_stackable' => 'boolean',
            'placement_type' => 'required|in:rack,floor',
            'container_id' => 'nullable|exists:containers,id',
            'rack_id' => 'nullable|exists:racks,id',
        ]);

        $item = $shipment->items()->create($data);

        return response()->json(['message' => 'Item ditambahkan.', 'data' => $item], 201);
    }

    public function updateItem(Request $request, ShipmentItem $item): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'quantity' => 'sometimes|integer|min:1',
            'gross_weight' => 'sometimes|numeric|min:0',
            'length' => 'nullable|numeric', 'width' => 'nullable|numeric', 'height' => 'nullable|numeric',
            'cbm' => 'nullable|numeric',
            'is_fragile' => 'boolean', 'is_stackable' => 'boolean',
            'placement_type' => 'sometimes|in:rack,floor',
            'container_id' => 'nullable|exists:containers,id',
            'rack_id' => 'nullable|exists:racks,id',
        ]);

        $item->update($data);

        return response()->json(['message' => 'Item diperbarui.', 'data' => $item]);
    }

    public function destroyItem(ShipmentItem $item): JsonResponse
    {
        $item->delete();
        return response()->json(['message' => 'Item dihapus.']);
    }

    public function downloadConsignmentNotePdf(Shipment $shipment)
    {
        $shipment->load([
            'originLocation', 'destinationLocation', 'serviceType', 'booking.cargoCategory',
            'items',
            'trackings' => fn ($q) => $q->orderBy('tracked_at', 'asc'),
        ]);

        $pdf = Pdf::loadView('pdf.consignment-note', ['shipment' => $shipment]);

        return $pdf->download('consignment-note-' . $shipment->waybill_number . '.pdf');
    }

    public function downloadWaybillPdf(Shipment $shipment)
    {
        $shipment->load([
            'originLocation', 'destinationLocation', 'serviceType',
            'trackings' => fn ($q) => $q->orderBy('tracked_at', 'asc'),
        ]);

        $pdf = Pdf::loadView('pdf.waybill', ['shipment' => $shipment]);

        return $pdf->download('waybill-' . $shipment->waybill_number . '.pdf');
    }
}
