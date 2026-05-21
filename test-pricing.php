<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Vendor Services: " . \App\Models\VendorService::count() . "\n";
echo "Pricings: " . \App\Models\Pricing::count() . "\n";
