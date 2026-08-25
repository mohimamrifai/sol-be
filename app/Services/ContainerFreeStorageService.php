<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContainerAsset;
use App\Models\ContainerMovement;
use App\Models\Shipment;
use App\Models\ShipmentTracking;
use App\Support\SystemConfig;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ContainerFreeStorageService
{
    /** @var list<string> */
    private const ORIGIN_STATUSES = ['cargo_received', 'stuffing_container', 'container_sealed'];

    /** @var list<string> */
    private const DESTINATION_STATUSES = [
        'arrived', 'train_arrived', 'container_unloading', 'unloading', 'ready_for_pickup',
    ];

    /**
     * @return list<int>
     */
    public function exceededAssetIds(Carbon $asOf): array
    {
        return $this->exceededAssets($asOf)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function exceededCount(Carbon $asOf): int
    {
        return $this->exceededAssets($asOf)->count();
    }

    /**
     * @return Collection<int, ContainerAsset>
     */
    public function exceededAssets(Carbon $asOf): Collection
    {
        $asOf = $asOf->copy()->startOfDay();
        $exceeded = collect();

        $assets = ContainerAsset::query()
            ->whereNotNull('current_yard_id')
            ->whereIn('status', ['available', 'reserved'])
            ->with([
                'shipmentContainers.shipment.serviceType:id,code',
            ])
            ->get();

        foreach ($assets as $asset) {
            $shipment = $asset->activeShipmentContainer()?->shipment;
            if (! $shipment) {
                continue;
            }

            $status = strtolower((string) $shipment->status);
            $leg = null;
            if (in_array($status, self::ORIGIN_STATUSES, true)) {
                $leg = 'origin';
            } elseif (in_array($status, self::DESTINATION_STATUSES, true)) {
                $leg = 'destination';
            }

            if ($leg === null) {
                continue;
            }

            $freeDays = $this->freeStorageDaysForShipment($shipment, $leg);
            if ($freeDays <= 0) {
                continue;
            }

            $enteredAt = $this->resolveStorageStartAt($asset, $shipment, $leg);
            if ($enteredAt === null) {
                continue;
            }

            $storedDays = $enteredAt->copy()->startOfDay()->diffInDays($asOf);
            if ($storedDays > $freeDays) {
                $exceeded->push($asset);
            }
        }

        return $exceeded->values();
    }

    private function freeStorageDaysForShipment(Shipment $shipment, string $leg): int
    {
        $stored = $leg === 'origin'
            ? $shipment->free_storage_origin_days
            : $shipment->free_storage_destination_days;

        if ($stored !== null) {
            return max(0, (int) $stored);
        }

        $defaults = SystemConfig::defaultFreeStorageDays($shipment->serviceType?->code);

        return max(0, (int) ($defaults[$leg] ?? 0));
    }

    private function resolveStorageStartAt(ContainerAsset $asset, Shipment $shipment, string $leg): ?Carbon
    {
        $movement = ContainerMovement::query()
            ->where('container_asset_id', $asset->id)
            ->where('yard_id', $asset->current_yard_id)
            ->orderByDesc('occurred_at')
            ->value('occurred_at');

        if ($movement) {
            return Carbon::parse($movement);
        }

        $trackingStatuses = $leg === 'origin'
            ? ['cargo_received', 'stuffing_container']
            : ['train_arrived', 'arrived', 'container_unloading', 'unloading'];

        $trackedAt = ShipmentTracking::query()
            ->where('shipment_id', $shipment->id)
            ->whereIn('status', $trackingStatuses)
            ->orderBy('tracked_at')
            ->value('tracked_at');

        return $trackedAt ? Carbon::parse($trackedAt) : null;
    }
}
