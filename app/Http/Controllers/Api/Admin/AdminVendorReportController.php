<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\VendorInvoice;
use App\Models\VendorPaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminVendorReportController extends Controller
{
    use AdminReportExportHelpers;
    public function invoiceIndex(Request $request): JsonResponse
    {
        $query = $this->invoiceReportQuery($request);
        $paginated = $query->orderByDesc('invoice_date')->paginate($request->integer('per_page', 10));

        $paginated->getCollection()->transform(fn (VendorInvoice $inv) => [
            'id' => $inv->id,
            'vendor' => $inv->vendor?->name,
            'vendor_id' => $inv->vendor_id,
            'vendor_invoice_no' => $inv->vendor_external_number ?? $inv->invoice_number,
            'job_orders' => $inv->jobOrders->pluck('job_order_number')->join(', '),
            'invoice_date' => $inv->invoice_date?->toDateString(),
            'amount' => $inv->total_amount,
            'status' => $inv->getRawOriginal('status') ?? $inv->status,
        ]);

        return response()->json($paginated);
    }

    public function invoiceExport(Request $request)
    {
        $rows = $this->invoiceReportQuery($request)->orderByDesc('invoice_date')->get();
        $headers = ['Vendor', 'Vendor Invoice No', 'Job Order', 'Invoice Date', 'Amount', 'Status'];

        return $this->exportByFormat(
            $request,
            'vendor-invoice-report.csv',
            $headers,
            $rows->map(fn (VendorInvoice $inv) => [
                $inv->vendor?->name,
                $inv->vendor_external_number ?? $inv->invoice_number,
                $inv->jobOrders->pluck('job_order_number')->join(', '),
                $inv->invoice_date?->toDateString(),
                $inv->total_amount,
                $inv->getRawOriginal('status') ?? $inv->status,
            ]),
            'Vendor Invoice Report'
        );
    }

    public function paymentIndex(Request $request): JsonResponse
    {
        $query = $this->paymentReportQuery($request);
        $paginated = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 10));

        $paginated->getCollection()->transform(function (VendorPaymentRequest $req) {
            $lastPayment = $req->payments()->orderByDesc('payment_date')->first();

            return [
                'id' => $req->id,
                'vendor' => $req->vendorInvoice?->vendor?->name,
                'vendor_id' => $req->vendorInvoice?->vendor_id,
                'payment_number' => $req->payment_number,
                'payment_date' => $lastPayment?->payment_date?->toDateString(),
                'amount' => $req->paid_amount,
                'method' => $lastPayment?->payment_method,
                'status' => $req->status?->value ?? $req->status,
            ];
        });

        return response()->json($paginated);
    }

    public function paymentExport(Request $request)
    {
        $rows = $this->paymentReportQuery($request)->orderByDesc('created_at')->get();
        $headers = ['Vendor', 'Payment No', 'Payment Date', 'Amount', 'Method', 'Status'];

        return $this->exportByFormat(
            $request,
            'vendor-payment-report.csv',
            $headers,
            $rows->map(function (VendorPaymentRequest $req) {
                $lastPayment = $req->payments()->orderByDesc('payment_date')->first();

                return [
                    $req->vendorInvoice?->vendor?->name,
                    $req->payment_number,
                    $lastPayment?->payment_date?->toDateString(),
                    $req->paid_amount,
                    $lastPayment?->payment_method,
                    $req->status?->value ?? $req->status,
                ];
            }),
            'Vendor Payment Report'
        );
    }

    private function invoiceReportQuery(Request $request)
    {
        $query = VendorInvoice::query()
            ->where('source', 'admin')
            ->with(['vendor:id,name', 'jobOrders:id,job_order_number']);

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('invoice_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('invoice_date', '<=', $request->date_to);
        }

        return $query;
    }

    private function paymentReportQuery(Request $request)
    {
        $query = VendorPaymentRequest::query()->with(['vendorInvoice.vendor:id,name']);

        if ($request->filled('vendor_id')) {
            $query->whereHas('vendorInvoice', fn ($q) => $q->where('vendor_id', $request->vendor_id));
        }
        if ($request->filled('date_from')) {
            $query->whereHas('payments', fn ($q) => $q->whereDate('payment_date', '>=', $request->date_from));
        }
        if ($request->filled('date_to')) {
            $query->whereHas('payments', fn ($q) => $q->whereDate('payment_date', '<=', $request->date_to));
        }

        return $query;
    }
}
