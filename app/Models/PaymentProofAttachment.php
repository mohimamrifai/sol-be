<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProofAttachment extends Model
{
    protected $fillable = [
        'payment_id',
        'uploaded_by',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'category',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
