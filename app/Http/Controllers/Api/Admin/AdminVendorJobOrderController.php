<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\VendorJobOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\VendorJobOrder;
use App\Models\VendorJobOrderDocument;
use App\Services\DocumentPdfService;
use App\Services\VendorJobOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminVendorJobOrderController extends Controller
{
    public function __construct(
        private VendorJobOrderService $jobOrderService,
        private DocumentPdfService $pdfService,
    ) {}

    public function stats(): JsonResponse
    {
        $counts = VendorJobOrder::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => [
                'draft' => (int) ($counts['draft'] ?? 0),
                'sent' => (int) ($counts['sent'] ?? 0),
                'in_progress' => (int) ($counts['in_progress'] ?? 0),
                'completed' => (int) ($counts['completed'] ?? 0),
                'cancelled' => (int) ($counts['cancelled'] ?? 0),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = VendorJobOrder::query()
            ->with([
                'vendor:id,name,code',
                'shipment:id,shipment_number,origin_location_id,destination_location_id',
                'shipment.originLocation:id,code,name',
                'shipment.destinationLocation:id,code,name',
            ]);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('job_order_number', 'like', "%{$s}%")
                    ->orWhereHas('shipment', fn ($sq) => $sq->where('shipment_number', 'like', "%{$s}%"))
                    ->orWhereHas('vendor', fn ($vq) => $vq->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
            });
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }
        if ($request->filled('service_type')) {
            $query->where('service_type', $request->service_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('origin_location_id')) {
            $query->whereHas('shipment', fn ($q) => $q->where('origin_location_id', $request->origin_location_id));
        }
        if ($request->filled('destination_location_id')) {
            $query->whereHas('shipment', fn ($q) => $q->where('destination_location_id', $request->destination_location_id));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $paginated = $query->orderByDesc('created_at')->paginate($request->per_page ?? 15);
        $paginated->getCollection()->transform(fn (VendorJobOrder $jo) => $this->transformListRow($jo));

        return response()->json($paginated);
    }

    public function show(VendorJobOrder $vendorJobOrder): JsonResponse
    {
        $vendorJobOrder->load([
            'vendor.contacts',
            'shipment.company',
            'shipment.originLocation',
            'shipment.destinationLocation',
            'originYard',
            'destinationYard',
            'train',
            'pricing',
            'activities.user:id,name',
            'documents.uploadedBy:id,name',
        ]);

        return response()->json(['data' => $this->transformDetail($vendorJobOrder)]);
    }

    public function update(Request $request, VendorJobOrder $vendorJobOrder): JsonResponse
    {
        if (! $vendorJobOrder->isEditable()) {
            return response()->json(['message' => 'Job Order completed tidak dapat diubah.'], 422);
        }

        $data = $request->validate([
            'status' => 'sometimes|in:'.implode(',', VendorJobOrderStatus::values()),
            'pickup_date' => 'nullable|date',
            'pickup_remark' => 'nullable|string|max:5000',
            'delivery_date' => 'nullable|date',
            'delivery_remark' => 'nullable|string|max:5000',
            'vehicle_plate' => 'nullable|string|max:30',
            'driver_name' => 'nullable|string|max:120',
            'driver_mobile' => 'nullable|string|max:30',
            'vehicle_remark' => 'nullable|string|max:5000',
            'additional_cost' => 'nullable|numeric|min:0',
        ]);

        $userId = $request->user()?->id;
        $oldStatus = $vendorJobOrder->status?->value;

        if (isset($data['status']) && $data['status'] === VendorJobOrderStatus::Completed->value) {
            $data['completed_at'] = now();
        }

        $vendorJobOrder->update($data);

        if (isset($data['pickup_date'])) {
            $this->jobOrderService->logActivity($vendorJobOrder, 'Pickup Date diubah.', $userId);
        }
        if (isset($data['delivery_date'])) {
            $this->jobOrderService->logActivity($vendorJobOrder, 'Delivery Date diubah.', $userId);
        }
        if (isset($data['driver_name']) || isset($data['driver_mobile']) || isset($data['vehicle_plate'])) {
            $this->jobOrderService->logActivity($vendorJobOrder, 'Driver diperbarui.', $userId);
        }
        if (isset($data['status']) && $data['status'] !== $oldStatus) {
            $this->jobOrderService->logActivity($vendorJobOrder, 'Status menjadi '.VendorJobOrderStatus::from($data['status'])->label().'.', $userId);
        }

        return response()->json(['message' => 'Job Order diperbarui.', 'data' => $this->transformDetail($vendorJobOrder->fresh())]);
    }

    public function send(Request $request, VendorJobOrder $vendorJobOrder): JsonResponse
    {
        if ($vendorJobOrder->status !== VendorJobOrderStatus::Draft) {
            return response()->json(['message' => 'Hanya Job Order draft yang dapat dikirim.'], 422);
        }

        $this->jobOrderService->sendJobOrder($vendorJobOrder, $request->user()?->id);

        return response()->json(['message' => 'Job Order dikirim.', 'data' => $this->transformDetail($vendorJobOrder->fresh())]);
    }

    public function verifyCompletion(Request $request, VendorJobOrder $vendorJobOrder): JsonResponse
    {
        try {
            $this->jobOrderService->verifyCompletion($vendorJobOrder, $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $shipment = $vendorJobOrder->shipment;
        if ($shipment && $shipment->vendor_status === 'waiting_verification') {
            $shipment->update([
                'vendor_status' => 'completed',
                'completion_verified_at' => now(),
            ]);
            $this->jobOrderService->syncStatusFromShipment($shipment->fresh(), $request->user()?->id);
        }

        return response()->json(['message' => 'Penyelesaian Job Order diverifikasi.', 'data' => $this->transformDetail($vendorJobOrder->fresh())]);
    }

    public function storeDocument(Request $request, VendorJobOrder $vendorJobOrder): JsonResponse
    {
        $data = $request->validate([
            'document_type' => 'nullable|in:supporting,job_order_pdf',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $file = $request->file('file');
        $path = $file->store('vendor-job-orders/'.$vendorJobOrder->id, 'public');

        $doc = VendorJobOrderDocument::create([
            'vendor_job_order_id' => $vendorJobOrder->id,
            'document_type' => $data['document_type'] ?? 'supporting',
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()?->id,
        ]);

        $this->jobOrderService->logActivity($vendorJobOrder, 'Supporting Document diunggah.', $request->user()?->id);

        return response()->json(['message' => 'Dokumen diunggah.', 'data' => $this->transformDocument($doc)], 201);
    }

    public function downloadDocument(VendorJobOrder $vendorJobOrder, VendorJobOrderDocument $document): JsonResponse
    {
        abort_unless($document->vendor_job_order_id === $vendorJobOrder->id, 404);

        return response()->json([
            'data' => [
                'url' => Storage::disk('public')->url($document->file_path),
                'name' => $document->original_name,
            ],
        ]);
    }

    public function pdf(VendorJobOrder $vendorJobOrder)
    {
        $pdf = $this->pdfService->renderVendorJobOrder($vendorJobOrder);
        $filename = addslashes($vendorJobOrder->job_order_number.'.pdf');

        return $pdf->download($filename);
    }

    private function transformListRow(VendorJobOrder $jo): array
    {
        $snap = $jo->shipment_snapshot ?? [];
        $origin = $jo->shipment?->originLocation;
        $dest = $jo->shipment?->destinationLocation;
        $originLabel = $origin?->code ?? $origin?->name ?? $snap['origin'] ?? null;
        $destLabel = $dest?->code ?? $dest?->name ?? $snap['destination'] ?? null;
        $routeLabel = implode(' → ', array_filter([$originLabel, $destLabel])) ?: '—';

        return [
            'id' => $jo->id,
            'job_order_number' => $jo->job_order_number,
            'shipment_number' => $jo->shipment?->shipment_number,
            'shipment_id' => $jo->shipment_id,
            'vendor' => $jo->vendor?->name,
            'vendor_id' => $jo->vendor_id,
            'service_type' => $jo->service_type?->value ?? $jo->service_type,
            'service_label' => $jo->service_type?->label() ?? '—',
            'route' => $routeLabel,
            'status' => $jo->status?->value ?? $jo->status,
            'status_label' => $jo->status?->label() ?? '—',
            'created_at' => $jo->created_at?->toIso8601String(),
        ];
    }

    private function transformDetail(VendorJobOrder $jo): array
    {
        $snap = $jo->shipment_snapshot ?? [];
        $vendorSnap = $jo->vendor_snapshot ?? [];
        $origin = $jo->shipment?->originLocation;
        $dest = $jo->shipment?->destinationLocation;

        return array_merge($this->transformListRow($jo), [
            'vendor_code' => $vendorSnap['code'] ?? $jo->vendor?->code,
            'vendor_pic' => $vendorSnap['pic_name'] ?? null,
            'vendor_mobile' => $vendorSnap['pic_mobile'] ?? null,
            'consignment_note' => $snap['waybill_number'] ?? $jo->shipment?->waybill_number,
            'customer' => $snap['customer'] ?? $jo->shipment?->company?->name,
            'origin' => $snap['origin'] ?? $origin?->name ?? $origin?->code,
            'destination' => $snap['destination'] ?? $dest?->name ?? $dest?->code,
            'shipment_coverage' => $snap['shipment_coverage'] ?? $jo->shipment?->shipment_coverage,
            'pickup_address' => $jo->pickup_address,
            'pickup_date' => $jo->pickup_date?->toIso8601String(),
            'pickup_remark' => $jo->pickup_remark,
            'pickup_cargo_info' => $jo->pickup_cargo_info,
            'delivery_address' => $jo->delivery_address,
            'delivery_date' => $jo->delivery_date?->toIso8601String(),
            'delivery_remark' => $jo->delivery_remark,
            'delivery_cargo_info' => $jo->delivery_cargo_info,
            'origin_yard' => $jo->originYard?->name,
            'destination_yard' => $jo->destinationYard?->name,
            'train_schedule' => $jo->train?->name,
            'departure_at' => $jo->departure_at?->toIso8601String(),
            'vehicle_type' => $jo->vehicle_type,
            'vehicle_plate' => $jo->vehicle_plate,
            'driver_name' => $jo->driver_name,
            'driver_mobile' => $jo->driver_mobile,
            'vehicle_remark' => $jo->vehicle_remark,
            'vendor_rate' => $jo->vendor_rate,
            'additional_cost' => $jo->additional_cost,
            'total_cost' => $jo->total_cost,
            'is_editable' => $jo->isEditable(),
            'can_verify_completion' => $jo->status === VendorJobOrderStatus::InProgress,
            'can_send' => $jo->status === VendorJobOrderStatus::Draft,
            'has_job_order_pdf' => true,
            'documents' => $jo->documents->map(fn ($d) => $this->transformDocument($d)),
            'activities' => $jo->activities->map(fn ($a) => [
                'activity' => $a->activity,
                'created_by' => $a->user?->name,
                'created_at' => $a->created_at?->toIso8601String(),
            ]),
        ]);
    }

    private function transformDocument(VendorJobOrderDocument $doc): array
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
}
