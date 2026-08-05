<?php

use App\Models\Pricing;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$pricings = Pricing::where('vendor_service_id', 1)->get();
foreach ($pricings as $p) {
    echo "ID: {$p->id}, Type: {$p->price_type}, CT: {$p->container_type_id}\n";
}
