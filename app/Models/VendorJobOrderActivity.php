<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorJobOrderActivity extends Model
{
    protected $fillable = ['vendor_job_order_id', 'user_id', 'activity'];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(VendorJobOrder::class, 'vendor_job_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
