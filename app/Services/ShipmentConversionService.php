<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Booking;
use App\Models\Container;
use App\Models\Shipment;
use App\Models\ShipmentItem;

final class ShipmentConversionService
{
    public function copyCargoFromBooking(Shipment $shipment, Booking $booking): void
    {
        $booking->loadMissing(['packages', 'containers']);

        foreach ($booking->containers as $bookingContainer) {
            $qty = max(1, (int) ($bookingContainer->quantity ?? 1));
            for ($i = 0; $i < $qty; $i++) {
                Container::create([
                    'shipment_id' => $shipment->id,
                    'container_type_id' => $bookingContainer->container_type_id,
                    'container_number' => $bookingContainer->container_number,
                    'seal_number' => $bookingContainer->seal_number,
                    'slot_sequence' => $i + 1,
                    'ownership' => strtoupper((string) ($booking->container_responsibility ?? '')) === 'SOC' ? 'customer' : null,
                    'assignment_status' => $bookingContainer->container_number ? 'assigned' : 'waiting',
                ]);
            }
        }

        foreach ($booking->packages as $pkg) {
            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'name' => $pkg->description ?? 'Package #'.$pkg->sequence,
                'description' => $pkg->remark,
                'quantity' => (int) ($pkg->piece_count ?? 1),
                'gross_weight' => $pkg->weight_kg,
                'length' => $pkg->length,
                'width' => $pkg->width,
                'height' => $pkg->height,
                'cbm' => $pkg->volume_cbm,
                'placement_type' => 'floor',
            ]);
        }

        if ($booking->packages->isEmpty() && $booking->containers->isEmpty() && $booking->cargo_description) {
            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'name' => $booking->cargo_description,
                'description' => $booking->cargo_description,
                'quantity' => 1,
                'gross_weight' => $booking->estimated_weight,
                'cbm' => $booking->estimated_cbm,
                'placement_type' => 'floor',
            ]);
        }
    }
}
