<?php

use App\Models\Booking;
use App\Models\Company;
use App\Models\CustomerLocation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Normalize legacy rows where shipper company was stored as customer location name.
     */
    public function up(): void
    {
        Booking::query()
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->chunkById(200, function ($bookings) {
                foreach ($bookings as $booking) {
                    $companyName = Company::query()->whereKey($booking->company_id)->value('name');
                    if (! is_string($companyName) || trim($companyName) === '') {
                        continue;
                    }
                    $companyName = trim($companyName);

                    $locationName = null;
                    if ($booking->shipper_location_id) {
                        $resolved = CustomerLocation::query()
                            ->whereKey($booking->shipper_location_id)
                            ->value('name');
                        $locationName = is_string($resolved) && $resolved !== '' ? $resolved : null;
                    }

                    if ($locationName === null) {
                        $snapshot = is_array($booking->shipper_snapshot) ? $booking->shipper_snapshot : [];
                        foreach (['location_name', 'name'] as $key) {
                            $value = $snapshot[$key] ?? null;
                            if (is_string($value) && trim($value) !== '') {
                                $locationName = trim($value);
                                break;
                            }
                        }
                    }

                    $snapshot = is_array($booking->shipper_snapshot) ? $booking->shipper_snapshot : [];
                    $snapshotCompany = $snapshot['company'] ?? null;
                    $snapshotCompany = is_string($snapshotCompany) && trim($snapshotCompany) !== ''
                        ? trim($snapshotCompany)
                        : null;

                    $shipperName = is_string($booking->shipper_name) ? trim($booking->shipper_name) : '';
                    $pollutedByLocation = $locationName !== null && (
                        $shipperName === $locationName
                        || $snapshotCompany === $locationName
                    );

                    if (! $pollutedByLocation && $snapshotCompany === $companyName && $shipperName === $companyName) {
                        continue;
                    }

                    if ($pollutedByLocation) {
                        $snapshot['company'] = $companyName;
                        DB::table('bookings')
                            ->where('id', $booking->id)
                            ->update([
                                'shipper_name' => $companyName,
                                'shipper_snapshot' => json_encode($snapshot),
                                'updated_at' => now(),
                            ]);

                        continue;
                    }

                    if ($snapshotCompany === null && $shipperName === $companyName) {
                        $snapshot['company'] = $companyName;
                        DB::table('bookings')
                            ->where('id', $booking->id)
                            ->update([
                                'shipper_snapshot' => json_encode($snapshot),
                                'updated_at' => now(),
                            ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Non-reversible data backfill.
    }
};
