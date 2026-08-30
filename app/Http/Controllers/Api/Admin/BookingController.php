<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingAttachment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\BookingDraftExpiryService;
use App\Services\BookingPersistenceService;
use App\Services\BookingPriceEstimateService;
use App\Services\ShipmentConversionService;
use App\Services\ShipmentActivityLogger;
use App\Services\ShipmentViewService;
use App\Support\SystemConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    public function __construct(
        private BookingPriceEstimateService $priceEstimateService,
        private BookingPersistenceService $bookingPersistence,
        private ShipmentConversionService $shipmentConversion,
        private ShipmentViewService $shipmentView,
        private ShipmentActivityLogger $shipmentActivityLogger,
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
        ])->withExists('shipment as shipment_exists')
            ->with('shipment:id,booking_id');

        if ($request->filled('status')) {
            if ($request->status === 'converted') {
                $query->whereHas('shipment');
            } elseif ($request->status === 'submitted') {
                $query->whereIn('status', ['submitted', 'under_review'])->whereDoesntHave('shipment');
            } elseif ($request->status === 'confirmed') {
                $query->whereIn('status', ['approved', 'confirmed'])->whereDoesntHave('shipment');
            } else {
                $query->where('status', $request->status);
                if (! in_array($request->status, ['cancelled', 'rejected'], true)) {
                    $query->whereDoesntHave('shipment');
                }
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
        if ($request->filled('origin_location_id')) {
            $query->where('origin_location_id', $request->origin_location_id);
        }
        if ($request->filled('destination_location_id')) {
            $query->where('destination_location_id', $request->destination_location_id);
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
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        $paginated = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 15);
        $paginated->getCollection()->transform(function (Booking $booking) {
            $booking->setAttribute('shipment_id', $booking->shipment?->id);
            $booking->setAttribute('shipment_exists', (bool) ($booking->shipment_exists ?? $booking->shipment));

            return $booking;
        });

        return response()->json($paginated);
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
                'submitted' => (int) (($counts['submitted'] ?? 0) + ($counts['under_review'] ?? 0)),
                'confirmed' => (int) (($counts['approved'] ?? 0) + ($counts['confirmed'] ?? 0)),
                'converted' => $converted,
                'rejected' => (int) ($counts['rejected'] ?? 0),
                'cancelled' => (int) ($counts['cancelled'] ?? 0),
            ],
        ]);
    }

    public function show(Booking $booking): JsonResponse
    {
        $booking->load([
            'company.salesPic:id,name', 'user', 'cargoCategory', 'dgClass',
            'originLocation', 'destinationLocation',
            'shipperLocation:id,name', 'consigneeLocation:id,name',
            'transportMode', 'serviceType', 'containerType',
            'additionalServices', 'shipment.createdByUser:id,name', 'approvedByUser:id,name',
            'activities.actor:id,name',
            'packages.dgClass', 'containers.containerType', 'containers.dgClass',
            'attachments.uploader:id,name',
        ]);
        $booking->setAttribute('has_shipment', $booking->shipment()->exists());
        $booking->setAttribute('shipment_id', $booking->shipment?->id);
        $booking->setAttribute('price_breakdown', $this->priceEstimateService->breakdownForBooking($booking));

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

        if (in_array($booking->status, ['cancelled', 'rejected'], true)) {
            return response()->json(['message' => 'Booking dengan status ini tidak bisa diubah.'], 422);
        }

        if (is_string($request->additional_services)) {
            $request->merge([
                'additional_services' => json_decode($request->additional_services, true),
            ]);
        }
        $this->bookingPersistence->mergeJsonFields($request);
        $this->bookingPersistence->applyDerivedCargoCategory($request);

        $data = $request->validate(array_merge([
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
            'shipment_coverage' => 'nullable|in:door_to_door,door_to_port,port_to_door,port_to_port',
            'pickup_date' => 'nullable|date',
            'pickup_time' => 'nullable|string|max:20',
            'pickup_notes' => 'nullable|string|max:1000',
            'additional_services' => 'nullable',
            'additional_services.*.id' => 'required|exists:additional_services,id',
            'additional_services.*.notes' => 'nullable|string|max:2000',
            'is_dangerous_goods' => 'nullable|boolean',
            'dg_class_id' => 'nullable|exists:dg_classes,id',
            'un_number' => 'nullable|string|max:50',
            'msds_file' => 'nullable|file|mimes:pdf|max:5120',
            'equipment_condition' => 'nullable|in:CLEAN,RESIDUAL',
            'temperature' => 'nullable|numeric',
            'notes' => 'nullable|string|max:5000',
        ], $this->bookingPersistence->cargoFieldRules(false)));

        if ($cargoErrors = $this->bookingPersistence->validateCargoRules($request, $data, false, (int) $booking->company_id)) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $cargoErrors], 422);
        }

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

        unset(
            $payload['additional_services'],
            $payload['attachments'],
            $payload['attachments_meta'],
            $payload['packages_msds_files'],
            $payload['containers_msds_files'],
            $payload['packages'],
            $payload['containers'],
        );
        $booking->update($payload);

        $booking->additionalServices()->sync(
            collect($data['additional_services'] ?? [])->mapWithKeys(fn ($svc) => [
                $svc['id'] => ['notes' => $svc['notes'] ?? null],
            ])->all()
        );

        if (array_key_exists('packages', $data)) {
            $this->bookingPersistence->syncPackages($booking, $request, $data['packages'] ?? []);
        }
        if (array_key_exists('containers', $data)) {
            $this->bookingPersistence->syncContainers($booking, $request, $data['containers'] ?? []);
        }
        if ($request->hasFile('attachments')) {
            $this->bookingPersistence->syncAttachments(
                $booking,
                $request,
                (int) $request->user()->id,
                is_array($data['attachments_meta'] ?? null) ? $data['attachments_meta'] : []
            );
        }
        $this->bookingPersistence->recalculateAndSave($booking);

        $booking->load([
            'company', 'user', 'cargoCategory', 'dgClass',
            'originLocation', 'destinationLocation',
            'shipperLocation:id,name', 'consigneeLocation:id,name',
            'transportMode', 'serviceType', 'containerType',
            'additionalServices', 'shipment', 'approvedByUser:id,name',
            'packages', 'containers', 'attachments',
        ]);
        $booking->setAttribute('has_shipment', $booking->shipment()->exists());
        $this->logBookingActivity($booking, 'booking_updated', 'Booking diperbarui.', $request->user());

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
            'shipment_coverage' => 'nullable|in:door_to_door,door_to_port,port_to_door,port_to_port',
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
            'shipment_coverage' => $data['shipment_coverage'] ?? $request->input('shipment_coverage'),
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
        $this->bookingPersistence->mergeJsonFields($request);
        $this->bookingPersistence->applyDerivedCargoCategory($request);

        if (is_string($request->additional_services)) {
            $request->merge(['additional_services' => json_decode($request->additional_services, true)]);
        }

        $isDraft = filter_var($request->input('is_draft', false), FILTER_VALIDATE_BOOLEAN);

        $data = $request->validate(array_merge([
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
            'cargo_category_id' => $isDraft ? 'nullable|exists:cargo_categories,id' : 'required|exists:cargo_categories,id',
            'departure_date' => 'nullable|date',
            'cargo_description' => 'nullable|string|max:2000',
            'shipper_name' => $isDraft ? 'nullable|string|max:255' : 'required|string|max:255',
            'shipper_address' => $isDraft ? 'nullable|string' : 'required|string',
            'shipper_phone' => $isDraft ? 'nullable|string|max:50' : 'required|string|max:50',
            'consignee_name' => $isDraft ? 'nullable|string|max:255' : 'required|string|max:255',
            'consignee_address' => $isDraft ? 'nullable|string' : 'required|string',
            'consignee_phone' => $isDraft ? 'nullable|string|max:50' : 'required|string|max:50',
            'shipment_coverage' => 'nullable|in:door_to_door,door_to_port,port_to_door,port_to_port',
            'pickup_date' => 'nullable|date',
            'pickup_time' => 'nullable|string|max:20',
            'pickup_notes' => 'nullable|string|max:1000',
            'delivery_notes' => 'nullable|string|max:1000',
            'is_draft' => 'nullable|boolean',
            'additional_services' => 'nullable|array',
            'additional_services.*.id' => 'required|exists:additional_services,id',
            'additional_services.*.notes' => 'nullable|string|max:2000',
            'is_dangerous_goods' => 'nullable|boolean',
            'dg_class_id' => 'nullable|exists:dg_classes,id',
            'un_number' => 'nullable|string|max:50',
            'msds_file' => 'nullable|file|mimes:pdf|max:5120',
            'equipment_condition' => 'nullable|in:CLEAN,RESIDUAL',
            'temperature' => 'nullable|numeric',
            'notes' => 'nullable|string|max:5000',
        ], $this->bookingPersistence->cargoFieldRules($isDraft)));

        if ($cargoErrors = $this->bookingPersistence->validateCargoRules($request, $data, $isDraft, (int) $data['company_id'])) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $cargoErrors], 422);
        }

        return DB::transaction(function () use ($request, $user, $data, $isDraft) {
            $estimate = null;
            if (! $isDraft) {
                $estimate = $this->priceEstimateService->estimate([
                    ...$data,
                    'additional_services' => array_column($data['additional_services'] ?? [], 'id'),
                    'container_count' => $data['container_count'] ?? 1,
                    'estimated_weight' => (float) ($data['estimated_weight'] ?? 0),
                    'estimated_cbm' => (float) ($data['estimated_cbm'] ?? 0),
                ]);
            }

            $msdsPath = null;
            if ($request->hasFile('msds_file')) {
                $msdsPath = $request->file('msds_file')->store('msds_files', 'public');
            }

            unset($data['is_draft'], $data['attachments'], $data['attachments_meta'], $data['packages_msds_files'], $data['containers_msds_files']);

            $packageRows = $data['packages'] ?? [];
            $containerRows = $data['containers'] ?? [];
            $additionalServiceRows = $data['additional_services'] ?? [];
            unset($data['packages'], $data['containers'], $data['additional_services']);

            $booking = Booking::create([
                ...$data,
                'user_id' => $user->id,
                'status' => $isDraft ? 'draft' : 'submitted',
                'estimated_price' => $estimate['estimated_price'] ?? null,
                'msds_file' => $msdsPath,
                'draft_expires_at' => $isDraft ? SystemConfig::draftExpiresAt() : null,
            ]);

            if (! empty($additionalServiceRows)) {
                foreach ($additionalServiceRows as $svc) {
                    $booking->additionalServices()->attach($svc['id'], [
                        'notes' => $svc['notes'] ?? null,
                    ]);
                }
            }

            if (! empty($packageRows)) {
                $this->bookingPersistence->syncPackages($booking, $request, $packageRows);
            }
            if (! empty($containerRows)) {
                $this->bookingPersistence->syncContainers($booking, $request, $containerRows);
            }
            $this->bookingPersistence->syncAttachments(
                $booking,
                $request,
                (int) $user->id,
                is_array($data['attachments_meta'] ?? null) ? $data['attachments_meta'] : []
            );
            $this->bookingPersistence->recalculateAndSave($booking);

            if (! $isDraft) {
                $this->logBookingActivity($booking, 'booking_submitted', 'Booking disubmit.', $user);
            } else {
                $this->logBookingActivity($booking, 'booking_created', 'Booking draft dibuat.', $user);
            }

            $booking->load([
                'company', 'user', 'cargoCategory', 'originLocation', 'destinationLocation',
                'serviceType', 'additionalServices', 'packages', 'containers', 'attachments',
            ]);

            return response()->json([
                'message' => 'Booking berhasil dibuat.',
                'data' => $booking,
                'estimated_price' => $estimate['estimated_price'] ?? null,
                'breakdown' => $estimate['breakdown'] ?? null,
            ], 201);
        });
    }

    /**
     * Konfirmasi booking (FSD: Submitted → Confirmed).
     */
    public function approve(Request $request, Booking $booking): JsonResponse
    {
        if (! in_array($booking->status, ['submitted', 'under_review', 'confirmed'])) {
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

        if (BookingDraftExpiryService::isExpired($booking)) {
            return response()->json(['message' => 'Booking draft sudah expired.'], 422);
        }

        $booking->update(['status' => 'submitted', 'draft_expires_at' => null]);
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

        if ($booking->status !== 'draft') {
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
        if (! in_array($booking->status, ['submitted', 'under_review'], true)) {
            return response()->json(['message' => 'Booking tidak dalam status yang bisa ditolak.'], 422);
        }

        $request->validate(['reason' => 'required|string|max:1000']);

        $booking->update([
            'status' => 'rejected',
            'rejection_reason' => $request->reason,
        ]);

        $this->logBookingActivity($booking, 'booking_rejected', 'Booking ditolak.', $request->user());

        return response()->json([
            'message' => 'Booking berhasil ditolak.',
            'data' => $booking,
        ]);
    }

    /**
     * Duplikasi booking sebagai draft baru.
     */
    public function duplicate(Request $request, Booking $booking): JsonResponse
    {
        if ($resp = $this->ensureCanCreateBooking($request)) {
            return $resp;
        }

        $booking->load(['packages', 'containers', 'additionalServices', 'attachments']);

        $newBooking = DB::transaction(function () use ($booking, $request) {
            $copy = $booking->replicate([
                'booking_number', 'status', 'approved_by', 'approved_at', 'rejection_reason',
                'estimated_price',
            ]);
            $copy->status = 'draft';
            $copy->user_id = $request->user()->id;
            $copy->approved_by = null;
            $copy->approved_at = null;
            $copy->rejection_reason = null;
            $copy->estimated_price = null;
            $copy->draft_expires_at = SystemConfig::draftExpiresAt();
            $copy->save();

            foreach ($booking->packages as $pkg) {
                $pkgClone = $pkg->replicate();
                $pkgClone->booking_id = $copy->id;
                if ($pkgClone->msds_file_path) {
                    $pkgClone->msds_file_path = $this->duplicateStorageFile($pkgClone->msds_file_path, 'msds_files');
                }
                $pkgClone->save();
            }

            foreach ($booking->containers as $container) {
                $ctrClone = $container->replicate();
                $ctrClone->booking_id = $copy->id;
                if ($ctrClone->msds_file_path) {
                    $ctrClone->msds_file_path = $this->duplicateStorageFile($ctrClone->msds_file_path, 'msds_files');
                }
                $ctrClone->save();
            }

            foreach ($booking->attachments as $attachment) {
                $newPath = $this->duplicateStorageFile($attachment->file_path, 'booking_attachments');
                if ($newPath) {
                    $copy->attachments()->create([
                        'uploaded_by' => $request->user()->id,
                        'file_path' => $newPath,
                        'original_name' => $attachment->original_name,
                        'mime_type' => $attachment->mime_type,
                        'file_size' => $attachment->file_size,
                        'category' => $attachment->category,
                        'document_type' => $attachment->document_type,
                        'remarks' => $attachment->remarks,
                    ]);
                }
            }

            $syncData = $booking->additionalServices->mapWithKeys(fn ($svc) => [
                $svc->id => ['notes' => $svc->pivot->notes ?? null],
            ])->all();
            $copy->additionalServices()->sync($syncData);

            return $copy;
        });

        $this->logBookingActivity($newBooking, 'booking_created', 'Booking diduplikasi dari '.$booking->booking_number.'.', $request->user());

        return response()->json([
            'message' => 'Booking berhasil diduplikasi.',
            'data' => $newBooking->load([
                'company', 'user', 'cargoCategory', 'originLocation', 'destinationLocation',
                'serviceType', 'additionalServices', 'packages', 'containers',
            ]),
        ], 201);
    }

    /**
     * Konversi booking yang sudah disetujui → Shipment.
     */
    public function convertToShipment(Request $request, Booking $booking): JsonResponse
    {
        if (! in_array($booking->status, ['approved', 'confirmed'], true)) {
            return response()->json(['message' => 'Hanya booking yang sudah disetujui yang bisa dikonversi.'], 422);
        }

        if ($booking->shipment()->exists()) {
            return response()->json(['message' => 'Booking ini sudah memiliki shipment.'], 422);
        }

        $booking->loadMissing('serviceType:id,code');
        $freeStorage = SystemConfig::defaultFreeStorageDays($booking->serviceType?->code);

        $shipment = Shipment::create([
            'booking_id' => $booking->id,
            'waybill_number' => (new Shipment)->generateWaybillNumber(),
            'company_id' => $booking->company_id,
            'origin_location_id' => $booking->origin_location_id,
            'destination_location_id' => $booking->destination_location_id,
            'transport_mode_id' => $booking->transport_mode_id,
            'service_type_id' => $booking->service_type_id,
            'shipment_coverage' => $booking->shipment_coverage,
            'status' => 'created',
            'created_by' => $request->user()->id,
            'estimated_departure' => $booking->departure_date,
            'cargo_category_id' => $booking->cargo_category_id,
            'is_dangerous_goods' => $booking->is_dangerous_goods,
            'dg_class_id' => $booking->dg_class_id,
            'un_number' => $booking->un_number,
            'msds_file' => $booking->msds_file,
            'equipment_condition' => $booking->equipment_condition,
            'temperature' => $booking->temperature,
            'shipper_snapshot' => $this->buildShipperSnapshot($booking),
            'consignee_snapshot' => $this->buildConsigneeSnapshot($booking),
            'free_storage_origin_days' => $freeStorage['origin'],
            'free_storage_destination_days' => $freeStorage['destination'],
        ]);

        $this->shipmentConversion->copyCargoFromBooking($shipment, $booking);

        $shipment->update([
            'cargo_snapshot' => $this->shipmentView->buildCargoFromBooking($shipment->fresh(['booking.serviceType', 'serviceType'])),
        ]);

        $shipment->trackings()->create([
            'status' => 'shipment_created',
            'notes' => 'Shipment Created',
            'tracked_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        $this->shipmentActivityLogger->log(
            $shipment,
            'shipment_created',
            'Shipment dibuat dari Booking '.$booking->booking_number.'.',
            $request->user(),
            ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number],
        );

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

    private function duplicateStorageFile(?string $path, string $directory): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return $path;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $newPath = trim($directory, '/').'/'.Str::uuid().($extension ? '.'.$extension : '');

        Storage::disk('public')->copy($path, $newPath);

        return $newPath;
    }

    private function buildShipperSnapshot(Booking $booking): array
    {
        return [
            'name' => $booking->shipper_name,
            'phone' => $booking->shipper_phone,
            'address' => $booking->shipper_address,
            'branch_id' => $booking->shipper_location_id,
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
            'branch_id' => $booking->consignee_location_id,
            'snapshot' => $booking->consignee_snapshot,
        ];
    }

    public function uploadAttachment(Request $request, Booking $booking): JsonResponse
    {
        if ($booking->shipment()->exists()) {
            return response()->json(['message' => 'Booking readonly setelah convert.'], 422);
        }

        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,xlsx|max:10240',
            'document_type' => 'nullable|string|max:120',
            'remarks' => 'nullable|string|max:500',
        ]);

        $file = $request->file('file');
        $path = $file->store('booking_attachments', 'public');

        $attachment = $booking->attachments()->create([
            'uploaded_by' => $request->user()->id,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'category' => 'general',
            'document_type' => $request->input('document_type'),
            'remarks' => $request->input('remarks'),
        ]);

        return response()->json(['message' => 'Attachment diunggah.', 'data' => $attachment], 201);
    }

    public function deleteAttachment(Booking $booking, BookingAttachment $attachment): JsonResponse
    {
        if ($attachment->booking_id !== $booking->id) {
            abort(404);
        }
        if ($booking->shipment()->exists()) {
            return response()->json(['message' => 'Booking readonly setelah convert.'], 422);
        }

        $attachment->delete();

        return response()->json(['message' => 'Attachment dihapus.']);
    }
}
