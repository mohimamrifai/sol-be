<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rack;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Ringkasan agregat untuk dashboard internal (admin).
     */
    public function index(): JsonResponse
    {
        $today = now()->toDateString();

        $bookingsToday = Booking::whereDate('created_at', $today)->count();

        $activeShipments = Shipment::whereNotIn('status', ['completed', 'cancelled'])->count();

        $overdueInvoices = Invoice::where('status', 'overdue')->count();

        $activeCompanies = Company::where('status', 'active')->count();

        $pendingCompanyApprovals = Company::where('status', 'pending')->count();

        $unpaidInvoices = Invoice::whereIn('status', ['unpaid', 'overdue'])->count();

        $paymentsToday = Payment::where('status', 'success')
            ->whereDate('paid_at', $today)
            ->count();

        $departuresToday = Shipment::where(function ($q) use ($today) {
            $q->whereDate('estimated_departure', $today)
                ->orWhereDate('actual_departure', $today);
        })->count();

        $arrivalsToday = Shipment::whereDate('actual_arrival', $today)->count();

        $pendingBookings = Booking::with([
            'company:id,name',
            'originLocation:id,name,code',
            'destinationLocation:id,name,code',
            'serviceType:id,name,code',
        ])
            ->whereIn('status', ['submitted', 'draft'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'booking_number' => $b->booking_number,
                'code' => $b->booking_number,
                'customer' => $b->company?->name,
                'route' => ($b->originLocation?->name ?? '').' → '.($b->destinationLocation?->name ?? ''),
                'serviceType' => $b->serviceType?->name ?? $b->serviceType?->code,
                'status' => $b->status,
            ]);

        $recentShipments = Shipment::with([
            'company:id,name',
            'originLocation:id,name,code',
            'destinationLocation:id,name,code',
        ])
            ->orderBy('updated_at', 'desc')
            ->limit(8)
            ->get()
            ->map(fn ($s) => [
                'id' => (string) $s->id,
                'customer' => $s->company?->name,
                'route' => ($s->originLocation?->name ?? '').' → '.($s->destinationLocation?->name ?? ''),
                'status' => $s->status,
            ]);

        $overdueInvoiceRows = Invoice::with('company:id,name')
            ->where('status', 'overdue')
            ->orderBy('due_date')
            ->limit(8)
            ->get()
            ->map(fn ($i) => [
                'number' => $i->invoice_number,
                'customer' => $i->company?->name,
                'status' => 'overdue',
                'dueDate' => $i->due_date?->toDateString(),
                'amount' => (float) $i->total_amount,
            ]);

        $recentPayments = Payment::with(['invoice.company:id,name'])
            ->whereHas('invoice')
            ->orderBy('created_at', 'desc')
            ->limit(8)
            ->get()
            ->map(function ($p) {
                return [
                    'ref' => $p->midtrans_order_id ?? (string) $p->id,
                    'customer' => $p->invoice?->company?->name ?? '',
                    'method' => $p->payment_type ?? 'midtrans',
                    'amount' => (float) $p->amount,
                    'status' => $p->status,
                ];
            });

        $shipmentVolumeByWeek = $this->shipmentVolumeByWeek();

        $rackUtilization = $this->rackUtilizationLclPercent();

        return response()->json([
            'data' => [
                'summary' => [
                    'bookingsToday' => $bookingsToday,
                    'activeShipments' => $activeShipments,
                    'rackUtilization' => $rackUtilization,
                    'overdueInvoices' => $overdueInvoices,
                    'activeCompanies' => $activeCompanies,
                    'pendingCompanyApprovals' => $pendingCompanyApprovals,
                    'unpaidInvoices' => $unpaidInvoices,
                    'paymentsToday' => $paymentsToday,
                    'departuresToday' => $departuresToday,
                    'arrivalsToday' => $arrivalsToday,
                    'activeCustomers' => $activeCompanies,
                    'newCustomersThisWeek' => Company::where('created_at', '>=', now()->subWeek())->count(),
                    'pendingProspects' => $pendingCompanyApprovals,
                ],
                'pendingBookings' => $pendingBookings,
                'activeShipments' => $recentShipments,
                'overdueInvoices' => $overdueInvoiceRows,
                'recentPayments' => $recentPayments,
                'shipmentVolumeByWeek' => $shipmentVolumeByWeek,
            ],
        ]);
    }

    /**
     * Persentase utilisasi volume rack untuk shipment LCL aktif (bukan completed/cancelled):
     * total CBM kargo di rack / total volume rack (p × l × t) dari data racks.
     * Dibulatkan 1 desimal; jika tidak ada rack berdimensi, 0.
     */
    private function rackUtilizationLclPercent(): float
    {
        $lclShipmentIds = Shipment::query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereHas('serviceType', function ($q): void {
                $q->where(function ($q2): void {
                    $q2->whereRaw('LOWER(COALESCE(code, \'\')) like ?', ['%lcl%'])
                        ->orWhereRaw('LOWER(COALESCE(name, \'\')) like ?', ['%lcl%']);
                });
            })
            ->pluck('id');

        if ($lclShipmentIds->isEmpty()) {
            return 0.0;
        }

        $racks = Rack::query()
            ->whereHas('container', fn ($q) => $q->whereIn('shipment_id', $lclShipmentIds))
            ->get();

        $totalCapacityM3 = 0.0;
        $totalUsedCbm = 0.0;

        foreach ($racks as $rack) {
            $l = (float) ($rack->length ?? 0);
            $w = (float) ($rack->width ?? 0);
            $h = (float) ($rack->height ?? 0);
            $capacity = $l * $w * $h;
            if ($capacity <= 0) {
                continue;
            }

            $used = $this->sumCbmUsedOnRack((int) $rack->id);
            $totalCapacityM3 += $capacity;
            $totalUsedCbm += $used;
        }

        if ($totalCapacityM3 <= 0) {
            return 0.0;
        }

        $pct = ($totalUsedCbm / $totalCapacityM3) * 100.0;

        return round(min(100.0, $pct), 1);
    }

    /**
     * Jumlah CBM pada rack: pakai kolom cbm; jika kosong, perkiraan dari dimensi item × quantity.
     */
    private function sumCbmUsedOnRack(int $rackId): float
    {
        $sum = 0.0;
        $items = ShipmentItem::query()->where('rack_id', $rackId)->get();

        foreach ($items as $item) {
            $cbm = $item->cbm;
            if ($cbm !== null && (float) $cbm > 0) {
                $sum += (float) $cbm;

                continue;
            }

            $l = (float) ($item->length ?? 0);
            $w = (float) ($item->width ?? 0);
            $h = (float) ($item->height ?? 0);
            if ($l > 0 && $w > 0 && $h > 0) {
                $qty = max(1, (int) $item->quantity);
                $sum += $l * $w * $h * $qty;
            }
        }

        return $sum;
    }

    /**
     * Shipment baru per minggu (4 minggu terakhir), dipisah FCL vs LCL dari kode/nama service type.
     */
    private function shipmentVolumeByWeek(): array
    {
        $series = [];

        for ($i = 3; $i >= 0; $i--) {
            $weekStart = now()->subWeeks($i)->startOfWeek();
            $weekEnd = now()->subWeeks($i)->endOfWeek();

            $shipments = Shipment::query()
                ->whereBetween('created_at', [$weekStart, $weekEnd])
                ->with('serviceType:id,code,name')
                ->get();

            $fcl = 0;
            $lcl = 0;

            foreach ($shipments as $s) {
                $code = strtoupper((string) ($s->serviceType?->code ?? ''));
                $name = strtoupper((string) ($s->serviceType?->name ?? ''));

                if (str_contains($code, 'LCL') || str_contains($name, 'LCL')) {
                    $lcl++;
                } elseif (str_contains($code, 'FCL') || str_contains($name, 'FCL')) {
                    $fcl++;
                } else {
                    $fcl++;
                }
            }

            $series[] = [
                'week' => $weekStart->format('d M').' – '.$weekEnd->format('d M'),
                'fcl' => $fcl,
                'lcl' => $lcl,
            ];
        }

        return $series;
    }
}
