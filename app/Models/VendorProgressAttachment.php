<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorProgressAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_progress_update_id',
        'file_path',
        'original_name',
        'mime_type',
        'size',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function progressUpdate(): BelongsTo
    {
        return $this->belongsTo(VendorProgressUpdate::class, 'vendor_progress_update_id');
    }
}
