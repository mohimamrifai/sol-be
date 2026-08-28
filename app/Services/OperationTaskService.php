<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OperationTaskStatus;
use App\Enums\OperationType;
use App\Enums\VendorJobOrderService;
use App\Models\OperationTask;
use App\Models\Shipment;
use App\Models\VendorJobOrder;

class OperationTaskService
{
    public function __construct(
        private AdminActivityLogger $activityLogger,
        private ProofOfDeliveryService $proofOfDeliveryService,
        private ContainerAssetService $containerAssetService,
    ) {}
    public function ensureTasksForShipment(Shipment $shipment): void
    {
        $shipment->loadMissing(['booking', 'serviceType']);

        $coverage = (string) ($shipment->shipment_coverage ?? '');
        $needsPickup = str_starts_with($coverage, 'door');
        $needsDelivery = str_ends_with($coverage, 'door');
        $needsGateOut = str_ends_with($coverage, 'door');

        $types = [
            OperationType::GateInOrigin,
            OperationType::Loading,
            OperationType::TrainDeparture,
            OperationType::TrainArrival,
        ];

        if ($needsPickup) {
            array_unshift($types, OperationType::Pickup);
        }
        if ($needsGateOut) {
            $types[] = OperationType::GateOutDestination;
        }
        if ($needsDelivery) {
            $types[] = OperationType::Delivery;
        }

        $plannedDate = $shipment->pickup_scheduled_at?->toDateString()
            ?? $shipment->estimated_departure?->toDateString()
            ?? now()->toDateString();

        foreach ($types as $type) {
            OperationTask::query()->firstOrCreate(
                [
                    'shipment_id' => $shipment->id,
                    'operation_type' => $type,
                ],
                [
                    'status' => OperationTaskStatus::Waiting,
                    'planned_date' => $plannedDate,
                ]
            );
        }

        $this->syncFromVendorJo($shipment);
    }

    public function start(OperationTask $task, ?int $actorUserId = null): OperationTask
    {
        if ($task->status === OperationTaskStatus::Completed) {
            throw new \RuntimeException('Task sudah selesai.');
        }

        $task->loadMissing('shipment');
        $this->assertTransportReadyForOperation($task);

        $task->update([
            'status' => OperationTaskStatus::InProgress,
            'metadata' => array_merge($task->metadata ?? [], [
                'started_at' => now()->toIso8601String(),
                'started_by' => $actorUserId,
            ]),
        ]);

        $this->recordOperationTracking($task, starting: true, actorUserId: $actorUserId);

        $this->activityLogger->log(
            'operation_task',
            'Operasi '.$task->operation_type?->label().' dimulai.',
            $task,
            'started',
            null,
            $actorUserId
        );

        return $task->fresh();
    }

    public function complete(OperationTask $task, ?int $actorUserId = null): OperationTask
    {
        if ($task->status === OperationTaskStatus::Completed) {
            throw new \RuntimeException('Task sudah selesai.');
        }

        $now = now();
        $task->update([
            'status' => OperationTaskStatus::Completed,
            'actual_at' => $now,
            'metadata' => array_merge($task->metadata ?? [], [
                'completed_at' => $now->toIso8601String(),
                'completed_by' => $actorUserId,
            ]),
        ]);

        $shipment = $task->shipment;
        if ($shipment) {
            if ($task->operation_type === OperationType::Delivery) {
                $this->proofOfDeliveryService->createFromDeliveryCompletion($shipment, $actorUserId);
                $shipment->update(['actual_arrival' => $now->toDateString()]);
            } else {
                $nextStatus = $this->shipmentStatusForCompletedTask($task->operation_type);
                if ($nextStatus) {
                    $updates = ['status' => $nextStatus];
                    if ($task->operation_type === OperationType::TrainDeparture) {
                        $updates['actual_departure'] = $now->toDateString();
                    }
                    if ($task->operation_type === OperationType::TrainArrival) {
                        $updates['actual_arrival'] = $now->toDateString();
                    }
                    $shipment->update($updates);
                }
            }
        }

        $this->containerAssetService->handleOperationTaskCompleted($task, $actorUserId);

        $this->recordOperationTracking($task, starting: false, actorUserId: $actorUserId);

        $this->activityLogger->log(
            'operation_task',
            'Operasi '.$task->operation_type?->label().' selesai.',
            $task,
            'completed',
            null,
            $actorUserId
        );

        return $task->fresh();
    }

