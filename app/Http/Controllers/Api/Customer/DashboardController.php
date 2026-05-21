<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Ringkasan data untuk halaman Dashboard Customer Portal (sesuai brief).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        if (! $companyId) {
            return response()->json([
                'data' => [
                    'bookings' => ['total' => 0, 'by_status' => [], 'submitted' => 0],
                    'shipments' => ['total' => 0, 'active' => 0],
                    'invoices' => ['total' => 0, 'unpaid' => 0, 'overdue' => 0],
                ],
            ]);
        }

        $bookings = Booking::where('company_id', $companyId)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $shipmentsTotal = Shipment::where('company_id', $companyId)->count();
        $shipmentsActive = Shipment::where('company_id', $companyId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        $invoicesTotal = Invoice::where('company_id', $companyId)->count();
        $invoicesUnpaid = Invoice::where('company_id', $companyId)->where('status', 'unpaid')->count();
        $invoicesOverdue = Invoice::where('company_id', $companyId)->where('status', 'overdue')->count();

        return response()->json([
            'data' => [
                'bookings' => [
                    'total' => $bookings->sum(),
                    'by_status' => $bookings->all(),
                    'submitted' => (int) ($bookings['submitted'] ?? 0),
                ],
                'shipments' => [
                    'total' => $shipmentsTotal,
                    'active' => $shipmentsActive,
                ],
                'invoices' => [
                    'total' => $invoicesTotal,
                    'unpaid' => $invoicesUnpaid,
                    'overdue' => $invoicesOverdue,
                ],
            ],
        ]);
    }
}
