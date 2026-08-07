<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\ServiceType;
use App\Models\TransportMode;
use Illuminate\Http\JsonResponse;

class MasterDataReadController extends Controller
{
    public function serviceTypes(): JsonResponse
    {
        $items = ServiceType::orderBy('name')->get(['id', 'code', 'name']);

        return response()->json(['data' => $items]);
    }

    public function transportModes(): JsonResponse
    {
        $items = TransportMode::orderBy('name')->get(['id', 'code', 'name']);

        return response()->json(['data' => $items]);
    }
}