    public function syncFromVendorJo(Shipment $shipment): void
    {
        $mapping = [
            VendorJobOrderService::Pickup->value => OperationType::Pickup,
            VendorJobOrderService::Delivery->value => OperationType::Delivery,
        ];

        $jobOrders = VendorJobOrder::query()
            ->where('shipment_id', $shipment->id)
            ->get();

        foreach ($jobOrders as $jo) {
            $service = $jo->service_type?->value ?? (string) $jo->service_type;
            $operationType = $mapping[$service] ?? null;
            if (! $operationType) {
                continue;
            }

            $task = OperationTask::query()->firstOrCreate(
                [
                    'shipment_id' => $shipment->id,
                    'operation_type' => $operationType,
                ],
                [
                    'status' => OperationTaskStatus::Waiting,
                    'planned_date' => $jo->pickup_date?->toDateString()
                        ?? $jo->delivery_date?->toDateString()
                        ?? now()->toDateString(),
                ]
            );

            $task->update(['vendor_job_order_id' => $jo->id]);

            $joStatus = $jo->status?->value ?? (string) $jo->status;
            $taskStatus = match ($joStatus) {
                'sent', 'in_progress' => OperationTaskStatus::InProgress,
                'completed' => OperationTaskStatus::Completed,
                'cancelled' => OperationTaskStatus::Cancelled,
                default => OperationTaskStatus::Waiting,
            };

            if ($task->status !== OperationTaskStatus::Completed) {
                $updates = ['status' => $taskStatus];
                if ($taskStatus === OperationTaskStatus::Completed) {
                    $updates['actual_at'] = $jo->completed_at ?? now();
                }
                $task->update($updates);
            }
        }
    }

    private function shipmentStatusForCompletedTask(OperationType $type): ?string
    {
        return match ($type) {
            OperationType::Pickup => 'cargo_received',
            OperationType::GateInOrigin => 'cargo_received',
            OperationType::Loading => 'stuffing_container',
            OperationType::TrainDeparture => 'train_departed',
            OperationType::TrainArrival => 'train_arrived',
            OperationType::GateOutDestination => 'ready_for_pickup',
            OperationType::Delivery => 'proof_of_delivery',
            OperationType::ProofOfDelivery => 'proof_of_delivery',
        };
    }

    private function assertTransportReadyForOperation(OperationTask $task): void
    {
        $shipment = $task->shipment;
        if (! $shipment) {
            return;
        }

        if ($task->operation_type === OperationType::Pickup) {
            if (! $shipment->pickup_vehicle_plate) {
                throw new \RuntimeException('Nomor polisi kendaraan pickup wajib diisi sebelum proses pickup dimulai.');
            }
        }

        if ($task->operation_type === OperationType::Delivery) {
            if (! $shipment->delivery_vehicle_plate) {
                throw new \RuntimeException('Nomor polisi kendaraan delivery wajib diisi sebelum proses delivery dimulai.');
            }
        }
    }

    private function recordOperationTracking(OperationTask $task, bool $starting, ?int $actorUserId): void
    {
        $shipment = $task->shipment;
        if (! $shipment || ! $task->operation_type) {
            return;
        }

        $status = $this->trackingStatusForOperation($task->operation_type, $starting);
        if (! $status) {
            return;
        }

        $shipment->trackings()->create([
            'status' => $status,
            'notes' => $this->trackingTitle($status),
            'tracked_at' => now(),
            'updated_by' => $actorUserId,
        ]);
    }

    private function trackingStatusForOperation(OperationType $type, bool $starting): ?string
    {
        return match ($type) {
            OperationType::Pickup => $starting ? 'pickup_in_progress' : 'pickup_completed',
            OperationType::GateInOrigin => $starting ? null : 'gate_in_origin',
            OperationType::Loading => $starting ? null : 'loaded_to_train',
            OperationType::TrainDeparture => $starting ? null : 'train_departed',
            OperationType::TrainArrival => $starting ? null : 'train_arrived',
            OperationType::GateOutDestination => $starting ? null : 'gate_out_destination',
            OperationType::Delivery => $starting ? 'out_for_delivery' : 'delivered',
            OperationType::ProofOfDelivery => $starting ? null : 'pod_uploaded',
            default => null,
        };
    }

    private function trackingTitle(string $status): string
    {
        $map = [
            'pickup_in_progress' => 'Pickup In Progress',
            'delivery_in_progress' => 'Delivery In Progress',
            'pickup_completed' => 'Pickup Completed',
            'arrived_origin_yard' => 'Arrived Origin Yard',
            'gate_in_origin' => 'Gate In Origin',
            'loaded_to_train' => 'Loaded to Train',
            'train_departed' => 'Train Departed',
            'train_arrived' => 'Train Arrived',
            'gate_out_destination' => 'Gate Out Destination',
            'container_released' => 'Container Released',
            'out_for_delivery' => 'Out for Delivery',
            'delivered' => 'Delivered',
            'pod_uploaded' => 'POD Uploaded',
            'completed' => 'Completed',
        ];

        return $map[$status] ?? ucwords(str_replace('_', ' ', $status));
    }
}
