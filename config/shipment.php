<?php

/**
 * Daftar status tracking shipment (sesuai brief 4.h).
 * Digunakan untuk validasi saat admin menambah/update tracking.
 */
return [
    'tracking_statuses' => [
        'booking_created',
        'survey_completed',
        'cargo_received',
        'stuffing_container',
        'container_sealed',
        'train_departed',
        'train_arrived',
        'container_unloading',
        'ready_for_pickup',
        'completed',
    ],
];
