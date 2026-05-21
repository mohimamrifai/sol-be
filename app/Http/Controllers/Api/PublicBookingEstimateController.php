<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BookingPriceEstimateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estimasi harga booking untuk pengunjung (tanpa login).
 * Diskon perusahaan tidak diterapkan (company_id null).
 */
class PublicBookingEstimateController extends Controller
{
    public function __construct(
        private BookingPriceEstimateService $priceEstimateService
    ) {}

    public function estimate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'origin_location_id' => 'required|exists:locations,id',
            'destination_location_id' => 'required|exists:locations,id',
            'transport_mode_id' => 'required|exists:transport_modes,id',
            'service_type_id' => 'required|exists:service_types,id',
            'cargo_category_id' => 'nullable|exists:cargo_categories,id',
            'container_type_id' => 'nullable|exists:container_types,id',
            'container_count' => 'nullable|integer|min:1',
            'estimated_weight' => 'nullable|numeric|min:0',
            'estimated_cbm' => 'nullable|numeric|min:0',
            'additional_services' => 'nullable|array',
            'additional_services.*.id' => 'exists:additional_services,id',
            'is_dangerous_goods' => 'nullable|boolean',
            'dg_class_id' => 'nullable|exists:dg_classes,id',
            'un_number' => 'nullable|string|max:50',
            'equipment_condition' => 'nullable|in:CLEAN,RESIDUAL',
            'temperature' => 'nullable|numeric',
        ]);

        $params = [
            ...$data,
            'additional_services' => array_column($data['additional_services'] ?? [], 'id'),
            'company_id' => null,
        ];

        $result = $this->priceEstimateService->estimate($params);

        return response()->json(['data' => $result]);
    }
}
