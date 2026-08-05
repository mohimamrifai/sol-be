<?php

declare(strict_types=1);

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationStatsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'total' => $this->resource['total'] ?? 0,
            'head_office' => $this->resource['head_office'] ?? 0,
            'branch_office' => $this->resource['branch_office'] ?? 0,
            'warehouse' => $this->resource['warehouse'] ?? 0,
            'active' => $this->resource['active'] ?? 0,
        ];
    }
}
