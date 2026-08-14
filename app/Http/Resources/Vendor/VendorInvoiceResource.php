<?php

declare(strict_types=1);

namespace App\Http\Resources\Vendor;

use App\Enums\VendorInvoiceStatus;
use App\Models\VendorInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VendorInvoice
 */
class VendorInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'vendor_company_id' => $this->vendor_company_id,
            'shipment_id' => $this->shipment_id,
            'job_order' => $this->whenLoaded('shipment', fn () => [
                'id' => $this->shipment->id,
                'shipment_no' => $this->shipment->shipment_no,
                'shipment_number' => $this->shipment->shipment_number,
            ]),
            'invoice_date' => $this->invoice_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'invoice_amount' => (float) $this->invoice_amount,
            'tax_amount' => (float) $this->tax_amount,
            'total_amount' => (float) $this->total_amount,
            'paid_amount' => (float) $this->paidAmount(),
            'outstanding_amount' => (float) $this->outstandingAmount(),
            'status' => $this->statusValue(),
            'status_label' => VendorInvoiceStatus::tryFrom($this->statusValue())?->label() ?? $this->statusValue(),
            'notes' => $this->notes,
            'file_path' => $this->file_path,
            'file_url' => $this->file_path ? url('storage/'.$this->file_path) : null,
            'tax_invoice_path' => $this->tax_invoice_path,
            'tax_invoice_url' => $this->tax_invoice_path ? url('storage/'.$this->tax_invoice_path) : null,
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewedByUser', fn () => $this->reviewedByUser?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'is_editable' => $this->isEditable(),
            'is_submittable' => $this->isSubmittable(),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'file_path' => $a->file_path,
                'file_url' => url('storage/'.$a->file_path),
                'original_name' => $a->original_name,
                'mime_type' => $a->mime_type,
                'size' => (int) $a->size,
                'kind' => $a->kind,
            ])),
        ];
    }
}
