<?php

declare(strict_types=1);

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $logoUrl = null;
        if ($this->logo_path) {
            $logoUrl = Storage::disk('public')->url($this->logo_path);
            if ($request->getSchemeAndHttpHost()) {
                $logoUrl = $request->getSchemeAndHttpHost().parse_url($logoUrl, PHP_URL_PATH);
            }
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'business_entity_type' => $this->business_entity_type,
            'company_code' => $this->company_code,
            'npwp' => $this->npwp,
            'nib' => $this->nib,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'logo_url' => $logoUrl,
            'address' => $this->address,
            'country' => $this->country,
            'province' => $this->province,
            'city' => $this->city,
            'district' => $this->district,
            'postal_code' => $this->postal_code,
            'business_category' => $this->business_category,
            'business_category_other' => $this->business_category_other,
            'monthly_shipment_estimate' => $this->monthly_shipment_estimate,
            'contact_person' => $this->contact_person,
            'status' => $this->status,
            'documents' => CompanyDocumentResource::collection($this->whenLoaded('documents')),
            'customer_locations' => CustomerLocationResource::collection($this->whenLoaded('customerLocations')),
        ];
    }
}
