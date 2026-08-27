<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OperationType;
use App\Models\AdminActivityLog;
use App\Models\Container;
use App\Models\ContainerAsset;
use App\Models\ContainerMaintenance;
use App\Models\ContainerMovement;
use App\Models\OperationTask;
use App\Models\Shipment;
use App\Models\Yard;

class ContainerAssetService
{
    public const ACTIVITY_REGISTERED = 'registered';

    public const ACTIVITY_ASSIGNED = 'assigned';

    public const ACTIVITY_LOADED = 'loaded';

    public const ACTIVITY_ARRIVED = 'arrived';

    public const ACTIVITY_RELEASED = 'released';

    /** @var array<int, string> */
    public const MOVEMENT_ACTIVITIES = [
        self::ACTIVITY_REGISTERED,
        self::ACTIVITY_ASSIGNED,
        self::ACTIVITY_LOADED,
        self::ACTIVITY_ARRIVED,
        self::ACTIVITY_RELEASED,
    ];

    public function __construct(
        private readonly AdminActivityLogger $activityLogger,
    ) {}

    public function recordMovement(
        ContainerAsset $asset,
        string $activity,
        ?Shipment $shipment = null,
        ?string $locationFrom = null,
        ?string $locationTo = null,
        ?int $yardId = null,
        ?int $actorUserId = null,
    ): ContainerMovement {
        $yard = $yardId ? Yard::query()->find($yardId) : null;

        $movement = ContainerMovement::query()->create([
            'container_asset_id' => $asset->id,
            'shipment_id' => $shipment?->id,
            'activity' => $activity,
            'location_from' => $locationFrom,
            'location_to' => $locationTo ?? $yard?->name,
            'yard_id' => $yardId,
            'created_by_id' => $actorUserId,
            'occurred_at' => now(),
        ]);

        if ($yardId !== null) {
            $asset->update(['current_yard_id' => $yardId]);
        }

        return $movement;
    }

    public function log(
        ContainerAsset $asset,
        string $description,
        string $eventKey = 'updated',
        ?int $actorUserId = null,
        ?array $meta = null,
    ): void {
        $this->activityLogger->log('container', $description, $asset, $eventKey, $meta, $actorUserId);
    }

    public function onRegistered(ContainerAsset $asset, ?int $actorUserId = null): void
    {
        $asset->loadMissing('currentYard');
        $yardName = $asset->currentYard?->name;

        $this->recordMovement(
            $asset,
            self::ACTIVITY_REGISTERED,
            null,
            null,
            $yardName,
            $asset->current_yard_id,
            $actorUserId,
        );

        $this->log(
            $asset,
            'Container '.$asset->container_number.' terdaftar.',
            'registered',
            $actorUserId,
        );
    }

    public function onAssigned(ContainerAsset $asset, Shipment $shipment, ?int $actorUserId = null): void
    {
        $shipment->loadMissing(['originYard', 'company']);
        $yardId = $shipment->origin_yard_id;
        $yardName = $shipment->originYard?->name;

        $asset->update(['status' => 'reserved']);

        $this->recordMovement(
            $asset,
            self::ACTIVITY_ASSIGNED,
            $shipment,
            $yardName,
            $yardName,
            $yardId,
            $actorUserId,
        );

        $this->log(
            $asset,
            'Container dialokasikan ke shipment '.$shipment->shipment_number.'.',
            'assigned',
            $actorUserId,
            ['shipment_id' => $shipment->id],
        );
    }

    public function onReleased(ContainerAsset $asset, ?Shipment $shipment = null, ?int $actorUserId = null): void
    {
        $asset->loadMissing('currentYard');

        $this->recordMovement(
            $asset,
            self::ACTIVITY_RELEASED,
            $shipment,
            $asset->currentYard?->name,
            null,
            $asset->current_yard_id,
            $actorUserId,
        );

        $this->log(
            $asset,
            $shipment
                ? 'Container dilepas dari shipment '.$shipment->shipment_number.'.'
                : 'Container dilepas dari assignment.',
            'released',
            $actorUserId,
            $shipment ? ['shipment_id' => $shipment->id] : null,
        );

        $this->syncOperationalStatus($asset);
    }

