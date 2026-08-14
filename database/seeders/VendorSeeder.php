<?php

namespace Database\Seeders;

use App\Models\ContainerType;
use App\Models\Location;
use App\Models\Pricing;
use App\Models\PricingActivity;
use App\Models\ServiceType;
use App\Models\TransportMode;
use App\Models\Vendor;
use App\Models\VendorContact;
use App\Models\VendorService;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    public function run(): void
    {
        if (Vendor::query()->exists()) {
            return;
        }

        $jkt = Location::query()->where('code', 'JKT')->first();
        $sub = Location::query()->where('code', 'SUB')->first();
        $roadMode = TransportMode::query()->where('code', 'road')->first()
            ?? TransportMode::query()->first();
        $railMode = TransportMode::query()->where('code', 'rail')->first()
            ?? TransportMode::query()->first();
        $containerType = ContainerType::query()->first();

        $vendors = [
            [
                'name' => 'PT ABC Logistics',
                'business_entity' => 'company',
                'vendor_types' => [Vendor::TYPE_TRUCKING, Vendor::TYPE_RAIL],
                'vendor_category' => 'company',
                'npwp' => '01.234.567.8-901.000',
                'email' => 'info@abclogistics.test',
                'phone' => '021-5550101',
                'address' => 'Jl. Industri Raya No. 12',
                'country' => 'Indonesia',
                'province' => 'DKI Jakarta',
                'city' => 'Jakarta Utara',
                'district' => 'Tanjung Priok',
                'postal_code' => '14310',
                'payment_terms' => '30_days',
                'payment_method' => 'transfer',
                'bank_name' => 'BCA',
                'bank_account_number' => '1234567890',
                'account_holder' => 'PT ABC Logistics',
                'tax_status' => 'pkp',
                'contacts' => [
                    ['name' => 'Budi Santoso', 'position' => 'Ops Manager', 'email' => 'budi@abclogistics.test', 'mobile' => '081234567890', 'is_primary' => true],
                ],
                'pricing' => [
                    ['category' => 'trucking_pickup', 'basis' => 'per_trip', 'vehicle' => 'Fuso', 'price' => 3500000, 'origin' => $jkt, 'dest' => $sub, 'mode' => $roadMode],
                    ['category' => 'rail', 'basis' => 'per_container', 'container' => $containerType, 'price' => 5200000, 'origin' => $jkt, 'dest' => $sub, 'mode' => $railMode],
                ],
            ],
            [
                'name' => 'CV Mitra Angkut',
                'business_entity' => 'individual',
                'vendor_types' => [Vendor::TYPE_TRUCKING],
                'npwp' => '98.765.432.1-000.000',
                'email' => 'mitra@angkut.test',
                'phone' => '031-5550202',
                'address' => 'Jl. Raya Surabaya No. 88',
                'country' => 'Indonesia',
                'province' => 'Jawa Timur',
                'city' => 'Surabaya',
                'district' => 'Genteng',
                'postal_code' => '60275',
                'payment_terms' => '14_days',
                'payment_method' => 'transfer',
                'bank_name' => 'Mandiri',
                'bank_account_number' => '9876543210',
                'account_holder' => 'CV Mitra Angkut',
                'contacts' => [
                    ['name' => 'Siti Rahayu', 'mobile' => '081298765432', 'is_primary' => true],
                ],
                'pricing' => [
                    ['category' => 'trucking_delivery', 'basis' => 'per_trip', 'vehicle' => 'CDD', 'price' => 2800000, 'origin' => $sub, 'dest' => $jkt, 'mode' => $roadMode],
                ],
            ],
            [
                'name' => 'PT Container Nusantara',
                'business_entity' => 'company',
                'vendor_types' => [Vendor::TYPE_CONTAINER],
                'npwp' => '02.111.222.3-444.000',
                'email' => 'ops@containernusantara.test',
                'phone' => '021-5550303',
                'address' => 'Kawasan Pelabuhan Tanjung Priok',
                'country' => 'Indonesia',
                'province' => 'DKI Jakarta',
                'city' => 'Jakarta Utara',
                'district' => 'Tanjung Priok',
                'postal_code' => '14310',
                'payment_terms' => '30_days',
                'payment_method' => 'transfer',
                'bank_name' => 'BNI',
                'bank_account_number' => '5555666677',
                'account_holder' => 'PT Container Nusantara',
                'contacts' => [
                    ['name' => 'Agus Wijaya', 'position' => 'Fleet Manager', 'mobile' => '081311122233', 'is_primary' => true],
                ],
                'pricing' => [
                    ['category' => 'container_rental', 'basis' => 'per_container', 'container' => $containerType, 'price' => 1800000, 'origin' => $jkt, 'dest' => $jkt, 'mode' => $roadMode],
                ],
            ],
        ];

        $codeNum = 1;
        foreach ($vendors as $row) {
            $contacts = $row['contacts'];
            $pricingRows = $row['pricing'];
            unset($row['contacts'], $row['pricing']);

            $vendor = Vendor::create(array_merge($row, [
                'code' => 'VND'.str_pad((string) $codeNum++, 5, '0', STR_PAD_LEFT),
                'is_active' => true,
            ]));

            foreach ($contacts as $c) {
                VendorContact::create(array_merge($c, ['vendor_id' => $vendor->id, 'is_active' => true]));
            }

            foreach ($pricingRows as $pr) {
                if (! $pr['origin'] || ! $pr['dest'] || ! $pr['mode']) {
                    continue;
                }

                $vs = VendorService::create([
                    'vendor_id' => $vendor->id,
                    'transport_mode_id' => $pr['mode']->id,
                    'service_type_id' => ServiceType::query()->value('id'),
                    'origin_location_id' => $pr['origin']->id,
                    'destination_location_id' => $pr['dest']->id,
                    'is_active' => true,
                ]);

                $legacy = match ($pr['basis']) {
                    'per_kg' => ['price_per_kg' => $pr['price']],
                    'per_cbm' => ['price_per_cbm' => $pr['price']],
                    default => ['price_per_container' => $pr['price']],
                };

                $pricing = Pricing::create(array_merge([
                    'vendor_service_id' => $vs->id,
                    'service_category' => $pr['category'],
                    'pricing_basis' => $pr['basis'],
                    'vehicle_type' => $pr['vehicle'] ?? null,
                    'container_type_id' => isset($pr['container']) ? $pr['container']->id : null,
                    'unit_price' => $pr['price'],
                    'price_type' => 'buy',
                    'is_active' => true,
                ], $legacy));

                $pricing->update(['pricing_group_id' => $pricing->id]);
                PricingActivity::create([
                    'pricing_group_id' => $pricing->id,
                    'pricing_id' => $pricing->id,
                    'activity' => 'Pricing dibuat.',
                ]);
            }
        }
    }
}
