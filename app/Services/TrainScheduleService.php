<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TrainScheduleStatus;
use App\Models\ContainerAsset;
use App\Models\Shipment;
use App\Models\TrainSchedule;
use Illuminate\Support\Facades\DB;

class TrainScheduleService
{
    public function applyStatusTransition(TrainSchedule $schedule, TrainScheduleStatus $newStatus, ?TrainScheduleStatus $previousStatus = null): void
    {
        if ($newStatus === TrainScheduleStatus::Departed) {
            $this->markDeparted($schedule);
        }

        if ($newStatus === TrainScheduleStatus::Completed) {
            $this->markCompleted($schedule);
        }
    }

    private function markDeparted(TrainSchedule $schedule): void
    {
        DB::transaction(function () use ($schedule) {
            $shipments = $schedule->shipments()->get();

            foreach ($shipments as $shipment) {
                if (! in_array($shipment->status, ['completed', 'cancelled'], true)) {
                    $shipment->update(['status' => 'train_departed']);
                }
            }

            $this->updateLinkedContainerAssets($shipments, 'in_transit');
        });
    }

    private function markCompleted(TrainSchedule $schedule): void
    {
        DB::transaction(function () use ($schedule) {
            $shipments = $schedule->shipments()->get();

            foreach ($shipments as $shipment) {
                if (! in_array($shipment->status, ['completed', 'cancelled'], true)) {
                    $shipment->update(['status' => 'train_arrived']);
                }
            }
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Shipment>  $shipments
     */
    private function updateLinkedContainerAssets($shipments, string $status): void
    {
        $assetIds = $shipments
            ->flatMap(fn (Shipment $shipment) => $shipment->containers()->pluck('container_asset_id'))
            ->filter()
            ->unique()
            ->values();

        if ($assetIds->isEmpty()) {
            return;
        }

        ContainerAsset::query()
            ->whereIn('id', $assetIds)
            ->whereNotIn('status', ['inactive', 'maintenance'])
            ->update(['status' => $status]);
    }
}
