<?php

declare(strict_types=1);

namespace App\Enums;

enum OperationType: string
{
    case Pickup = 'pickup';
    case GateInOrigin = 'gate_in_origin';
    case Loading = 'loading';
    case TrainDeparture = 'train_departure';
    case TrainArrival = 'train_arrival';
    case GateOutDestination = 'gate_out_destination';
    case Delivery = 'delivery';
    case ProofOfDelivery = 'proof_of_delivery';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Pickup',
            self::GateInOrigin => 'Gate In Origin',
            self::Loading => 'Loading',
            self::TrainDeparture => 'Train Departure',
            self::TrainArrival => 'Train Arrival',
            self::GateOutDestination => 'Gate Out Destination',
            self::Delivery => 'Delivery',
            self::ProofOfDelivery => 'Proof of Delivery',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
