<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$req = Request::create('/api/estimate', 'POST', [
    'origin_location_id' => 1,
    'additional_services' => [1, 2],
]);

try {
    $data = $req->validate([
        'origin_location_id' => 'required',
        'additional_services' => 'nullable|array',
        'additional_services.*.id' => 'exists:additional_services,id',
    ]);
    print_r($data);
} catch (ValidationException $e) {
    print_r($e->errors());
}
