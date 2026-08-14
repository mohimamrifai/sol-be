<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPricingCharge extends Model
{
    protected $fillable = [
        'customer_pricing_id',
        'additional_charge_id',
        'charge_type',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function customerPricing(): BelongsTo
    {
        return $this->belongsTo(CustomerPricing::class);
    }

    public function additionalCharge(): BelongsTo
    {
        return $this->belongsTo(AdditionalCharge::class);
    }
}
