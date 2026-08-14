<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Route;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRouteController extends Controller
{
    public function stats(): JsonResponse
    {
        $base = Route::query();

        return response()->json([
            'data' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('status', 'active')->count(),
                'inactive' => (clone $base)->where('status', 'inactive')->count(),
                'port_to_port' => (clone $base)->whereJsonContains('shipment_coverages', 'port_to_port')->count(),
                'door_services' => (clone $base)->where(function ($q) {
                    $q->whereJsonContains('shipment_coverages', 'door_to_port')
                        ->orWhereJsonContains('shipment_coverages', 'port_to_door')
                        ->orWhereJsonContains('shipment_coverages', 'door_to_door');
                })->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Route::query()->with(['originStation:id,code,name', 'destinationStation:id,code,name']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('code', 'like', "%{$s}%")
                    ->orWhereHas('originStation', fn ($sq) => $sq->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
                    ->orWhereHas('destinationStation', fn ($sq) => $sq->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
            });
        }
        if ($request->filled('business_entity')) {
            $query->where('business_entity', $request->business_entity);
        }
        if ($request->filled('origin_station_id')) {
            $query->where('origin_station_id', $request->origin_station_id);
        }
        if ($request->filled('destination_station_id')) {
            $query->where('destination_station_id', $request->destination_station_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderBy('code')->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateRoute($request);
        $data['code'] = $this->generateCode();

        $route = Route::create($data);

        return response()->json(['message' => 'Route berhasil dibuat.', 'data' => $route->load(['originStation', 'destinationStation'])], 201);
    }

    public function show(Route $route): JsonResponse
    {
        return response()->json(['data' => $route->load(['originStation', 'destinationStation'])]);
    }

    public function update(Request $request, Route $route): JsonResponse
    {
        $data = $this->validateRoute($request, $route->id);
        unset($data['code']);
        $route->update($data);

        return response()->json(['message' => 'Route diperbarui.', 'data' => $route->fresh(['originStation', 'destinationStation'])]);
    }

    public function deactivate(Route $route): JsonResponse
    {
        $route->update(['status' => 'inactive']);

        return response()->json(['message' => 'Route dinonaktifkan.', 'data' => $route]);
    }

    private function validateRoute(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'business_entity' => 'required|string|max:50',
            'origin_station_id' => 'required|exists:stations,id|different:destination_station_id',
            'destination_station_id' => 'required|exists:stations,id',
            'distance_km' => 'required|numeric|min:0',
            'transit_days' => 'required|integer|min:1',
            'status' => 'required|in:active,inactive',
            'remark' => 'nullable|string|max:5000',
            'service_types' => 'required|array|min:1',
            'service_types.*' => 'in:lcl,fcl',
            'shipment_coverages' => 'required|array|min:1',
            'shipment_coverages.*' => 'in:port_to_port,door_to_port,port_to_door,door_to_door',
        ]);
    }

    private function generateCode(): string
    {
        $next = Route::count() + 1;

        return 'RTE'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
