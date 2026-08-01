<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentActivity extends Model
{
    protected $fillable = [
        'payment_id',
        'actor_user_id',
        'event_key',
        'description',
        'meta',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
