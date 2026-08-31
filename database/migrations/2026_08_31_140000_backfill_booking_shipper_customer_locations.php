<?php

use App\Enums\LocationType;
use App\Models\Booking;
use App\Models\CustomerLocation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill shipper customer location for legacy bookings so admin detail header
     * can show Customer Location even when only snapshot data exists.
     */
    public function up(): void
    {
        Booking::query()
            ->whereNull('shipper_location_id')
            ->orderBy('id')
            ->chunkById(200, function ($bookings) {
                foreach ($bookings as $booking) {
                    $location = CustomerLocation::query()
                        ->where('company_id', $booking->company_id)
                        ->where('status', 'active')
                        ->orderByRaw('CASE WHEN type = ? THEN 0 ELSE 1 END', [LocationType::HeadOffice->value])
                        ->orderBy('id')
                        ->first();

                    if (! $location) {
                        continue;
                    }

                    $snapshot = is_array($booking->shipper_snapshot) ? $booking->shipper_snapshot : [];
                    $snapshot['location_name'] = $location->name;
                    if (! empty($location->code)) {
                        $snapshot['location_code'] = $location->code;
                    }

                    DB::table('bookings')
                        ->where('id', $booking->id)
                        ->update([
                            'shipper_location_id' => $location->id,
                            'shipper_snapshot' => json_encode($snapshot),
                            'updated_at' => now(),
                        ]);
                }
            });

        Booking::query()
            ->whereNotNull('shipper_location_id')
            ->orderBy('id')
            ->chunkById(200, function ($bookings) {
                foreach ($bookings as $booking) {
                    $snapshot = is_array($booking->shipper_snapshot) ? $booking->shipper_snapshot : [];
                    if (! empty($snapshot['location_name'])) {
                        continue;
                    }

                    $location = CustomerLocation::query()->find($booking->shipper_location_id);
                    if (! $location) {
                        continue;
                    }

                    $snapshot['location_name'] = $location->name;
                    if (! empty($location->code)) {
                        $snapshot['location_code'] = $location->code;
                    }

                    DB::table('bookings')
                        ->where('id', $booking->id)
                        ->update([
                            'shipper_snapshot' => json_encode($snapshot),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Non-reversible data backfill.
    }
};
