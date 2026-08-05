<?php

namespace App\Services;

use App\Models\CompanyActivity;
use Illuminate\Database\Eloquent\Model;

class CompanyActivityLogger
{
    /**
     * Record an activity for a subject (Company, CustomerLocation, User, etc.).
     */
    public function log(
        Model $subject,
        string $eventKey,
        string $description,
        ?array $meta = null,
        ?int $actorUserId = null
    ): CompanyActivity {
        return CompanyActivity::create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event_key' => $eventKey,
            'description' => $description,
            'meta' => $meta,
            'actor_user_id' => $actorUserId,
            'occurred_at' => now(),
        ]);
    }
}
