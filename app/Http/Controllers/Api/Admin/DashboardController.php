<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly AdminDashboardService $dashboardService) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'nullable|in:today,week,month,custom',
            'business_date' => 'nullable|date',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $fsd = $this->dashboardService->build($validated);
        $summary = $fsd['summary'];

        return response()->json([
            'data' => array_merge($fsd, [
                'pendingBookings' => [],
                'activeShipments' => [],
                'overdueInvoices' => [],
                'recentPayments' => [],
                'shipmentVolumeByWeek' => [],
                'summary' => $summary,
            ]),
        ]);
    }
}
