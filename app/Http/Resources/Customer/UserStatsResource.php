<?php

declare(strict_types=1);

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserStatsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'total' => $this->resource['total'] ?? 0,
            'active' => $this->resource['active'] ?? 0,
            'inactive' => $this->resource['inactive'] ?? 0,
            'company_admin' => $this->resource['company_admin'] ?? 0,
        ];
    }
}
