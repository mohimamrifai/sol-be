<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicTrackingController extends Controller
{
    /**
     * Pelacakan publik – tanpa login.
     * Cari berdasarkan waybill_number.
     */
    public function track(Request $request): JsonResponse
    {
        $request->validate([
            'waybill' => 'required|string',
        ]);

        $shipment = Shipment::where('waybill_number', $request->waybill)
            ->with([
                'originLocation:id,name,code',
                'destinationLocation:id,name,code',
                'trackings' => fn($q) => $q->orderBy('tracked_at', 'asc'),
                'trackings.photos' => fn($q) => $q->where('is_public', true),
            ])
            ->first();

        if (! $shipment) {
            return response()->json(['message' => 'Pengiriman tidak ditemukan.'], 404);
        }

        return response()->json([
            'data' => [
                'waybill_number' => $shipment->waybill_number,
                'status' => $shipment->status,
                'origin' => $shipment->originLocation,
                'destination' => $shipment->destinationLocation,
                'estimated_departure' => $shipment->estimated_departure,
                'estimated_arrival' => $shipment->estimated_arrival,
                'actual_departure' => $shipment->actual_departure,
                'actual_arrival' => $shipment->actual_arrival,
                'timeline' => $shipment->trackings->map(fn($t) => [
                    'status' => $t->status,
                    'notes' => $t->notes,
                    'location' => $t->location,
                    'tracked_at' => $t->tracked_at,
                    'photos' => $t->photos->map(fn($p) => ['path' => $p->path, 'url' => $p->url]),
                ]),
            ],
        ]);
    }

    /**
     * Download Consignment Note (CN) PDF by number (public, no login).
     */
    public function consignmentNotePdf(Request $request)
    {
        $request->validate(['waybill' => 'required|string']);

        $shipment = Shipment::where('waybill_number', $request->waybill)
            ->with([
                'originLocation', 'destinationLocation', 'serviceType', 'booking.cargoCategory',
                'items',
                'trackings' => fn ($q) => $q->orderBy('tracked_at', 'asc')
            ])
            ->first();

        if (! $shipment) {
            return response()->json(['message' => 'Pengiriman tidak ditemukan.'], 404);
        }

        $pdf = Pdf::loadView('pdf.consignment-note', ['shipment' => $shipment]);

        return $pdf->download('consignment-note-' . $shipment->waybill_number . '.pdf');
    }

    /**
     * Download Waybill PDF by waybill number (public, no login).
     */
    public function waybillPdf(Request $request)
    {
        $request->validate(['waybill' => 'required|string']);

        $shipment = Shipment::where('waybill_number', $request->waybill)
            ->with([
                'originLocation', 'destinationLocation', 'serviceType',
                'trackings' => fn ($q) => $q->orderBy('tracked_at', 'asc'),
            ])
            ->first();

        if (! $shipment) {
            return response()->json(['message' => 'Pengiriman tidak ditemukan.'], 404);
        }

        $pdf = Pdf::loadView('pdf.waybill', ['shipment' => $shipment]);

        return $pdf->download('waybill-' . $shipment->waybill_number . '.pdf');
    }
}
