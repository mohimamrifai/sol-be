<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContainerAsset;
use App\Models\ContainerType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminContainerController extends Controller
{
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

        $activeContainer = $containerAsset->activeShipmentContainer();
        $activeShipment = $activeContainer?->shipment?->load([
            'company:id,name',
            'serviceType:id,name,code',
        ]);

        return response()->json([
            'data' => array_merge($this->transformListRow($containerAsset), [
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
                'current_assignment' => $activeShipment ? [
                    'shipment_id' => $activeShipment->id,
                    'shipment_number' => $activeShipment->shipment_number,
                    'customer' => $activeShipment->company?->name,
                    'service' => $activeShipment->serviceType?->name,
                    'status' => $activeShipment->status,
                    'departure' => $activeShipment->estimated_departure?->toDateString(),
                    'eta' => $activeShipment->estimated_arrival?->toDateString(),
                ] : null,
                'utilization' => $this->buildUtilization($containerAsset, $activeContainer, $activeShipment),
                'movements' => $containerAsset->movements->map(fn ($m) => [
                    'id' => $m->id,
                    'occurred_at' => $m->occurred_at?->toIso8601String(),
                    'activity' => $m->activity,
                    'location_from' => $m->location_from,
                    'location_to' => $m->location_to,
                    'shipment_number' => $m->shipment?->shipment_number,
                    'created_by' => $m->createdBy?->name,
                ]),
                'maintenances' => $containerAsset->maintenances->map(fn ($m) => [
                    'id' => $m->id,
                    'maintenance_date' => $m->maintenance_date?->toDateString(),
                    'maintenance_type' => $m->maintenance_type,
                    'vendor' => $m->vendor?->name,
                    'remark' => $m->remark,
                    'status' => $m->status,
                ]),
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
            'ownership' => 'company',
            'vendor_id' => null,
            'status' => 'available',
            'max_payload_kg' => $data['max_payload_kg'] ?? $type?->max_payload_kg,
            'max_capacity_cbm' => $data['max_capacity_cbm'] ?? $type?->max_capacity_cbm,
        ]);

        return response()->json(['message' => 'Container perusahaan berhasil dibuat.', 'data' => $this->transformListRow($asset->fresh(['containerType', 'currentYard']))], 201);
    }

    public function update(Request $request, ContainerAsset $containerAsset): JsonResponse
    {
        $data = $request->validate([
            'remark' => 'nullable|string|max:5000',
        ]);

        $containerAsset->update($data);

        return response()->json(['message' => 'Container diperbarui.', 'data' => $this->transformListRow($containerAsset->fresh(['containerType', 'currentYard']))]);
    }

    private function transformListRow(ContainerAsset $asset): array
    {
        $maxCbm = (float) ($asset->max_capacity_cbm ?? 0);
        $usedCbm = 0.0;
        $active = $asset->activeShipmentContainer();
        if ($active) {
            $usedCbm = (float) $active->items()->sum('cbm');
        }
        $utilizationPct = $maxCbm > 0 ? round(($usedCbm / $maxCbm) * 100, 1) : 0.0;

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
            'utilization_pct' => $utilizationPct,
            'status' => $asset->status,
            'manufacture_year' => $asset->manufacture_year,
            'remark' => $asset->remark,
            'created_at' => $asset->created_at?->toIso8601String(),
        ];
    }

    private function buildUtilization(ContainerAsset $asset, $activeContainer, $activeShipment): array
    {
        if (! $activeContainer || ! $activeShipment) {
            return [
                'mode' => 'available',
                'used_cbm' => 0,
                'remaining_cbm' => (float) ($asset->max_capacity_cbm ?? 0),
                'used_payload_kg' => 0,
                'remaining_payload_kg' => (float) ($asset->max_payload_kg ?? 0),
                'remaining_pct' => 100,
            ];
        }

        $serviceCode = strtolower((string) ($activeShipment->serviceType?->code ?? ''));
        $isFcl = str_contains($serviceCode, 'fcl');

        if ($isFcl) {
            return [
                'mode' => 'fcl',
                'shipment_number' => $activeShipment->shipment_number,
                'shipment_id' => $activeShipment->id,
                'dedicated_message' => 'Dedicated for Shipment '.$activeShipment->shipment_number,
            ];
        }

        $usedCbm = (float) $activeContainer->items()->sum('cbm');
        $usedPayload = (float) $activeContainer->items()->sum('gross_weight');
        $maxCbm = (float) ($asset->max_capacity_cbm ?? 0);
        $maxPayload = (float) ($asset->max_payload_kg ?? 0);

        return [
            'mode' => 'lcl',
            'shipment_number' => $activeShipment->shipment_number,
            'shipment_id' => $activeShipment->id,
            'used_cbm' => $usedCbm,
            'remaining_cbm' => max(0, $maxCbm - $usedCbm),
            'used_payload_kg' => $usedPayload,
            'remaining_payload_kg' => max(0, $maxPayload - $usedPayload),
            'remaining_pct' => $maxCbm > 0 ? round((max(0, $maxCbm - $usedCbm) / $maxCbm) * 100, 1) : 100,
        ];
    }
}
