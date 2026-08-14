<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProofOfDelivery extends Model
{
    protected $fillable = [
        'shipment_id',
        'pod_number',
        'status',
        'receiver_name',
        'receiver_position',
        'received_at',
        'remark',
        'pod_date',
        'signed_pod_path',
        'delivery_photo_path',
        'other_documents',
        'verified_by',
        'verified_at',
        'verification_notes',
        'submitted_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PodStatus::class,
            'received_at' => 'datetime',
            'pod_date' => 'datetime',
            'verified_at' => 'datetime',
            'other_documents' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProofOfDelivery $pod) {
            if (empty($pod->pod_number)) {
                $pod->pod_number = self::generateNumber();
            }
            if (empty($pod->status)) {
                $pod->status = PodStatus::WaitingPod;
            }
        });
    }

    public static function generateNumber(): string
    {
        $next = (int) (self::max('id') ?? 0) + 1;

        return 'POD'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function canSubmit(): bool
    {
        return in_array($this->status, [PodStatus::WaitingPod, PodStatus::Rejected], true);
    }

    public function canVerify(): bool
    {
        return $this->status === PodStatus::Received;
    }

    public function isReadOnly(): bool
    {
        return $this->status === PodStatus::Verified;
    }
}
