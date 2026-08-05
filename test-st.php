<?php

use App\Models\ServiceType;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$st = ServiceType::all();
foreach ($st as $s) {
    echo "{$s->id} - {$s->name} - {$s->code}\n";
}