    public function onTrainDeparted(ContainerAsset $asset, Shipment $shipment, ?int $actorUserId = null): void
    {
        if (! in_array($asset->status, ['inactive', 'maintenance'], true)) {
            $asset->update(['status' => 'in_transit']);
        }

        if ($this->movementExists($asset->id, self::ACTIVITY_LOADED, $shipment->id)) {
            return;
        }

        $shipment->loadMissing(['originYard', 'originLocation', 'train']);

        $trainLabel = $shipment->train?->train_number
            ? 'Train '.$shipment->train->train_number
            : 'Train';

        $this->recordMovement(
            $asset,
            self::ACTIVITY_LOADED,
            $shipment,
            $shipment->originYard?->name ?? $shipment->originLocation?->name,
            $trainLabel,
            null,
            $actorUserId,
        );
    }

    public function onShipmentTerminal(Shipment $shipment, ?int $actorUserId = null): void
    {
        $shipment->loadMissing(['containers.containerAsset']);

        foreach ($shipment->containers as $container) {
            $asset = $container->containerAsset;
            if (! $asset) {
                continue;
            }

            if ($shipment->status === 'cancelled') {
                if (! $this->movementExists($asset->id, self::ACTIVITY_RELEASED, $shipment->id)) {
                    $this->onReleased($asset, $shipment, $actorUserId);
                } else {
                    $this->syncOperationalStatus($asset);
                }

                continue;
            }

            if ($shipment->status === 'completed') {
                if (! $this->movementExists($asset->id, self::ACTIVITY_RELEASED, $shipment->id)) {
                    $shipment->loadMissing(['destinationYard', 'destinationLocation']);
                    $this->recordMovement(
                        $asset,
                        self::ACTIVITY_RELEASED,
                        $shipment,
                        $shipment->destinationYard?->name ?? $shipment->destinationLocation?->name,
                        null,
                        $shipment->destination_yard_id,
                        $actorUserId,
                    );
                }

                $this->syncOperationalStatus($asset);

                $this->log(
                    $asset,
                    'Container tersedia kembali setelah shipment '.$shipment->shipment_number.' selesai.',
                    'completed',
                    $actorUserId,
                    ['shipment_id' => $shipment->id],
                );
            }
        }
    }

    /**
     * @var array<int, string>
     */
    private const IN_TRANSIT_SHIPMENT_STATUSES = [
        'train_departed',
        'departed',
        'train_arrived',
        'arrived',
        'container_unloading',
        'unloading',
    ];

    public function syncShipmentContainerStatuses(Shipment $shipment): void
    {
        $shipment->loadMissing(['containers.containerAsset']);

        $inTransit = in_array($shipment->status, self::IN_TRANSIT_SHIPMENT_STATUSES, true);

        foreach ($shipment->containers as $container) {
            $asset = $container->containerAsset;
            if (! $asset || in_array($asset->status, ['inactive', 'maintenance'], true)) {
                continue;
            }

            if ($inTransit) {
                $this->onTrainDeparted($asset, $shipment);
            } elseif ($this->activeContainerLinks($asset)->isNotEmpty()) {
                $asset->update(['status' => 'reserved']);
            }
        }
    }

    public function syncMaintenanceStatus(ContainerAsset $asset): void
    {
        if ($asset->status === 'inactive') {
            return;
        }

        $previousStatus = $asset->status;

        $openMaintenance = ContainerMaintenance::query()
            ->where('container_asset_id', $asset->id)
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->exists();

        if ($openMaintenance) {
            $asset->update(['status' => 'maintenance']);
            if ($previousStatus !== 'maintenance') {
                $this->log($asset, 'Container dalam periode maintenance.', 'maintenance');
            }

            return;
        }

        $latestMaintenance = ContainerMaintenance::query()
            ->where('container_asset_id', $asset->id)
            ->orderByDesc('maintenance_date')
            ->orderByDesc('id')
            ->first();

        if (
            $latestMaintenance
            && $latestMaintenance->status === 'cancelled'
            && $latestMaintenance->maintenance_type === 'repair'
        ) {
            $asset->update(['status' => 'inactive']);
            if ($previousStatus !== 'inactive') {
                $this->log($asset, 'Container tidak dapat digunakan setelah perbaikan dibatalkan.', 'inactive');
            }

            return;
        }

        $this->syncOperationalStatus($asset);
    }

