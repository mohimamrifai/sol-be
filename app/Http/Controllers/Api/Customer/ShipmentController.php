<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\ShipmentViewService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShipmentController extends Controller
{
    public function __construct(
        private ShipmentViewService $view
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'status' => ['nullable', 'string', Rule::in(Shipment::HIGH_LEVEL_STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'service_type' => ['nullable', 'string', Rule::in(['LCL', 'FCL'])],
            'shipment_coverage' => ['nullable', 'string', Rule::in([
                'port_to_port', 'door_to_port', 'port_to_door', 'door_to_door',
            ])],
            'origin_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'destination_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'shipment_date_from' => ['nullable', 'date'],
            'shipment_date_to' => ['nullable', 'date', 'after_or_equal:shipment_date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Shipment::with([
            'originLocation:id,name,code',
            'destinationLocation:id,name,code',
            'serviceType:id,name,code',
            'booking' => fn ($q) => $q->select('id', 'booking_number', 'shipment_coverage', 'service_type_id'),
        ])
            ->withCount('trackings')
            ->where('company_id', $user->company_id);

        if (! empty($data['status'])) {
            $map = [
                Shipment::HL_PLANNING => ['created', 'booking_created', 'survey_completed'],
                Shipment::HL_IN_PROGRESS => [
                    'cargo_received', 'stuffing_container', 'container_sealed',
                    'departed', 'train_departed', 'arrived', 'train_arrived',
                    'unloading', 'container_unloading', 'ready_for_pickup',
                ],
                Shipment::HL_COMPLETED => ['completed'],
                Shipment::HL_CANCELLED => ['cancelled'],
            ];
            $query->whereIn('status', $map[$data['status']] ?? []);
        }

        if (! empty($data['search'])) {
            $s = $data['search'];
            $query->where(function (Builder $q) use ($s) {
                $q->where('shipment_number', 'like', "%{$s}%")
                    ->orWhere('waybill_number', 'like', "%{$s}%")
                    ->orWhere('shipment_no', 'like', "%{$s}%")
                    ->orWhereHas('booking', function (Builder $bq) use ($s) {
                        $bq->where('booking_number', 'like', "%{$s}%");
                    });
            });
        }

        if (! empty($data['service_type'])) {
            $query->whereHas('booking', function (Builder $bq) use ($data) {
                $bq->whereHas('serviceType', function (Builder $sq) use ($data) {
                    $sq->where('code', $data['service_type']);
                });
            });
        }

        if (! empty($data['shipment_coverage'])) {
            $query->where('shipment_coverage', $data['shipment_coverage']);
        }

        if (! empty($data['origin_location_id'])) {
            $query->where('origin_location_id', $data['origin_location_id']);
        }

        if (! empty($data['destination_location_id'])) {
            $query->where('destination_location_id', $data['destination_location_id']);
        }

        if (! empty($data['shipment_date_from'])) {
            $query->whereDate('estimated_departure', '>=', $data['shipment_date_from']);
        }
        if (! empty($data['shipment_date_to'])) {
            $query->whereDate('estimated_departure', '<=', $data['shipment_date_to']);
        }

        $paginated = $query->orderBy('created_at', 'desc')
            ->paginate($data['per_page'] ?? 15);

        return response()->json($paginated);
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $counts = [
            Shipment::HL_PLANNING => 0,
            Shipment::HL_IN_PROGRESS => 0,
            Shipment::HL_COMPLETED => 0,
            Shipment::HL_CANCELLED => 0,
        ];

        $rows = Shipment::query()
            ->where('company_id', $user->company_id)
            ->select('status')
            ->get();

        foreach ($rows as $row) {
            $ship = new Shipment(['status' => $row->status]);
            $hl = $ship->high_level_status;
            $counts[$hl] = ($counts[$hl] ?? 0) + 1;
        }

        return response()->json(['data' => $counts]);
    }

    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        if ($shipment->company_id !== $request->user()->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $shipment->load([
            'company:id,name',
            'originLocation', 'destinationLocation', 'transportMode', 'serviceType',
            'cargoCategory', 'dgClass', 'invoice',
            'booking' => fn ($q) => $q->with([
                'serviceType', 'cargoCategory', 'dgClass', 'attachments',
                'packages.cargoCategory', 'containers.containerType', 'containers.cargoCategory', 'additionalServices',
            ]),
            'trackings' => fn ($q) => $q->orderBy('tracked_at', 'asc')->with('photos'),
            'createdByUser:id,name',
        ]);

        $payload = $shipment->toArray();
        $payload['documents'] = $this->view->documents($shipment);
        $payload['cargo'] = $this->view->cargo($shipment);
        $payload['tracking_timeline'] = $this->view->trackingTimeline($shipment);
        $payload['activity_log'] = $this->view->activityLog($shipment);

        return response()->json(['data' => $payload]);
    }

    public function downloadConsignmentNotePdf(Request $request, Shipment $shipment)
    {
        if ($shipment->company_id !== $request->user()->company_id) {
            abort(403, 'Akses ditolak.');
        }

        $shipment->load([
            'originLocation', 'destinationLocation', 'serviceType', 'booking.cargoCategory',
            'items',
            'trackings' => fn ($q) => $q->orderBy('tracked_at', 'asc'),
        ]);

        $pdf = Pdf::loadView('pdf.consignment-note', ['shipment' => $shipment]);

        return $pdf->download('consignment-note-'.$shipment->display_number.'.pdf');
    }

    public function downloadWaybillPdf(Request $request, Shipment $shipment)
    {
        if ($shipment->company_id !== $request->user()->company_id) {
            abort(403, 'Akses ditolak.');
        }

        $shipment->load([
            'originLocation', 'destinationLocation', 'serviceType',
            'trackings' => fn ($q) => $q->orderBy('tracked_at', 'asc'),
        ]);

        $pdf = Pdf::loadView('pdf.waybill', ['shipment' => $shipment]);

        return $pdf->download('waybill-'.$shipment->waybill_number.'.pdf');
    }
}
