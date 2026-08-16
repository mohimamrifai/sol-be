<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Shipment;
use App\Models\VendorJobOrder;

class VendorShipmentHelper
{
    public static function joNumber(Shipment $shipment): string
    {
        if ($shipment->relationLoaded('adminVendorJobOrders')) {
            $vjo = $shipment->adminVendorJobOrders->first();
        } else {
            $vjo = VendorJobOrder::query()
                ->where('shipment_id', $shipment->id)
                ->orderByDesc('id')
                ->first();
        }

        if ($vjo?->job_order_number) {
            return $vjo->job_order_number;
        }

        return 'JO-'.str_pad((string) $shipment->id, 5, '0', STR_PAD_LEFT);
    }
}
