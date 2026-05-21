<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = new \App\Http\Controllers\Api\Admin\MasterDataController();
$request = \Illuminate\Http\Request::create('/api/admin/master/container-types', 'GET');
$response = $controller->containerTypes($request);
echo $response->getContent();
