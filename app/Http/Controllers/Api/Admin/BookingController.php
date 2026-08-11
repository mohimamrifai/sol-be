<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CargoCategory;
use App\Models\Shipment;
use App\Models\User;
use App\Services\BookingPriceEstimateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private BookingPriceEstimateService $priceEstimateService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Booking::with([
            'company:id,name,company_code',
            'user:id,name',
            'originLocation:id,name,code',
            'destinationLocation:id,name,code',
            'serviceType:id,name,code',
            'transportMode:id,name',
        ])->withExists('shipment');

        if ($request->filled('status')) {
            if ($request->status === 'converted') {
                $query->whereHas('shipment');
            } else {
                $query->where('status', $request->status)->whereDoesntHave('shipment');
            }
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
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('booking_number', 'like', "%{$search}%")
                    ->orWhere('cargo_description', 'like', "%{$search}%")
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        return response()->json(
            $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 15)
        );
    }

    public function stats(): JsonResponse
    {
        $counts = Booking::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $converted = Booking::whereHas('shipment')->count();

        return response()->json([
            'data' => [
                'draft' => (int) ($counts['draft'] ?? 0),
                'submitted' => (int) ($counts['submitted'] ?? 0),
                'confirmed' => (int) ($counts['approved'] ?? 0),
                'converted' => $converted,
                'rejected' => (int) ($counts['rejected'] ?? 0),
                'cancelled' => (int) ($counts['cancelled'] ?? 0),
            ],
        ]);
    }

    public function show(Booking $booking): JsonResponse
    {
        $booking->load([
            'company', 'user', 'cargoCategory', 'dgClass',
            'originLocation', 'destinationLocation',
            'transportMode', 'serviceType', 'containerType',
            'additionalServices', 'shipment', 'approvedByUser:id,name',
            'activities.actor:id,name',
        ]);
        $booking->setAttribute('has_shipment', $booking->shipment()->exists());

        return response()->json(['data' => $booking]);
    }

    public function update(Request $request, Booking $booking): JsonResponse
    {
        if (! $request->user()?->hasAnyRole(['super_admin', 'operations'])) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if ($booking->shipment()->exists()) {
            return response()->json(['message' => 'Booking yang sudah memiliki shipment tidak bisa diubah.'], 422);
        }

        if ($booking->status === 'cancelled') {
            return response()->json(['message' => 'Booking yang sudah dibatalkan tidak bisa diubah.'], 422);
        }

        if (is_string($request->additional_services)) {
            $request->merge([
                'additional_services' => json_decode($request->additional_services, true),
            ]);
        }

        $data = $request->validate([
            'origin_location_id' => 'required|exists:locations,id',
            'destination_location_id' => 'required|exists:locations,id',
            'transport_mode_id' => 'required|exists:transport_modes,id',
            'service_type_id' => 'required|exists:service_types,id',
            'container_type_id' => 'nullable|exists:container_types,id',
            'container_count' => 'nullable|integer|min:0',
            'estimated_weight' => 'nullable|numeric|min:0',
            'estimated_cbm' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'cargo_category_id' => 'required|exists:cargo_categories,id',
            'departure_date' => 'nullable|date',
            'cargo_description' => 'nullable|string|max:2000',
            'shipper_name' => 'required|string|max:255',
            'shipper_address' => 'required|string',
            'shipper_phone' => 'required|string|max:50',
            'consignee_name' => 'required|string|max:255',
            'consignee_address' => 'required|string',
            'consignee_phone' => 'required|string|max:50',
            'additional_services' => 'nullable',
            'additional_services.*.id' => 'required|exists:additional_services,id',
            'additional_services.*.notes' => 'nullable|string|max:2000',
            'is_dangerous_goods' => 'nullable|boolean',
            'dg_class_id' => 'nullable|exists:dg_classes,id',
            'un_number' => 'nullable|string|max:50',
            'msds_file' => 'nullable|file|mimes:pdf|max:5120',
            'equipment_condition' => 'nullable|in:CLEAN,RESIDUAL',
            'temperature' => 'nullable|numeric',
        ]);

        $estimateParams = [
            ...$data,
            'additional_services' => array_column($data['additional_services'] ?? [], 'id'),
            'container_count' => $data['container_count'] ?? 1,
            'estimated_weight' => (float) ($data['estimated_weight'] ?? 0),
            'estimated_cbm' => (float) ($data['estimated_cbm'] ?? 0),
        ];
        $estimate = $this->priceEstimateService->estimate($estimateParams);

        $msdsPath = $booking->msds_file;
        if ($request->hasFile('msds_file')) {
            $msdsPath = $request->file('msds_file')->store('msds_files', 'public');
        }

        $payload = [
            ...$data,
            'estimated_price' => $estimate['estimated_price'],
            'msds_file' => ! empty($data['is_dangerous_goods']) ? $msdsPath : null,
            'dg_class_id' => ! empty($data['is_dangerous_goods']) ? ($data['dg_class_id'] ?? null) : null,
            'un_number' => ! empty($data['is_dangerous_goods']) ? ($data['un_number'] ?? null) : null,
        ];

        unset($payload['additional_services']);
        $booking->update($payload);

        $booking->additionalServices()->sync(
            collect($data['additional_services'] ?? [])->mapWithKeys(fn ($svc) => [
                $svc['id'] => ['notes' => $svc['notes'] ?? null],
            ])->all()
        );
        $booking->load([
            'company', 'user', 'cargoCategory', 'dgClass',
            'originLocation', 'destinationLocation',
            'transportMode', 'serviceType', 'containerType',
            'additionalServices', 'shipment', 'approvedByUser:id,name',
        ]);
        $booking->setAttribute('has_shipment', $booking->shipment()->exists());

        return response()->json([
            'message' => 'Detail booking berhasil diperbarui.',
            'data' => $booking,
        ]);
    }

    private function ensureCanCreateBooking(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->hasAnyRole(['super_admin', 'operations'])) {
            return response()->json(['message' => 'Akses ditolak. Hanya untuk super_admin / operations.'], 403);
        }

        return null;
    }

    /**
     * Estimasi harga booking (tanpa membuat booking).
     */
    public function estimatePrice(Request $request): JsonResponse
    {
        if ($resp = $this->ensureCanCreateBooking($request)) {
            return $resp;
        }

        $data = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'origin_location_id' => 'required|exists:locations,id',
            'destination_location_id' => 'required|exists:locations,id',
            'transport_mode_id' => 'required|exists:transport_modes,id',
            'service_type_id' => 'required|exists:service_types,id',
            'cargo_category_id' => 'nullable|exists:cargo_categories,id',
            'container_type_id' => 'nullable|exists:container_types,id',
            'container_count' => 'nullable|integer|min:0',
            'estimated_weight' => 'nullable|numeric|min:0',
            'estimated_cbm' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'additional_services' => 'nullable|array',
            'additional_services.*.id' => 'exists:additional_services,id',
            'is_dangerous_goods' => 'nullable|boolean',
            'dg_class_id' => 'nullable|exists:dg_classes,id',
            'un_number' => 'nullable|string|max:50',
            'equipment_condition' => 'nullable|in:CLEAN,RESIDUAL',
            'temperature' => 'nullable|numeric',
        ]);

        $params = [
            ...$data,
            'additional_services' => array_column($data['additional_services'] ?? [], 'id'),
        ];

        $result = $this->priceEstimateService->estimate($params);

        return response()->json(['data' => $result]);
    }

    /**
     * Buat booking oleh tim internal (admin).
     */
    public function store(Request $request): JsonResponse
    {
        if ($resp = $this->ensureCanCreateBooking($request)) {
            return $resp;
        }

        $user = $request->user();

        $data = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'origin_location_id' => 'required|exists:locations,id',
            'destination_location_id' => 'required|exists:locations,id',
            'transport_mode_id' => 'required|exists:transport_modes,id',
            'service_type_id' => 'required|exists:service_types,id',
            'container_type_id' => 'nullable|exists:container_types,id',
            'container_count' => 'nullable|integer|min:0',
            'estimated_weight' => 'nullable|numeric|min:0',
            'estimated_cbm' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'cargo_category_id' => 'required|exists:cargo_categories,id',
            'departure_date' => 'nullable|date',
            'cargo_description' => [
                'nullable', 'string', 'max:2000',
                function ($attribute, $value, $fail) use ($request) {
                    $cat = CargoCategory::find($request->cargo_category_id);
                    if ($cat && $cat->code === 'MIX' && empty($value)) {
                        $fail('Deskripsi barang wajib diisi untuk kategori Mixed Cargo.');
                    }
                },
            ],
            'shipper_name' => 'required|string|max:255',
            'shipper_address' => 'required|string',
            'shipper_phone' => 'required|string|max:50',
            'consignee_name' => 'required|string|max:255',
            'consignee_address' => 'required|string',
            'consignee_phone' => 'required|string|max:50',
            'additional_services' => 'nullable', // Handle possible JSON from FormData
            'additional_services.*.id' => 'required|exists:additional_services,id',
            'additional_services.*.notes' => 'nullable|string|max:2000',

            // DG & Special Cargo
            'is_dangerous_goods' => 'nullable|boolean',
            'dg_class_id' => 'required_if:is_dangerous_goods,1|exists:dg_classes,id',
            'un_number' => 'required_if:is_dangerous_goods,1|string|max:50',
            'msds_file' => 'required_if:is_dangerous_goods,1|file|mimes:pdf|max:5120',
            'equipment_condition' => 'nullable|in:CLEAN,RESIDUAL',
            'temperature' => [
                'nullable', 'numeric',
                function ($attribute, $value, $fail) use ($request) {
                    $cat = CargoCategory::find($request->cargo_category_id);
                    if ($cat && $cat->requires_temperature && $value === null) {
                        $fail('Suhu (temperature) wajib diisi untuk kategori kargo ini.');
                    }
                },
            ],
        ]);

        if (is_string($request->additional_services)) {
            $data['additional_services'] = json_decode($request->additional_services, true);
        }

        $estimateParams = [
            ...$data,
            'additional_services' => array_column($data['additional_services'] ?? [], 'id'),
            'container_count' => $data['container_count'] ?? 1,
            'estimated_weight' => (float) ($data['estimated_weight'] ?? 0),
            'estimated_cbm' => (float) ($data['estimated_cbm'] ?? 0),
        ];
        $estimate = $this->priceEstimateService->estimate($estimateParams);

        $msdsPath = null;
        if ($request->hasFile('msds_file')) {
            $msdsPath = $request->file('msds_file')->store('msds_files', 'public');
        }

        $booking = Booking::create([
            ...$data,
            'user_id' => $user->id,
            'status' => 'submitted',
            'estimated_price' => $estimate['estimated_price'],
            'msds_file' => $msdsPath,
            'additional_services' => null,
        ]);

        if (! empty($data['additional_services'])) {
            foreach ($data['additional_services'] as $svc) {
                $booking->additionalServices()->attach($svc['id'], [
                    'notes' => $svc['notes'] ?? null,
                ]);
            }
        }

        return response()->json([
            'message' => 'Booking berhasil dibuat.',
            'data' => $booking->load(['company', 'user', 'cargoCategory', 'dgClass', 'originLocation', 'destinationLocation', 'serviceType', 'additionalServices']),
            'estimated_price' => $estimate['estimated_price'],
            'breakdown' => $estimate['breakdown'],
        ], 201);
    }

    /**
     * Konfirmasi booking (FSD: Submitted → Confirmed).
     */
    public function approve(Request $request, Booking $booking): JsonResponse
    {
        if (! in_array($booking->status, ['submitted', 'confirmed'])) {
            return response()->json(['message' => 'Booking tidak dalam status yang bisa dikonfirmasi.'], 422);
        }

        if ($booking->shipment()->exists()) {
            return response()->json(['message' => 'Booking sudah dikonversi menjadi shipment.'], 422);
        }

        $booking->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        $this->logBookingActivity($booking, 'booking_confirmed', 'Booking dikonfirmasi.', $request->user());

        return response()->json([
            'message' => 'Booking berhasil dikonfirmasi.',
            'data' => $booking->fresh(['shipment', 'approvedByUser:id,name']),
        ]);
    }

    public function confirm(Request $request, Booking $booking): JsonResponse
    {
        return $this->approve($request, $booking);
    }

    public function submit(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->status !== 'draft') {
            return response()->json(['message' => 'Hanya booking draft yang dapat disubmit.'], 422);
        }

        $booking->update(['status' => 'submitted']);
        $this->logBookingActivity($booking, 'booking_submitted', 'Booking disubmit.', $request->user());

        return response()->json([
            'message' => 'Booking berhasil disubmit.',
            'data' => $booking,
        ]);
    }

    public function destroy(Booking $booking): JsonResponse
    {
        if ($booking->shipment()->exists()) {
            return response()->json(['message' => 'Booking yang sudah dikonversi tidak dapat dihapus.'], 422);
        }

        if (! in_array($booking->status, ['draft', 'cancelled', 'rejected'])) {
            return response()->json(['message' => 'Hanya booking draft yang dapat dihapus.'], 422);
        }

        $booking->delete();

        return response()->json(['message' => 'Booking berhasil dihapus.']);
    }

    /**
     * Tolak booking.
     */
    public function reject(Request $request, Booking $booking): JsonResponse
    {
        if (! in_array($booking->status, ['submitted', 'confirmed'])) {
            return response()->json(['message' => 'Booking tidak dalam status yang bisa ditolak.'], 422);
        }

        $request->validate(['reason' => 'required|string|max:1000']);

        $booking->update([
            'status' => 'rejected',
            'rejection_reason' => $request->reason,
        ]);

        return response()->json([
            'message' => 'Booking berhasil ditolak.',
            'data' => $booking,
        ]);
    }

    /**
     * Konversi booking yang sudah disetujui → Shipment.
     */
    public function convertToShipment(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->status !== 'approved') {
            return response()->json(['message' => 'Hanya booking yang sudah disetujui yang bisa dikonversi.'], 422);
        }

        if ($booking->shipment()->exists()) {
            return response()->json(['message' => 'Booking ini sudah memiliki shipment.'], 422);
        }

        $shipment = Shipment::create([
            'booking_id' => $booking->id,
            'company_id' => $booking->company_id,
            'origin_location_id' => $booking->origin_location_id,
            'destination_location_id' => $booking->destination_location_id,
            'transport_mode_id' => $booking->transport_mode_id,
            'service_type_id' => $booking->service_type_id,
            'shipment_coverage' => $booking->shipment_coverage,
            'status' => 'created',
            'created_by' => $request->user()->id,

            // Copy DG & Special fields
            'cargo_category_id' => $booking->cargo_category_id,
            'is_dangerous_goods' => $booking->is_dangerous_goods,
            'dg_class_id' => $booking->dg_class_id,
            'un_number' => $booking->un_number,
            'msds_file' => $booking->msds_file,
            'equipment_condition' => $booking->equipment_condition,
            'temperature' => $booking->temperature,
            'shipper_snapshot' => $this->buildShipperSnapshot($booking),
            'consignee_snapshot' => $this->buildConsigneeSnapshot($booking),
        ]);

        $shipment->trackings()->create([
            'status' => 'created',
            'notes' => 'Shipment dibuat dari booking '.$booking->booking_number,
            'tracked_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        $this->logBookingActivity($booking, 'booking_converted', 'Booking dikonversi menjadi shipment.', $request->user());

        return response()->json([
            'message' => 'Shipment berhasil dibuat dari booking.',
            'data' => $shipment->load(['booking', 'trackings']),
        ], 201);
    }

    private function logBookingActivity(Booking $booking, string $type, string $title, ?User $actor): void
    {
        $booking->recordActivity($type, $title, null, null, $actor);
    }

    private function buildShipperSnapshot(Booking $booking): array
    {
        return [
            'name' => $booking->shipper_name,
            'phone' => $booking->shipper_phone,
            'address' => $booking->shipper_address,
            'branch_id' => $booking->shipper_branch_id,
            'snapshot' => $booking->shipper_snapshot,
        ];
    }

    private function buildConsigneeSnapshot(Booking $booking): array
    {
        return [
            'name' => $booking->consignee_name,
            'phone' => $booking->consignee_phone,
            'address' => $booking->consignee_address,
            'type' => $booking->consignee_type,
            'branch_id' => $booking->consignee_branch_id,
            'snapshot' => $booking->consignee_snapshot,
        ];
    }
}
