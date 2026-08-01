<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingAttachment;
use App\Models\Branch;
use App\Models\CargoCategory;
use App\Models\Company;
use App\Models\ContainerType;
use App\Models\Invoice;
use App\Models\InvoiceActivity;
use App\Models\InvoiceItem;
use App\Models\Location;
use App\Models\Payment;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShipmentTracking;
use App\Models\TransportMode;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Demo Seeder
 *
 * Menghasilkan data demo untuk role customer (company_admin, ops_pic, finance_pic)
 * dengan cakupan semua status & state penting pada modul:
 *  - Bookings   (draft, submitted, approved, rejected)
 *  - Shipments  (planning, in_progress, completed, cancelled)
 *  - Documents  (booking attachment, CN, DO, POD, invoice, tax invoice, payment receipt)
 *  - Invoices   (draft, issued, partially_paid, paid, overdue)
 *
 * Strategi: TRUNCATE + seed ulang. Idempotent (aman dipanggil berulang).
 *
 * Akun demo (password: "password"):
 *  - admin@customer.test  → company_admin
 *  - ops@customer.test    → ops_pic
 *  - finance@customer.test → finance_pic
 *
 * Perusahaan: PT ABC Indonesia (satu company utama; 1 company pembanding tanpa PIC
 * untuk menguji filter company_id).
 */
class CustomerDemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $this->truncateCustomerData();

        $mainCompany = $this->seedMainCompany();
        $this->seedSecondaryCompany();

        $admin = $this->seedUser($mainCompany, 'admin@customer.test', 'Demo Company Admin', 'company_admin');
        $ops = $this->seedUser($mainCompany, 'ops@customer.test', 'Demo Ops PIC', 'ops_pic');
        $finance = $this->seedUser($mainCompany, 'finance@customer.test', 'Demo Finance PIC', 'finance_pic');

        $customerUsers = collect([$admin, $ops, $finance]);

        $bookings = $this->seedBookings($mainCompany, $customerUsers);
        $this->seedShipments($bookings, $customerUsers);
        $this->seedInvoices($bookings, $customerUsers);

        $this->command?->info('Customer demo data seeded: 1 main company + 3 PIC users.');
    }

    private function truncateCustomerData(): void
    {
        $tables = [
            'invoice_activities',
            'invoice_items',
            'payments',
            'invoices',
            'shipment_tracking_photos',
            'shipment_trackings',
            'shipment_items',
            'shipments',
            'booking_attachments',
            'booking_activities',
            'booking_additional_service',
            'booking_additional_charge',
            'booking_packages',
            'booking_containers',
            'bookings',
            'customer_discounts',
            'branches',
            'users',
            'companies',
        ];

        Schema::disableForeignKeyConstraints();
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }
        Schema::enableForeignKeyConstraints();
    }

    private function seedMainCompany(): Company
    {
        return Company::create([
            'name' => 'PT ABC Indonesia',
            'business_entity_type' => 'PT',
            'company_code' => 'ABC',
            'npwp' => '01.234.567.8-901.000',
            'nib' => '9120000012345',
            'address' => 'Jl. Sudirman Kav. 45',
            'city' => 'Jakarta',
            'province' => 'DKI Jakarta',
            'country' => 'Indonesia',
            'postal_code' => '12190',
            'business_category' => 'manufacturing',
            'business_category_other' => 'Manufaktur',
            'contact_person' => 'Andi Wijaya',
            'email' => 'finance@abc-indonesia.co.id',
            'phone' => '021-555-0188',
            'status' => 'active',
            'billing_cycle' => 'end_of_month',
            'payment_type' => 'postpaid',
            'postpaid_term_days' => 14,
        ]);
    }

    private function seedSecondaryCompany(): Company
    {
        return Company::create([
            'name' => 'PT Sembilan Jaya',
            'business_entity_type' => 'PT',
            'company_code' => 'SMB',
            'npwp' => '02.345.678.9-012.000',
            'nib' => '9120000098765',
            'address' => 'Jl. Asia Afrika No. 100',
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
            'country' => 'Indonesia',
            'postal_code' => '40112',
            'business_category' => 'distributor',
            'business_category_other' => 'Distribusi',
            'contact_person' => 'Rina Kartika',
            'email' => 'finance@sembilan-jaya.co.id',
            'phone' => '022-555-9911',
            'status' => 'active',
            'billing_cycle' => 'half_monthly_1',
            'payment_type' => 'postpaid',
            'postpaid_term_days' => 30,
        ]);
    }

    private function seedUser(Company $company, string $email, string $name, string $role): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt(self::PASSWORD),
            'phone' => '0812'.str_pad((string) (random_int(1000000, 9999999)), 7, '0', STR_PAD_LEFT),
            'status' => 'active',
            'user_type' => 'customer',
            'company_id' => $company->id,
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    private function seedBookings(Company $company, $customerUsers): \Illuminate\Support\Collection
    {
        $locations = Location::orderBy('id')->get();
        $modes = TransportMode::orderBy('id')->get();
        $serviceTypes = ServiceType::orderBy('id')->get();
        $containerTypes = ContainerType::orderBy('id')->get();
        $cargoGeneral = CargoCategory::where('code', 'GEN')->first() ?? CargoCategory::first();

        $origin = $locations->firstWhere('code', 'JKT');
        $destination = $locations->firstWhere('code', 'SUB');
        $destination2 = $locations->firstWhere('code', 'SMG');
        $destination3 = $locations->firstWhere('code', 'BDG');

        $serviceFcl = $serviceTypes->firstWhere('code', 'FCL') ?? $serviceTypes->first();
        $serviceLcl = $serviceTypes->firstWhere('code', 'LCL') ?? $serviceTypes->first();
        $rail = $modes->firstWhere('code', 'RAIL') ?? $modes->first();
        $container20 = $containerTypes->firstWhere('size', '20ft') ?? $containerTypes->first();
        $container40 = $containerTypes->firstWhere('size', '40ft') ?? $containerTypes->first();

        $admin = $customerUsers->firstWhere('email', 'admin@customer.test');
        $ops = $customerUsers->firstWhere('email', 'ops@customer.test');

        $bookings = collect();

        $bookings->push($this->makeBooking([
            'company' => $company,
            'creator' => $admin,
            'origin' => $origin,
            'destination' => $destination,
            'service' => $serviceFcl,
            'mode' => $rail,
            'container' => $container40,
            'cargo' => $cargoGeneral,
            'status' => Booking::STATUS_DRAFT,
            'departure_date' => now()->addDays(20)->toDateString(),
            'coverage' => 'door_to_door',
            'estimated_price' => 15000000,
            'remarks' => 'Draft baru, menunggu data final.',
        ]));

        $bookings->push($this->makeBooking([
            'company' => $company,
            'creator' => $ops,
            'origin' => $origin,
            'destination' => $destination2,
            'service' => $serviceLcl,
            'mode' => $rail,
            'container' => $container20,
            'cargo' => $cargoGeneral,
            'status' => Booking::STATUS_DRAFT,
            'departure_date' => now()->addDays(25)->toDateString(),
            'coverage' => 'port_to_port',
            'estimated_price' => 6500000,
            'remarks' => 'Draft LCL untuk pengujian kapasitas.',
        ]));

        $bookings->push($this->makeBooking([
            'company' => $company,
            'creator' => $admin,
            'origin' => $origin,
            'destination' => $destination3,
            'service' => $serviceFcl,
            'mode' => $rail,
            'container' => $container40,
            'cargo' => $cargoGeneral,
            'status' => Booking::STATUS_DRAFT,
            'departure_date' => now()->addDays(28)->toDateString(),
            'coverage' => 'door_to_door',
            'estimated_price' => 12500000,
        ]));

        for ($i = 1; $i <= 3; $i++) {
            $bookings->push($this->makeBooking([
                'company' => $company,
                'creator' => $ops,
                'origin' => $origin,
                'destination' => $destination,
                'service' => $serviceFcl,
                'mode' => $rail,
                'container' => $container40,
                'cargo' => $cargoGeneral,
                'status' => Booking::STATUS_SUBMITTED,
                'departure_date' => now()->addDays(7 + $i)->toDateString(),
                'coverage' => 'door_to_door',
                'estimated_price' => 12000000 + ($i * 500000),
            ]));
        }

        for ($i = 1; $i <= 3; $i++) {
            $bookings->push($this->makeBooking([
                'company' => $company,
                'creator' => $admin,
                'origin' => $origin,
                'destination' => $destination2,
                'service' => $serviceLcl,
                'mode' => $rail,
                'container' => $container20,
                'cargo' => $cargoGeneral,
                'status' => Booking::STATUS_REJECTED,
                'departure_date' => now()->addDays(2 + $i)->toDateString(),
                'coverage' => 'port_to_door',
                'estimated_price' => 7800000,
                'rejection_reason' => $i === 1
                    ? 'Tidak ada slot kereta untuk rute tersebut pada tanggal yang diminta.'
                    : ($i === 2
                        ? 'Dimensi kargo melebihi kapasitas kontainer. Mohon tinjau kembali.'
                        : 'Tarif tidak sesuai dengan pricelist terbaru.'),
            ]));
        }

        $approvedBookings = collect();
        for ($i = 1; $i <= 5; $i++) {
            $b = $this->makeBooking([
                'company' => $company,
                'creator' => $ops,
                'origin' => $origin,
                'destination' => $i % 2 === 0 ? $destination : $destination2,
                'service' => $i % 2 === 0 ? $serviceFcl : $serviceLcl,
                'mode' => $rail,
                'container' => $i % 2 === 0 ? $container40 : $container20,
                'cargo' => $cargoGeneral,
                'status' => Booking::STATUS_APPROVED,
                'departure_date' => now()->subDays(20 - ($i * 4))->toDateString(),
                'coverage' => $i % 2 === 0 ? 'door_to_door' : 'port_to_port',
                'estimated_price' => 8000000 + ($i * 1500000),
            ]);
            $approvedBookings->push($b);
            $bookings->push($b);
        }

        BookingActivity::create([
            'booking_id' => $approvedBookings[0]->id,
            'actor_id' => $admin->id,
            'actor_role' => 'customer',
            'activity_type' => 'submitted',
            'title' => 'Booking diajukan',
            'description' => 'Booking diajukan oleh customer',
            'occurred_at' => $approvedBookings[0]->created_at,
        ]);

        return $bookings;
    }

    private function makeBooking(array $ctx): Booking
    {
        $creator = $ctx['creator'];
        $status = $ctx['status'];

        $booking = Booking::create([
            'company_id' => $ctx['company']->id,
            'user_id' => $creator->id,
            'origin_location_id' => $ctx['origin']->id,
            'destination_location_id' => $ctx['destination']->id,
            'transport_mode_id' => $ctx['mode']->id,
            'service_type_id' => $ctx['service']->id,
            'container_type_id' => $ctx['container']->id,
            'container_count' => 1,
            'cargo_category_id' => $ctx['cargo']->id,
            'shipment_coverage' => $ctx['coverage'],
            'estimated_weight' => 18000,
            'estimated_cbm' => 32.5,
            'departure_date' => $ctx['departure_date'],
            'cargo_description' => 'Peralatan industri dan suku cadang.',
            'is_dangerous_goods' => false,
            'equipment_condition' => 'CLEAN',
            'shipper_name' => $ctx['company']->name,
            'shipper_address' => $ctx['company']->address,
            'shipper_phone' => $ctx['company']->phone,
            'consignee_name' => 'CV '.$ctx['destination']->name.' Logistik',
            'consignee_address' => 'Jl. '.$ctx['destination']->name.' No. 88',
            'consignee_phone' => '031-555-'.str_pad((string) (random_int(1000, 9999)), 4, '0', STR_PAD_LEFT),
            'estimated_price' => $ctx['estimated_price'],
            'status' => $status,
            'rejection_reason' => $ctx['rejection_reason'] ?? null,
            'notes' => $ctx['remarks'] ?? null,
        ]);

        if ($status === Booking::STATUS_APPROVED) {
            $booking->update([
                'approved_by' => $creator->id,
                'approved_at' => now()->subDays(rand(2, 15)),
            ]);
        }

        BookingActivity::create([
            'booking_id' => $booking->id,
            'actor_id' => $creator->id,
            'actor_role' => 'customer',
            'activity_type' => 'created',
            'title' => 'Booking dibuat',
            'description' => 'Booking dibuat',
            'occurred_at' => $booking->created_at,
        ]);

        if (in_array($status, [Booking::STATUS_SUBMITTED, Booking::STATUS_APPROVED, Booking::STATUS_REJECTED], true)) {
            BookingActivity::create([
                'booking_id' => $booking->id,
                'actor_id' => $creator->id,
                'actor_role' => 'customer',
                'activity_type' => 'submitted',
                'title' => 'Booking diajukan',
                'description' => 'Booking diajukan',
                'occurred_at' => $booking->created_at->copy()->addMinutes(5),
            ]);
        }

        if ($status === Booking::STATUS_REJECTED) {
            BookingActivity::create([
                'booking_id' => $booking->id,
                'actor_id' => $creator->id,
                'actor_role' => 'admin',
                'activity_type' => 'rejected',
                'title' => 'Booking ditolak',
                'description' => 'Booking ditolak: '.$ctx['rejection_reason'],
                'occurred_at' => $booking->created_at->copy()->addHours(2),
            ]);
        }

        if (in_array($status, [Booking::STATUS_APPROVED], true)) {
            BookingActivity::create([
                'booking_id' => $booking->id,
                'actor_id' => $creator->id,
                'actor_role' => 'admin',
                'activity_type' => 'approved',
                'title' => 'Booking disetujui',
                'description' => 'Booking disetujui',
                'occurred_at' => $booking->created_at->copy()->addHours(4),
            ]);
        }

        return $booking;
    }

    private function seedShipments(\Illuminate\Support\Collection $bookings, $customerUsers): void
    {
        $approved = $bookings->filter(fn (Booking $b) => $b->status === Booking::STATUS_APPROVED)->values();

        $scenarioMatrix = [
            Shipment::HL_PLANNING => ['count' => 3, 'statuses' => ['created', 'survey_completed', 'created']],
            Shipment::HL_IN_PROGRESS => ['count' => 4, 'statuses' => ['cargo_received', 'stuffing_container', 'departed', 'arrived']],
            Shipment::HL_COMPLETED => ['count' => 3, 'statuses' => ['unloading', 'ready_for_pickup', 'completed']],
            Shipment::HL_CANCELLED => ['count' => 1, 'statuses' => ['cancelled']],
        ];

        $cursor = 0;
        $admin = $customerUsers->firstWhere('email', 'admin@customer.test');

        foreach ($scenarioMatrix as $highLevel => $plan) {
            for ($i = 0; $i < $plan['count']; $i++) {
                if (! isset($approved[$cursor])) {
                    $cursor = 0;
                }
                $booking = $approved[$cursor] ?? $approved->first();
                if (! $booking) {
                    return;
                }
                $cursor++;

                $operational = $plan['statuses'][$i] ?? $plan['statuses'][0];
                $shipment = Shipment::create([
                    'booking_id' => $booking->id,
                    'company_id' => $booking->company_id,
                    'origin_location_id' => $booking->origin_location_id,
                    'destination_location_id' => $booking->destination_location_id,
                    'transport_mode_id' => $booking->transport_mode_id,
                    'service_type_id' => $booking->service_type_id,
                    'cargo_category_id' => $booking->cargo_category_id,
                    'shipment_coverage' => $booking->shipment_coverage,
                    'status' => $operational,
                    'estimated_departure' => $booking->departure_date,
                    'estimated_arrival' => Carbon::parse($booking->departure_date)->addDays(5),
                    'actual_departure' => in_array($operational, ['departed', 'arrived', 'container_unloading', 'ready_for_pickup', 'completed'], true)
                        ? Carbon::parse($booking->departure_date)->addDays(rand(0, 1))
                        : null,
                    'actual_arrival' => $operational === 'completed' ? Carbon::parse($booking->departure_date)->addDays(5) : null,
                    'is_dangerous_goods' => false,
                    'equipment_condition' => 'CLEAN',
                    'created_by' => $admin->id,
                    'shipper_snapshot' => [
                        'name' => $booking->shipper_name,
                        'address' => $booking->shipper_address,
                        'phone' => $booking->shipper_phone,
                    ],
                    'consignee_snapshot' => [
                        'name' => $booking->consignee_name,
                        'address' => $booking->consignee_address,
                        'phone' => $booking->consignee_phone,
                    ],
                    'cancelled_reason' => $operational === 'cancelled' ? 'Pembatalan atas permintaan customer karena perubahan jadwal produksi.' : null,
                ]);

                ShipmentItem::create([
                    'shipment_id' => $shipment->id,
                    'name' => 'Peralatan Industri',
                    'description' => 'Suku cadang dan mesin ringan',
                    'quantity' => rand(4, 14),
                    'gross_weight' => 12000 + ($i * 850),
                    'length' => 220,
                    'width' => 180,
                    'height' => 160,
                    'cbm' => 32.0 + $i,
                    'is_fragile' => $i % 2 === 0,
                    'is_stackable' => true,
                ]);

                $this->seedTracking($shipment, $operational);
            }
        }
    }

    private function seedTracking(Shipment $shipment, string $operational): void
    {
        $timeline = match ($operational) {
            'created' => [['status' => 'created', 'notes' => 'Shipment dibuat dari booking', 'offset_days' => -2]],
            'booking_created' => [
                ['status' => 'created', 'notes' => 'Shipment dibuat', 'offset_days' => -3],
                ['status' => 'booking_created', 'notes' => 'Booking dikonfirmasi', 'offset_days' => -2],
            ],
            'survey_completed' => [
                ['status' => 'created', 'notes' => 'Shipment dibuat', 'offset_days' => -4],
                ['status' => 'booking_created', 'notes' => 'Booking dikonfirmasi', 'offset_days' => -3],
                ['status' => 'survey_completed', 'notes' => 'Survey lokasi selesai', 'offset_days' => -1],
            ],
            'cargo_received' => [
                ['status' => 'created', 'notes' => 'Shipment dibuat', 'offset_days' => -5],
                ['status' => 'booking_created', 'notes' => 'Booking dikonfirmasi', 'offset_days' => -4],
                ['status' => 'cargo_received', 'notes' => 'Kargo diterima di gudang asal', 'offset_days' => -1],
            ],
            'stuffing_container' => [
                ['status' => 'created', 'notes' => 'Shipment dibuat', 'offset_days' => -6],
                ['status' => 'cargo_received', 'notes' => 'Kargo diterima di gudang', 'offset_days' => -3],
                ['status' => 'stuffing_container', 'notes' => 'Pemuatan ke kontainer', 'offset_days' => -1],
            ],
            'departed' => [
                ['status' => 'created', 'notes' => 'Shipment dibuat', 'offset_days' => -7],
                ['status' => 'cargo_received', 'notes' => 'Kargo diterima di gudang', 'offset_days' => -4],
                ['status' => 'container_sealed', 'notes' => 'Kontainer disegel', 'offset_days' => -2],
                ['status' => 'departed', 'notes' => 'Kontainer diberangkatkan', 'offset_days' => 0],
            ],
            'arrived' => [
                ['status' => 'created', 'notes' => 'Shipment dibuat', 'offset_days' => -8],
                ['status' => 'departed', 'notes' => 'Kontainer berangkat', 'offset_days' => -3],
                ['status' => 'train_arrived', 'notes' => 'Tiba di stasiun tujuan', 'offset_days' => -1],
                ['status' => 'arrived', 'notes' => 'Tiba di hub tujuan', 'offset_days' => 0],
            ],
            'container_unloading' => [
                ['status' => 'created', 'notes' => 'Shipment dibuat', 'offset_days' => -10],
                ['status' => 'departed', 'notes' => 'Kontainer berangkat', 'offset_days' => -5],
                ['status' => 'arrived', 'notes' => 'Tiba di hub tujuan', 'offset_days' => -2],
                ['status' => 'container_unloading', 'notes' => 'Pembongkaran kontainer', 'offset_days' => 0],
            ],
            'ready_for_pickup' => [
                ['status' => 'created', 'notes' => 'Shipment dibuat', 'offset_days' => -12],
                ['status' => 'arrived', 'notes' => 'Tiba di hub tujuan', 'offset_days' => -4],
                ['status' => 'container_unloading', 'notes' => 'Pembongkaran kontainer', 'offset_days' => -2],
                ['status' => 'ready_for_pickup', 'notes' => 'Siap diambil consignee', 'offset_days' => 0],
            ],
            'completed' => [
                ['status' => 'created', 'notes' => 'Shipment dibuat', 'offset_days' => -15],
                ['status' => 'departed', 'notes' => 'Kontainer berangkat', 'offset_days' => -10],
                ['status' => 'arrived', 'notes' => 'Tiba di hub tujuan', 'offset_days' => -6],
                ['status' => 'container_unloading', 'notes' => 'Pembongkaran kontainer', 'offset_days' => -4],
                ['status' => 'ready_for_pickup', 'notes' => 'Siap diambil', 'offset_days' => -2],
                ['status' => 'completed', 'notes' => 'Pengiriman selesai', 'offset_days' => 0],
            ],
            'cancelled' => [
                ['status' => 'created', 'notes' => 'Shipment dibuat', 'offset_days' => -3],
                ['status' => 'cancelled', 'notes' => 'Shipment dibatalkan', 'offset_days' => -1],
            ],
            default => [],
        };

        foreach ($timeline as $entry) {
            ShipmentTracking::create([
                'shipment_id' => $shipment->id,
                'status' => $entry['status'],
                'notes' => $entry['notes'],
                'tracked_at' => $shipment->created_at?->copy()->addDays($entry['offset_days']) ?? now()->addDays($entry['offset_days']),
                'updated_by' => $shipment->created_by,
            ]);
        }
    }

    private function seedInvoices(\Illuminate\Support\Collection $bookings, $customerUsers): void
    {
        $approved = $bookings->filter(fn (Booking $b) => $b->status === Booking::STATUS_APPROVED)->values();
        $shipments = Shipment::with('booking')->get()->keyBy('booking_id');

        $finance = $customerUsers->firstWhere('email', 'finance@customer.test');
        $ops = $customerUsers->firstWhere('email', 'ops@customer.test');

        $scenarios = [
            ['status' => 'draft', 'count' => 1, 'issued_offset' => null, 'due_offset' => null, 'paid_amount' => 0],
            ['status' => 'issued', 'count' => 3, 'issued_offset' => -5, 'due_offset' => 9, 'paid_amount' => 0],
            ['status' => 'partially_paid', 'count' => 2, 'issued_offset' => -15, 'due_offset' => -1, 'paid_amount' => 0.5],
            ['status' => 'paid', 'count' => 2, 'issued_offset' => -25, 'due_offset' => 5, 'paid_amount' => 1.0],
            ['status' => 'issued', 'count' => 2, 'issued_offset' => -30, 'due_offset' => -5, 'paid_amount' => 0, 'overdue' => true],
        ];

        $cursor = 0;
        foreach ($scenarios as $scenario) {
            for ($i = 0; $i < $scenario['count']; $i++) {
                if (! isset($approved[$cursor])) {
                    $cursor = 0;
                }
                $booking = $approved[$cursor] ?? $approved->first();
                if (! $booking) {
                    continue;
                }
                $cursor++;

                $shipment = $shipments->get($booking->id);
                $this->seedInvoice(
                    $booking,
                    $shipment,
                    $finance,
                    $ops,
                    $scenario['status'],
                    $scenario['issued_offset'],
                    $scenario['due_offset'],
                    $scenario['paid_amount'],
                    $scenario['overdue'] ?? false
                );
            }
        }
    }

    private function seedInvoice(
        Booking $booking,
        ?Shipment $shipment,
        User $finance,
        User $ops,
        string $status,
        ?int $issuedOffset,
        ?int $dueOffset,
        float $paidRatio,
        bool $overdue = false
    ): void {
        $subtotal = (float) ($booking->estimated_price ?? 10000000);
        $tax = round($subtotal * 0.11, 2);
        $total = $subtotal + $tax;

        $issuedDate = $issuedOffset !== null ? now()->addDays($issuedOffset)->toDateString() : null;
        $dueDate = $dueOffset !== null ? now()->addDays($dueOffset)->toDateString() : null;

        $company = Company::find($booking->company_id);

        $invoice = Invoice::create([
            'shipment_id' => $shipment?->id,
            'company_id' => $booking->company_id,
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'issued_date' => $issuedDate,
            'due_date' => $dueDate,
            'status' => $status,
            'notes' => $status === 'draft' ? 'Invoice ini masih dalam draft, belum diterbitkan.' : null,
            'created_by' => $finance->id,
            'company_snapshot' => [
                'name' => $company->name,
                'address' => $company->address,
                'npwp' => $company->npwp,
                'payment_terms' => ($company->postpaid_term_days ?? 14).' days',
            ],
            'shipment_snapshot' => $shipment ? [
                'shipment_no' => $shipment->shipment_number,
                'waybill_number' => $shipment->waybill_number,
                'booking_no' => $booking->booking_number,
                'service_type' => $shipment->serviceType?->name,
                'shipment_coverage' => $shipment->shipment_coverage,
                'origin' => $shipment->originLocation?->name,
                'destination' => $shipment->destinationLocation?->name,
            ] : null,
        ]);

        $items = [
            ['description' => 'Rail Freight', 'quantity' => 1, 'unit_price' => $subtotal * 0.7],
            ['description' => 'Pickup Trucking', 'quantity' => 1, 'unit_price' => $subtotal * 0.1],
            ['description' => 'Delivery Trucking', 'quantity' => 1, 'unit_price' => $subtotal * 0.1],
            ['description' => 'Additional Charge', 'quantity' => 1, 'unit_price' => $subtotal * 0.1],
        ];

        foreach ($items as $item) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => round($item['unit_price'], 2),
                'total_price' => round($item['unit_price'] * $item['quantity'], 2),
            ]);
        }

        if ($status !== 'draft') {
            InvoiceActivity::create([
                'invoice_id' => $invoice->id,
                'actor_user_id' => $finance->id,
                'event_key' => 'invoice_issued',
                'description' => 'Invoice diterbitkan oleh tim finance',
                'occurred_at' => now()->addDays($issuedOffset ?? 0),
            ]);
        }

        if ($paidRatio > 0) {
            $paidAmount = round($total * $paidRatio, 2);
            Payment::create([
                'invoice_id' => $invoice->id,
                'midtrans_order_id' => 'SEED-'.$invoice->id.'-'.uniqid(),
                'midtrans_transaction_id' => 'TX-'.strtoupper(uniqid()),
                'amount' => $paidAmount,
                'payment_type' => $paidRatio >= 1 ? 'bank_transfer' : 'partial_bank',
                'status' => 'success',
                'paid_at' => now()->subDays(1),
            ]);
            InvoiceActivity::create([
                'invoice_id' => $invoice->id,
                'actor_user_id' => $finance->id,
                'event_key' => 'payment_received',
                'description' => 'Pembayaran diterima via bank transfer',
                'occurred_at' => now()->subDays(1),
            ]);
        }

        $invoice->refresh();
        $invoice->syncStatusFromPayments();

        if ($invoice->status === 'paid') {
            InvoiceActivity::create([
                'invoice_id' => $invoice->id,
                'actor_user_id' => $finance->id,
                'event_key' => 'status_paid',
                'description' => 'Status invoice menjadi Paid',
                'occurred_at' => now(),
            ]);
        }

        $this->seedBookingAttachment($booking, $ops, $shipment, $invoice);
    }

    private function seedBookingAttachment(Booking $booking, User $ops, ?Shipment $shipment, Invoice $invoice): void
    {
        BookingAttachment::create([
            'booking_id' => $booking->id,
            'uploaded_by' => $ops->id,
            'file_path' => 'seed/attachments/'.strtolower($booking->booking_number).'-invoice.pdf',
            'original_name' => $booking->booking_number.'-Invoice.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 184320,
            'category' => 'invoice',
            'document_type' => 'invoice',
            'remarks' => 'Lampiran invoice PDF hasil seed.',
        ]);
    }
}
