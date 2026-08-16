<?php

declare(strict_types=1);

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_key' => $this->event_key,
            'title' => $this->description,
            'description' => $this->description,
            'meta' => $this->meta,
            'actor' => $this->whenLoaded('actor', fn () => [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ]),
            'actor_name' => $this->whenLoaded('actor', fn () => $this->actor?->name),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
