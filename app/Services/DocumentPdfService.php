<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\VendorJobOrder;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders system-generated documents (CN, DO, Invoice, Tax Invoice,
 * Payment Receipt) to PDF on-the-fly when the customer opens or
 * downloads them. No PDF is stored on disk.
 */
class DocumentPdfService
{
    public function renderConsignmentNote(Shipment $shipment)
    {
        $shipment->loadMissing([
            'originLocation', 'destinationLocation', 'serviceType',
            'booking.cargoCategory', 'items',
            'trackings' => fn ($q) => $q->orderBy('tracked_at', 'asc'),
        ]);

        return Pdf::loadView('pdf.consignment-note', ['shipment' => $shipment]);
    }

    public function renderDeliveryOrder(Shipment $shipment)
    {
        $shipment->loadMissing([
            'originLocation', 'destinationLocation', 'serviceType', 'booking.cargoCategory',
        ]);

        return Pdf::loadView('pdf.delivery-order', ['shipment' => $shipment]);
    }

    public function renderInvoice(Invoice $invoice, bool $tax = false)
    {
        if ($tax) {
            $invoice->loadMissing(['company', 'shipment.originLocation', 'shipment.destinationLocation', 'shipment.booking', 'items']);

            return Pdf::loadView('pdf.tax-invoice', ['invoice' => $invoice]);
        }

        $presenter = app(InvoicePdfPresenter::class);

        return Pdf::loadView('pdf.invoice', $presenter->present($invoice));
    }

    public function renderPaymentReceipt(Payment $payment)
    {
        $payment->loadMissing(['invoice.company', 'invoice.shipment', 'invoice.shipment.booking']);

        return Pdf::loadView('pdf.payment-receipt', ['payment' => $payment]);
    }

    public function renderVendorJobOrder(VendorJobOrder $jobOrder)
    {
        $jobOrder->loadMissing([
            'vendor',
            'shipment.company',
            'originYard',
            'destinationYard',
            'train',
        ]);

        return Pdf::loadView('pdf.vendor-job-order', [
            'jobOrder' => $jobOrder,
            'vendorSnap' => $jobOrder->vendor_snapshot ?? [],
            'snap' => $jobOrder->shipment_snapshot ?? [],
        ]);
    }
}
