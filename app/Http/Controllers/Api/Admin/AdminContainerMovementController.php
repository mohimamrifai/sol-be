<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContainerMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminContainerMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ContainerMovement::query()
            ->with([
                'containerAsset:id,container_number',
                'shipment:id,shipment_number',
                'yard:id,code,name',
                'createdBy:id,name',
            ]);

        if ($request->filled('container_asset_id')) {
            $query->where('container_asset_id', $request->container_asset_id);
        }
        if ($request->filled('shipment_id')) {
            $query->where('shipment_id', $request->shipment_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('occurred_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('occurred_at', '<=', $request->date_to);
        }
        if ($request->filled('activity')) {
            $query->where('activity', $request->activity);
        }
        if ($request->filled('yard_id')) {
            $query->where('yard_id', $request->yard_id);
        }

        $paginated = $query->orderByDesc('occurred_at')->paginate($request->integer('per_page', 50));
        $paginated->getCollection()->transform(fn (ContainerMovement $m) => [
            'id' => $m->id,
            'occurred_at' => $m->occurred_at?->toIso8601String(),
            'container_number' => $m->containerAsset?->container_number,
            'container_asset_id' => $m->container_asset_id,
            'shipment_number' => $m->shipment?->shipment_number,
            'shipment_id' => $m->shipment_id,
            'location_from' => $m->location_from,
            'location_to' => $m->location_to,
            'activity' => $m->activity,
            'yard' => $m->yard?->name,
            'yard_id' => $m->yard_id,
            'created_by' => $m->createdBy?->name,
        ]);

        return response()->json($paginated);
    }
}
