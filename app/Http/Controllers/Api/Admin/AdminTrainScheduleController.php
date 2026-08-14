<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TrainScheduleStatus;
use App\Http\Controllers\Controller;
use App\Models\Container;
use App\Models\TrainSchedule;
use App\Services\TrainScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminTrainScheduleController extends Controller
{
    public function __construct(
        private readonly TrainScheduleService $trainScheduleService,
    ) {}

    public function stats(): JsonResponse
    {
        $base = TrainSchedule::query();

        return response()->json([
            'data' => [
                'total' => (clone $base)->count(),
                'upcoming' => (clone $base)->where('status', TrainScheduleStatus::Upcoming)->count(),
                'departed' => (clone $base)->where('status', TrainScheduleStatus::Departed)->count(),
                'completed' => (clone $base)->where('status', TrainScheduleStatus::Completed)->count(),
                'cancelled' => (clone $base)->where('status', TrainScheduleStatus::Cancelled)->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = TrainSchedule::query()
            ->with(['route.originStation:id,code,name', 'route.destinationStation:id,code,name'])
            ->withCount('shipments');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('train_number', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%");
            });
        }
        if ($request->filled('business_entity')) {
            $query->where('business_entity', $request->business_entity);
        }
        if ($request->filled('route_id')) {
            $query->where('route_id', $request->route_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('departure_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('departure_at', '<=', $request->date_to);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $paginated = $query->orderByDesc('departure_at')->paginate($request->integer('per_page', 15));
        $paginated->getCollection()->transform(fn (TrainSchedule $schedule) => $this->transformListRow($schedule));

        return response()->json($paginated);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateSchedule($request);
        $schedule = TrainSchedule::create($data);

        if ($schedule->status === TrainScheduleStatus::Departed) {
            $this->trainScheduleService->applyStatusTransition($schedule, TrainScheduleStatus::Departed);
        } elseif ($schedule->status === TrainScheduleStatus::Completed) {
            $this->trainScheduleService->applyStatusTransition($schedule, TrainScheduleStatus::Completed);
        }

        return response()->json([
            'message' => 'Train schedule berhasil dibuat.',
            'data' => $this->transformDetail($schedule->fresh(['route.originStation', 'route.destinationStation'])),
        ], 201);
    }

    public function show(TrainSchedule $trainSchedule): JsonResponse
    {
        $trainSchedule->load([
            'route.originStation:id,code,name',
            'route.destinationStation:id,code,name',
            'shipments.company:id,name',
            'shipments.serviceType:id,name',
            'shipments.containers.containerType:id,name',
            'shipments.containers.containerAsset:id,container_number,status',
        ]);

        return response()->json(['data' => $this->transformDetail($trainSchedule, true)]);
    }

    public function update(Request $request, TrainSchedule $trainSchedule): JsonResponse
    {
        if ($trainSchedule->status === TrainScheduleStatus::Cancelled) {
            return response()->json(['message' => 'Train schedule yang dibatalkan tidak dapat diubah.'], 422);
        }

        $data = $this->validateSchedule($request, $trainSchedule->id);
        unset($data['code']);

        $previousStatus = $trainSchedule->status;
        $newStatus = isset($data['status'])
            ? TrainScheduleStatus::from($data['status'])
            : $previousStatus;

        if ($newStatus === TrainScheduleStatus::Cancelled && $previousStatus !== TrainScheduleStatus::Upcoming) {
            return response()->json(['message' => 'Train schedule hanya dapat dibatalkan jika belum berangkat.'], 422);
        }

        $trainSchedule->update($data);

        if ($newStatus !== $previousStatus) {
            $this->trainScheduleService->applyStatusTransition($trainSchedule->fresh(), $newStatus, $previousStatus);
        }

        return response()->json([
            'message' => 'Train schedule diperbarui.',
            'data' => $this->transformDetail($trainSchedule->fresh(['route.originStation', 'route.destinationStation']), true),
        ]);
    }

    public function cancel(TrainSchedule $trainSchedule): JsonResponse
    {
        if ($trainSchedule->status !== TrainScheduleStatus::Upcoming) {
            return response()->json(['message' => 'Train schedule hanya dapat dibatalkan jika belum berangkat.'], 422);
        }

        $trainSchedule->update(['status' => TrainScheduleStatus::Cancelled]);

        return response()->json([
            'message' => 'Train schedule dibatalkan.',
            'data' => $this->transformDetail($trainSchedule->fresh(['route.originStation', 'route.destinationStation'])),
        ]);
    }

    private function validateSchedule(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'business_entity' => 'required|string|max:50',
            'train_number' => 'required|string|max:50',
            'route_id' => 'required|exists:routes,id',
            'departure_at' => 'required|date',
            'eta_at' => 'required|date|after:departure_at',
            'max_containers' => 'nullable|integer|min:1',
            'status' => ['required', Rule::enum(TrainScheduleStatus::class)],
            'remark' => 'nullable|string|max:5000',
        ]);
    }

    private function transformListRow(TrainSchedule $schedule): array
    {
        $origin = $schedule->route?->originStation?->name;
        $destination = $schedule->route?->destinationStation?->name;

        return [
            'id' => $schedule->id,
            'code' => $schedule->code,
            'train_number' => $schedule->train_number,
            'business_entity' => $schedule->business_entity,
            'route_id' => $schedule->route_id,
            'route' => $origin && $destination ? "{$origin} → {$destination}" : null,
            'departure_at' => $schedule->departure_at?->toIso8601String(),
            'eta_at' => $schedule->eta_at?->toIso8601String(),
            'status' => $schedule->status?->value,
            'assigned_shipments_count' => $schedule->shipments_count ?? 0,
        ];
    }

    private function transformDetail(TrainSchedule $schedule, bool $includeAssignments = false): array
    {
        $row = [
            ...$this->transformListRow($schedule),
            'max_containers' => $schedule->max_containers,
            'remark' => $schedule->remark,
            'created_at' => $schedule->created_at?->toIso8601String(),
            'updated_at' => $schedule->updated_at?->toIso8601String(),
            'route_detail' => $schedule->relationLoaded('route') && $schedule->route ? [
                'id' => $schedule->route->id,
                'code' => $schedule->route->code,
                'origin_station' => $schedule->route->originStation,
                'destination_station' => $schedule->route->destinationStation,
            ] : null,
        ];

        if (! $includeAssignments) {
            return $row;
        }

        $row['assigned_shipments'] = $schedule->shipments->map(fn ($shipment) => [
            'id' => $shipment->id,
            'shipment_number' => $shipment->shipment_number,
            'customer' => $shipment->company?->name,
            'service' => $shipment->serviceType?->name,
            'container_count' => $shipment->containers->count(),
            'status' => $shipment->status,
        ]);

        $seenAssets = [];
        $row['assigned_containers'] = $schedule->shipments
            ->flatMap(fn ($shipment) => $shipment->containers->map(function (Container $container) use ($shipment, &$seenAssets) {
                $assetId = $container->container_asset_id;
                if ($assetId && isset($seenAssets[$assetId])) {
                    return null;
                }
                if ($assetId) {
                    $seenAssets[$assetId] = true;
                }

                return [
                    'container_number' => $container->containerAsset?->container_number ?? $container->container_number,
                    'type' => $container->containerType?->name,
                    'shipment_number' => $shipment->shipment_number,
                    'customer' => $shipment->company?->name,
                    'status' => $container->containerAsset?->status ?? $container->assignment_status,
                ];
            }))
            ->filter()
            ->values();

        return $row;
    }
}
