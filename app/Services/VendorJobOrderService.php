<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VendorJobOrderService as VendorJobOrderServiceType;
use App\Enums\VendorJobOrderStatus;
use App\Enums\VendorJobStatus;
use App\Models\Pricing;
use App\Models\Shipment;
use App\Models\Vendor;
use App\Models\VendorJobOrder;
use App\Models\VendorJobOrderActivity;
use Illuminate\Support\Facades\DB;

class VendorJobOrderService
{
    public function syncFromShipment(Shipment $shipment, ?int $actorUserId = null): void
    {
        $shipment->loadMissing([
            'booking', 'company', 'originLocation', 'destinationLocation',
            'pickupVendor.contacts', 'deliveryVendor.contacts', 'railVendor.contacts', 'train',
            'originYard', 'destinationYard', 'containers.containerType',
        ]);

        if ($shipment->pickup_vendor_id) {
            $this->upsertJobOrder($shipment, VendorJobOrderServiceType::Pickup, (int) $shipment->pickup_vendor_id, $actorUserId);
        }

        if ($shipment->delivery_vendor_id) {
            $this->upsertJobOrder($shipment, VendorJobOrderServiceType::Delivery, (int) $shipment->delivery_vendor_id, $actorUserId);
        }

        if ($shipment->rail_vendor_id && $shipment->train_id) {
            $this->upsertJobOrder($shipment, VendorJobOrderServiceType::Rail, (int) $shipment->rail_vendor_id, $actorUserId);
        }

        $this->syncStatusFromShipment($shipment->fresh(), $actorUserId);
    }

    public function syncStatusFromShipment(Shipment $shipment, ?int $actorUserId = null): void
    {
        $vendorStatus = $shipment->vendor_status;
        if (! $vendorStatus) {
            return;
        }

        $targetStatus = match ($vendorStatus) {
            VendorJobStatus::PendingAcceptance->value => VendorJobOrderStatus::Sent,
            VendorJobStatus::Accepted->value => VendorJobOrderStatus::Sent,
            VendorJobStatus::InProgress->value => VendorJobOrderStatus::InProgress,
            VendorJobStatus::WaitingVerification->value => VendorJobOrderStatus::InProgress,
            VendorJobStatus::Completed->value => VendorJobOrderStatus::Completed,
            default => null,
        };

        if (! $targetStatus) {
            return;
        }

        $jobOrders = VendorJobOrder::query()
            ->where('shipment_id', $shipment->id)
            ->whereNotIn('status', [VendorJobOrderStatus::Completed, VendorJobOrderStatus::Cancelled])
            ->get();

        foreach ($jobOrders as $jo) {
            if ($jo->status === $targetStatus) {
                continue;
            }

            $updates = ['status' => $targetStatus];
            if ($targetStatus === VendorJobOrderStatus::Sent && ! $jo->sent_at) {
                $updates['sent_at'] = now();
            }
            if ($targetStatus === VendorJobOrderStatus::Completed) {
                $updates['completed_at'] = $shipment->completion_verified_at ?? now();
            }

            $jo->update($updates);
            $this->logActivity($jo, 'Status menjadi '.$targetStatus->label().'.', $actorUserId);
        }
    }

    public function sendJobOrder(VendorJobOrder $jo, ?int $actorUserId = null): void
    {
        if ($jo->status !== VendorJobOrderStatus::Draft) {
            return;
        }

        $jo->update([
            'status' => VendorJobOrderStatus::Sent,
            'sent_at' => now(),
        ]);
        $this->logActivity($jo, 'JO dikirim ke vendor.', $actorUserId);
    }

    public function verifyCompletion(VendorJobOrder $jo, ?int $actorUserId = null): void
    {
        if (! $jo->isEditable()) {
            throw new \RuntimeException('Job Order tidak dapat diverifikasi.');
        }

        $jo->update([
            'status' => VendorJobOrderStatus::Completed,
            'completed_at' => now(),
        ]);
        $this->logActivity($jo, 'Status menjadi Completed.', $actorUserId);
    }

