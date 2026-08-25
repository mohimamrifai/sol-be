<?php

namespace Database\Seeders;

use App\Models\AdditionalCharge;
use App\Models\CargoCategory;
use App\Models\Company;
use App\Models\ContainerType;
use App\Models\CustomerPricing;
use App\Models\CustomerPricingCharge;
use App\Models\Location;
use Illuminate\Database\Seeder;

class CustomerPricingSeeder extends Seeder
{
    public function run(): void
    {
        $mainCompany = Company::where('company_code', 'ABC')->first();
        $secondaryCompany = Company::where('company_code', 'SMB')->first();

        if (! $mainCompany) {
            $this->command?->warn('CustomerPricingSeeder: company ABC tidak ditemukan. Jalankan CustomerDemoSeeder terlebih dahulu.');

            return;
        }

        $this->seedPricings($mainCompany, $secondaryCompany ?? $mainCompany);
    }

    private function seedPricings(Company $mainCompany, Company $secondaryCompany): void
    {
        $jkt = Location::where('code', 'JKT')->first();
        $sub = Location::where('code', 'SUB')->first();
        $smg = Location::where('code', 'SMG')->first();
        $bdg = Location::where('code', 'BDG')->first();
        $mes = Location::where('code', 'MES')->first();

        $gen = CargoCategory::where('code', 'GEN')->first();
        $ele = CargoCategory::where('code', 'ELE')->first();
        $gmt = CargoCategory::where('code', 'GMT')->first();

        if (! $jkt || ! $sub || ! $gen) {
            $this->command?->warn('CustomerPricingSeeder: master data lokasi/kategori belum lengkap.');

            return;
        }

        $container20 = ContainerType::where('size', '20ft')->first();
        $container40 = ContainerType::where('size', '40ft')->first();
        $pickupCharge = AdditionalCharge::where('code', 'PICKUP')->first();
        $storageCharge = AdditionalCharge::where('code', 'STRG_DAY')->first();

        $definitions = [
            [
                'company_id' => $mainCompany->id,
                'origin_location_id' => $jkt->id,
                'destination_location_id' => $sub->id,
                'cargo_category_id' => $gen->id,
                'service_type' => 'lcl',
                'shipment_coverage' => 'port_to_port',
                'pricing_basis' => 'per_kg',
                'rate' => 3500,
                'minimum_charge' => 500000,
                'status' => 'active',
                'remark' => 'Tarif LCL Jakarta–Surabaya general cargo',
                'charges' => $pickupCharge ? [['additional_charge_id' => $pickupCharge->id, 'charge_type' => 'fixed', 'amount' => 750000]] : [],
            ],
            [
                'company_id' => $mainCompany->id,
                'origin_location_id' => $jkt->id,
                'destination_location_id' => $sub->id,
                'cargo_category_id' => $ele->id ?? $gen->id,
                'service_type' => 'lcl',
                'shipment_coverage' => 'door_to_door',
                'pricing_basis' => 'per_cbm',
                'rate' => 850000,
                'minimum_charge' => 1200000,
                'status' => 'active',
                'remark' => 'Tarif LCL elektronik door-to-door',
                'charges' => [],
            ],
            [
                'company_id' => $mainCompany->id,
                'origin_location_id' => $jkt->id,
                'destination_location_id' => $sub->id,
                'cargo_category_id' => $gen->id,
                'service_type' => 'fcl',
                'shipment_coverage' => 'port_to_port',
                'pricing_basis' => 'per_container',
                'rate' => 12500000,
                'minimum_charge' => null,
                'container_type_id' => $container20?->id,
                'status' => 'active',
                'remark' => 'Tarif FCL 20ft Jakarta–Surabaya',
                'charges' => $storageCharge ? [['additional_charge_id' => $storageCharge->id, 'charge_type' => 'fixed', 'amount' => 250000]] : [],
            ],
            [
                'company_id' => $mainCompany->id,
                'origin_location_id' => $smg?->id ?? $jkt->id,
                'destination_location_id' => $sub->id,
                'cargo_category_id' => $gmt->id ?? $gen->id,
                'service_type' => 'lcl',
                'shipment_coverage' => 'port_to_port',
                'pricing_basis' => 'per_kg',
                'rate' => 3200,
                'minimum_charge' => 450000,
                'status' => 'active',
                'remark' => 'Tarif LCL Semarang–Surabaya garment',
                'charges' => [],
            ],
            [
                'company_id' => $mainCompany->id,
                'origin_location_id' => $jkt->id,
                'destination_location_id' => $mes?->id ?? $sub->id,
                'cargo_category_id' => $gen->id,
                'service_type' => 'fcl',
                'shipment_coverage' => 'door_to_port',
                'pricing_basis' => 'per_container',
                'rate' => 18500000,
                'minimum_charge' => null,
                'container_type_id' => $container40?->id,
                'status' => 'inactive',
                'remark' => 'Tarif FCL 40ft Jakarta–Medan (inactive)',
                'charges' => [],
            ],
            [
                'company_id' => $secondaryCompany->id,
                'origin_location_id' => $bdg?->id ?? $jkt->id,
                'destination_location_id' => $sub->id,
                'cargo_category_id' => $gen->id,
                'service_type' => 'lcl',
                'shipment_coverage' => 'port_to_port',
                'pricing_basis' => 'per_ton',
                'rate' => 2800000,
                'minimum_charge' => 600000,
                'status' => 'active',
                'remark' => 'Tarif LCL Bandung–Surabaya PT Sembilan Jaya',
                'charges' => [],
            ],
        ];

        foreach ($definitions as $def) {
            $charges = $def['charges'] ?? [];
            unset($def['charges']);

            $pricing = CustomerPricing::updateOrCreate(
                [
                    'company_id' => $def['company_id'],
                    'origin_location_id' => $def['origin_location_id'],
                    'destination_location_id' => $def['destination_location_id'],
                    'cargo_category_id' => $def['cargo_category_id'],
                    'service_type' => $def['service_type'],
                ],
                $def
            );

            if ($charges !== []) {
                $pricing->charges()->delete();
                foreach ($charges as $charge) {
                    CustomerPricingCharge::create([
                        'customer_pricing_id' => $pricing->id,
                        'additional_charge_id' => $charge['additional_charge_id'],
                        'charge_type' => $charge['charge_type'],
                        'amount' => $charge['amount'],
                    ]);
                }
            }
        }
    }
}
