<?php

declare(strict_types=1);

namespace App\Http\Resources\Customer;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locationAccess = $this->whenLoaded('locationAccess');
        $base = $request->getSchemeAndHttpHost();
        $roleName = $this->roles->first()?->name;
        $locations = $locationAccess
            ? $locationAccess->map(fn ($loc) => [
                'id' => $loc->id,
                'code' => $loc->code,
                'name' => $loc->name,
                'type' => $loc->type,
                'status' => $loc->status,
            ])->values()
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status instanceof UserStatus ? $this->status->value : $this->status,
            'status_label' => $this->status?->label(),
            'role' => $roleName,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'label' => UserRole::tryFrom((string) $r->name)?->label(),
            ])),
            'location_access' => $locations,
            'locations' => $locations,
            'location_ids' => $locationAccess ? $locationAccess->pluck('id')->values()->all() : null,
            'feature_access' => $this->feature_access ?? [],
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'company_code' => $this->company->company_code ?? null,
            ]),
            'profile_photo_path' => $this->profile_photo_path,
            'profile_photo_url' => $this->profile_photo_path
                ? $base.'/storage/'.$this->profile_photo_path
                : null,
            'is_current_user' => $request->user() instanceof User && (int) $request->user()->id === (int) $this->id,
            'is_last_company_admin' => $this->isLastActiveCompanyAdmin(),
        ];
    }

    private function isLastActiveCompanyAdmin(): bool
    {
        if (! $this->resource instanceof User) {
            return false;
        }

        if ($this->status !== UserStatus::Active || ! $this->hasRole(UserRole::CompanyAdmin->value)) {
            return false;
        }

        $count = User::query()
            ->where('company_id', $this->company_id)
            ->where('user_type', 'customer')
            ->where('status', UserStatus::Active)
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::CompanyAdmin->value))
            ->count();

        return $count === 1;
    }
}
