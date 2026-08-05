<?php

use App\Http\Controllers\Api\Admin\MasterDataController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$controller = new MasterDataController;
$request = Request::create('/api/admin/master/container-types', 'GET');
$response = $controller->containerTypes($request);
echo $response->getContent();
