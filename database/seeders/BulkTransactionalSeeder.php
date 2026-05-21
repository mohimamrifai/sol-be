<?php

namespace Database\Seeders;

use App\Models\AdditionalService;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Container;
use App\Models\ContainerType;
use App\Models\CustomerDiscount;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Pricing;
use App\Models\Rack;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShipmentTracking;
use App\Models\ShipmentTrackingPhoto;
use App\Models\TransportMode;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class BulkTransactionalSeeder extends Seeder
{
    private const SEED_COUNT = 25;

    public function run(): void
    {
        if (Company::where('nib', 'like', 'SEED-%')->count() >= self::SEED_COUNT) {
            return;
        }

        $admin = User::where('email', 'admin@sol.com')->first();
        $locations = Location::orderBy('id')->get();
        $transportModes = TransportMode::orderBy('id')->get();
        $containerTypes = ContainerType::orderBy('id')->get();
        $additionalServices = AdditionalService::orderBy('id')->get();

        if ($locations->count() < self::SEED_COUNT || $transportModes->count() < self::SEED_COUNT) {
            $this->command?->warn('Master data kurang — jalankan MasterDataSeeder terlebih dahulu.');

            return;
        }

        $companies = collect();
        for ($i = 1; $i <= self::SEED_COUNT; $i++) {
            $loc = $locations[($i - 1) % $locations->count()];
            $company = Company::firstOrCreate(
                ['nib' => sprintf('SEED-%08d', $i)],
                [
                    'name' => "PT Seed Logistik {$i}",
                    'npwp' => sprintf('01.234.%03d.4-567.000', $i),
                    'address' => "Jl. Industri Raya No. {$i}",
                    'city' => $loc->city ?? 'Jakarta',
                    'province' => $loc->province ?? 'DKI Jakarta',
                    'postal_code' => str_pad((string) (10000 + $i), 5, '0', STR_PAD_LEFT),
                    'contact_person' => "PIC Utama {$i}",
                    'email' => "company{$i}@seed.sol.test",
                    'phone' => '0812'.str_pad((string) (3000000 + $i), 7, '0', STR_PAD_LEFT),
                    'status' => 'active',
                    'billing_cycle' => ['half_monthly_1', 'half_monthly_2', 'both_half', 'end_of_month'][($i - 1) % 4],
                ]
            );
            $companies->push($company);

            Branch::firstOrCreate(
                ['company_id' => $company->id, 'name' => 'Kantor Pusat'],
                [
                    'address' => $company->address,
                    'city' => $company->city,
                    'phone' => $company->phone,
                    'contact_person' => $company->contact_person,
                    'is_active' => true,
                ]
            );

            $user = User::firstOrCreate(
                ['email' => "customer{$i}@seed.sol.test"],
                [
                    'name' => "User Perusahaan {$i}",
                    'password' => bcrypt('password'),
                    'phone' => '0821'.str_pad((string) (2000000 + $i), 7, '0', STR_PAD_LEFT),
                    'status' => 'active',
                    'user_type' => 'customer',
                    'company_id' => $company->id,
                ]
            );
            if (! $user->hasRole('company_admin')) {
                $user->assignRole('company_admin');
            }
        }

        $vendors = collect();
        for ($i = 1; $i <= self::SEED_COUNT; $i++) {
            $vendors->push(Vendor::firstOrCreate(
                ['code' => sprintf('VND-%03d', $i)],
                [
                    'name' => "Vendor Transport {$i}",
                    'address' => "Jl. Niaga Logistik No. {$i}",
                    'phone' => '021'.str_pad((string) (5000000 + $i), 8, '0', STR_PAD_LEFT),
                    'email' => "vendor{$i}@seed.sol.test",
                    'contact_person' => "Koordinator {$i}",
                    'is_active' => true,
                ]
            ));
        }

        $vendorServices = collect();
        for ($i = 0; $i < self::SEED_COUNT; $i++) {
            $mode = $transportModes[$i];
            $serviceType = ServiceType::where('transport_mode_id', $mode->id)->first();
            if (! $serviceType) {
                $vendorServices->push(null);

                continue;
            }
            $origin = $locations[$i];
            $dest = $locations[($i + 5) % $locations->count()];

            $vs = VendorService::firstOrCreate(
                [
                    'vendor_id' => $vendors[$i]->id,
                    'transport_mode_id' => $mode->id,
                    'service_type_id' => $serviceType->id,
                    'origin_location_id' => $origin->id,
                    'destination_location_id' => $dest->id,
                ],
                ['is_active' => true]
            );
            $vendorServices->push($vs);
        }

        for ($i = 0; $i < self::SEED_COUNT; $i++) {
            $vs = $vendorServices[$i] ?? null;
            if (! $vs) {
                continue;
            }
            $ct = $containerTypes[$i % $containerTypes->count()];
            Pricing::firstOrCreate(
                [
                    'vendor_service_id' => $vs->id,
                    'container_type_id' => $ct->id,
                    'price_type' => $i % 2 === 0 ? 'buy' : 'sell',
                ],
                [
                    'price_per_kg' => 5000 + ($i * 100),
                    'price_per_cbm' => 1500000 + ($i * 50000),
                    'price_per_container' => 8000000 + ($i * 250000),
                    'minimum_charge' => 500000,
                    'effective_from' => Carbon::now()->subMonths(3),
                    'effective_to' => null,
                    'is_active' => true,
                ]
            );
        }

        for ($i = 0; $i < self::SEED_COUNT; $i++) {
            $company = $companies[$i];
            $vs = $vendorServices[$i] ?? null;
            CustomerDiscount::firstOrCreate(
                ['company_id' => $company->id],
                [
                    'vendor_service_id' => $vs?->id,
                    'discount_type' => $i % 2 === 0 ? 'percentage' : 'fixed',
                    'discount_value' => $i % 2 === 0 ? 5 + ($i % 10) : 100000 + ($i * 10000),
                    'effective_from' => Carbon::now()->subMonth(),
                    'effective_to' => Carbon::now()->addYear(),
                    'is_active' => true,
                ]
            );
        }

        $bookingStatuses = array_merge(
            array_fill(0, self::SEED_COUNT, 'approved'),
            ['draft', 'submitted', 'confirmed', 'rejected', 'cancelled']
        );

        $bookings = collect();
        for ($i = 0; $i < count($bookingStatuses); $i++) {
            $company = $companies[$i % $companies->count()];
            $user = User::where('company_id', $company->id)->where('user_type', 'customer')->first();
            $mode = $transportModes[$i % $transportModes->count()];
            $serviceType = ServiceType::where('transport_mode_id', $mode->id)->first();
            $origin = $locations[$i % $locations->count()];
            $dest = $locations[($i + 7) % $locations->count()];
            $ct = $containerTypes[$i % $containerTypes->count()];

            $status = $bookingStatuses[$i];
            $booking = Booking::create([
                'company_id' => $company->id,
                'user_id' => $user?->id ?? $admin->id,
                'origin_location_id' => $origin->id,
                'destination_location_id' => $dest->id,
                'transport_mode_id' => $mode->id,
                'service_type_id' => $serviceType?->id ?? ServiceType::first()->id,
                'container_type_id' => $ct->id,
                'container_count' => 1 + ($i % 3),
                'estimated_weight' => 1000 + ($i * 50),
                'estimated_cbm' => 5 + ($i * 0.2),
                'cargo_description' => "Kargo contoh {$i} — elektronik / tekstil.",
                'shipper_name' => "PT Pengirim Dummy {$i}",
                'shipper_address' => "Jl. Pengirim No. {$i}",
                'shipper_phone' => '0812' . str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT),
                'consignee_name' => "PT Penerima Dummy {$i}",
                'consignee_address' => "Jl. Penerima No. {$i}",
                'consignee_phone' => '0898' . str_pad((string) (9000000 + $i), 7, '0', STR_PAD_LEFT),
                'departure_date' => Carbon::now()->addDays($i % 14),
                'estimated_price' => 5000000 + ($i * 125000),
                'status' => $status,
                'notes' => 'Data seed otomatis.',
                'approved_by' => in_array($status, ['approved', 'confirmed'], true) ? $admin->id : null,
                'approved_at' => $status === 'approved' ? Carbon::now()->subDays($i % 5) : null,
                'rejection_reason' => $status === 'rejected' ? 'Contoh alasan penolakan seed.' : null,
            ]);
            $bookings->push($booking);

            $add = $additionalServices[$i % $additionalServices->count()];
            $booking->additionalServices()->syncWithoutDetaching([
                $add->id => [
                    'notes' => 'Layanan tambahan seed',
                    'price' => 150000 + ($i * 5000),
                ],
            ]);
        }

        $approvedBookings = $bookings->filter(fn ($b) => $b->status === 'approved')->take(self::SEED_COUNT);
        $shipmentStatuses = [
            'created', 'survey_completed', 'cargo_received', 'stuffing_container', 'container_sealed',
            'departed', 'arrived', 'unloading', 'ready_for_pickup', 'completed',
        ];

        $shipments = collect();
        foreach ($approvedBookings as $idx => $booking) {
            $st = $shipmentStatuses[$idx % count($shipmentStatuses)];
            $shipment = Shipment::create([
                'booking_id' => $booking->id,
                'company_id' => $booking->company_id,
                'origin_location_id' => $booking->origin_location_id,
                'destination_location_id' => $booking->destination_location_id,
                'transport_mode_id' => $booking->transport_mode_id,
                'service_type_id' => $booking->service_type_id,
                'status' => $st,
                'estimated_departure' => Carbon::now()->addDays($idx % 10),
                'estimated_arrival' => Carbon::now()->addDays(10 + ($idx % 10)),
                'actual_departure' => $idx % 3 === 0 ? Carbon::now()->subDays(2) : null,
                'actual_arrival' => $idx % 7 === 0 ? Carbon::now()->subDay() : null,
                'notes' => "Shipment seed #{$idx}",
                'created_by' => $admin->id,
            ]);
            $shipments->push($shipment);

            $container = Container::create([
                'shipment_id' => $shipment->id,
                'container_type_id' => $booking->container_type_id,
                'container_number' => 'CONT'.str_pad((string) (1000 + $idx), 6, '0', STR_PAD_LEFT),
                'seal_number' => 'SEAL'.str_pad((string) (2000 + $idx), 6, '0', STR_PAD_LEFT),
            ]);

            $rack = Rack::create([
                'container_id' => $container->id,
                'name' => 'Rack A-'.($idx + 1),
                'length' => 120,
                'width' => 80,
                'height' => 100,
            ]);

            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'name' => "Item kargo {$idx}",
                'description' => 'Barang contoh untuk pengujian seed.',
                'quantity' => 10 + ($idx % 20),
                'gross_weight' => 250.5 + $idx,
                'length' => 50,
                'width' => 40,
                'height' => 35,
                'cbm' => 0.07,
                'is_fragile' => $idx % 4 === 0,
                'is_stackable' => true,
                'placement_type' => 'rack',
                'container_id' => $container->id,
                'rack_id' => $rack->id,
            ]);
        }

        $trackings = collect();
        foreach ($shipments as $idx => $shipment) {
            for ($t = 0; $t < 2; $t++) {
                $trackings->push(ShipmentTracking::create([
                    'shipment_id' => $shipment->id,
                    'status' => $shipmentStatuses[($idx + $t) % count($shipmentStatuses)],
                    'notes' => "Update tracking #{$t} untuk shipment {$shipment->id}",
                    'location' => $locations[($idx + $t) % $locations->count()]->name,
                    'tracked_at' => Carbon::now()->subHours(48 - ($idx * 2) - $t),
                    'updated_by' => $admin->id,
                ]));
            }
        }

        foreach ($trackings->take(self::SEED_COUNT) as $ti => $tr) {
            ShipmentTrackingPhoto::create([
                'shipment_tracking_id' => $tr->id,
                'path' => 'seed/tracking/placeholder-'.($ti + 1).'.jpg',
                'caption' => 'Foto bukti seed '.$ti,
            ]);
        }

        foreach ($shipments as $idx => $shipment) {
            $subtotal = 12000000 + ($idx * 350000);
            $tax = round($subtotal * 0.11, 2);
            $invoice = Invoice::create([
                'shipment_id' => $shipment->id,
                'company_id' => $shipment->company_id,
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total_amount' => $subtotal + $tax,
                'issued_date' => Carbon::now()->subDays($idx % 7),
                'due_date' => Carbon::now()->addDays(14 - ($idx % 7)),
                'status' => ['unpaid', 'paid', 'overdue', 'unpaid', 'paid'][$idx % 5],
                'notes' => 'Invoice seed',
                'created_by' => $admin->id,
            ]);

            $line = 2500000 + ($idx * 100000);
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Biaya pengangkutan utama',
                'quantity' => 1,
                'unit_price' => $line,
                'total_price' => $line,
            ]);
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Biaya bongkar muat & dokumentasi',
                'quantity' => 1,
                'unit_price' => 500000 + ($idx * 25000),
                'total_price' => 500000 + ($idx * 25000),
            ]);

            Payment::create([
                'invoice_id' => $invoice->id,
                'midtrans_transaction_id' => 'SEED-TXN-'.str_pad((string) ($idx + 1), 5, '0', STR_PAD_LEFT),
                'midtrans_order_id' => 'SEED-ORD-'.str_pad((string) ($idx + 1), 5, '0', STR_PAD_LEFT),
                'amount' => $invoice->total_amount,
                'payment_type' => $idx % 2 === 0 ? 'bank_transfer' : 'credit_card',
                'status' => $idx % 3 === 0 ? 'pending' : 'success',
                'midtrans_response' => ['seed' => true, 'idx' => $idx],
                'paid_at' => $idx % 3 === 0 ? null : Carbon::now()->subDays(1),
            ]);
        }
    }
}