    private function upsertJobOrder(
        Shipment $shipment,
        VendorJobOrderServiceType $service,
        int $vendorId,
        ?int $actorUserId
    ): void {
        $serviceValue = $service->value;
        $existing = VendorJobOrder::query()
            ->where('shipment_id', $shipment->id)
            ->where('vendor_id', $vendorId)
            ->where('service_type', $serviceValue)
            ->first();

        if ($existing && in_array($existing->status, [VendorJobOrderStatus::Completed, VendorJobOrderStatus::Cancelled], true)) {
            return;
        }

        $vendor = Vendor::with('contacts')->find($vendorId);
        if (! $vendor || ! $vendor->is_active) {
            return;
        }

        if ($existing) {
            if ((int) $existing->vendor_id !== $vendorId) {
                return;
            }

            return;
        }

        $pricing = $this->resolvePricing($vendor, $shipment, $service);
        $vendorRate = $pricing ? (float) ($pricing->unit_price ?? $pricing->displayUnitPrice() ?? 0) : 0;
        $primaryContact = $vendor->contacts->firstWhere('is_primary', true) ?? $vendor->contacts->first();

        $payload = [
            'vendor_id' => $vendorId,
            'service_type' => $serviceValue,
            'status' => VendorJobOrderStatus::Draft,
            'pricing_id' => $pricing?->id,
            'vendor_rate' => $vendorRate,
            'additional_cost' => 0,
            'vendor_snapshot' => [
                'name' => $vendor->name,
                'code' => $vendor->code,
                'pic_name' => $primaryContact?->name,
                'pic_mobile' => $primaryContact?->mobile,
            ],
            'shipment_snapshot' => $this->buildShipmentSnapshot($shipment),
            'origin_yard_id' => $shipment->origin_yard_id,
            'destination_yard_id' => $shipment->destination_yard_id,
            'train_id' => $shipment->train_id,
            'departure_at' => $shipment->estimated_departure,
            'vehicle_type' => match ($service) {
                VendorJobOrderServiceType::Pickup => $shipment->pickup_vehicle_type,
                VendorJobOrderServiceType::Delivery => $shipment->delivery_vehicle_type,
                default => null,
            },
            'vehicle_plate' => match ($service) {
                VendorJobOrderServiceType::Pickup => $shipment->pickup_vehicle_plate,
                VendorJobOrderServiceType::Delivery => $shipment->delivery_vehicle_plate,
                default => null,
            },
            'driver_name' => match ($service) {
                VendorJobOrderServiceType::Pickup => $shipment->pickup_driver_name,
                VendorJobOrderServiceType::Delivery => $shipment->delivery_driver_name,
                default => null,
            },
            'driver_mobile' => match ($service) {
                VendorJobOrderServiceType::Pickup => $shipment->pickup_driver_mobile,
                VendorJobOrderServiceType::Delivery => $shipment->delivery_driver_mobile,
                default => null,
            },
            'pickup_address' => $service === VendorJobOrderServiceType::Pickup ? ($shipment->booking?->shipper_address) : null,
            'pickup_date' => $service === VendorJobOrderServiceType::Pickup ? $shipment->pickup_scheduled_at : null,
            'pickup_remark' => $service === VendorJobOrderServiceType::Pickup ? $shipment->pickup_remark : null,
            'delivery_address' => $service === VendorJobOrderServiceType::Delivery ? ($shipment->booking?->consignee_address) : null,
            'delivery_date' => $service === VendorJobOrderServiceType::Delivery ? $shipment->delivery_scheduled_at : null,
            'delivery_remark' => $service === VendorJobOrderServiceType::Delivery ? $shipment->delivery_remark : null,
            'pickup_cargo_info' => $service === VendorJobOrderServiceType::Pickup ? $shipment->notes : null,
            'delivery_cargo_info' => $service === VendorJobOrderServiceType::Delivery ? $shipment->notes : null,
            'created_by' => $actorUserId,
        ];

        DB::transaction(function () use ($payload, $shipment, $actorUserId) {
            $jo = VendorJobOrder::create(array_merge($payload, ['shipment_id' => $shipment->id]));
            $this->logActivity($jo, 'JO dibuat.', $actorUserId);
            $this->logActivity($jo, 'Vendor diassign.', $actorUserId);
        });
    }

    private function resolvePricing(Vendor $vendor, Shipment $shipment, VendorJobOrderServiceType $service): ?Pricing
    {
        $category = match ($service) {
            VendorJobOrderServiceType::Pickup => 'trucking_pickup',
            VendorJobOrderServiceType::Delivery => 'trucking_delivery',
            VendorJobOrderServiceType::Rail => 'rail',
        };

        $vehicleType = match ($service) {
            VendorJobOrderServiceType::Pickup => $shipment->pickup_vehicle_type,
            VendorJobOrderServiceType::Delivery => $shipment->delivery_vehicle_type,
            default => null,
        };

        $containerTypeId = $shipment->containers->first()?->container_type_id;

        $query = Pricing::query()
            ->where('is_active', true)
            ->where('service_category', $category)
            ->whereHas('vendorService', function ($q) use ($vendor, $shipment) {
                $q->where('vendor_id', $vendor->id)
                    ->where('origin_location_id', $shipment->origin_location_id)
                    ->where('destination_location_id', $shipment->destination_location_id);
            });

        if ($vehicleType) {
            $query->where(function ($q) use ($vehicleType) {
                $q->where('vehicle_type', $vehicleType)->orWhereNull('vehicle_type');
            })->orderByRaw('CASE WHEN vehicle_type = ? THEN 0 ELSE 1 END', [$vehicleType]);
        }

        if ($containerTypeId && in_array($category, ['rail', 'container_rental'], true)) {
            $query->where(function ($q) use ($containerTypeId) {
                $q->where('container_type_id', $containerTypeId)->orWhereNull('container_type_id');
            })->orderByRaw('CASE WHEN container_type_id = ? THEN 0 ELSE 1 END', [$containerTypeId]);
        }

        return $query->orderByDesc('id')->first();
    }

    private function buildShipmentSnapshot(Shipment $shipment): array
    {
        return [
            'shipment_number' => $shipment->shipment_number,
            'waybill_number' => $shipment->waybill_number,
            'customer' => $shipment->company?->name,
            'origin' => $shipment->originLocation?->code ?? $shipment->originLocation?->name,
            'destination' => $shipment->destinationLocation?->code ?? $shipment->destinationLocation?->name,
            'shipment_coverage' => $shipment->shipment_coverage,
        ];
    }

    public function logActivity(VendorJobOrder $jo, string $activity, ?int $userId): void
    {
        VendorJobOrderActivity::create([
            'vendor_job_order_id' => $jo->id,
            'user_id' => $userId,
            'activity' => $activity,
        ]);
    }
}
