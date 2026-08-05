<?php

namespace Database\Seeders;

use App\Enums\CompanyDocumentType;
use App\Enums\LocationStatus;
use App\Enums\LocationType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingAttachment;
use App\Models\Branch;
use App\Models\CargoCategory;
use App\Models\Company;
use App\Models\CompanyActivity;
use App\Models\CompanyDocument;
use App\Models\ContainerType;
use App\Models\CustomerLocation;
use App\Models\Invoice;
use App\Models\InvoiceActivity;
use App\Models\InvoiceItem;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentActivity;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShipmentTracking;
use App\Models\TransportMode;
use App\Models\User;
use App\Services\LocationCodeService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Customer Demo Seeder
 *
 * Menghasilkan data demo untuk role customer (company_admin, ops_pic, finance_pic, viewer)
 * dengan cakupan semua status & state penting pada modul:
 *  - Company Profile (info + commercial + documents + activities)
 *  - Locations (head office, branch, warehouse, dengan activities)
 *  - Users (admin, ops, finance, viewer)
 *  - Bookings   (draft, submitted, approved, rejected)
 *  - Shipments  (planning, in_progress, completed, cancelled)
 *  - Documents  (booking attachment, CN, DO, POD, invoice, tax invoice, payment receipt)
 *  - Invoices   (draft, issued, partially_paid, paid, overdue)
 *
 * Strategi: TRUNCATE + seed ulang. Idempotent (aman dipanggil berulang).
 *
 * Akun demo (password: "password"):
 *  - admin@customer.test   → company_admin
 *  - ops@customer.test     → ops_pic
 *  - finance@customer.test → finance_pic
 *  - viewer@customer.test  → viewer (read-only)
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

        $admin = $this->seedUser($mainCompany, 'admin@customer.test', 'Demo Company Admin', UserRole::CompanyAdmin);
        $ops = $this->seedUser($mainCompany, 'ops@customer.test', 'Demo Ops PIC', UserRole::OpsPic);
        $finance = $this->seedUser($mainCompany, 'finance@customer.test', 'Demo Finance PIC', UserRole::FinancePic);
        $viewer = $this->seedUser($mainCompany, 'viewer@customer.test', 'Demo Viewer', UserRole::Viewer);
        $inactive = $this->seedUser($mainCompany, 'former@customer.test', 'Demo Former PIC', UserRole::OpsPic, UserStatus::Inactive);

        $customerUsers = collect([$admin, $ops, $finance, $viewer, $inactive]);

        $locations = $this->seedLocations($mainCompany, $admin);
        $this->syncLocationAccess($admin, $locations->pluck('id')->all());
        $this->syncLocationAccess($ops, $locations->pluck('id')->all());
        $this->syncLocationAccess($finance, $locations->pluck('id')->all());
        $this->syncLocationAccess($viewer, $locations->pluck('id')->all());

        $this->seedCompanyDocuments($mainCompany, $admin);
        $this->seedCompanyActivities($mainCompany, $admin, $locations);

        $bookings = $this->seedBookings($mainCompany, $customerUsers);
        $this->seedShipments($bookings, $customerUsers);
        $this->seedInvoices($bookings, $customerUsers);

        $this->command?->info('Customer demo data seeded: 1 main company + 4 PIC users + 4 locations + 2 documents + activities.');
    }

    private function truncateCustomerData(): void
    {
        $tables = [
            'payment_proof_attachments',
            'payment_activities',
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
            'company_activities',
            'company_documents',
            'user_location_access',
            'customer_locations',
            'model_has_roles',
            'model_has_permissions',
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
            'city' => 'Kota Jakarta Selatan',
            'province' => 'DKI Jakarta',
            'country' => 'Indonesia',
            'district' => 'Kebayoran Baru',
            'postal_code' => '12190',
            'business_category' => 'manufacturing',
            'business_category_other' => 'Manufaktur',
            'monthly_shipment_estimate' => '50_to_100',
            'website' => 'https://www.abc-indonesia.co.id',
            'contact_person' => 'Andi Wijaya',
            'email' => 'finance@abc-indonesia.co.id',
            'phone' => '021-555-0188',
            'status' => 'active',
            'billing_type' => 'postpaid',
            'pricing_type' => 'standard',
            'discount_percent' => 5.00,
            'billing_cycle' => 'end_of_month',
            'payment_term' => 'net_14',
            'credit_limit' => 50000000,
            'current_deposit_balance' => 2500000,
            'outstanding_balance' => 0,
            'payment_type' => 'postpaid',
            'postpaid_term_days' => 14,
            'manual_payment_enabled' => true,
            'bank_name' => 'BCA',
            'bank_account_number' => '123-456-7890',
            'bank_account_name' => 'PT ABC Indonesia',
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
            'city' => 'Kota Bandung',
            'province' => 'Jawa Barat',
            'country' => 'Indonesia',
            'district' => 'Coblong',
            'postal_code' => '40112',
            'business_category' => 'distributor',
            'business_category_other' => 'Distribusi',
            'monthly_shipment_estimate' => '10_to_50',
            'website' => 'https://www.sembilan-jaya.co.id',
            'contact_person' => 'Rina Kartika',
            'email' => 'finance@sembilan-jaya.co.id',
            'phone' => '022-555-9911',
            'status' => 'active',
            'billing_type' => 'prepaid',
            'pricing_type' => 'discount',
            'discount_percent' => 2.50,
            'billing_cycle' => 'half_monthly_1',
            'payment_term' => 'cod',
            'credit_limit' => 0,
            'current_deposit_balance' => 1000000,
            'outstanding_balance' => 0,
            'payment_type' => 'postpaid',
            'postpaid_term_days' => 30,
            'manual_payment_enabled' => true,
            'bank_name' => 'Mandiri',
            'bank_account_number' => '987-654-3210',
            'bank_account_name' => 'PT Sembilan Jaya',
        ]);
    }

    private function seedUser(Company $company, string $email, string $name, UserRole $role, UserStatus $status = UserStatus::Active): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt(self::PASSWORD),
            'phone' => '0812'.str_pad((string) (random_int(1000000, 9999999)), 7, '0', STR_PAD_LEFT),
            'status' => $status,
            'user_type' => 'customer',
            'company_id' => $company->id,
            'last_login_at' => $role === UserRole::CompanyAdmin ? now() : null,
            'feature_access' => $role->defaultFeatureAccess(),
        ]);
        $user->syncRoles([$role->value]);

        return $user;
    }

    private function seedLocations(Company $company, User $actor): Collection
    {
        $service = app(LocationCodeService::class);
        $data = [
            [
                'type' => LocationType::HeadOffice,
                'name' => 'PT ABC Indonesia — Head Office Jakarta',
                'phone' => '021-555-0188',
                'country' => 'Indonesia',
                'province' => 'DKI Jakarta',
                'city' => 'Kota Jakarta Selatan',
                'district' => 'Kebayoran Baru',
                'postal_code' => '12190',
                'address' => 'Jl. Sudirman Kav. 45, Kebayoran Baru 12190',
                'pic_name' => 'Andi Wijaya',
                'pic_email' => 'andi.wijaya@abc-indonesia.co.id',
                'pic_mobile' => '0812-3456-7890',
            ],
            [
                'type' => LocationType::BranchOffice,
                'name' => 'PT ABC Indonesia — Branch Office Surabaya',
                'phone' => '031-555-1010',
                'country' => 'Indonesia',
                'province' => 'Jawa Timur',
                'city' => 'Kota Surabaya',
                'district' => 'Genteng',
                'postal_code' => '60275',
                'address' => 'Jl. Tunjungan No. 88, Genteng 60275',
                'pic_name' => 'Budi Santoso',
                'pic_email' => 'budi.santoso@abc-indonesia.co.id',
                'pic_mobile' => '0813-1234-5678',
            ],
            [
                'type' => LocationType::BranchOffice,
                'name' => 'PT ABC Indonesia — Branch Office Medan',
                'phone' => '061-555-2020',
                'country' => 'Indonesia',
                'province' => 'Sumatera Utara',
                'city' => 'Kota Medan',
                'district' => 'Medan Polonia',
                'postal_code' => '20157',
                'address' => 'Jl. Gatot Subroto No. 99, Medan Polonia 20157',
                'pic_name' => 'Citra Lestari',
                'pic_email' => 'citra.lestari@abc-indonesia.co.id',
                'pic_mobile' => '0814-9876-5432',
            ],
            [
                'type' => LocationType::Warehouse,
                'name' => 'PT ABC Indonesia — Warehouse Bandung',
                'phone' => '022-555-3030',
                'country' => 'Indonesia',
                'province' => 'Jawa Barat',
                'city' => 'Kota Bandung',
                'district' => 'Cibiru',
                'postal_code' => '40614',
                'address' => 'Jl. Raya Cibiru No. 12, Cibiru 40614',
                'pic_name' => 'Dedi Kurniawan',
                'pic_email' => 'dedi.kurniawan@abc-indonesia.co.id',
                'pic_mobile' => '0815-5555-1234',
            ],
        ];

        $locations = collect();
        $statusForIndex = [LocationStatus::Active, LocationStatus::Active, LocationStatus::Active, LocationStatus::Inactive];
        foreach ($data as $idx => $row) {
            $row['company_id'] = $company->id;
            $row['code'] = $service->next($company->id);
            $row['status'] = $statusForIndex[$idx] ?? LocationStatus::Active;
            $location = CustomerLocation::create($row);

            CompanyActivity::create([
                'subject_type' => CustomerLocation::class,
                'subject_id' => $location->id,
                'event_key' => 'location_created',
                'description' => 'Location dibuat.',
                'meta' => ['code' => $location->code, 'name' => $location->name, 'type' => $location->type->value],
                'actor_user_id' => $actor->id,
                'occurred_at' => $location->created_at,
            ]);

            $locations->push($location);
        }

        return $locations;
    }

    private function syncLocationAccess(User $user, array $locationIds): void
    {
        $user->locationAccess()->sync($locationIds);
    }

    private function seedCompanyDocuments(Company $company, User $actor): void
    {
        $basePath = storage_path('app/private/company-documents/'.$company->id);
        if (! is_dir($basePath)) {
            @mkdir($basePath, 0755, true);
        }

        $documents = [
            [
                'type' => CompanyDocumentType::Npwp,
                'label' => 'NPWP PT ABC Indonesia',
                'content' => "DUMMY NPWP\nPT ABC Indonesia\n01.234.567.8-901.000\n",
            ],
            [
                'type' => CompanyDocumentType::Nib,
                'label' => 'NIB PT ABC Indonesia',
                'content' => "DUMMY NIB\nPT ABC Indonesia\n9120000012345\n",
            ],
        ];

        foreach ($documents as $doc) {
            $typeDir = $basePath.'/'.$doc['type']->value;
            if (! is_dir($typeDir)) {
                @mkdir($typeDir, 0755, true);
            }
            $filename = Str::uuid()->toString().'.txt';
            $relativePath = "company-documents/{$company->id}/{$doc['type']->value}/{$filename}";
            $absolutePath = storage_path('app/private/'.$relativePath);
            file_put_contents($absolutePath, $doc['content']);

            $document = CompanyDocument::create([
                'company_id' => $company->id,
                'type' => $doc['type']->value,
                'label' => $doc['label'],
                'file_path' => $relativePath,
                'file_name' => $filename,
                'file_size' => strlen($doc['content']),
                'mime_type' => 'text/plain',
                'uploaded_by_user_id' => $actor->id,
            ]);

            CompanyActivity::create([
                'subject_type' => Company::class,
                'subject_id' => $company->id,
                'event_key' => 'company_document_uploaded',
                'description' => 'Dokumen '.strtoupper($doc['type']->value).' diunggah.',
                'meta' => ['document_id' => $document->id, 'type' => $doc['type']->value],
                'actor_user_id' => $actor->id,
                'occurred_at' => $document->created_at,
            ]);
        }
    }

    private function seedCompanyActivities(Company $company, User $actor, Collection $locations): void
    {
        CompanyActivity::create([
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'event_key' => 'company_created',
            'description' => 'Perusahaan dibuat.',
            'meta' => ['name' => $company->name, 'company_code' => $company->company_code],
            'actor_user_id' => $actor->id,
            'occurred_at' => $company->created_at,
        ]);

        CompanyActivity::create([
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'event_key' => 'company_profile_updated',
            'description' => 'Profile perusahaan diperbarui.',
            'meta' => ['changes' => ['address' => 'Jl. Sudirman Kav. 45']],
            'actor_user_id' => $actor->id,
            'occurred_at' => now()->subDays(2),
        ]);
    }

    private function seedBookings(Company $company, $customerUsers): Collection
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

    private function seedShipments(Collection $bookings, $customerUsers): void
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

    private function seedInvoices(Collection $bookings, $customerUsers): void
    {
        $approved = $bookings->filter(fn (Booking $b) => $b->status === Booking::STATUS_APPROVED)->values();
        $shipments = Shipment::with('booking')->get()->keyBy('booking_id');

        $finance = $customerUsers->firstWhere('email', 'finance@customer.test');
        $ops = $customerUsers->firstWhere('email', 'ops@customer.test');

        $scenarios = [
            ['status' => 'draft', 'count' => 1, 'issued_offset' => null, 'due_offset' => null, 'paid_amount' => 0, 'payment_type' => null],
            ['status' => 'issued', 'count' => 3, 'issued_offset' => -5, 'due_offset' => 9, 'paid_amount' => 0, 'payment_type' => 'pending'],
            ['status' => 'partially_paid', 'count' => 2, 'issued_offset' => -15, 'due_offset' => -1, 'paid_amount' => 0.5, 'payment_type' => 'success'],
            ['status' => 'paid', 'count' => 2, 'issued_offset' => -25, 'due_offset' => 5, 'paid_amount' => 1.0, 'payment_type' => 'success'],
            ['status' => 'issued', 'count' => 2, 'issued_offset' => -30, 'due_offset' => -5, 'paid_amount' => 0, 'overdue' => true, 'payment_type' => 'pending'],
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
                    $scenario['overdue'] ?? false,
                    $scenario['payment_type'] ?? null
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
        bool $overdue = false,
        ?string $paymentType = null
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
            $payment = $this->createSeededPayment(
                $invoice,
                $paidAmount,
                $finance,
                'success',
                'bank_transfer',
                Payment::METHOD_TRANSFER,
                now()->subDays(1)
            );

            InvoiceActivity::create([
                'invoice_id' => $invoice->id,
                'actor_user_id' => $finance->id,
                'event_key' => 'payment_received',
                'description' => 'Pembayaran diterima via bank transfer',
                'occurred_at' => now()->subDays(1),
            ]);
        } elseif ($paymentType === 'pending' && $status !== 'draft') {
            $this->createSeededPayment(
                $invoice,
                $total,
                $finance,
                'pending',
                null,
                Payment::METHOD_MIDTRANS,
                null,
                now()->addDay()
            );
        } elseif ($status === 'partially_paid') {
            $halfAmount = round($total * 0.5, 2);
            $this->createSeededPayment(
                $invoice,
                $halfAmount,
                $finance,
                'pending',
                null,
                Payment::METHOD_TRANSFER,
                null,
                now()->addDay(),
                Payment::MANUAL_SUBMITTED,
                [
                    'payment_date' => now()->subDays(2)->toDateString(),
                    'bank_name' => 'BCA',
                    'reference_number' => 'TRF-'.strtoupper(uniqid()),
                    'remark' => 'Sebagian pembayaran menunggu verifikasi tim finance.',
                ]
            );
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

    private function createSeededPayment(
        Invoice $invoice,
        float $amount,
        User $actor,
        string $status,
        ?string $paymentType,
        string $method,
        ?Carbon $paidAt = null,
        ?Carbon $expiredAt = null,
        string $manualStatus = Payment::MANUAL_UNSUBMITTED,
        array $manualMeta = []
    ): Payment {
        $nextNumber = (int) (Payment::query()
            ->whereHas('invoice', fn ($q) => $q->where('company_id', $invoice->company_id))
            ->max('payment_number') ?? 0) + 1;

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'payment_number' => $nextNumber,
            'midtrans_order_id' => $status === 'pending' ? 'SEED-MT-'.$invoice->id.'-'.uniqid() : null,
            'midtrans_transaction_id' => $status === 'success' ? 'TX-'.strtoupper(uniqid()) : null,
            'amount' => $amount,
            'payment_type' => $paymentType,
            'method' => $method,
            'status' => $status,
            'paid_at' => $paidAt,
            'expired_at' => $expiredAt,
            'manual_status' => $manualStatus,
            'manual_payment_date' => $manualMeta['payment_date'] ?? null,
            'manual_bank_name' => $manualMeta['bank_name'] ?? null,
            'manual_reference_number' => $manualMeta['reference_number'] ?? null,
            'manual_remark' => $manualMeta['remark'] ?? null,
            'manual_submitted_at' => $manualStatus !== Payment::MANUAL_UNSUBMITTED ? now()->subDay() : null,
            'midtrans_response' => $status === 'pending' ? [
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/'.bin2hex(random_bytes(6)),
                'token' => bin2hex(random_bytes(12)),
                'expiry_time' => $expiredAt?->toIso8601String(),
                'seed' => true,
            ] : null,
        ]);

        $this->logPaymentActivity($payment, $actor, 'payment_created', 'Payment dibuat via seed.', now()->subDays(2));

        if ($status === 'pending' && $method === Payment::METHOD_MIDTRANS) {
            $this->logPaymentActivity($payment, $actor, 'payment_link_generated', 'Payment Link dibuat via Midtrans Snap.', now()->subDays(2));
        }

        if ($status === 'success') {
            $this->logPaymentActivity($payment, $actor, 'midtrans_callback', 'Midtrans mengirim callback settlement.', $paidAt ?? now()->subDay());
            $this->logPaymentActivity($payment, $actor, 'payment_settled', 'Pembayaran berhasil (Settlement).', $paidAt ?? now()->subDay());
        }

        if ($manualStatus === Payment::MANUAL_SUBMITTED) {
            $this->logPaymentActivity(
                $payment,
                $actor,
                'payment_proof_uploaded',
                'Customer mengunggah bukti pembayaran manual.',
                now()->subDay(),
                ['bank_name' => $manualMeta['bank_name'] ?? null]
            );
        }

        return $payment;
    }

    private function logPaymentActivity(
        Payment $payment,
        ?User $actor,
        string $eventKey,
        string $description,
        Carbon $occurredAt,
        array $meta = []
    ): void {
        if (! Schema::hasTable('payment_activities')) {
            return;
        }

        PaymentActivity::create([
            'payment_id' => $payment->id,
            'actor_user_id' => $actor?->id,
            'event_key' => $eventKey,
            'description' => $description,
            'meta' => $meta ?: null,
            'occurred_at' => $occurredAt,
        ]);
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
