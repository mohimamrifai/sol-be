<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminStationController extends Controller
{
    public function stats(): JsonResponse
    {
        $base = Station::query();

        return response()->json([
            'data' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('status', 'active')->count(),
                'inactive' => (clone $base)->where('status', 'inactive')->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Station::query();

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%")
                    ->orWhere('city', 'like', "%{$s}%");
            });
        }
        if ($request->filled('business_entity')) {
            $query->where('business_entity', $request->business_entity);
        }
        if ($request->filled('province')) {
            $query->where('province', $request->province);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderBy('name')->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateStation($request);
        if (empty($data['code'])) {
            $data['code'] = $this->generateCode();
        }

        $station = Station::create($data);

        return response()->json(['message' => 'Station berhasil dibuat.', 'data' => $station], 201);
    }

    public function show(Station $station): JsonResponse
    {
        $station->load([
            'originRoutes.destinationStation:id,code,name',
            'destinationRoutes.originStation:id,code,name',
            'yards:id,code,name,status',
        ]);

        $connectedRoutes = Route::query()
            ->with(['originStation:id,code,name', 'destinationStation:id,code,name'])
            ->where(function ($q) use ($station) {
                $q->where('origin_station_id', $station->id)
                    ->orWhere('destination_station_id', $station->id);
            })
            ->orderBy('code')
            ->get()
            ->map(fn (Route $route) => [
                'id' => $route->id,
                'code' => $route->code,
                'origin_station' => $route->originStation,
                'destination_station' => $route->destinationStation,
                'status' => $route->status,
            ]);

        return response()->json([
            'data' => [
                ...$station->toArray(),
                'connected_routes' => $connectedRoutes,
            ],
        ]);
    }

    public function update(Request $request, Station $station): JsonResponse
    {
        $data = $this->validateStation($request, $station->id);
        $station->update($data);

        return response()->json(['message' => 'Station diperbarui.', 'data' => $station]);
    }

    public function deactivate(Station $station): JsonResponse
    {
        if ($station->isInUse()) {
            return response()->json(['message' => 'Station masih digunakan dan tidak dapat dinonaktifkan.'], 422);
        }

        $station->update(['status' => 'inactive']);

        return response()->json(['message' => 'Station dinonaktifkan.', 'data' => $station]);
    }

    private function validateStation(Request $request, ?int $ignoreId = null): array
    {
        $codeRule = 'nullable|string|max:30|unique:stations,code';
        if ($ignoreId) {
            $codeRule .= ",{$ignoreId}";
        }

        return $request->validate([
            'code' => $codeRule,
            'name' => 'required|string|max:255',
            'business_entity' => 'required|string|max:50',
            'city' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'status' => 'required|in:active,inactive',
            'remark' => 'nullable|string|max:5000',
        ]);
    }

    private function generateCode(): string
    {
        $next = Station::count() + 1;

        return 'STN'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
