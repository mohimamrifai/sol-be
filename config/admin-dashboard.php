<?php

return [
    'fsd_shipment_statuses' => [
        'planning' => ['created', 'booking_created'],
        'ready_operation' => ['survey_completed'],
        'pickup' => ['cargo_received'],
        'gate_in_origin' => ['stuffing_container'],
        'loading' => ['container_sealed'],
        'train_departure' => ['departed', 'train_departed'],
        'train_arrival' => ['arrived', 'train_arrived'],
        'gate_out_destination' => ['container_unloading', 'unloading'],
        'delivery' => ['ready_for_pickup'],
        'proof_of_delivery' => ['proof_of_delivery'],
        'completed' => ['completed'],
    ],

    'fsd_booking_statuses' => [
        'draft',
        'submitted',
        'confirmed',
        'rejected',
        'cancelled',
    ],
];
