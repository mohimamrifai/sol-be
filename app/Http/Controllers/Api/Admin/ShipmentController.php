<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Container;
use App\Models\ContainerAsset;
use App\Models\Invoice;
use App\Models\Rack;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Services\ShipmentViewService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentController extends Controller
{
    public function __construct(
        private readonly ShipmentViewService $shipmentView,
    ) {}

    public function stats(): JsonResponse
    {
        $all = Shipment::query()->select('status')->get();
        $planning = $all->whereIn('status', ['created', 'booking_created', 'survey_completed'])->count();
        $ready = $all->whereIn('status', ['cargo_received', 'stuffing_container', 'container_sealed', 'ready_for_pickup'])->count();
        $inTransit = $all->whereIn('status', ['train_departed', 'departed', 'train_arrived', 'arrived', 'container_unloading', 'unloading'])->count();
        $completed = $all->where('status', 'completed')->count();
        $cancelled = $all->where('status', 'cancelled')->count();

        return response()->json([
            'data' => [
                'planning' => $planning,
                'ready_for_departure' => $ready,
                'in_transit' => $inTransit,
                'completed' => $completed,
                'cancelled' => $cancelled,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Shipment::with([
            'company:id,name,company_code', 'booking:id,booking_number',
            'originLocation:id,name,code',
            'destinationLocation:id,name,code', 'serviceType:id,name,code',
        ]);

        if ($request->filled('status')) {
            $this->applyFsdStatusFilter($query, (string) $request->status);
        }
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->filled('service_type_id')) {
            $query->where('service_type_id', $request->service_type_id);
        }
        if ($request->filled('shipment_coverage')) {
            $query->where('shipment_coverage', $request->shipment_coverage);
        }
        if ($request->filled('origin_location_id')) {
            $query->where('origin_location_id', $request->origin_location_id);
        }
        if ($request->filled('destination_location_id')) {
            $query->where('destination_location_id', $request->destination_location_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('estimated_departure', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('estimated_departure', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('shipment_number', 'like', "%{$s}%")
                    ->orWhere('waybill_number', 'like', "%{$s}%")
                    ->orWhereHas('booking', fn ($bq) => $bq->where('booking_number', 'like', "%{$s}%"))
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$s}%"));
            });
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 15));
    }

    private function applyFsdStatusFilter($query, string $fsdStatus): void
    {
        $map = [
            'planning' => ['created', 'booking_created', 'survey_completed'],
            'ready_for_departure' => ['cargo_received', 'stuffing_container', 'container_sealed', 'ready_for_pickup'],
            'in_transit' => ['train_departed', 'departed', 'train_arrived', 'arrived', 'container_unloading', 'unloading'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
        ];
        $statuses = $map[$fsdStatus] ?? [$fsdStatus];
        $query->whereIn('status', $statuses);
    }

    public function show(Shipment $shipment): JsonResponse
    {
        $shipment->load([
            'booking.cargoCategory', 'booking.dgClass',
            'company', 'originLocation', 'destinationLocation',
            'transportMode', 'serviceType', 'createdByUser:id,name',
            'internalPic:id,name', 'train:id,name,code',
            'originYard:id,name,code', 'destinationYard:id,name,code',
            'pickupVendor:id,name,code', 'deliveryVendor:id,name,code',
            'containers.containerType', 'containers.containerAsset.vendor', 'containers.containerAsset.currentYard',
            'containers.racks.items',
            'items', 'trackings.photos', 'trackings.updatedByUser:id,name',
            'invoice',
        ]);

        $payload = $shipment->toArray();
        $payload['cargo'] = $this->shipmentView->cargo($shipment);
        $payload['documents'] = $this->shipmentView->documents($shipment);
        $payload['activity_log'] = $this->shipmentView->activityLog($shipment);

        return response()->json(['data' => $payload]);
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
            'internal_pic_id' => 'nullable|exists:users,id',
            'train_id' => 'nullable|exists:trains,id',
            'origin_yard_id' => 'nullable|exists:locations,id',
            'destination_yard_id' => 'nullable|exists:locations,id',
            'planning_notes' => 'nullable|string|max:5000',
            'pickup_vendor_id' => 'nullable|exists:vendors,id',
            'pickup_vehicle_type' => 'nullable|string|max:60',
            'pickup_vehicle_plate' => 'nullable|string|max:30',
            'pickup_driver_name' => 'nullable|string|max:120',
            'pickup_driver_mobile' => 'nullable|string|max:30',
            'pickup_vendor_pic' => 'nullable|string|max:120',
            'pickup_scheduled_at' => 'nullable|date',
            'pickup_remark' => 'nullable|string|max:5000',
            'delivery_vendor_id' => 'nullable|exists:vendors,id',
            'delivery_vehicle_type' => 'nullable|string|max:60',
            'delivery_vehicle_plate' => 'nullable|string|max:30',
            'delivery_driver_name' => 'nullable|string|max:120',
            'delivery_driver_mobile' => 'nullable|string|max:30',
            'delivery_vendor_pic' => 'nullable|string|max:120',
            'delivery_scheduled_at' => 'nullable|date',
            'delivery_remark' => 'nullable|string|max:5000',
        ]);

        $shipment->update($data);

        return response()->json([
            'message' => 'Shipment diperbarui.',
            'data' => $shipment->fresh([
                'internalPic:id,name', 'train:id,name,code',
                'originYard:id,name,code', 'destinationYard:id,name,code',
            ]),
        ]);
    }

    /**
     * Tandai shipment siap berangkat (FSD: Ready for Departure).
     */
    public function readyForDeparture(Request $request, Shipment $shipment): JsonResponse
    {
        if ($shipment->status === 'cancelled') {
            return response()->json(['message' => 'Shipment sudah dibatalkan.'], 422);
        }
        if ($shipment->status === 'completed') {
            return response()->json(['message' => 'Shipment sudah selesai.'], 422);
        }
        if ($shipment->status === 'ready_for_pickup') {
            return response()->json(['message' => 'Shipment sudah siap berangkat.'], 422);
        }

        $errors = [];
        if (! $shipment->estimated_departure) {
            $errors['estimated_departure'] = 'Tanggal keberangkatan estimasi wajib diisi.';
        }
        if (! $shipment->train_id) {
            $errors['train_id'] = 'Kereta wajib dipilih.';
        }
        if ($shipment->containers()->count() === 0 && $shipment->items()->count() === 0) {
            $errors['cargo'] = 'Minimal satu container atau item cargo harus ada.';
        }
        if (! $shipment->waybill_number) {
            $errors['waybill_number'] = 'Nomor waybill wajib ada sebelum siap berangkat.';
        }

        $coverage = (string) ($shipment->shipment_coverage ?? '');
        if (in_array($coverage, ['door_to_port', 'door_to_door'], true) && ! $shipment->pickup_vendor_id) {
            $errors['pickup_vendor_id'] = 'Pickup vendor wajib di-assign untuk layanan door pickup.';
        }
        if (in_array($coverage, ['port_to_door', 'door_to_door'], true) && ! $shipment->delivery_vendor_id) {
            $errors['delivery_vendor_id'] = 'Delivery vendor wajib di-assign untuk layanan door delivery.';
        }

        if ($errors !== []) {
            return response()->json(['message' => 'Shipment belum memenuhi syarat siap berangkat.', 'errors' => $errors], 422);
        }

        $shipment->update(['status' => 'ready_for_pickup']);
        $shipment->trackings()->create([
            'status' => 'ready_for_pickup',
            'notes' => 'Shipment siap berangkat.',
            'tracked_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Shipment ditandai siap berangkat.',
            'data' => $shipment->fresh(['trackings']),
        ]);
    }

    /**
     * Batalkan shipment (FSD).
     */
    public function cancelShipment(Request $request, Shipment $shipment): JsonResponse
    {
        if (in_array($shipment->status, ['completed', 'cancelled'], true)) {
            return response()->json(['message' => 'Shipment tidak dapat dibatalkan.'], 422);
        }

        $preReadyStatuses = [
            'created', 'booking_created', 'survey_completed',
            'cargo_received', 'stuffing_container', 'container_sealed',
        ];
        if (! in_array($shipment->status, $preReadyStatuses, true)) {
            return response()->json(['message' => 'Shipment hanya dapat dibatalkan sebelum status Ready for Departure.'], 422);
        }

        $data = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $shipment->update([
            'status' => 'cancelled',
            'cancelled_reason' => $data['reason'],
        ]);
        $shipment->trackings()->create([
            'status' => 'cancelled',
            'notes' => $data['reason'],
            'tracked_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Shipment berhasil dibatalkan.',
            'data' => $shipment->fresh(['trackings']),
        ]);
    }

    // ── STATUS TRACKING ──
    public function updateTracking(Request $request, Shipment $shipment): JsonResponse
    {
        $statuses = config('shipment.tracking_statuses', []);
        $statusRule = empty($statuses) ? 'required|string' : 'required|string|in:'.implode(',', $statuses);
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
                        'description' => 'Layanan Tambahan: '.$addSvc['name'],
                        'quantity' => 1,
                        'unit_price' => $addSvc['price'],
                        'total_price' => $addSvc['price'],
                    ]);
                }
            }

            foreach ($shipmentChargesDetail as $charge) {
                if ($charge['price'] > 0) {
                    $invoice->items()->create([
                        'description' => 'Biaya Tambahan (Shipment): '.$charge['name'],
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
    public function availableContainers(Request $request, Shipment $shipment): JsonResponse
    {
        $shipment->loadMissing(['booking.containers.containerType', 'serviceType', 'originYard', 'originLocation']);

        $data = $request->validate([
            'ownership' => 'nullable|in:company,vendor,all',
            'container_type_id' => 'nullable|exists:container_types,id',
            'search' => 'nullable|string|max:100',
        ]);

        $serviceCode = strtoupper((string) ($shipment->serviceType?->code ?? ''));
        $isLcl = $serviceCode === 'LCL';
        $requiredTypeId = $data['container_type_id'] ?? null;

        if (! $requiredTypeId && $isLcl) {
            $requiredTypeId = $shipment->containers->first()?->container_type_id;
        }

        $query = ContainerAsset::query()
            ->with(['containerType:id,name,size', 'vendor:id,name,code', 'currentYard:id,name,code'])
            ->where('status', 'available');

        if ($requiredTypeId) {
            $query->where('container_type_id', $requiredTypeId);
        }

        $ownership = $data['ownership'] ?? 'all';
        if ($ownership !== 'all') {
            $query->where('ownership', $ownership);
        }

        if (! empty($data['search'])) {
            $s = $data['search'];
            $query->where('container_number', 'like', "%{$s}%");
        }

        $rows = $query->orderBy('container_number')->limit(100)->get();

        $shipmentCbm = (float) ($shipment->booking?->estimated_cbm ?? $shipment->items()->sum('cbm') ?? 0);
        $shipmentWeight = (float) ($shipment->booking?->estimated_weight ?? $shipment->items()->sum('gross_weight') ?? 0);

        $mapped = $rows->map(function (ContainerAsset $asset) use ($isLcl, $shipmentCbm, $shipmentWeight) {
            $usedCbm = 0.0;
            $usedPayload = 0.0;

            if ($isLcl) {
                $linkedShipments = Container::query()
                    ->where('container_asset_id', $asset->id)
                    ->whereHas('shipment', fn ($q) => $q->whereNotIn('status', ['cancelled', 'completed']))
                    ->with('shipment.items')
                    ->get();

                foreach ($linkedShipments as $linked) {
                    $usedCbm += (float) ($linked->shipment?->booking?->estimated_cbm ?? $linked->shipment?->items?->sum('cbm') ?? 0);
                    $usedPayload += (float) ($linked->shipment?->booking?->estimated_weight ?? $linked->shipment?->items?->sum('gross_weight') ?? 0);
                }
            }

            $maxCbm = (float) ($asset->max_capacity_cbm ?? $asset->containerType?->capacity_cbm ?? 0);
            $maxPayload = (float) ($asset->max_payload_kg ?? $asset->containerType?->capacity_weight ?? 0);
            $remainingCbm = $maxCbm > 0 ? max(0, $maxCbm - $usedCbm) : null;
            $remainingPayload = $maxPayload > 0 ? max(0, $maxPayload - $usedPayload) : null;

            $canAssign = ! $isLcl || (
                ($remainingCbm === null || $remainingCbm >= $shipmentCbm)
                && ($remainingPayload === null || $remainingPayload >= $shipmentWeight)
            );

            return [
                'id' => $asset->id,
                'container_number' => $asset->container_number,
                'container_type' => $asset->containerType,
                'ownership' => $asset->ownership,
                'vendor' => $asset->vendor,
                'current_yard' => $asset->currentYard,
                'status' => $asset->status,
                'used_cbm' => round($usedCbm, 3),
                'remaining_cbm' => $remainingCbm !== null ? round($remainingCbm, 3) : null,
                'used_payload_kg' => round($usedPayload, 2),
                'remaining_payload_kg' => $remainingPayload !== null ? round($remainingPayload, 2) : null,
                'can_assign' => $canAssign,
            ];
        })->values();

        return response()->json(['data' => $mapped]);
    }

    public function assignContainerSlot(Request $request, Shipment $shipment, Container $container): JsonResponse
    {
        if ((int) $container->shipment_id !== (int) $shipment->id) {
            return response()->json(['message' => 'Container tidak termasuk shipment ini.'], 422);
        }

        if ($deny = $this->ensureCanModifyPlanning($request, $shipment)) {
            return $deny;
        }

        $data = $request->validate([
            'container_asset_id' => 'nullable|exists:container_assets,id',
            'container_number' => 'nullable|string|max:255',
            'seal_number' => 'nullable|string|max:255',
            'ownership' => 'nullable|in:company,vendor,customer',
            'remark' => 'nullable|string|max:2000',
        ]);

        $responsibility = strtoupper((string) ($shipment->booking?->container_responsibility ?? ''));
        $isCustomerProvided = $responsibility === 'SOC';

        if ($isCustomerProvided) {
            if (empty($data['container_number'])) {
                return response()->json(['message' => 'Nomor container wajib diisi.'], 422);
            }
            $container->update([
                'container_number' => $data['container_number'],
                'seal_number' => $data['seal_number'] ?? null,
                'ownership' => 'customer',
                'assignment_status' => 'assigned',
                'container_asset_id' => null,
                'remark' => $data['remark'] ?? null,
            ]);
        } else {
            $asset = null;
            if (! empty($data['container_asset_id'])) {
                $asset = ContainerAsset::query()
                    ->where('id', $data['container_asset_id'])
                    ->where('status', 'available')
                    ->first();
                if (! $asset) {
                    return response()->json(['message' => 'Container tidak tersedia.'], 422);
                }
            } elseif (empty($data['container_number'])) {
                return response()->json(['message' => 'Pilih container atau isi nomor container.'], 422);
            }

            $container->update([
                'container_asset_id' => $asset?->id,
                'container_number' => $asset?->container_number ?? $data['container_number'],
                'seal_number' => $data['seal_number'] ?? null,
                'ownership' => $data['ownership'] ?? ($asset?->ownership === 'vendor' ? 'vendor' : 'company'),
                'assignment_status' => 'assigned',
                'remark' => $data['remark'] ?? null,
            ]);

            if ($asset) {
                $asset->update(['status' => 'reserved']);
            }
        }

        return response()->json([
            'message' => 'Container berhasil dialokasikan.',
            'data' => $container->fresh(['containerType', 'containerAsset.vendor', 'containerAsset.currentYard']),
        ]);
    }

    public function registerVendorContainer(Request $request, Shipment $shipment): JsonResponse
    {
        if ($deny = $this->ensureCanModifyPlanning($request, $shipment)) {
            return $deny;
        }

        $data = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'container_number' => 'required|string|max:255',
            'container_type_id' => 'required|exists:container_types,id',
            'current_yard_id' => 'nullable|exists:locations,id',
            'remark' => 'nullable|string|max:2000',
        ]);

        $asset = ContainerAsset::query()->firstOrCreate(
            ['container_number' => strtoupper(trim($data['container_number']))],
            [
                'container_type_id' => $data['container_type_id'],
                'ownership' => 'vendor',
                'vendor_id' => $data['vendor_id'],
                'current_yard_id' => $data['current_yard_id'] ?? $shipment->origin_yard_id ?? $shipment->origin_location_id,
                'status' => 'available',
                'remark' => $data['remark'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Vendor container terdaftar.',
            'data' => $asset->load(['containerType:id,name,size', 'vendor:id,name,code', 'currentYard:id,name,code']),
        ], 201);
    }

    public function addContainer(Request $request, Shipment $shipment): JsonResponse
    {
        if ($deny = $this->ensureCanModifyPlanning($request, $shipment)) {
            return $deny;
        }

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
        $shipment = $container->shipment;
        if ($shipment && ($deny = $this->ensureCanModifyPlanning($request, $shipment))) {
            return $deny;
        }

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
        $assetId = $container->container_asset_id;
        $container->delete();

        if ($assetId) {
            ContainerAsset::where('id', $assetId)->where('status', 'reserved')->update(['status' => 'available']);
        }

        return response()->json(['message' => 'Container dihapus.']);
    }

    private function ensureCanModifyPlanning(Request $request, Shipment $shipment): ?JsonResponse
    {
        $postReadyStatuses = [
            'ready_for_pickup', 'departed', 'train_departed', 'arrived', 'train_arrived',
            'unloading', 'container_unloading', 'completed',
        ];

        if (! in_array($shipment->status, $postReadyStatuses, true)) {
            return null;
        }

        if ($request->user()?->hasRole('super_admin')) {
            return null;
        }

        return response()->json([
            'message' => 'Perubahan setelah Ready for Departure memerlukan otorisasi super admin.',
        ], 403);
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

        return $pdf->download('consignment-note-'.$shipment->waybill_number.'.pdf');
    }

    public function downloadWaybillPdf(Shipment $shipment)
    {
        $shipment->load([
            'originLocation', 'destinationLocation', 'serviceType',
            'trackings' => fn ($q) => $q->orderBy('tracked_at', 'asc'),
        ]);

        $pdf = Pdf::loadView('pdf.waybill', ['shipment' => $shipment]);

        return $pdf->download('waybill-'.$shipment->waybill_number.'.pdf');
    }
}
