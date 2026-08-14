<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pricing;
use App\Models\PricingActivity;
use App\Models\ServiceType;
use App\Models\TransportMode;
use App\Models\Vendor;
use App\Models\VendorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PricingController extends Controller
{
    private const SERVICE_CATEGORIES = [
        'rail', 'trucking_pickup', 'trucking_delivery', 'container_rental',
        'lift_on_o', 'storage', 'other',
    ];

    private const PRICING_BASIS = [
        'per_container', 'per_trip', 'per_ton', 'per_kg', 'per_cbm',
    ];

    private const TRUCKING_CATEGORIES = ['trucking_pickup', 'trucking_delivery'];

    private const CONTAINER_CATEGORIES = ['rail', 'container_rental'];

    public function stats(): JsonResponse
    {
        $base = Pricing::query();
        $active = (clone $base)->where('is_active', true)->count();
        $inactive = (clone $base)->where('is_active', false)->count();
        $vendorsWithPricing = Pricing::query()
            ->join('vendor_services', 'pricings.vendor_service_id', '=', 'vendor_services.id')
            ->distinct('vendor_services.vendor_id')
            ->count('vendor_services.vendor_id');

        return response()->json([
            'data' => [
                'active' => $active,
                'inactive' => $inactive,
                'vendors' => $vendorsWithPricing,
                'total' => (clone $base)->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Pricing::query()
            ->with([
                'vendorService.vendor:id,name,code',
                'vendorService.originLocation:id,code,name',
                'vendorService.destinationLocation:id,code,name',
                'containerType:id,name,size',
                'createdBy:id,name',
            ]);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->whereHas('vendorService.vendor', fn ($vq) => $vq->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%"))
                    ->orWhereHas('vendorService.originLocation', fn ($lq) => $lq->where('name', 'like', "%{$s}%")
                        ->orWhere('code', 'like', "%{$s}%"))
                    ->orWhereHas('vendorService.destinationLocation', fn ($lq) => $lq->where('name', 'like', "%{$s}%")
                        ->orWhere('code', 'like', "%{$s}%"));
            });
        }

        if ($request->filled('vendor_id')) {
            $query->whereHas('vendorService', fn ($q) => $q->where('vendor_id', $request->vendor_id));
        }

        if ($request->filled('service_category')) {
            $query->where('service_category', $request->service_category);
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        $paginated = $query->orderByDesc('created_at')->paginate($request->per_page ?? 15);

        $paginated->getCollection()->transform(function (Pricing $p) {
            return $this->transformPricingRow($p);
        });

        return response()->json($paginated);
    }

    public function show(Pricing $pricing): JsonResponse
    {
        $pricing->load([
            'vendorService.vendor:id,name,code',
            'vendorService.originLocation:id,code,name',
            'vendorService.destinationLocation:id,code,name',
            'containerType:id,name,size',
            'createdBy:id,name',
        ]);

        $groupId = $pricing->pricing_group_id ?? $pricing->id;
        $history = Pricing::query()
            ->where('pricing_group_id', $groupId)
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Pricing $p) => [
                'id' => $p->id,
                'created_at' => $p->created_at?->toIso8601String(),
                'unit_price' => $p->displayUnitPrice(),
                'is_active' => $p->is_active,
                'created_by' => $p->createdBy?->name,
            ]);

        $activities = PricingActivity::query()
            ->where('pricing_group_id', $groupId)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PricingActivity $a) => [
                'activity' => $a->activity,
                'created_at' => $a->created_at?->toIso8601String(),
                'created_by' => $a->user?->name,
            ]);

        if ($activities->isEmpty()) {
            $activities = collect([[
                'activity' => 'Pricing dibuat.',
                'created_at' => $pricing->created_at?->toIso8601String(),
                'created_by' => $pricing->createdBy?->name,
            ]]);
        }

        return response()->json([
            'data' => array_merge($this->transformPricingRow($pricing), [
                'pricing_history' => $history,
                'activity_log' => $activities,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'service_category' => 'required|in:'.implode(',', self::SERVICE_CATEGORIES),
            'pricing_basis' => 'required|in:'.implode(',', self::PRICING_BASIS),
            'origin_location_id' => 'required|exists:locations,id',
            'destination_location_id' => 'required|exists:locations,id',
            'vehicle_type' => 'nullable|string|max:60',
            'container_type_id' => 'nullable|exists:container_types,id',
            'unit_price' => 'required|numeric|min:0',
            'minimum_charge' => 'nullable|numeric|min:0',
            'remark' => 'nullable|string|max:2000',
            'deactivate_existing' => 'boolean',
        ]);

        if (in_array($data['service_category'], self::TRUCKING_CATEGORIES, true) && empty($data['vehicle_type'])) {
            return response()->json(['message' => 'Vehicle Type wajib untuk layanan Trucking.'], 422);
        }

        if (in_array($data['service_category'], self::CONTAINER_CATEGORIES, true) && empty($data['container_type_id'])) {
            return response()->json(['message' => 'Container Type wajib untuk layanan Rail / Container Rental.'], 422);
        }

        $vendor = Vendor::findOrFail($data['vendor_id']);
        $transportModeId = $this->resolveTransportModeId($data['service_category']);

        $vendorService = $this->findOrCreateVendorService(
            $vendor,
            $transportModeId,
            $data['origin_location_id'],
            $data['destination_location_id']
        );

        $duplicateQuery = Pricing::query()
            ->where('vendor_service_id', $vendorService->id)
            ->where('service_category', $data['service_category'])
            ->where('is_active', true);

        if (! empty($data['vehicle_type'])) {
            $duplicateQuery->where('vehicle_type', $data['vehicle_type']);
        } else {
            $duplicateQuery->whereNull('vehicle_type');
        }

        if (! empty($data['container_type_id'])) {
            $duplicateQuery->where('container_type_id', $data['container_type_id']);
        } else {
            $duplicateQuery->whereNull('container_type_id');
        }

        $existingActive = $duplicateQuery->first();

        if ($existingActive && empty($data['deactivate_existing'])) {
            return response()->json([
                'message' => 'Pricing aktif dengan kombinasi yang sama sudah ada. Nonaktifkan pricing lama atau centang opsi nonaktifkan otomatis.',
                'existing_pricing_id' => $existingActive->id,
            ], 422);
        }

        $pricing = DB::transaction(function () use ($request, $data, $vendorService, $existingActive) {
            $groupId = $existingActive?->pricing_group_id ?? $existingActive?->id;

            if ($existingActive && ! empty($data['deactivate_existing'])) {
                $existingActive->update(['is_active' => false]);
                PricingActivity::create([
                    'pricing_group_id' => $groupId ?? $existingActive->id,
                    'pricing_id' => $existingActive->id,
                    'user_id' => $request->user()?->id,
                    'activity' => 'Pricing diubah menjadi Inactive.',
                ]);
            }

            $legacyPrices = $this->mapUnitPriceToLegacy($data['pricing_basis'], $data['unit_price']);

            $pricing = $vendorService->pricings()->create([
                'service_category' => $data['service_category'],
                'pricing_basis' => $data['pricing_basis'],
                'vehicle_type' => $data['vehicle_type'] ?? null,
                'container_type_id' => $data['container_type_id'] ?? null,
                'unit_price' => $data['unit_price'],
                'minimum_charge' => $data['minimum_charge'] ?? 0,
                'remark' => $data['remark'] ?? null,
                'price_type' => 'buy',
                'is_active' => true,
                'created_by_id' => $request->user()?->id,
                'pricing_group_id' => $groupId,
                ...$legacyPrices,
            ]);

            if (! $pricing->pricing_group_id) {
                $pricing->update(['pricing_group_id' => $pricing->id]);
                $groupId = $pricing->id;
            }

            PricingActivity::create([
                'pricing_group_id' => $groupId ?? $pricing->id,
                'pricing_id' => $pricing->id,
                'user_id' => $request->user()?->id,
                'activity' => $existingActive ? 'Pricing baru ditambahkan.' : 'Pricing dibuat.',
            ]);

            return $pricing->load([
                'vendorService.vendor',
                'vendorService.originLocation',
                'vendorService.destinationLocation',
                'containerType',
                'createdBy',
            ]);
        });

        return response()->json([
            'message' => 'Pricing berhasil dibuat.',
            'data' => $this->transformPricingRow($pricing),
        ], 201);
    }

    public function deactivate(Request $request, Pricing $pricing): JsonResponse
    {
        $pricing->update(['is_active' => false]);
        PricingActivity::create([
            'pricing_group_id' => $pricing->pricing_group_id ?? $pricing->id,
            'pricing_id' => $pricing->id,
            'user_id' => $request->user()?->id,
            'activity' => 'Pricing diubah menjadi Inactive.',
        ]);

        return response()->json(['message' => 'Pricing dinonaktifkan.', 'data' => $pricing]);
    }

    private function transformPricingRow(Pricing $p): array
    {
        $vs = $p->vendorService;
        $origin = $vs?->originLocation;
        $dest = $vs?->destinationLocation;
        $vehicleOrContainer = $p->vehicle_type
            ?? ($p->containerType ? trim($p->containerType->name.' '.($p->containerType->size ?? '')) : null);

        return [
            'id' => $p->id,
            'vendor' => $vs?->vendor?->name,
            'vendor_id' => $vs?->vendor_id,
            'vendor_code' => $vs?->vendor?->code,
            'service_category' => $p->service_category,
            'service_label' => $this->serviceCategoryLabel($p->service_category),
            'pricing_basis' => $p->pricing_basis,
            'origin' => $origin ? ($origin->code ?? $origin->name) : null,
            'destination' => $dest ? ($dest->code ?? $dest->name) : null,
            'vehicle_container_type' => $vehicleOrContainer,
            'unit_price' => $p->displayUnitPrice(),
            'minimum_charge' => $p->minimum_charge,
            'remark' => $p->remark,
            'is_active' => $p->is_active,
            'created_at' => $p->created_at?->toIso8601String(),
            'created_by' => $p->createdBy?->name,
            'pricing_group_id' => $p->pricing_group_id ?? $p->id,
        ];
    }

    private function serviceCategoryLabel(?string $code): string
    {
        return match ($code) {
            'rail' => 'Rail',
            'trucking_pickup' => 'Trucking Pickup',
            'trucking_delivery' => 'Trucking Delivery',
            'container_rental' => 'Container Rental',
            'lift_on_o' => 'Lift On-O',
            'storage' => 'Storage',
            'other' => 'Other',
            default => $code ?? '—',
        };
    }

    private function resolveTransportModeId(string $serviceCategory): int
    {
        $code = match ($serviceCategory) {
            'rail' => 'rail',
            'trucking_pickup', 'trucking_delivery' => 'road',
            default => 'multimodal',
        };

        $mode = TransportMode::query()->where('code', $code)->first();

        return $mode?->id ?? TransportMode::query()->value('id');
    }

    private function findOrCreateVendorService(
        Vendor $vendor,
        int $transportModeId,
        int $originId,
        int $destinationId
    ): VendorService {
        $existing = $vendor->vendorServices()
            ->where('transport_mode_id', $transportModeId)
            ->where('origin_location_id', $originId)
            ->where('destination_location_id', $destinationId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $serviceTypeId = ServiceType::query()->value('id');

        return $vendor->vendorServices()->create([
            'transport_mode_id' => $transportModeId,
            'service_type_id' => $serviceTypeId,
            'origin_location_id' => $originId,
            'destination_location_id' => $destinationId,
            'is_active' => true,
        ]);
    }

    private function mapUnitPriceToLegacy(string $basis, float $unitPrice): array
    {
        return match ($basis) {
            'per_kg' => ['price_per_kg' => $unitPrice],
            'per_cbm' => ['price_per_cbm' => $unitPrice],
            'per_container', 'per_trip', 'per_ton' => ['price_per_container' => $unitPrice],
            default => ['price_per_container' => $unitPrice],
        };
    }
}