    public function syncOperationalStatus(ContainerAsset $asset): void
    {
        if ($asset->status === 'inactive') {
            return;
        }

        $openMaintenance = ContainerMaintenance::query()
            ->where('container_asset_id', $asset->id)
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->exists();

        if ($openMaintenance) {
            $asset->update(['status' => 'maintenance']);

            return;
        }

        $links = $this->activeContainerLinks($asset);

        if ($links->isEmpty()) {
            if ($asset->status !== 'maintenance') {
                $asset->update(['status' => 'available']);
            }

            return;
        }

        $inTransit = $links->contains(
            fn (Container $link) => in_array((string) ($link->shipment?->status ?? ''), self::IN_TRANSIT_SHIPMENT_STATUSES, true)
        );

        $asset->update(['status' => $inTransit ? 'in_transit' : 'reserved']);
    }

    private function movementExists(int $assetId, string $activity, ?int $shipmentId): bool
    {
        return ContainerMovement::query()
            ->where('container_asset_id', $assetId)
            ->where('activity', $activity)
            ->when($shipmentId, fn ($q) => $q->where('shipment_id', $shipmentId))
            ->exists();
    }

    public function handleOperationTaskCompleted(OperationTask $task, ?int $actorUserId = null): void
    {
        $shipment = $task->shipment;
        if (! $shipment) {
            return;
        }

        $shipment->loadMissing(['containers.containerAsset.currentYard', 'originYard', 'destinationYard', 'destinationLocation', 'train']);

        foreach ($shipment->containers as $container) {
            $asset = $container->containerAsset;
            if (! $asset) {
                continue;
            }

            match ($task->operation_type) {
                OperationType::Loading => $this->recordMovementIfNew(
                    $asset,
                    self::ACTIVITY_LOADED,
                    $shipment,
                    $shipment->originYard?->name,
                    $shipment->train?->train_number ? 'Train '.$shipment->train->train_number : 'Train',
                    $shipment->origin_yard_id,
                    $actorUserId,
                ),
                OperationType::TrainDeparture => $this->onTrainDeparted($asset, $shipment, $actorUserId),
                OperationType::GateInOrigin => null,
                OperationType::TrainArrival => $this->recordMovementIfNew(
                    $asset,
                    self::ACTIVITY_ARRIVED,
                    $shipment,
                    $shipment->train?->train_number ? 'Train '.$shipment->train->train_number : 'Train',
                    $shipment->destinationYard?->name ?? $shipment->destinationLocation?->name,
                    $shipment->destination_yard_id,
                    $actorUserId,
                ),
                OperationType::GateOutDestination, OperationType::Delivery => $this->recordMovementIfNew(
                    $asset,
                    self::ACTIVITY_RELEASED,
                    $shipment,
                    $shipment->destinationYard?->name,
                    null,
                    $shipment->destination_yard_id,
                    $actorUserId,
                ),
                default => null,
            };
        }
    }

