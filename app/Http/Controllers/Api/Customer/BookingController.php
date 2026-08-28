<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingAttachment;
use App\Models\CargoCategory;
use App\Models\CustomerLocation;
use App\Models\Invoice;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Services\BookingDraftExpiryService;
use App\Services\BookingPriceEstimateService;
use App\Services\MidtransService;
use App\Support\SystemConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function __construct(
        private BookingPriceEstimateService $priceEstimateService,
        private MidtransService $midtransService,
    ) {}

    /**
     * List bookings for the logged-in customer's company (spec L5).
     *
     * Supports filters: search (booking_no, shipper, consignee), status,
     * service_type, shipment_coverage, date_from/date_to (booking date).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Booking::with([
            'originLocation:id,name,code',
            'destinationLocation:id,name,code',
            'serviceType:id,name,code',
            'transportMode:id,name',
            'shipment:id,booking_id',
        ])->where('company_id', $user->company_id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
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
            $needle = '%'.$request->search.'%';
            $query->where(function ($q) use ($needle) {
                $q->where('booking_number', 'like', $needle)
                    ->orWhere('shipper_name', 'like', $needle)
                    ->orWhere('consignee_name', 'like', $needle);
            });
        }

        $perPage = (int) ($request->per_page ?? 15);

        return response()->json($query->orderBy('created_at', 'desc')->paginate($perPage));
    }

    /**
     * Booking stats for the 4 spec cards (L19-23): Draft, Submitted, Approved, Rejected.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $counts = Booking::where('company_id', $user->company_id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => [
                'draft' => (int) ($counts['draft'] ?? 0),
                'submitted' => (int) ($counts['submitted'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
            ],
        ]);
    }

    /**
     * Cost estimation. Accepts an `is_draft` boolean flag so the customer
     * can preview the price before deciding to save (spec L8: draft
     * generates a booking number only on first save).
     */
    public function estimatePrice(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'origin_location_id' => 'required|exists:locations,id',
            'destination_location_id' => 'required|exists:locations,id',
            'transport_mode_id' => 'required|exists:transport_modes,id',
            'service_type_id' => 'required|exists:service_types,id',
            'shipment_coverage' => 'nullable|in:port_to_port,door_to_port,port_to_door,door_to_door',
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
            'company_id' => $user->company_id,
        ];

        $result = $this->priceEstimateService->estimate($params);

        return response()->json(['data' => $result]);
    }

    /**
     * Create a booking. Supports `is_draft=true` to save a draft without
     * forcing the customer to complete every field (spec L8-10).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->canMutateBookings($user)) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki permission untuk membuat booking.'], 403);
        }

        if (! $user->company || $user->company->status !== 'active') {
            return response()->json([
                'message' => 'Customer tidak aktif. Booking baru hanya dapat dibuat oleh customer berstatus Active.',
            ], 403);
        }

        if ($user->company && $user->company->hasOverdueInvoices()) {
            return response()->json([
                'message' => 'Perusahaan Anda memiliki invoice jatuh tempo. Silakan lunasi terlebih dahulu.',
            ], 403);
        }

        $isDraft = filter_var($request->input('is_draft', false), FILTER_VALIDATE_BOOLEAN);

        foreach (['additional_services', 'packages', 'containers', 'shipper_snapshot', 'consignee_snapshot', 'attachments_meta'] as $key) {
            if (is_string($request->input($key))) {
                $decoded = json_decode($request->input($key), true);
                if (is_array($decoded)) {
                    $request->merge([$key => $decoded]);
                }
            }
        }

        $data = $request->validate([
            'is_draft' => 'nullable|boolean',

            'origin_location_id' => 'required|exists:locations,id',
            'destination_location_id' => 'required|exists:locations,id',
            'transport_mode_id' => 'required|exists:transport_modes,id',
            'service_type_id' => 'required|exists:service_types,id',
            'shipment_coverage' => 'nullable|in:port_to_port,door_to_port,port_to_door,door_to_door',
            'container_type_id' => 'nullable|exists:container_types,id',
            'container_count' => 'nullable|integer|min:0',
            'container_responsibility' => 'nullable|in:SOC,COC',
            'estimated_weight' => 'nullable|numeric|min:0',
            'estimated_cbm' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',

            // For draft we let customer skip cargo details.
            'cargo_category_id' => $isDraft ? 'nullable|exists:cargo_categories,id' : 'required|exists:cargo_categories,id',
            'cargo_description' => [
                'nullable', 'string',
                function ($attribute, $value, $fail) use ($request, $isDraft) {
                    if ($isDraft) {
                        return;
                    }
                    $cat = CargoCategory::find($request->cargo_category_id);
                    if ($cat && $cat->code === 'MIX' && empty($value)) {
                        $fail('Deskripsi barang wajib diisi untuk kategori Mixed Cargo.');
                    }
                },
            ],

            'departure_date' => 'nullable|date|after_or_equal:today',

            // Snapshot shipper & consignee (spec L7)
            'shipper_name' => $isDraft ? 'nullable|string|max:255' : 'required|string|max:255',
            'shipper_address' => $isDraft ? 'nullable|string' : 'required|string',
            'shipper_phone' => $isDraft ? 'nullable|string|max:50' : 'required|string|max:50',
            'shipper_location_id' => [
                $isDraft ? 'nullable' : 'required',
                'integer',
                'exists:customer_locations,id',
                function ($attribute, $value, $fail) use ($user) {
                    if ($value && CustomerLocation::where('id', $value)
                        ->where('company_id', $user->company_id)
                        ->where('status', 'active')
                        ->doesntExist()) {
                        $fail('Customer Location tidak ditemukan atau tidak aktif.');
                    }
                },
            ],
            'shipper_snapshot' => 'nullable|array',
            'consignee_name' => $isDraft ? 'nullable|string|max:255' : 'required|string|max:255',
            'consignee_address' => $isDraft ? 'nullable|string' : 'required|string',
            'consignee_phone' => $isDraft ? 'nullable|string|max:50' : 'required|string|max:50',
            'consignee_type' => $isDraft ? 'nullable|in:customer_location,external' : 'required|in:customer_location,external',
            'consignee_location_id' => [
                'nullable',
                'integer',
                'exists:customer_locations,id',
                function ($attribute, $value, $fail) use ($user) {
                    if ($value && CustomerLocation::where('id', $value)
                        ->where('company_id', $user->company_id)
                        ->where('status', 'active')
                        ->doesntExist()) {
                        $fail('Customer Location tidak ditemukan atau tidak aktif.');
                    }
                },
            ],
            'consignee_snapshot' => 'nullable|array',

            'notes' => 'nullable|string',
            'pickup_date' => 'nullable|date',
            'pickup_time' => 'nullable|date_format:H:i',
            'pickup_notes' => 'nullable|string',
            'delivery_notes' => 'nullable|string',
            'confirm_booking' => $isDraft ? 'nullable|boolean' : 'required|accepted',
            'additional_services' => 'nullable',

            'is_dangerous_goods' => 'nullable|boolean',
            'dg_class_id' => 'nullable|required_if:is_dangerous_goods,1|exists:dg_classes,id',
            'un_number' => 'nullable|required_if:is_dangerous_goods,1|string|max:50',
            'msds_file' => 'nullable|required_if:is_dangerous_goods,1|file|mimes:pdf|max:5120',
            'equipment_condition' => 'nullable|in:CLEAN,RESIDUAL',
            'temperature' => [
                'nullable', 'numeric',
                function ($attribute, $value, $fail) use ($request, $isDraft) {
                    if ($isDraft) {
                        return;
                    }
                    $cat = CargoCategory::find($request->cargo_category_id);
                    if ($cat && $cat->requires_temperature && $value === null) {
                        $fail('Suhu (temperature) wajib diisi untuk kategori kargo ini.');
                    }
                },
            ],

            // Per-item payload (LCL packages[] / FCL containers[])
            'packages' => 'nullable|array',
            'packages.*.description' => 'nullable|string|max:500',
            'packages.*.length' => 'nullable|numeric|min:0',
            'packages.*.width' => 'nullable|numeric|min:0',
            'packages.*.height' => 'nullable|numeric|min:0',
            'packages.*.weight_kg' => 'nullable|numeric|min:0',
            'packages.*.piece_count' => 'nullable|integer|min:1',
            'packages.*.package_type' => 'nullable|string|max:80',
            'packages.*.remark' => 'nullable|string',
            'packages.*.cargo_category_id' => 'nullable|exists:cargo_categories,id',
            'packages.*.dg_class_id' => 'nullable|exists:dg_classes,id',
            'packages.*.un_number' => 'nullable|string|max:50',
            'packages.*.packing_group' => 'nullable|string|max:10',
            'packages.*.proper_shipping_name' => 'nullable|string|max:255',
            'packages.*.flash_point' => 'nullable|numeric',
            'packages.*.dg_notes' => 'nullable|string',
            'packages.*.dg_remark' => 'nullable|string',
            'packages_msds_files' => 'nullable|array',
            'packages_msds_files.*' => 'nullable|file|mimes:pdf|max:5120',

            'containers' => 'nullable|array',
            'containers.*.container_type_id' => 'nullable|exists:container_types,id',
            'containers.*.quantity' => 'nullable|integer|min:1',
            'containers.*.container_number' => 'nullable|string|max:20',
            'containers.*.seal_number' => 'nullable|string|max:50',
            'containers.*.gross_weight_kg' => 'nullable|numeric|min:0',
            'containers.*.volume_cbm' => 'nullable|numeric|min:0',
            'containers.*.cargo_description' => 'nullable|string|max:500',
            'containers.*.remark' => 'nullable|string',
            'containers.*.cargo_category_id' => 'nullable|exists:cargo_categories,id',
            'containers.*.equipment_condition' => 'nullable|in:CLEAN,RESIDUAL',
            'containers.*.temperature' => 'nullable|numeric',
            'containers.*.is_dangerous_goods' => 'nullable|boolean',
            'containers.*.dg_class_id' => 'nullable|exists:dg_classes,id',
            'containers.*.un_number' => 'nullable|string|max:50',
            'containers.*.packing_group' => 'nullable|string|max:10',
            'containers.*.proper_shipping_name' => 'nullable|string|max:255',
            'containers.*.flash_point' => 'nullable|numeric',
            'containers.*.dg_notes' => 'nullable|string',
            'containers.*.dg_remark' => 'nullable|string',
            'containers_msds_files' => 'nullable|array',
            'containers_msds_files.*' => 'nullable|file|mimes:pdf|max:5120',

            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,xlsx|max:10240',
            'attachments_meta' => 'nullable',
        ]);

        if (! $isDraft) {
            $errors = [];

            $coverage = $data['shipment_coverage'] ?? null;
            if (in_array($coverage, ['door_to_port', 'door_to_door'], true)) {
                if (empty($data['pickup_date'])) {
                    $errors['pickup_date'][] = 'Preferred Pickup Date wajib diisi.';
                }
                if (empty($data['pickup_time'])) {
                    $errors['pickup_time'][] = 'Preferred Pickup Time wajib diisi.';
                }
            }

            if (($data['consignee_type'] ?? null) === 'customer_location' && empty($data['consignee_location_id'])) {
                $errors['consignee_location_id'][] = 'Customer Location wajib dipilih.';
            }

            if (empty($data['shipper_location_id'])) {
                $errors['shipper_location_id'][] = 'Customer Location wajib dipilih.';
            }

            $serviceType = ServiceType::find($data['service_type_id']);
            $serviceCode = $serviceType?->code;
            if ($serviceCode === 'LCL') {
                if (empty($data['packages']) || ! is_array($data['packages'])) {
                    $errors['packages'][] = 'Package detail wajib diisi untuk service type LCL.';
                }
            }
            if ($serviceCode === 'FCL') {
                if (empty($data['containers']) || ! is_array($data['containers'])) {
                    $errors['containers'][] = 'Container detail wajib diisi untuk service type FCL.';
                }
                if (empty($data['container_responsibility'])) {
                    $errors['container_responsibility'][] = 'Container Responsibility wajib dipilih.';
                }
            }

            if (! empty($data['packages']) && is_array($data['packages'])) {
                foreach ($data['packages'] as $i => $pkg) {
                    if (empty($pkg['description'])) {
                        $errors["packages.$i.description"][] = 'Package Description wajib diisi.';
                    }
                    if (empty($pkg['piece_count'])) {
                        $errors["packages.$i.piece_count"][] = 'Quantity wajib diisi.';
                    }
                    if (empty($pkg['cargo_category_id'])) {
                        $errors["packages.$i.cargo_category_id"][] = 'Cargo Category wajib diisi.';
                    }
                    if ($this->isDangerousCargoCategory($pkg['cargo_category_id'] ?? null)) {
                        if (empty($pkg['dg_class_id'])) {
                            $errors["packages.$i.dg_class_id"][] = 'DG Class wajib diisi.';
                        }
                        if (empty($pkg['un_number'])) {
                            $errors["packages.$i.un_number"][] = 'UN Number wajib diisi.';
                        }
                        if (empty($pkg['packing_group'])) {
                            $errors["packages.$i.packing_group"][] = 'Packing Group wajib diisi.';
                        }
                        if (empty($pkg['proper_shipping_name'])) {
                            $errors["packages.$i.proper_shipping_name"][] = 'Proper Shipping Name wajib diisi.';
                        }
                        if (! $request->file("packages_msds_files.$i")) {
                            $errors["packages_msds_files.$i"][] = 'MSDS / SDS wajib diunggah.';
                        }
                    }
                }
            }

            if (! empty($data['containers']) && is_array($data['containers'])) {
                foreach ($data['containers'] as $i => $ctr) {
                    if (empty($ctr['container_type_id'])) {
                        $errors["containers.$i.container_type_id"][] = 'Container Type wajib diisi.';
                    }
                    if (empty($ctr['quantity'])) {
                        $errors["containers.$i.quantity"][] = 'Quantity wajib diisi.';
                    }
                    if (empty($ctr['cargo_category_id'])) {
                        $errors["containers.$i.cargo_category_id"][] = 'Cargo Category wajib diisi.';
                    }
                    if ($this->isDangerousCargoCategory($ctr['cargo_category_id'] ?? null)) {
                        if (empty($ctr['dg_class_id'])) {
                            $errors["containers.$i.dg_class_id"][] = 'DG Class wajib diisi.';
                        }
                        if (empty($ctr['un_number'])) {
                            $errors["containers.$i.un_number"][] = 'UN Number wajib diisi.';
                        }
                        if (empty($ctr['packing_group'])) {
                            $errors["containers.$i.packing_group"][] = 'Packing Group wajib diisi.';
                        }
                        if (empty($ctr['proper_shipping_name'])) {
                            $errors["containers.$i.proper_shipping_name"][] = 'Proper Shipping Name wajib diisi.';
                        }
                        if (! $request->file("containers_msds_files.$i")) {
                            $errors["containers_msds_files.$i"][] = 'MSDS / SDS wajib diunggah.';
                        }
                    }
                }
            }

            if (! empty($errors)) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $errors,
                ], 422);
            }
        }

        return DB::transaction(function () use ($request, $user, $data, $isDraft) {
            unset($data['confirm_booking']);
            $packages = $data['packages'] ?? [];
            $containers = $data['containers'] ?? [];
            $additionalServices = $data['additional_services'] ?? [];
            unset($data['is_draft'], $data['packages'], $data['containers'], $data['additional_services'], $data['attachments_meta']);

            $data['confirmed_terms_at'] = $isDraft ? null : now();

            if (! empty($data['shipper_snapshot']) && is_array($data['shipper_snapshot'])) {
                $shipperCompany = $data['shipper_snapshot']['company'] ?? null;
                if (is_string($shipperCompany) && $shipperCompany !== '') {
                    $data['shipper_name'] = $shipperCompany;
                }
            }
            if (! empty($data['consignee_snapshot']) && is_array($data['consignee_snapshot'])) {
                $consigneeCompany = $data['consignee_snapshot']['company'] ?? null;
                if (is_string($consigneeCompany) && $consigneeCompany !== '') {
                    $data['consignee_name'] = $consigneeCompany;
                }
            }

            $estimate = null;
            if (! $isDraft) {
                $estimateParams = [
                    'origin_location_id' => $data['origin_location_id'],
                    'destination_location_id' => $data['destination_location_id'],
                    'transport_mode_id' => $data['transport_mode_id'],
                    'service_type_id' => $data['service_type_id'],
                    'shipment_coverage' => $data['shipment_coverage'] ?? null,
                    'container_type_id' => $data['container_type_id'] ?? null,
                    'container_count' => $data['container_count'] ?? 1,
                    'estimated_weight' => $data['estimated_weight'] ?? 0,
                    'estimated_cbm' => $data['estimated_cbm'] ?? 0,
                    'length' => $data['length'] ?? 0,
                    'width' => $data['width'] ?? 0,
                    'height' => $data['height'] ?? 0,
                    'additional_services' => array_column($data['additional_services'] ?? [], 'id'),
                    'company_id' => $user->company_id,
                ];
                $estimate = $this->priceEstimateService->estimate($estimateParams);
            }

            // Booking-level MSDS (legacy field) – still kept for the
            // single-item case. Real apps with multiple DG items should
            // attach the MSDS to the per-package / per-container row.
            $msdsPath = null;
            if ($request->hasFile('msds_file')) {
                $msdsPath = $request->file('msds_file')->store('msds_files', 'public');
            }

            $booking = Booking::create([
                ...collect($data)->only((new Booking)->getFillable())->all(),
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'status' => $isDraft ? Booking::STATUS_DRAFT : Booking::STATUS_SUBMITTED,
                'estimated_price' => $estimate['estimated_price'] ?? null,
                'msds_file' => $msdsPath,
                'draft_expires_at' => $isDraft ? SystemConfig::draftExpiresAt() : null,
            ]);
            $booking->recalculateCargoMetrics();
            $booking->save();

            // Snapshot additional services
            if (! empty($additionalServices)) {
                foreach ($additionalServices as $svc) {
                    $booking->additionalServices()->attach($svc['id'], [
                        'notes' => $svc['notes'] ?? null,
                    ]);
                }
            }

            // Per-item packages (LCL)
            if (! empty($packages)) {
                $sequence = 1;
                foreach ($packages as $i => $pkg) {
                    $msdsItem = $request->file("packages_msds_files.$i");
                    $pkgMsds = $msdsItem ? $msdsItem->store('msds_files', 'public') : null;
                    $isDg = $this->isDangerousCargoCategory($pkg['cargo_category_id'] ?? null);
                    $booking->packages()->create([
                        'sequence' => $sequence++,
                        'description' => $pkg['description'] ?? null,
                        'length' => $pkg['length'] ?? null,
                        'width' => $pkg['width'] ?? null,
                        'height' => $pkg['height'] ?? null,
                        'weight_kg' => $pkg['weight_kg'] ?? null,
                        'volume_cbm' => $this->calcPackageCbm($pkg),
                        'piece_count' => $pkg['piece_count'] ?? 1,
                        'package_type' => $pkg['package_type'] ?? null,
                        'remark' => $pkg['remark'] ?? null,
                        'cargo_category_id' => $pkg['cargo_category_id'] ?? null,
                        'is_dangerous_goods' => $isDg,
                        'dg_class_id' => $pkg['dg_class_id'] ?? null,
                        'un_number' => $pkg['un_number'] ?? null,
                        'packing_group' => $pkg['packing_group'] ?? null,
                        'proper_shipping_name' => $pkg['proper_shipping_name'] ?? null,
                        'flash_point' => $pkg['flash_point'] ?? null,
                        'msds_file_path' => $pkgMsds,
                        'dg_notes' => $pkg['dg_notes'] ?? null,
                        'dg_remark' => $pkg['dg_remark'] ?? null,
                    ]);
                }
            }

            // Per-item containers (FCL)
            if (! empty($containers)) {
                $sequence = 1;
                foreach ($containers as $i => $ctr) {
                    $msdsItem = $request->file("containers_msds_files.$i");
                    $ctrMsds = $msdsItem ? $msdsItem->store('msds_files', 'public') : null;
                    $isDg = $this->isDangerousCargoCategory($ctr['cargo_category_id'] ?? null);
                    $booking->containers()->create([
                        'sequence' => $sequence++,
                        'container_type_id' => $ctr['container_type_id'] ?? null,
                        'quantity' => $ctr['quantity'] ?? 1,
                        'container_number' => $ctr['container_number'] ?? null,
                        'seal_number' => $ctr['seal_number'] ?? null,
                        'gross_weight_kg' => $ctr['gross_weight_kg'] ?? null,
                        'volume_cbm' => $ctr['volume_cbm'] ?? null,
                        'cargo_description' => $ctr['cargo_description'] ?? null,
                        'remark' => $ctr['remark'] ?? null,
                        'cargo_category_id' => $ctr['cargo_category_id'] ?? null,
                        'equipment_condition' => $ctr['equipment_condition'] ?? null,
                        'temperature' => $ctr['temperature'] ?? null,
                        'is_dangerous_goods' => $isDg,
                        'dg_class_id' => $ctr['dg_class_id'] ?? null,
                        'un_number' => $ctr['un_number'] ?? null,
                        'packing_group' => $ctr['packing_group'] ?? null,
                        'proper_shipping_name' => $ctr['proper_shipping_name'] ?? null,
                        'flash_point' => $ctr['flash_point'] ?? null,
                        'msds_file_path' => $ctrMsds,
                        'dg_notes' => $ctr['dg_notes'] ?? null,
                        'dg_remark' => $ctr['dg_remark'] ?? null,
                    ]);
                }
            }

            // Generic attachments
            if (! empty($data['attachments'])) {
                $meta = is_array($data['attachments_meta'] ?? null) ? $data['attachments_meta'] : [];
                foreach ($request->file('attachments') as $i => $file) {
                    $path = $file->store('booking_attachments', 'public');
                    $rowMeta = is_array($meta[$i] ?? null) ? $meta[$i] : [];
                    $booking->attachments()->create([
                        'uploaded_by' => $user->id,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'category' => 'general',
                        'document_type' => $rowMeta['document_type'] ?? null,
                        'remarks' => $rowMeta['remarks'] ?? null,
                    ]);
                }
            }

            // Log activity (spec L70-83)
            $booking->recordActivity(
                $isDraft ? 'created_draft' : 'created_submitted',
                $isDraft ? 'Booking dibuat sebagai draft' : 'Booking dibuat & disubmit',
                $isDraft ? 'Nomor booking dibuat otomatis.' : null,
                ['status' => $booking->status],
                $user,
            );

            $booking->load([
                'company', 'user', 'cargoCategory', 'dgClass',
                'originLocation', 'destinationLocation', 'transportMode',
                'serviceType', 'containerType', 'additionalServices',
                'shipment', 'activities', 'attachments', 'packages.dgClass', 'containers.dgClass',
            ]);

            $prepaidPayload = null;

            // Pre-paid companies auto-approve and create shipment + invoice
            if (! $isDraft && $booking->company && $booking->company->payment_type === 'prepaid') {
                $prepaidPayload = $this->autoApproveForPrepaid($booking, $estimate, $user);
            }

            return response()->json([
                'message' => $isDraft
                    ? 'Booking berhasil disimpan sebagai draft.'
                    : 'Booking berhasil dibuat.',
                'data' => $booking,
                'estimated_price' => $estimate['estimated_price'] ?? null,
                'breakdown' => $estimate['breakdown'] ?? null,
                'prepaid' => $prepaidPayload,
            ], 201);
        });
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();

        if ($booking->company_id !== $user->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $booking->load([
            'company', 'user', 'cargoCategory', 'dgClass',
            'originLocation', 'destinationLocation', 'transportMode',
            'serviceType', 'containerType', 'additionalServices',
            'shipment', 'activities.actor', 'attachments.uploader',
            'packages.dgClass', 'containers.dgClass', 'containers.containerType',
            'packages.cargoCategory', 'containers.cargoCategory',
        ]);
        $booking->setAttribute('has_shipment', $booking->shipment()->exists());
        $booking->setAttribute('available_actions', $this->availableActions($booking, $user));

        $costBreakdown = null;
        if ($booking->estimated_price !== null) {
            $estimate = $this->priceEstimateService->estimate([
                'origin_location_id' => $booking->origin_location_id,
                'destination_location_id' => $booking->destination_location_id,
                'transport_mode_id' => $booking->transport_mode_id,
                'service_type_id' => $booking->service_type_id,
                'shipment_coverage' => $booking->shipment_coverage,
                'container_type_id' => $booking->container_type_id,
                'container_count' => $booking->container_count ?? 1,
                'estimated_weight' => $booking->estimated_weight ?? 0,
                'estimated_cbm' => $booking->estimated_cbm ?? 0,
                'length' => $booking->length ?? 0,
                'width' => $booking->width ?? 0,
                'height' => $booking->height ?? 0,
                'additional_services' => $booking->additionalServices->pluck('id')->all(),
                'company_id' => $booking->company_id,
            ]);
            $costBreakdown = $estimate['breakdown'] ?? null;
        }
        $booking->setAttribute('cost_breakdown', $costBreakdown);

        return response()->json(['data' => $booking]);
    }

    /**
     * Update booking. Only editable while in draft (spec L9).
     */
    public function update(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();

        if (! $this->canMutateBookings($user)) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki permission untuk mengubah booking.'], 403);
        }

        if ($booking->company_id !== $user->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if (! $booking->isEditable()) {
            return response()->json([
                'message' => 'Booking hanya dapat diedit pada status Draft.',
            ], 422);
        }

        $data = $request->validate([
            'origin_location_id' => 'required|exists:locations,id',
            'destination_location_id' => 'required|exists:locations,id',
            'transport_mode_id' => 'required|exists:transport_modes,id',
            'service_type_id' => 'required|exists:service_types,id',
            'shipment_coverage' => 'nullable|in:port_to_port,door_to_port,port_to_door,door_to_door',
            'container_type_id' => 'nullable|exists:container_types,id',
            'container_count' => 'nullable|integer|min:0',
            'estimated_weight' => 'nullable|numeric|min:0',
            'estimated_cbm' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'cargo_category_id' => 'nullable|exists:cargo_categories,id',
            'cargo_description' => 'nullable|string',
            'departure_date' => 'nullable|date|after_or_equal:today',
            'shipper_name' => 'required|string|max:255',
            'shipper_address' => 'required|string',
            'shipper_phone' => 'required|string|max:50',
            'consignee_name' => 'required|string|max:255',
            'consignee_address' => 'required|string',
            'consignee_phone' => 'required|string|max:50',
            'notes' => 'nullable|string',
            'additional_services' => 'nullable',
            'is_dangerous_goods' => 'nullable|boolean',
            'dg_class_id' => 'nullable|required_if:is_dangerous_goods,1|exists:dg_classes,id',
            'un_number' => 'nullable|required_if:is_dangerous_goods,1|string|max:50',
            'msds_file' => 'nullable|file|mimes:pdf|max:5120',
            'equipment_condition' => 'nullable|in:CLEAN,RESIDUAL',
            'temperature' => 'nullable|numeric',
        ]);

        if (is_string($request->additional_services)) {
            $data['additional_services'] = json_decode($request->additional_services, true);
        }

        return DB::transaction(function () use ($request, $user, $booking, $data) {
            $msdsPath = $booking->msds_file;
            if ($request->hasFile('msds_file')) {
                $msdsPath = $request->file('msds_file')->store('msds_files', 'public');
            }

            $estimateParams = [
                'origin_location_id' => $data['origin_location_id'],
                'destination_location_id' => $data['destination_location_id'],
                'transport_mode_id' => $data['transport_mode_id'],
                'service_type_id' => $data['service_type_id'],
                'shipment_coverage' => $data['shipment_coverage'] ?? null,
                'container_type_id' => $data['container_type_id'] ?? null,
                'container_count' => $data['container_count'] ?? 1,
                'estimated_weight' => $data['estimated_weight'] ?? 0,
                'estimated_cbm' => $data['estimated_cbm'] ?? 0,
                'length' => $data['length'] ?? 0,
                'width' => $data['width'] ?? 0,
                'height' => $data['height'] ?? 0,
                'additional_services' => array_column($data['additional_services'] ?? [], 'id'),
                'company_id' => $user->company_id,
            ];
            $estimate = $this->priceEstimateService->estimate($estimateParams);

            $updatePayload = [
                ...$data,
                'estimated_price' => $estimate['estimated_price'],
                'msds_file' => $msdsPath,
            ];
            unset($updatePayload['additional_services']);

            $booking->fill($updatePayload);
            $booking->recalculateCargoMetrics();
            $booking->save();
            BookingDraftExpiryService::touchDraftExpiry($booking);

            $syncData = [];
            foreach ($data['additional_services'] ?? [] as $svc) {
                $syncData[$svc['id']] = ['notes' => $svc['notes'] ?? null];
            }
            $booking->additionalServices()->sync($syncData);

            $booking->recordActivity(
                'edited',
                'Draft diperbarui',
                null,
                null,
                $user,
            );

            $booking->load([
                'company', 'user', 'cargoCategory', 'dgClass',
                'originLocation', 'destinationLocation', 'transportMode',
                'serviceType', 'containerType', 'additionalServices',
                'shipment', 'activities.actor', 'attachments.uploader',
                'packages.dgClass', 'packages.cargoCategory', 'containers.dgClass', 'containers.cargoCategory',
            ]);

            return response()->json([
                'message' => 'Draft booking berhasil diperbarui.',
                'data' => $booking,
                'estimated_price' => $estimate['estimated_price'],
                'breakdown' => $estimate['breakdown'],
            ]);
        });
    }

    /**
     * Move a draft to "submitted" (spec L9-10).
     */
    public function submit(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();

        if (! $this->canMutateBookings($user)) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki permission untuk submit booking.'], 403);
        }

        if ($booking->company_id !== $user->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if ($booking->status !== Booking::STATUS_DRAFT) {
            return response()->json([
                'message' => 'Hanya booking dengan status Draft yang dapat disubmit.',
            ], 422);
        }

        if (BookingDraftExpiryService::isExpired($booking)) {
            return response()->json(['message' => 'Booking draft sudah expired.'], 422);
        }

        $booking->update(['status' => Booking::STATUS_SUBMITTED, 'draft_expires_at' => null]);
        $booking->recordActivity(
            'submitted',
            'Booking disubmit',
            'Menunggu review internal.',
            null,
            $user,
        );

        $booking->load(['activities.actor', 'shipment']);

        return response()->json([
            'message' => 'Booking berhasil disubmit.',
            'data' => $booking,
        ]);
    }

    /**
     * Cancel a draft or submitted booking (spec L10). For pre-paid companies
     * the booking is marked rejected with the user-supplied reason so the
     * activity log keeps a single narrative thread.
     */
    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();

        if (! $this->canMutateBookings($user)) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki permission untuk membatalkan booking.'], 403);
        }

        if ($booking->company_id !== $user->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        if (! $booking->isCancellable()) {
            return response()->json([
                'message' => 'Booking ini tidak dapat dibatalkan.',
            ], 422);
        }

        $booking->update([
            'status' => Booking::STATUS_REJECTED,
            'rejection_reason' => '[Customer cancel] '.$request->reason,
        ]);
        $booking->recordActivity(
            'cancelled',
            'Booking dibatalkan oleh customer',
            $request->reason,
            ['reason' => $request->reason],
            $user,
        );

        return response()->json([
            'message' => 'Booking berhasil dibatalkan.',
            'data' => $booking->fresh('activities.actor'),
        ]);
    }

    /**
     * Duplicate an existing booking into a new draft (spec L12). The duplicate
     * keeps the customer-facing snapshot fields but starts a fresh booking
     * number and resets any review state.
     */
    public function duplicate(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();

        if (! $this->canMutateBookings($user)) {
            return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki permission untuk menduplikasi booking.'], 403);
        }

        if ($booking->company_id !== $user->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        return DB::transaction(function () use ($user, $booking) {
            $new = $booking->replicate([
                'booking_number',
                'status',
                'estimated_price',
                'rejection_reason',
                'notes',
                'approved_by',
                'approved_at',
                'total_volume_cbm',
                'volume_weight_kg',
                'chargeable_weight_kg',
            ]);
            $new->status = Booking::STATUS_DRAFT;
            $new->estimated_price = null;
            $new->user_id = $user->id;
            $new->draft_expires_at = SystemConfig::draftExpiresAt();
            $new->save();

            // Copy many-to-many additional services
            foreach ($booking->additionalServices as $svc) {
                $new->additionalServices()->attach($svc->id, [
                    'notes' => $svc->pivot->notes,
                ]);
            }

            // Copy packages & containers so the customer does not retype them
            foreach ($booking->packages as $pkg) {
                $pkgClone = $pkg->replicate();
                $pkgClone->booking_id = $new->id;
                $pkgClone->save();
            }
            foreach ($booking->containers as $ctr) {
                $ctrClone = $ctr->replicate();
                $ctrClone->booking_id = $new->id;
                $ctrClone->save();
            }

            $new->recordActivity(
                'duplicated',
                'Booking diduplikasi',
                'Sumber: '.$booking->booking_number,
                ['source_booking_id' => $booking->id, 'source_booking_number' => $booking->booking_number],
                $user,
            );

            $new->load([
                'company', 'user', 'cargoCategory', 'dgClass',
                'originLocation', 'destinationLocation', 'transportMode',
                'serviceType', 'containerType', 'additionalServices',
                'activities.actor', 'attachments', 'packages.dgClass', 'containers.dgClass',
            ]);

            return response()->json([
                'message' => 'Booking diduplikasi sebagai draft baru.',
                'data' => $new,
            ], 201);
        });
    }

    /**
     * Timeline of activities for a booking (spec L70-76). The same activities
     * back Section 6 of the detail view (spec L78-84); the client filters
     * by `activity_type` to render the two views.
     */
    public function activities(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();
        if ($booking->company_id !== $user->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        return response()->json([
            'data' => $booking->activities()->with('actor:id,name')->orderBy('occurred_at')->get(),
        ]);
    }

    public function uploadAttachment(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();

        if ($booking->company_id !== $user->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $data = $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,xlsx|max:10240',
            'category' => 'nullable|string|max:50',
        ]);

        $file = $request->file('file');
        $path = $file->store('booking_attachments', 'public');

        $attachment = $booking->attachments()->create([
            'uploaded_by' => $user->id,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'category' => $data['category'] ?? 'general',
        ]);

        $booking->recordActivity(
            'attachment_added',
            'Lampiran ditambahkan',
            $attachment->original_name,
            ['attachment_id' => $attachment->id, 'category' => $attachment->category],
            $user,
        );

        return response()->json([
            'message' => 'Lampiran berhasil diunggah.',
            'data' => $attachment,
        ], 201);
    }

    public function deleteAttachment(Request $request, Booking $booking, BookingAttachment $attachment): JsonResponse
    {
        $user = $request->user();

        if ($booking->company_id !== $user->company_id || $attachment->booking_id !== $booking->id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $attachment->delete();
        $booking->recordActivity(
            'attachment_removed',
            'Lampiran dihapus',
            $attachment->original_name,
            ['attachment_id' => $attachment->id],
            $user,
        );

        return response()->json(['message' => 'Lampiran dihapus.']);
    }

    // ── Internal helpers ───────────────────────────────────────────────

    private function availableActions(Booking $booking, $user = null): array
    {
        $canMutate = $user === null || $this->canMutateBookings($user);

        $actions = [];
        switch ($booking->status) {
            case Booking::STATUS_DRAFT:
                $actions = $canMutate ? ['edit', 'submit', 'cancel'] : ['view'];
                break;
            case Booking::STATUS_SUBMITTED:
                $actions = $canMutate && $booking->isCancellable() ? ['view', 'cancel'] : ['view'];
                break;
            case Booking::STATUS_APPROVED:
                $actions = $canMutate ? ['view', 'duplicate'] : ['view'];
                break;
            case Booking::STATUS_REJECTED:
                $actions = $canMutate ? ['view', 'duplicate'] : ['view'];
                break;
            default:
                $actions = ['view'];
        }

        return $actions;
    }

    private function canMutateBookings($user): bool
    {
        return $user->can('create_bookings') || $user->can('manage_bookings');
    }

    private function calcPackageCbm(array $pkg): ?float
    {
        $l = (float) ($pkg['length'] ?? 0);
        $w = (float) ($pkg['width'] ?? 0);
        $h = (float) ($pkg['height'] ?? 0);
        $qty = (int) ($pkg['piece_count'] ?? 1);

        return $l > 0 && $w > 0 && $h > 0
            ? round((($l * $w * $h) / 1_000_000) * max($qty, 1), 4)
            : null;
    }

    private function isDangerousCargoCategory(mixed $cargoCategoryId): bool
    {
        if ($cargoCategoryId === null || $cargoCategoryId === '') {
            return false;
        }

        $cat = CargoCategory::find((int) $cargoCategoryId);

        return $cat !== null && strtoupper((string) $cat->code) === 'DG';
    }

    /**
     * For pre-paid companies, immediately approve, build shipment, issue
     * invoice and start a Midtrans transaction.
     */
    private function autoApproveForPrepaid(Booking $booking, ?array $estimate, $user): ?array
    {
        $booking->update([
            'status' => Booking::STATUS_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
        $booking->recordActivity('approved', 'Booking disetujui otomatis (pre-paid)', null, null, $user);

        $shipment = Shipment::create([
            'booking_id' => $booking->id,
            'company_id' => $booking->company_id,
            'origin_location_id' => $booking->origin_location_id,
            'destination_location_id' => $booking->destination_location_id,
            'transport_mode_id' => $booking->transport_mode_id,
            'service_type_id' => $booking->service_type_id,
            'status' => 'created',
            'created_by' => $user->id,
            'cargo_category_id' => $booking->cargo_category_id,
            'is_dangerous_goods' => $booking->is_dangerous_goods,
            'dg_class_id' => $booking->dg_class_id,
            'un_number' => $booking->un_number,
            'msds_file' => $booking->msds_file,
            'equipment_condition' => $booking->equipment_condition,
            'temperature' => $booking->temperature,
        ]);

        $shipment->trackings()->create([
            'status' => 'created',
            'notes' => 'Shipment dibuat otomatis (pre-paid) dari booking '.$booking->booking_number,
            'tracked_at' => now(),
            'updated_by' => $user->id,
        ]);

        $issuedDate = now()->toDateString();
        $subtotal = (float) ($estimate['estimated_price'] ?? 0);
        $taxBreakdown = SystemConfig::applyTax($subtotal);
        $taxAmount = $taxBreakdown['tax_amount'];
        $totalAmount = $taxBreakdown['total_amount'];

        $invoice = Invoice::create([
            'shipment_id' => $shipment->id,
            'company_id' => $booking->company_id,
            'issued_date' => $issuedDate,
            'due_date' => $issuedDate,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'status' => 'unpaid',
            'notes' => null,
            'created_by' => $user->id,
        ]);

        $breakdown = $estimate['breakdown'] ?? [];
        $baseFreight = (float) ($breakdown['base_freight'] ?? 0);
        $discount = (float) ($breakdown['discount_amount'] ?? 0);

        if ($baseFreight > 0) {
            $invoice->items()->create([
                'description' => 'Freight / Tarif Pengiriman',
                'quantity' => 1,
                'unit_price' => $baseFreight,
                'total_price' => $baseFreight,
            ]);
        }
        if ($discount > 0) {
            $invoice->items()->create([
                'description' => 'Diskon Pengiriman',
                'quantity' => 1,
                'unit_price' => -$discount,
                'total_price' => -$discount,
            ]);
        }
        foreach ($breakdown['additional_services_detail'] ?? [] as $addSvc) {
            $price = (float) ($addSvc['base_price'] ?? 0);
            if ($price > 0) {
                $invoice->items()->create([
                    'description' => 'Layanan Tambahan: '.($addSvc['name'] ?? 'Unknown'),
                    'quantity' => 1,
                    'unit_price' => $price,
                    'total_price' => $price,
                ]);
            }
        }
        if ($taxAmount > 0) {
            $invoice->items()->create([
                'description' => SystemConfig::taxLabel(),
                'quantity' => 1,
                'unit_price' => $taxAmount,
                'total_price' => $taxAmount,
            ]);
        }

        $snap = $this->midtransService->createSnapTransaction($invoice, [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
        ]);

        return [
            'invoice' => $invoice->load('items'),
            'midtrans' => $snap,
        ];
    }
}
