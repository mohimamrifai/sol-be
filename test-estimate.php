<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$svc = app(\App\Services\BookingPriceEstimateService::class);
$res = $svc->estimate([
    'origin_location_id' => 1,
    'destination_location_id' => 2,
    'transport_mode_id' => 1,
    'service_type_id' => 1,
    'company_id' => 1,
]);
print_r($res);
