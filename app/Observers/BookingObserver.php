<?php

namespace App\Observers;

use App\Models\Booking;

class BookingObserver
{
    public function saving(Booking $booking): void
    {
        // Rule: Residual always leads to DG
        if ($booking->equipment_condition === 'RESIDUAL') {
            $booking->is_dangerous_goods = true;
        }
    }

    public function saved(Booking $booking): void
    {
        $this->syncAutoCharges($booking);
    }

    protected function syncAutoCharges(Booking $booking): void
    {
        $triggers = [];

        // 1. DG Charge
        if ($booking->is_dangerous_goods && ($booking->wasRecentlyCreated || $booking->wasChanged('is_dangerous_goods'))) {
            $triggers['DG'] = true;
        }

        // 2. Cargo Category based charges
        if ($booking->cargo_category_id && ($booking->wasRecentlyCreated || $booking->wasChanged(['cargo_category_id', 'equipment_condition']))) {
            $cat = $booking->cargoCategory;
            if ($cat) {
                if ($cat->requires_temperature) $triggers['REF'] = true;
                if ($cat->is_liquid) $triggers['LIQ'] = true;
                if ($cat->is_project_cargo) $triggers['OOG'] = true;
                if ($cat->code === 'MIX') $triggers['MIX'] = true;
            }
            if ($booking->equipment_condition === 'RESIDUAL') {
                $triggers['CLEAN'] = true;
            }
        }

        if (empty($triggers)) return;

        $chargeModels = \App\Models\AdditionalCharge::whereIn('code', array_keys($triggers))->get();
        
        foreach ($chargeModels as $cm) {
            // Attach if not already attached (regardless of auto_triggered status)
            // This allows the initial auto-add, but if a user deletes it, 
            // it won't be re-added unless the category or condition changes again.
            $exists = $booking->additionalCharges()->where('additional_charge_id', $cm->id)->exists();
            if (!$exists) {
                $booking->additionalCharges()->attach($cm->id, [
                    'amount' => 0,
                    'is_auto_triggered' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
