<?php

declare(strict_types=1);

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locationAccess = $this->whenLoaded('locationAccess');
        $base = $request->getSchemeAndHttpHost();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'status_label' => $this->status?->label(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'label' => $r->name?->label(),
            ])),
            'location_access' => $locationAccess ? $locationAccess->map(fn ($loc) => [
                'id' => $loc->id,
                'code' => $loc->code,
                'name' => $loc->name,
                'type' => $loc->type,
                'status' => $loc->status,
            ]) : null,
            'feature_access' => $this->feature_access ?? [],
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'profile_photo_path' => $this->profile_photo_path,
            'profile_photo_url' => $this->profile_photo_path
                ? $base.'/storage/'.$this->profile_photo_path
                : null,
        ];
    }
}
