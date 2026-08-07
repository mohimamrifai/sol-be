<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorInvoiceAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_invoice_id',
        'file_path',
        'original_name',
        'mime_type',
        'size',
        'kind',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function vendorInvoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoice::class);
    }
}
