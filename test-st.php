<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$st = \App\Models\ServiceType::all();
foreach ($st as $s) {
    echo "{$s->id} - {$s->name} - {$s->code}\n";
}
