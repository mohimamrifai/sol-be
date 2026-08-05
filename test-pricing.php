<?php

use App\Models\Pricing;
use App\Models\VendorService;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

echo 'Vendor Services: '.VendorService::count()."\n";
echo 'Pricings: '.Pricing::count()."\n";
