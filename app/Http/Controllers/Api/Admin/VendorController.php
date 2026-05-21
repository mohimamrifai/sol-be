<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorService;
use App\Models\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    // ── VENDORS ──
    public function index(Request $request): JsonResponse
    {
        $query = Vendor::withCount('vendorServices')
            ->with([
                'vendorServices' => function ($q) {
                    $q->orderBy('id')
                        ->with([
                            'serviceType:id,name,code',
                            'originLocation:id,code,name',
                            'destinationLocation:id,code,name',
                        ]);
                },
            ]);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
        }
        return response()->json($query->orderBy('name')->paginate($request->per_page ?? 15));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:20|unique:vendors,code',
            'address' => 'nullable|string', 'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email', 'contact_person' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);
        return response()->json(['data' => Vendor::create($data)], 201);
    }

    public function show(Vendor $vendor): JsonResponse
    {
        $vendor->load(['vendorServices.transportMode', 'vendorServices.serviceType',
            'vendorServices.originLocation', 'vendorServices.destinationLocation',
            'vendorServices.pricings']);
        return response()->json(['data' => $vendor]);
    }

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => "nullable|string|max:20|unique:vendors,code,{$vendor->id}",
            'address' => 'nullable|string', 'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email', 'contact_person' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);
        $vendor->update($data);
        return response()->json(['data' => $vendor]);
    }

    public function destroy(Vendor $vendor): JsonResponse
    {
        $vendor->delete();
        return response()->json(['message' => 'Vendor dihapus.']);
    }

    // ── VENDOR SERVICES ──
    public function storeService(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'transport_mode_id' => 'required|exists:transport_modes,id',
            'service_type_id' => 'required|exists:service_types,id',
            'origin_location_id' => 'required|exists:locations,id',
            'destination_location_id' => 'required|exists:locations,id',
            'is_active' => 'boolean',
        ]);

        $exists = $vendor->vendorServices()
            ->where('transport_mode_id', $data['transport_mode_id'])
            ->where('service_type_id', $data['service_type_id'])
            ->where('origin_location_id', $data['origin_location_id'])
            ->where('destination_location_id', $data['destination_location_id'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Layanan (lane & service) ini sudah ada untuk vendor tersebut.'], 422);
        }

        $svc = $vendor->vendorServices()->create($data);
        return response()->json(['data' => $svc], 201);
    }

    // ── PRICING ──
    public function storePricing(Request $request, VendorService $vendorService): JsonResponse
    {
        $data = $request->validate([
            'container_type_id' => 'nullable|exists:container_types,id',
            'price_type' => 'required|in:buy,sell',
            'price_per_kg' => 'nullable|numeric|min:0',
            'price_per_cbm' => 'nullable|numeric|min:0',
            'price_per_container' => 'nullable|numeric|min:0',
            'minimum_charge' => 'nullable|numeric|min:0',
            'min_kg' => 'nullable|integer|min:0',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        $existingPricing = \App\Models\Pricing::whereHas('vendorService', function ($query) use ($vendorService) {
            $query->where('vendor_id', $vendorService->vendor_id)
                  ->where('transport_mode_id', $vendorService->transport_mode_id)
                  ->where('service_type_id', $vendorService->service_type_id)
                  ->where('origin_location_id', $vendorService->origin_location_id)
                  ->where('destination_location_id', $vendorService->destination_location_id);
        })
        ->where('container_type_id', $data['container_type_id'] ?? null)
        ->where('price_type', $data['price_type'])
        ->exists();

        if ($existingPricing) {
            return response()->json(['message' => 'Tarif dengan lane, layanan, dan tipe harga yang sama sudah ada untuk vendor ini.'], 422);
        }

        $pricing = $vendorService->pricings()->create($data);
        return response()->json(['data' => $pricing], 201);
    }

    public function updatePricing(Request $request, Pricing $pricing): JsonResponse
    {
        $data = $request->validate([
            'container_type_id' => 'nullable|exists:container_types,id',
            'price_type' => 'sometimes|in:buy,sell',
            'price_per_kg' => 'nullable|numeric|min:0',
            'price_per_cbm' => 'nullable|numeric|min:0',
            'price_per_container' => 'nullable|numeric|min:0',
            'minimum_charge' => 'nullable|numeric|min:0',
            'min_kg' => 'nullable|integer|min:0',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        $vendorService = $pricing->vendorService;
        $checkContainerType = array_key_exists('container_type_id', $data) ? $data['container_type_id'] : $pricing->container_type_id;
        $checkPriceType = $data['price_type'] ?? $pricing->price_type;

        $existingPricing = \App\Models\Pricing::whereHas('vendorService', function ($query) use ($vendorService) {
            $query->where('vendor_id', $vendorService->vendor_id)
                  ->where('transport_mode_id', $vendorService->transport_mode_id)
                  ->where('service_type_id', $vendorService->service_type_id)
                  ->where('origin_location_id', $vendorService->origin_location_id)
                  ->where('destination_location_id', $vendorService->destination_location_id);
        })
        ->where('container_type_id', $checkContainerType)
        ->where('price_type', $checkPriceType)
        ->where('id', '!=', $pricing->id)
        ->exists();

        if ($existingPricing) {
            return response()->json(['message' => 'Tarif dengan lane, layanan, dan tipe harga yang sama sudah ada untuk vendor ini.'], 422);
        }

        $pricing->update($data);
        return response()->json(['data' => $pricing]);
    }

    public function destroyPricing(Pricing $pricing): JsonResponse
    {
        $pricing->delete();
        return response()->json(['message' => 'Tarif dihapus.']);
    }
}
