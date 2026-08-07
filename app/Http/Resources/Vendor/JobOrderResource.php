<?php

declare(strict_types=1);

namespace App\Http\Resources\Vendor;

use App\Enums\VendorJobStatus;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Shipment
 */
class JobOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $vendorStatus = $this->vendor_status ?? VendorJobStatus::PendingAcceptance->value;

        return [
            'id' => $this->id,
            'shipment_no' => $this->shipment_no,
            'shipment_number' => $this->shipment_number,
            'jo_number' => 'JO-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT),
            'customer' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'company_code' => $this->company->company_code,
            ]),
            'service_type' => $this->whenLoaded('serviceType', fn () => [
                'id' => $this->serviceType->id,
                'code' => $this->serviceType->code,
                'name' => $this->serviceType->name,
            ]),
            'transport_mode' => $this->whenLoaded('transportMode', fn () => [
                'id' => $this->transportMode->id,
                'code' => $this->transportMode->code,
                'name' => $this->transportMode->name,
            ]),
            'origin_location' => $this->whenLoaded('originLocation', fn () => [
                'id' => $this->originLocation->id,
                'code' => $this->originLocation->code,
                'name' => $this->originLocation->name,
            ]),
            'destination_location' => $this->whenLoaded('destinationLocation', fn () => [
                'id' => $this->destinationLocation->id,
                'code' => $this->destinationLocation->code,
                'name' => $this->destinationLocation->name,
            ]),
            'assigned_date' => $this->created_at?->toDateString(),
            'due_date' => $this->estimated_arrival?->toDateString(),
            'estimated_departure' => $this->estimated_departure?->toDateString(),
            'estimated_arrival' => $this->estimated_arrival?->toDateString(),
            'status' => $this->status,
            'vendor_status' => $vendorStatus,
            'vendor_status_label' => VendorJobStatus::from($vendorStatus)->label(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'completion_submitted_at' => $this->completion_submitted_at?->toIso8601String(),
            'completion_verified_at' => $this->completion_verified_at?->toIso8601String(),
            'completion_remark' => $this->completion_remark,
            'shipment_coverage' => $this->shipment_coverage,
            'is_dangerous_goods' => (bool) $this->is_dangerous_goods,
            'temperature' => $this->temperature,
            'notes' => $this->notes,
        ];
    }
}
