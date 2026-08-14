<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TrainScheduleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainSchedule extends Model
{
    protected $fillable = [
        'code',
        'business_entity',
        'train_number',
        'route_id',
        'departure_at',
        'eta_at',
        'max_containers',
        'status',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'departure_at' => 'datetime',
            'eta_at' => 'datetime',
            'status' => TrainScheduleStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TrainSchedule $schedule) {
            if (empty($schedule->code)) {
                $schedule->code = self::generateCode();
            }
        });
    }

    public static function generateCode(): string
    {
        $next = self::count() + 1;

        return 'TRS'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
