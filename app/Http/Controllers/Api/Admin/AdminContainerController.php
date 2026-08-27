<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContainerAsset;
use App\Models\ContainerMaintenance;
use App\Models\ContainerType;
use App\Services\ContainerAssetService;
use App\Services\ContainerFreeStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminContainerController extends Controller
{
    public function __construct(
        private readonly ContainerFreeStorageService $containerFreeStorageService,
        private readonly ContainerAssetService $containerAssetService,
    ) {}

    public function stats(): JsonResponse
    {
        $base = ContainerAsset::query();
        $company = (clone $base)->where('ownership', 'company');
        $vendor = (clone $base)->where('ownership', 'vendor');

        return response()->json([
            'data' => [
                'total_company' => (clone $company)->count(),
                'total_vendor' => (clone $vendor)->count(),
                'available' => (clone $base)->where('status', 'available')->count(),
                'reserved' => (clone $base)->where('status', 'reserved')->count(),
                'in_transit' => (clone $base)->where('status', 'in_transit')->count(),
                'maintenance' => (clone $base)->where('status', 'maintenance')->count(),
                'inactive' => (clone $base)->where('status', 'inactive')->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ContainerAsset::query()
            ->with(['containerType:id,name,code', 'vendor:id,name,code', 'currentYard:id,code,name']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where('container_number', 'like', "%{$s}%");
        }
        if ($request->filled('ownership')) {
            $query->where('ownership', $request->ownership);
        }
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }
        if ($request->filled('container_type_id')) {
            $query->where('container_type_id', $request->container_type_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('current_yard_id')) {
            $query->where('current_yard_id', $request->current_yard_id);
        }
        if ($request->boolean('storage_exceeded')) {
            $ids = $this->containerFreeStorageService->exceededAssetIds(now());
            $query->whereIn('id', $ids ?: [0]);
        }

        $paginated = $query->orderBy('container_number')->paginate($request->integer('per_page', 15));
        $paginated->getCollection()->transform(fn (ContainerAsset $asset) => $this->transformListRow($asset));

        return response()->json($paginated);
    }

    public function show(ContainerAsset $containerAsset): JsonResponse
    {
        $containerAsset->load([
            'containerType',
            'vendor',
            'currentYard.station',
            'movements.shipment:id,shipment_number',
            'movements.createdBy:id,name',
            'movements.yard:id,name,code',
            'maintenances.vendor:id,name',
        ]);

        $assignments = $this->containerAssetService->buildCurrentAssignments($containerAsset);

        return response()->json([
            'data' => array_merge($this->transformListRow($containerAsset), [
                'header' => [
                    'container_number' => $containerAsset->container_number,
                    'container_type' => $containerAsset->containerType?->name,
                    'ownership' => $containerAsset->ownership,
                    'status' => $containerAsset->status,
                ],
                'general' => [
                    'container_number' => $containerAsset->container_number,
                    'container_type_id' => $containerAsset->container_type_id,
                    'container_type' => $containerAsset->containerType?->name,
                    'ownership' => $containerAsset->ownership,
                    'vendor' => $containerAsset->vendor?->name,
                    'vendor_id' => $containerAsset->vendor_id,
                    'max_payload_kg' => $containerAsset->max_payload_kg,
                    'max_capacity_cbm' => $containerAsset->max_capacity_cbm,
                    'current_yard' => $containerAsset->currentYard?->name,
                    'current_yard_id' => $containerAsset->current_yard_id,
                    'manufacture_year' => $containerAsset->manufacture_year,
                    'remark' => $containerAsset->remark,
                ],
                'current_assignments' => $assignments,
                'current_assignment' => $assignments[0] ?? null,
                'utilization' => $this->containerAssetService->buildUtilization($containerAsset),
                'movements' => $containerAsset->movements->map(fn ($m) => [
                    'id' => $m->id,
                    'occurred_at' => $m->occurred_at?->toIso8601String(),
                    'activity' => $m->activity,
                    'location' => $m->location_to ?? $m->location_from ?? $m->yard?->name,
                    'shipment_id' => $m->shipment_id,
                    'shipment_number' => $m->shipment?->shipment_number,
                    'updated_by' => $m->createdBy?->name,
                ]),
                'maintenances' => $containerAsset->maintenances->map(fn ($m) => [
                    'id' => $m->id,
                    'maintenance_date' => $m->maintenance_date?->toDateString(),
                    'maintenance_type' => $m->maintenance_type,
                    'vendor' => $m->vendor?->name,
                    'remark' => $m->remark,
                    'status' => $m->status,
                ]),
                'activity_log' => $this->containerAssetService->activityLog($containerAsset),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'container_number' => 'required|string|max:30|unique:container_assets,container_number',
            'container_type_id' => 'required|exists:container_types,id',
            'max_payload_kg' => 'nullable|numeric|min:0',
            'max_capacity_cbm' => 'nullable|numeric|min:0',
            'manufacture_year' => 'nullable|integer|min:1980|max:'.(date('Y') + 1),
            'current_yard_id' => 'nullable|exists:yards,id',
            'remark' => 'nullable|string|max:5000',
        ]);

        $type = ContainerType::find($data['container_type_id']);
        $asset = ContainerAsset::create([
            ...$data,
            'container_number' => strtoupper(trim($data['container_number'])),
            'ownership' => 'company',
            'vendor_id' => null,
            'status' => 'available',
            'max_payload_kg' => $data['max_payload_kg'] ?? $type?->capacity_weight,
            'max_capacity_cbm' => $data['max_capacity_cbm'] ?? $type?->capacity_cbm,
        ]);

        $this->containerAssetService->log(
            $asset,
            'Container perusahaan '.$asset->container_number.' dibuat.',
            'created',
            $request->user()?->id,
        );

        $this->containerAssetService->onRegistered($asset->fresh(['currentYard']), $request->user()?->id);

        return response()->json([
            'message' => 'Container perusahaan berhasil dibuat.',
            'data' => $this->transformListRow($asset->fresh(['containerType', 'currentYard'])),
        ], 201);
    }

    public function update(Request $request, ContainerAsset $containerAsset): JsonResponse
    {
        $data = $request->validate([
            'container_type_id' => 'sometimes|exists:container_types,id',
            'remark' => 'nullable|string|max:5000',
        ]);

        $containerAsset->update($data);

        $this->containerAssetService->log(
            $containerAsset,
            'Data container '.$containerAsset->container_number.' diperbarui.',
            'updated',
            $request->user()?->id,
            $data,
        );

        return response()->json([
            'message' => 'Container diperbarui.',
            'data' => $this->transformListRow($containerAsset->fresh(['containerType', 'currentYard'])),
        ]);
    }

    public function storeMaintenance(Request $request, ContainerAsset $containerAsset): JsonResponse
    {
        $data = $request->validate([
            'maintenance_type' => 'required|in:repair,inspection,cleaning',
            'maintenance_date' => 'required|date',
            'vendor_id' => 'nullable|exists:vendors,id',
            'remark' => 'nullable|string|max:5000',
            'status' => 'required|in:scheduled,in_progress,completed,cancelled',
        ]);

        $maintenance = $containerAsset->maintenances()->create($data);

        $this->containerAssetService->log(
            $containerAsset,
            'Maintenance '.$data['maintenance_type'].' dicatat.',
            'maintenance_recorded',
            $request->user()?->id,
            ['maintenance_id' => $maintenance->id],
        );

        return response()->json([
            'message' => 'Maintenance berhasil dicatat.',
            'data' => $maintenance->load('vendor:id,name'),
        ], 201);
    }

    public function updateMaintenance(Request $request, ContainerAsset $containerAsset, ContainerMaintenance $maintenance): JsonResponse
    {
        if ((int) $maintenance->container_asset_id !== (int) $containerAsset->id) {
            return response()->json(['message' => 'Maintenance tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'maintenance_type' => 'sometimes|in:repair,inspection,cleaning',
            'maintenance_date' => 'sometimes|date',
            'vendor_id' => 'nullable|exists:vendors,id',
            'remark' => 'nullable|string|max:5000',
            'status' => 'sometimes|in:scheduled,in_progress,completed,cancelled',
        ]);

        $maintenance->update($data);

        $this->containerAssetService->log(
            $containerAsset,
            'Maintenance diperbarui.',
            'maintenance_updated',
            $request->user()?->id,
            ['maintenance_id' => $maintenance->id],
        );

        return response()->json([
            'message' => 'Maintenance diperbarui.',
            'data' => $maintenance->fresh('vendor:id,name'),
        ]);
    }

    private function transformListRow(ContainerAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'container_number' => $asset->container_number,
            'container_type' => $asset->containerType?->name,
            'container_type_id' => $asset->container_type_id,
            'ownership' => $asset->ownership,
            'vendor' => $asset->vendor?->name,
            'vendor_id' => $asset->vendor_id,
            'current_yard' => $asset->currentYard?->name,
            'current_yard_id' => $asset->current_yard_id,
            'utilization_pct' => $this->containerAssetService->listUtilizationPct($asset),
            'status' => $asset->status,
            'manufacture_year' => $asset->manufacture_year,
            'remark' => $asset->remark,
            'created_at' => $asset->created_at?->toIso8601String(),
        ];
    }
}
