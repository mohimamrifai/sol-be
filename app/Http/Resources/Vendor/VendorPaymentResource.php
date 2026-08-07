<?php

declare(strict_types=1);

namespace App\Http\Resources\Vendor;

use App\Models\VendorPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VendorPayment
 */
class VendorPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'vendor_invoice_id' => $this->vendor_invoice_id,
            'amount' => (float) $this->amount,
            'payment_date' => $this->payment_date?->toDateString(),
            'payment_method' => $this->payment_method,
            'reference_no' => $this->reference_no,
            'status' => $this->status,
            'status_label' => $this->status ? $this->status->label() : null,
            'receipt_path' => $this->receipt_path,
            'receipt_url' => $this->receipt_path ? url('storage/'.$this->receipt_path) : null,
            'transfer_receipt_path' => $this->transfer_receipt_path,
            'transfer_receipt_url' => $this->transfer_receipt_path ? url('storage/'.$this->transfer_receipt_path) : null,
            'withholding_tax_path' => $this->withholding_tax_path,
            'withholding_tax_url' => $this->withholding_tax_path ? url('storage/'.$this->withholding_tax_path) : null,
            'notes' => $this->notes,
            'paid_by' => $this->whenLoaded('paidByUser', fn () => $this->paidByUser?->name),
            'invoice' => $this->whenLoaded('vendorInvoice', fn () => [
                'id' => $this->vendorInvoice->id,
                'invoice_number' => $this->vendorInvoice->invoice_number,
                'total_amount' => (float) $this->vendorInvoice->total_amount,
                'shipment_id' => $this->vendorInvoice->shipment_id,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
