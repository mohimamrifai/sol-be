<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\AdditionalCharge;
use App\Models\AdditionalService;
use App\Models\Booking;
use App\Models\CargoCategory;
use App\Models\ContainerType;
use App\Models\DgClass;
use App\Models\Location;
use App\Models\ServiceType;
use App\Models\TransportMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Master data read-only untuk form booking customer (tanpa akses admin).
 */
class MasterDataReadController extends Controller
{
    public function locations(Request $request): JsonResponse
    {
        $query = Location::query()->where('is_active', true);
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        return response()->json($query->orderBy('name')->paginate($request->per_page ?? 100));
    }

    public function transportModes(): JsonResponse
    {
        $data = TransportMode::query()
            ->where('is_active', true)
            ->with(['serviceTypes' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function serviceTypes(Request $request): JsonResponse
    {
        $query = ServiceType::with('transportMode')
            ->where('is_active', true);
        if ($request->filled('transport_mode_id')) {
            $query->where('transport_mode_id', $request->transport_mode_id);
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function containerTypes(): JsonResponse
    {
        $data = ContainerType::query()
            ->where('is_active', true)
            ->orderBy('size')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function additionalServices(): JsonResponse
    {
        $data = AdditionalService::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function cargoCategories(): JsonResponse
    {
        $data = CargoCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function dgClasses(): JsonResponse
    {
        $data = DgClass::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function additionalCharges(): JsonResponse
    {
        $data = AdditionalCharge::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function shipmentCoverages(): JsonResponse
    {
        $data = array_map(
            fn (string $value): array => ['value' => $value],
            Booking::SHIPMENT_COVERAGES
        );

        return response()->json(['data' => $data]);
    }
}
