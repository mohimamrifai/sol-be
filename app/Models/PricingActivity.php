<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingActivity extends Model
{
    protected $fillable = [
        'pricing_group_id', 'pricing_id', 'user_id', 'activity',
    ];

    public function pricing(): BelongsTo
    {
        return $this->belongsTo(Pricing::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
