<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Master data read-only untuk form booking customer (tanpa akses admin).
 */
class MasterDataReadController extends Controller
{
    public function locations(Request $request): JsonResponse
    {
        $query = \App\Models\Location::query()->where('is_active', true);
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
        $data = \App\Models\TransportMode::query()
            ->where('is_active', true)
            ->with(['serviceTypes' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function serviceTypes(Request $request): JsonResponse
    {
        $query = \App\Models\ServiceType::with('transportMode')
            ->where('is_active', true);
        if ($request->filled('transport_mode_id')) {
            $query->where('transport_mode_id', $request->transport_mode_id);
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function containerTypes(): JsonResponse
    {
        $data = \App\Models\ContainerType::query()
            ->where('is_active', true)
            ->orderBy('size')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function additionalServices(): JsonResponse
    {
        $data = \App\Models\AdditionalService::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function cargoCategories(): JsonResponse
    {
        $data = \App\Models\CargoCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function dgClasses(): JsonResponse
    {
        $data = \App\Models\DgClass::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function additionalCharges(): JsonResponse
    {
        $data = \App\Models\AdditionalCharge::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $data]);
    }
}
