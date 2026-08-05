<?php

declare(strict_types=1);

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => $this->type?->label(),
            'phone' => $this->phone,
            'status' => $this->status,
            'status_label' => $this->status?->label(),
            'country' => $this->country,
            'province' => $this->province,
            'city' => $this->city,
            'district' => $this->district,
            'postal_code' => $this->postal_code,
            'address' => $this->address,
            'pic_name' => $this->pic_name,
            'pic_email' => $this->pic_email,
            'pic_mobile' => $this->pic_mobile,
            'is_only_head_office' => $this->when(
                $this->relationLoaded('company') || $this->type === 'head_office',
                fn () => $this->isOnlyHeadOffice()
            ),
        ];
    }
}
