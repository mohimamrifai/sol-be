<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$req = Illuminate\Http\Request::create('/api/estimate', 'POST', [
    'origin_location_id' => 1,
    'additional_services' => [1, 2]
]);

try {
    $data = $req->validate([
        'origin_location_id' => 'required',
        'additional_services' => 'nullable|array',
        'additional_services.*.id' => 'exists:additional_services,id',
    ]);
    print_r($data);
} catch (\Illuminate\Validation\ValidationException $e) {
    print_r($e->errors());
}
