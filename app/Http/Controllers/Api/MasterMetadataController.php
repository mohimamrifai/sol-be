<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Public, cacheable, dropdown-option endpoints.
 *
 * Frontend registration form calls these to render Business Entity,
 * Business Category, etc.  They are intentionally outside /api/admin/*
 * because they are not gated by authentication.
 *
 * Constants live in this controller (not in a DB table) because the
 * allowed values are part of the business contract, not data.
 */
class MasterMetadataController extends Controller
{
    /**
     * GET /api/public/master/business-entity-types
     *
     * Returns the list of selectable Business Entity values for the
     * registration form, including the UI sentinel "Lainnya" which
     * reveals a free-text "Jelaskan bentuk usaha" input.
     */
    public function businessEntityTypes(): JsonResponse
    {
        return response()->json([
            'data' => [
                ['value' => 'PT', 'label' => 'PT'],
                ['value' => 'CV', 'label' => 'CV'],
                ['value' => 'UD', 'label' => 'UD'],
                ['value' => 'Koperasi', 'label' => 'Koperasi'],
                ['value' => 'Yayasan', 'label' => 'Yayasan'],
                ['value' => 'Firma', 'label' => 'Firma'],
                ['value' => 'Perorangan', 'label' => 'Perorangan'],
                ['value' => 'Lainnya', 'label' => 'Lainnya'],
            ],
        ]);
    }

    /**
     * GET /api/public/master/business-categories
     *
     * Returns the list of selectable Business Category values.
     * `others` is a UI sentinel that reveals a free-text input.
     */
    public function businessCategories(): JsonResponse
    {
        return response()->json([
            'data' => [
                ['value' => 'trading', 'label' => 'Trading'],
                ['value' => 'manufacturing', 'label' => 'Manufacturing'],
                ['value' => 'retail', 'label' => 'Retail'],
                ['value' => 'distributor', 'label' => 'Distributor'],
                ['value' => 'e_commerce', 'label' => 'E-Commerce'],
                ['value' => 'logistics', 'label' => 'Logistics'],
                ['value' => 'others', 'label' => 'Others'],
            ],
        ]);
    }

    /**
     * GET /api/public/master/monthly-shipment-estimates
     *
     * Section 3 of the registration form. Returns the range buckets
     * the customer picks to describe their expected monthly volume.
     */
    public function monthlyShipmentEstimates(): JsonResponse
    {
        return response()->json([
            'data' => [
                ['value' => '<10', 'label' => '<10 shipment / bulan'],
                ['value' => '10-50', 'label' => '10–50 shipment / bulan'],
                ['value' => '50-100', 'label' => '50–100 shipment / bulan'],
                ['value' => '>100', 'label' => '>100 shipment / bulan'],
            ],
        ]);
    }
}
