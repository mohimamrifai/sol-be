<?php

declare(strict_types=1);

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyCommercialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'billing_type' => $this->billing_type,
            'pricing_type' => $this->pricing_type,
            'discount_percent' => $this->discount_percent,
            'billing_cycle' => $this->billing_cycle,
            'payment_term' => $this->payment_term,
            'credit_limit' => $this->credit_limit,
            'current_deposit_balance' => $this->current_deposit_balance,
            'outstanding_balance' => $this->outstanding_balance,
        ];
    }
}
