<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorPaymentDocument extends Model
{
    protected $fillable = [
        'vendor_payment_request_id',
        'document_type',
        'file_path',
        'original_name',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(VendorPaymentRequest::class, 'vendor_payment_request_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
