<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminActivityLog;
use Illuminate\Database\Eloquent\Model;

class AdminActivityLogger
{
    public function log(
        string $module,
        string $description,
        ?Model $subject = null,
        string $eventKey = 'updated',
        ?array $meta = null,
        ?int $actorUserId = null
    ): AdminActivityLog {
        return AdminActivityLog::query()->create([
            'module' => $module,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'event_key' => $eventKey,
            'description' => $description,
            'meta' => $meta,
            'actor_user_id' => $actorUserId,
            'occurred_at' => now(),
        ]);
    }
}
