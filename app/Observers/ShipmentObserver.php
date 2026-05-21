<?php

namespace App\Observers;

use App\Models\Shipment;

class ShipmentObserver
{
    public function saving(\App\Models\Shipment $shipment): void
    {
        // Rule: Residual always leads to DG
        if ($shipment->equipment_condition === 'RESIDUAL') {
            $shipment->is_dangerous_goods = true;
        }
    }

    public function saved(\App\Models\Shipment $shipment): void
    {
        $this->syncAutoCharges($shipment);
    }

    protected function syncAutoCharges(Shipment $shipment): void
    {
        $triggers = [];

        // 1. DG Charge
        if ($shipment->is_dangerous_goods && ($shipment->wasRecentlyCreated || $shipment->wasChanged('is_dangerous_goods'))) {
            $triggers['DG'] = true;
        }

        // 2. Cargo Category based charges
        if ($shipment->cargo_category_id && ($shipment->wasRecentlyCreated || $shipment->wasChanged(['cargo_category_id', 'equipment_condition']))) {
            $cat = $shipment->cargoCategory;
            if ($cat) {
                if ($cat->requires_temperature) $triggers['REF'] = true;
                if ($cat->is_liquid) $triggers['LIQ'] = true;
                if ($cat->is_project_cargo) $triggers['OOG'] = true;
                if ($cat->code === 'MIX') $triggers['MIX'] = true;
            }
            if ($shipment->equipment_condition === 'RESIDUAL') {
                $triggers['CLEAN'] = true;
            }
        }

        if (empty($triggers)) return;

        $chargeModels = \App\Models\AdditionalCharge::whereIn('code', array_keys($triggers))->get();
        
        foreach ($chargeModels as $cm) {
            $exists = $shipment->additionalCharges()->where('additional_charge_id', $cm->id)->exists();
            if (!$exists) {
                $shipment->additionalCharges()->attach($cm->id, [
                    'amount' => 0,
                    'is_auto_triggered' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
