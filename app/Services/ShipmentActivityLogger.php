<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Shipment;
use App\Models\ShipmentActivity;
use App\Models\User;

final class ShipmentActivityLogger
{
    public function log(Shipment $shipment, string $eventKey, string $description, ?User $actor = null, ?array $meta = null): void
    {
        ShipmentActivity::create([
            'shipment_id' => $shipment->id,
            'actor_user_id' => $actor?->id,
            'event_key' => $eventKey,
            'description' => $description,
            'meta' => $meta,
            'occurred_at' => now(),
        ]);
    }
}
