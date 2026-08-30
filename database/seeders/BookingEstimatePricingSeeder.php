<?php

namespace Database\Seeders;

use App\Models\ContainerType;
use App\Models\Location;
use App\Models\Pricing;
use App\Models\ServiceType;
use App\Models\TransportMode;
use App\Models\Vendor;
use App\Models\VendorService;
use Illuminate\Database\Seeder;

/**
 * Ensures vendor sell pricings exist for demo booking routes so
 * BookingPriceEstimateService can calculate non-zero breakdowns.
 */
class BookingEstimatePricingSeeder extends Seeder
{
    public function run(): void
    {
        $vendor = Vendor::query()->orderBy('id')->first();
        if (! $vendor) {
            $this->command?->warn('BookingEstimatePricingSeeder: vendor tidak ditemukan. Jalankan VendorSeeder terlebih dahulu.');

            return;
        }

        $jkt = Location::where('code', 'JKT')->first();
        $sub = Location::where('code', 'SUB')->first();
        $smg = Location::where('code', 'SMG')->first();
        $bdg = Location::where('code', 'BDG')->first();
        $rail = TransportMode::where('code', 'RAIL')->first();
        $fcl = ServiceType::where('code', 'FCL')->first();
        $lcl = ServiceType::where('code', 'LCL')->first();
        $container20 = ContainerType::where('size', '20ft')->first();
        $container40 = ContainerType::where('size', '40ft')->first();

        if (! $jkt || ! $sub || ! $rail || ! $fcl || ! $lcl) {
            $this->command?->warn('BookingEstimatePricingSeeder: master data belum lengkap.');

            return;
        }

        $routes = array_filter([
            [$jkt, $sub],
            $smg ? [$jkt, $smg] : null,
            $bdg ? [$jkt, $bdg] : null,
        ]);

        foreach ($routes as [$origin, $destination]) {
            $this->seedFclSellPricing($vendor, $rail, $fcl, $origin, $destination, $container40, 12_500_000);
            $this->seedFclSellPricing($vendor, $rail, $fcl, $origin, $destination, $container20, 8_500_000);
            $this->seedLclSellPricing($vendor, $rail, $lcl, $origin, $destination);
        }
    }

    private function seedFclSellPricing(
        Vendor $vendor,
        TransportMode $mode,
        ServiceType $service,
        Location $origin,
        Location $destination,
        ?ContainerType $containerType,
        float $price
    ): void {
        if (! $containerType) {
            return;
        }

        $vendorService = VendorService::updateOrCreate(
            [
                'vendor_id' => $vendor->id,
                'transport_mode_id' => $mode->id,
                'service_type_id' => $service->id,
                'origin_location_id' => $origin->id,
                'destination_location_id' => $destination->id,
            ],
            ['is_active' => true]
        );

        Pricing::updateOrCreate(
            [
                'vendor_service_id' => $vendorService->id,
                'price_type' => 'sell',
                'container_type_id' => $containerType->id,
            ],
            [
                'service_category' => 'rail_freight',
                'pricing_basis' => 'per_container',
                'price_per_container' => $price,
                'unit_price' => $price,
                'is_active' => true,
            ]
        );
    }

    private function seedLclSellPricing(
        Vendor $vendor,
        TransportMode $mode,
        ServiceType $service,
        Location $origin,
        Location $destination
    ): void {
        $vendorService = VendorService::updateOrCreate(
            [
                'vendor_id' => $vendor->id,
                'transport_mode_id' => $mode->id,
                'service_type_id' => $service->id,
                'origin_location_id' => $origin->id,
                'destination_location_id' => $destination->id,
            ],
            ['is_active' => true]
        );

        Pricing::updateOrCreate(
            [
                'vendor_service_id' => $vendorService->id,
                'price_type' => 'sell',
                'container_type_id' => null,
            ],
            [
                'service_category' => 'rail_freight',
                'pricing_basis' => 'per_kg',
                'price_per_kg' => 3500,
                'price_per_cbm' => 200_000,
                'minimum_charge' => 6_500_000,
                'min_kg' => 20_000,
                'unit_price' => 3500,
                'is_active' => true,
            ]
        );
    }
}