    private function recordMovementIfNew(
        ContainerAsset $asset,
        string $activity,
        Shipment $shipment,
        ?string $locationFrom,
        ?string $locationTo,
        ?int $yardId,
        ?int $actorUserId,
    ): void {
        if ($this->movementExists($asset->id, $activity, $shipment->id)) {
            return;
        }

        $this->recordMovement($asset, $activity, $shipment, $locationFrom, $locationTo, $yardId, $actorUserId);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Container>
     */
    public function activeContainerLinks(ContainerAsset $asset)
    {
        return Container::query()
            ->where('container_asset_id', $asset->id)
            ->whereHas('shipment', fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled']))
            ->with(['shipment.company:id,name', 'shipment.serviceType:id,name,code', 'shipment.booking', 'items'])
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildCurrentAssignments(ContainerAsset $asset): array
    {
        return $this->activeContainerLinks($asset)
            ->map(function (Container $link) {
                $shipment = $link->shipment;
                if (! $shipment) {
                    return null;
                }

                return [
                    'shipment_id' => $shipment->id,
                    'shipment_number' => $shipment->shipment_number,
                    'customer' => $shipment->company?->name,
                    'service' => $shipment->serviceType?->name,
                    'status' => $shipment->status,
                    'departure' => $shipment->estimated_departure?->toDateString()
                        ?? $shipment->actual_departure?->toDateString(),
                    'eta' => $shipment->estimated_arrival?->toDateString()
                        ?? $shipment->actual_arrival?->toDateString(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function buildUtilization(ContainerAsset $asset): array
    {
        $links = $this->activeContainerLinks($asset);

        if ($links->isEmpty()) {
            return [
                'mode' => 'available',
                'used_cbm' => 0,
                'remaining_cbm' => (float) ($asset->max_capacity_cbm ?? 0),
                'used_payload_kg' => 0,
                'remaining_payload_kg' => (float) ($asset->max_payload_kg ?? 0),
                'remaining_pct' => 100,
                'lcl_rows' => [],
            ];
        }

        $firstShipment = $links->first()?->shipment;
        $serviceCode = strtolower((string) ($firstShipment?->serviceType?->code ?? ''));
        $isFcl = str_contains($serviceCode, 'fcl');

        if ($isFcl) {
            return [
                'mode' => 'fcl',
                'shipment_number' => $firstShipment?->shipment_number,
                'shipment_id' => $firstShipment?->id,
                'dedicated_message' => 'Dedicated for Shipment '.($firstShipment?->shipment_number ?? ''),
                'lcl_rows' => [],
            ];
        }

        $lclRows = [];
        $usedCbm = 0.0;
        $usedPayload = 0.0;

        foreach ($links as $link) {
            $shipment = $link->shipment;
            if (! $shipment) {
                continue;
            }

            $rowCbm = (float) $link->items()->sum('cbm');
            if ($rowCbm <= 0) {
                $rowCbm = (float) ($shipment->booking?->estimated_cbm ?? $shipment->items()->sum('cbm') ?? 0);
            }

            $rowWeight = (float) $link->items()->sum('gross_weight');
            if ($rowWeight <= 0) {
                $rowWeight = (float) ($shipment->booking?->estimated_weight ?? $shipment->items()->sum('gross_weight') ?? 0);
            }

            $usedCbm += $rowCbm;
            $usedPayload += $rowWeight;

            $lclRows[] = [
                'shipment_id' => $shipment->id,
                'shipment_number' => $shipment->shipment_number,
                'used_cbm' => round($rowCbm, 3),
                'used_weight' => round($rowWeight, 2),
            ];
        }

        $maxCbm = (float) ($asset->max_capacity_cbm ?? 0);
        $maxPayload = (float) ($asset->max_payload_kg ?? 0);

        return [
            'mode' => 'lcl',
            'used_cbm' => round($usedCbm, 3),
            'remaining_cbm' => round(max(0, $maxCbm - $usedCbm), 3),
            'used_payload_kg' => round($usedPayload, 2),
            'remaining_payload_kg' => round(max(0, $maxPayload - $usedPayload), 2),
            'remaining_pct' => $maxCbm > 0 ? round((max(0, $maxCbm - $usedCbm) / $maxCbm) * 100, 1) : 100,
            'lcl_rows' => $lclRows,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activityLog(ContainerAsset $asset): array
    {
        return AdminActivityLog::query()
            ->where('module', 'container')
            ->where('subject_type', $asset->getMorphClass())
            ->where('subject_id', $asset->id)
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn (AdminActivityLog $log) => [
                'occurred_at' => $log->occurred_at?->toIso8601String(),
                'event_key' => $log->event_key,
                'description' => $log->description,
                'user' => $log->actor?->name,
            ])
            ->all();
    }

    public function listUtilizationPct(ContainerAsset $asset): float
    {
        $util = $this->buildUtilization($asset);
        if (($util['mode'] ?? '') === 'available') {
            return 0.0;
        }
        if (($util['mode'] ?? '') === 'fcl') {
            return 100.0;
        }

        $maxCbm = (float) ($asset->max_capacity_cbm ?? 0);
        $usedCbm = (float) ($util['used_cbm'] ?? 0);

        return $maxCbm > 0 ? round(($usedCbm / $maxCbm) * 100, 1) : 0.0;
    }
}
