<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ContainerMaintenance;
use App\Services\ContainerAssetService;

class ContainerMaintenanceObserver
{
    public function __construct(
        private readonly ContainerAssetService $containerAssetService,
    ) {}

    public function saved(ContainerMaintenance $maintenance): void
    {
        $asset = $maintenance->containerAsset;
        if ($asset) {
            $this->containerAssetService->syncMaintenanceStatus($asset);
        }
    }

    public function deleted(ContainerMaintenance $maintenance): void
    {
        $asset = $maintenance->containerAsset;
        if ($asset) {
            $this->containerAssetService->syncMaintenanceStatus($asset);
        }
    }
}
