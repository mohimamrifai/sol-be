<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Invoice::with(['shipment:id,shipment_number,waybill_number'])
            ->where('company_id', $user->company_id);

        if ($request->filled('status')) $query->where('status', $request->status);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('invoice_number', 'like', "%{$s}%")
                    ->orWhereHas('shipment', function ($sq) use ($s) {
                        $sq->where('waybill_number', 'like', "%{$s}%")
                            ->orWhere('shipment_number', 'like', "%{$s}%");
                    });
            });
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 15));
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->company_id !== $request->user()->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $invoice->load(['shipment', 'items', 'payments']);

        return response()->json(['data' => $invoice]);
    }

    /**
     * Download invoice PDF (invoice harus milik company user).
     */
    public function downloadPdf(Request $request, Invoice $invoice)
    {
        if ($invoice->company_id !== $request->user()->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $invoice->load(['company', 'shipment', 'items']);

        $pdf = Pdf::loadView('pdf.invoice', ['invoice' => $invoice]);

        return $pdf->download('invoice-' . $invoice->invoice_number . '.pdf');
    }
}
