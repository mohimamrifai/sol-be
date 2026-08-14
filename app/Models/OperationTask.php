<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OperationTaskStatus;
use App\Enums\OperationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationTask extends Model
{
    protected $fillable = [
        'shipment_id',
        'operation_type',
        'status',
        'planned_date',
        'actual_at',
        'remark',
        'checklist',
        'vendor_job_order_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'operation_type' => OperationType::class,
            'status' => OperationTaskStatus::class,
            'planned_date' => 'date',
            'actual_at' => 'datetime',
            'checklist' => 'array',
            'metadata' => 'array',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function vendorJobOrder(): BelongsTo
    {
        return $this->belongsTo(VendorJobOrder::class);
    }

    public function isEditable(): bool
    {
        return ! in_array($this->status, [OperationTaskStatus::Completed, OperationTaskStatus::Cancelled], true);
    }
}
