<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContainerAsset;
use App\Models\ContainerMovement;
use App\Models\Yard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminYardController extends Controller
{
    public function stats(): JsonResponse
    {
        $base = Yard::query();

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
        $query = Yard::query()->with('station:id,code,name');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%");
            });
        }
        if ($request->filled('business_entity')) {
            $query->where('business_entity', $request->business_entity);
        }
        if ($request->filled('station_id')) {
            $query->where('station_id', $request->station_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderBy('name')->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateYard($request);
        if (empty($data['code'])) {
            $data['code'] = $this->generateCode();
        }

        $yard = Yard::create($data);

        return response()->json(['message' => 'Yard berhasil dibuat.', 'data' => $yard->load('station')], 201);
    }

    public function show(Yard $yard): JsonResponse
    {
        $yard->load('station');

        $assets = ContainerAsset::query()->where('current_yard_id', $yard->id);
        $containerSummary = [
            'total' => (clone $assets)->count(),
            'available' => (clone $assets)->where('status', 'available')->count(),
            'reserved' => (clone $assets)->where('status', 'reserved')->count(),
            'in_transit' => (clone $assets)->where('status', 'in_transit')->count(),
            'maintenance' => (clone $assets)->where('status', 'maintenance')->count(),
            'inactive' => (clone $assets)->where('status', 'inactive')->count(),
        ];

        $recentMovements = ContainerMovement::query()
            ->where('yard_id', $yard->id)
            ->with(['containerAsset:id,container_number', 'shipment:id,shipment_number'])
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get()
            ->map(fn (ContainerMovement $m) => [
                'occurred_at' => $m->occurred_at?->toIso8601String(),
                'container_number' => $m->containerAsset?->container_number,
                'activity' => $m->activity,
                'shipment_number' => $m->shipment?->shipment_number,
            ]);

        return response()->json([
            'data' => [
                ...$yard->toArray(),
                'station' => $yard->station,
                'container_summary' => $containerSummary,
                'recent_movements' => $recentMovements,
            ],
        ]);
    }

    public function update(Request $request, Yard $yard): JsonResponse
    {
        $data = $this->validateYard($request, $yard->id);
        $yard->update($data);

        return response()->json(['message' => 'Yard diperbarui.', 'data' => $yard->fresh('station')]);
    }

    public function deactivate(Yard $yard): JsonResponse
    {
        if ($yard->isInUse()) {
            return response()->json(['message' => 'Yard masih digunakan dan tidak dapat dinonaktifkan.'], 422);
        }

        $yard->update(['status' => 'inactive']);

        return response()->json(['message' => 'Yard dinonaktifkan.', 'data' => $yard]);
    }

    private function validateYard(Request $request, ?int $ignoreId = null): array
    {
        $codeRule = 'nullable|string|max:30|unique:yards,code';
        if ($ignoreId) {
            $codeRule .= ",{$ignoreId}";
        }

        return $request->validate([
            'code' => $codeRule,
            'name' => 'required|string|max:255',
            'business_entity' => 'required|string|max:50',
            'station_id' => 'required|exists:stations,id',
            'yard_type' => 'required|in:origin_yard,destination_yard,hub_yard',
            'status' => 'required|in:active,inactive',
            'remark' => 'nullable|string|max:5000',
            'country' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'address' => 'nullable|string',
        ]);
    }

    private function generateCode(): string
    {
        $next = Yard::count() + 1;

        return 'YRD'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
