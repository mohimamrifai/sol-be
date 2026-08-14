<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ContainerAsset;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReportController extends Controller
{
    use AdminReportExportHelpers;
    public function shipmentReport(Request $request): JsonResponse
    {
        $paginated = $this->shipmentReportQuery($request)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

        $paginated->getCollection()->transform(fn (Shipment $s) => $this->transformShipmentRow($s));

        return response()->json($paginated);
    }

    public function shipmentReportExport(Request $request)
    {
        $rows = $this->shipmentReportQuery($request)->orderByDesc('created_at')->get();
        $headers = [
            'Shipment No', 'CN No', 'Customer', 'Route', 'Service Type', 'Coverage', 'Container', 'Status',
            'Pickup Date', 'Departure', 'Arrival', 'Completion',
        ];

        return $this->exportByFormat(
            $request,
            'shipment-report.csv',
            $headers,
            $rows->map(fn (Shipment $s) => $this->shipmentCsvRow($s)),
            'Shipment Report'
        );
    }

    public function bookingReport(Request $request): JsonResponse
    {
        $paginated = $this->bookingReportQuery($request)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

        $paginated->getCollection()->transform(fn (Booking $b) => [
            'id' => $b->id,
            'booking_number' => $b->booking_number,
            'customer' => $b->company?->name,
            'route' => ($b->originLocation?->code ?? $b->originLocation?->name).' → '.($b->destinationLocation?->code ?? $b->destinationLocation?->name),
            'service_type' => $b->serviceType?->name,
            'shipment_coverage' => $b->shipment_coverage,
            'status' => $b->status,
            'created_at' => $b->created_at?->toDateString(),
        ]);

        return response()->json($paginated);
    }

    public function bookingReportExport(Request $request)
    {
        $rows = $this->bookingReportQuery($request)->orderByDesc('created_at')->get();
        $headers = ['Booking No', 'Customer', 'Route', 'Service Type', 'Coverage', 'Status', 'Created Date'];

        return $this->exportByFormat(
            $request,
            'booking-report.csv',
            $headers,
            $rows->map(fn (Booking $b) => [
                $b->booking_number,
                $b->company?->name,
                ($b->originLocation?->code ?? $b->originLocation?->name).' → '.($b->destinationLocation?->code ?? $b->destinationLocation?->name),
                $b->serviceType?->name,
                $b->shipment_coverage,
                $b->status,
                $b->created_at?->toDateString(),
            ]),
            'Booking Report'
        );
    }

    public function customerInvoiceReport(Request $request): JsonResponse
    {
        $paginated = $this->invoiceReportQuery($request)
            ->orderByDesc('issued_date')
            ->paginate($request->integer('per_page', 50));

        $paginated->getCollection()->transform(fn (Invoice $inv) => [
            'id' => $inv->id,
            'invoice_number' => $inv->invoice_number,
            'customer' => $inv->company?->name,
            'shipment_number' => $inv->shipment?->shipment_number,
            'issued_date' => $inv->issued_date?->toDateString(),
            'due_date' => $inv->due_date?->toDateString(),
            'total_amount' => $inv->total_amount,
            'status' => $inv->status,
        ]);

        return response()->json($paginated);
    }

    public function customerInvoiceReportExport(Request $request)
    {
        $rows = $this->invoiceReportQuery($request)->orderByDesc('issued_date')->get();
        $headers = ['Invoice No', 'Customer', 'Shipment', 'Issued Date', 'Due Date', 'Amount', 'Status'];

        return $this->exportByFormat(
            $request,
            'customer-invoice-report.csv',
            $headers,
            $rows->map(fn (Invoice $inv) => [
                $inv->invoice_number,
                $inv->company?->name,
                $inv->shipment?->shipment_number,
                $inv->issued_date?->toDateString(),
                $inv->due_date?->toDateString(),
                $inv->total_amount,
                $inv->status,
            ]),
            'Customer Invoice Report'
        );
    }

    public function customerPaymentReport(Request $request): JsonResponse
    {
        $paginated = $this->paymentReportQuery($request)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

        $paginated->getCollection()->transform(fn (Payment $p) => [
            'id' => $p->id,
            'payment_number' => $p->payment_number,
            'customer' => $p->invoice?->company?->name,
            'invoice_number' => $p->invoice?->invoice_number,
            'amount' => $p->amount,
            'method' => $p->method ?? $p->payment_type,
            'status' => $p->status,
            'paid_at' => $p->paid_at?->toDateString(),
        ]);

        return response()->json($paginated);
    }

    public function customerPaymentReportExport(Request $request)
    {
        $rows = $this->paymentReportQuery($request)->orderByDesc('created_at')->get();
        $headers = ['Payment No', 'Customer', 'Invoice', 'Amount', 'Method', 'Status', 'Paid Date'];

        return $this->exportByFormat(
            $request,
            'customer-payment-report.csv',
            $headers,
            $rows->map(fn (Payment $p) => [
                $p->payment_number,
                $p->invoice?->company?->name,
                $p->invoice?->invoice_number,
                $p->amount,
                $p->method ?? $p->payment_type,
                $p->status,
                $p->paid_at?->toDateString(),
            ]),
            'Customer Payment Report'
        );
    }

    public function containerReport(Request $request): JsonResponse
    {
        $paginated = $this->containerReportQuery($request)
            ->orderBy('container_number')
            ->paginate($request->integer('per_page', 50));

        $paginated->getCollection()->transform(fn (ContainerAsset $c) => [
            'id' => $c->id,
            'container_number' => $c->container_number,
            'container_type' => $c->containerType?->name,
            'ownership' => $c->ownership,
            'vendor' => $c->vendor?->name,
            'current_yard' => $c->currentYard?->name,
            'status' => $c->status,
            'manufacture_year' => $c->manufacture_year,
        ]);

        return response()->json($paginated);
    }

    public function containerReportExport(Request $request)
    {
        $rows = $this->containerReportQuery($request)->orderBy('container_number')->get();
        $headers = ['Container No', 'Type', 'Ownership', 'Vendor', 'Current Yard', 'Status', 'Manufacture Year'];

        return $this->exportByFormat(
            $request,
            'container-report.csv',
            $headers,
            $rows->map(fn (ContainerAsset $c) => [
                $c->container_number,
                $c->containerType?->name,
                $c->ownership,
                $c->vendor?->name,
                $c->currentYard?->name,
                $c->status,
                $c->manufacture_year,
            ]),
            'Container Report'
        );
    }

    private function shipmentReportQuery(Request $request)
    {
        $query = Shipment::query()->with([
            'company:id,name',
            'originLocation:id,code,name',
            'destinationLocation:id,code,name',
            'serviceType:id,name',
            'containers:id,shipment_id,container_number',
        ]);

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('service_type_id')) {
            $query->where('service_type_id', $request->service_type_id);
        }
        if ($request->filled('shipment_coverage')) {
            $query->where('shipment_coverage', $request->shipment_coverage);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return $query;
    }

    private function bookingReportQuery(Request $request)
    {
        $query = Booking::query()->with(['company:id,name', 'originLocation:id,code,name', 'destinationLocation:id,code,name', 'serviceType:id,name']);

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return $query;
    }

    private function invoiceReportQuery(Request $request)
    {
        $query = Invoice::query()->with(['company:id,name', 'shipment:id,shipment_number']);

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('issued_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('issued_date', '<=', $request->date_to);
        }

        return $query;
    }

    private function paymentReportQuery(Request $request)
    {
        $query = Payment::query()->with(['invoice.company:id,name']);

        if ($request->filled('company_id')) {
            $query->whereHas('invoice', fn ($q) => $q->where('company_id', $request->company_id));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('paid_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('paid_at', '<=', $request->date_to);
        }

        return $query;
    }

    private function containerReportQuery(Request $request)
    {
        $query = ContainerAsset::query()->with(['containerType:id,name', 'vendor:id,name', 'currentYard:id,name']);

        if ($request->filled('ownership')) {
            $query->where('ownership', $request->ownership);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('container_type_id')) {
            $query->where('container_type_id', $request->container_type_id);
        }

        return $query;
    }

    private function transformShipmentRow(Shipment $s): array
    {
        return [
            'id' => $s->id,
            'shipment_number' => $s->shipment_number,
            'waybill_number' => $s->waybill_number,
            'customer' => $s->company?->name,
            'route' => ($s->originLocation?->code ?? $s->originLocation?->name).' → '.($s->destinationLocation?->code ?? $s->destinationLocation?->name),
            'service_type' => $s->serviceType?->name,
            'shipment_coverage' => $s->shipment_coverage,
            'containers' => $s->containers->pluck('container_number')->filter()->join(', '),
            'status' => $s->status,
            'pickup_date' => $s->pickup_scheduled_at?->toDateString(),
            'departure_date' => $s->actual_departure?->toDateString() ?? $s->estimated_departure?->toDateString(),
            'arrival_date' => $s->actual_arrival?->toDateString() ?? $s->estimated_arrival?->toDateString(),
            'completion_date' => $s->status === 'completed' ? $s->updated_at?->toDateString() : null,
        ];
    }

    private function shipmentCsvRow(Shipment $s): array
    {
        $row = $this->transformShipmentRow($s);

        return [
            $row['shipment_number'],
            $row['waybill_number'],
            $row['customer'],
            $row['route'],
            $row['service_type'],
            $row['shipment_coverage'],
            $row['containers'],
            $row['status'],
            $row['pickup_date'],
            $row['departure_date'],
            $row['arrival_date'],
            $row['completion_date'],
        ];
    }
}
