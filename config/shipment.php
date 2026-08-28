<?php

/**
 * Shipment tracking activities (FSD §3.15).
 */
return [
    'tracking_statuses' => [
        'shipment_created',
        'pickup_in_progress',
        'delivery_in_progress',
        'pickup_completed',
        'arrived_origin_yard',
        'gate_in_origin',
        'loaded_to_train',
        'train_departed',
        'train_arrived',
        'gate_out_destination',
        'container_released',
        'out_for_delivery',
        'delivered',
        'pod_uploaded',
        'completed',
        'cancelled',
        // Legacy operational keys kept for backward compatibility
        'booking_created',
        'created',
        'survey_completed',
        'cargo_received',
        'stuffing_container',
        'container_sealed',
        'departed',
        'arrived',
        'container_unloading',
        'unloading',
        'ready_for_pickup',
        'proof_of_delivery',
    ],
    'vehicle_types' => [
        'pickup',
        'box',
        'fuso',
        'trailer',
        'wing_box',
    ],
];
