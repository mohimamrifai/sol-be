<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingPackage;
use App\Models\CargoCategory;
use App\Models\Company;
use App\Models\CustomerLocation;
use App\Models\DgClass;
use App\Models\Location;
use App\Models\ServiceType;
use App\Models\TransportMode;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\NumberingFormatSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBookingFsdTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private CustomerLocation $shipperLocation;

    private Location $origin;

    private Location $destination;

    private ServiceType $lclService;

    private TransportMode $mode;

    private CargoCategory $generalCargo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MasterDataSeeder::class);
        $this->seed(NumberingFormatSeeder::class);

        $this->admin = User::factory()->create(['user_type' => 'internal', 'status' => 'active']);
        $this->admin->syncRoles(['super_admin']);

        $this->company = Company::create([
            'name' => 'Admin Booking Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'AB01',
        ]);

        $this->origin = Location::firstOrFail();
        $this->destination = Location::skip(1)->first() ?? Location::firstOrFail();
        $this->mode = TransportMode::firstOrFail();
        $this->lclService = ServiceType::where('code', 'LCL')->firstOrFail();
        $this->generalCargo = CargoCategory::where('code', 'GEN')->firstOrFail();

        $this->shipperLocation = CustomerLocation::create([
            'company_id' => $this->company->id,
            'code' => 'HO-ADM',
            'name' => 'Admin Test Location',
            'type' => 'head_office',
            'phone' => '08123456789',
            'status' => 'active',
            'country' => 'Indonesia',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta Pusat',
            'district' => 'Menteng',
            'postal_code' => '10310',
            'address' => 'Jl. Admin 1',
            'pic_name' => 'Admin PIC',
            'pic_email' => 'admin@example.com',
            'pic_mobile' => '08123456789',
        ]);
    }

    public function test_admin_stats_include_draft_submitted_confirmed(): void
    {
        $this->createBooking('draft');
        $this->createBooking('submitted');
        $this->createBooking('approved');

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/admin/bookings/stats')->assertOk();

        $this->assertSame(1, $response->json('data.draft'));
        $this->assertSame(1, $response->json('data.submitted'));
        $this->assertSame(1, $response->json('data.confirmed'));
        $this->assertArrayNotHasKey('under_review', $response->json('data'));
    }

    public function test_submitted_filter_excludes_legacy_under_review_rows(): void
    {
        $submitted = $this->createBooking('submitted');
        $legacy = $this->createBooking('draft');
        Booking::query()->whereKey($legacy->id)->update(['status' => 'under_review']);

        Sanctum::actingAs($this->admin);

        $ids = collect($this->getJson('/api/admin/bookings?status=submitted')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($submitted->id, $ids);
        $this->assertNotContains($legacy->id, $ids);
    }

    public function test_reject_submitted_booking_logs_activity(): void
    {
        $booking = $this->createBooking('submitted');
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/bookings/{$booking->id}/reject", [
            'reason' => 'Data tidak lengkap',
        ])->assertOk();

        $booking->refresh();
        $this->assertSame('rejected', $booking->status);
        $this->assertTrue(
            BookingActivity::query()
                ->where('booking_id', $booking->id)
                ->where('activity_type', 'booking_rejected')
                ->exists()
        );
    }

    public function test_reject_confirmed_booking_is_not_allowed(): void
    {
        $booking = $this->createBooking('approved');
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/bookings/{$booking->id}/reject", [
            'reason' => 'Should fail',
        ])->assertStatus(422);
    }

    public function test_admin_can_edit_submitted_and_confirmed_bookings(): void
    {
        Sanctum::actingAs($this->admin);

        $submitted = $this->createBooking('submitted');
        $confirmed = $this->createBooking('approved');

        $this->putJson("/api/admin/bookings/{$submitted->id}", $this->adminUpdatePayload([
            'shipper_name' => 'Shipper Submitted Updated',
        ]))->assertOk();

        $this->putJson("/api/admin/bookings/{$confirmed->id}", $this->adminUpdatePayload([
            'shipper_name' => 'Shipper Confirmed Updated',
        ]))->assertOk();

        $this->assertSame('Shipper Submitted Updated', $submitted->fresh()->shipper_name);
        $this->assertSame('Shipper Confirmed Updated', $confirmed->fresh()->shipper_name);
    }

    public function test_admin_cannot_edit_rejected_or_cancelled_bookings(): void
    {
        Sanctum::actingAs($this->admin);

        $rejected = $this->createBooking('rejected');
        $cancelled = $this->createBooking('cancelled');

        $this->putJson("/api/admin/bookings/{$rejected->id}", $this->adminUpdatePayload())
            ->assertStatus(422);
        $this->putJson("/api/admin/bookings/{$cancelled->id}", $this->adminUpdatePayload())
            ->assertStatus(422);
    }

    public function test_customer_booking_is_editable_only_in_draft(): void
    {
        $draft = $this->createBooking('draft');
        $submitted = $this->createBooking('submitted');

        $this->assertTrue($draft->isEditable());
        $this->assertFalse($submitted->isEditable());
        $this->assertTrue($submitted->isAdminEditable());
    }

    public function test_only_draft_booking_can_be_deleted(): void
    {
        $draft = $this->createBooking('draft');
        $rejected = $this->createBooking('rejected');
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/admin/bookings/{$draft->id}")->assertOk();
        $this->deleteJson("/api/admin/bookings/{$rejected->id}")->assertStatus(422);
    }

    public function test_duplicate_copies_packages_and_creates_draft(): void
    {
        $booking = $this->createBooking('submitted');
        BookingPackage::create([
            'booking_id' => $booking->id,
            'sequence' => 1,
            'description' => 'Carton Elektronik',
            'package_type' => 'Carton',
            'piece_count' => 1,
            'weight_kg' => 120,
            'length' => 100,
            'width' => 80,
            'height' => 60,
            'cargo_category_id' => $this->generalCargo->id,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/admin/bookings/{$booking->id}/duplicate")->assertCreated();
        $newId = (int) $response->json('data.id');
        $copy = Booking::with('packages')->findOrFail($newId);

        $this->assertSame('draft', $copy->status);
        $this->assertNotSame($booking->booking_number, $copy->booking_number);
        $this->assertCount(1, $copy->packages);
        $this->assertSame($this->generalCargo->id, $copy->packages->first()->cargo_category_id);
    }

    public function test_admin_list_search_matches_booking_number_and_customer(): void
    {
        $booking = $this->createBooking('draft');
        $booking->update(['booking_number' => 'BK-TEST-00001']);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/bookings?search=BK-TEST-00001')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/admin/bookings?search='.urlencode($this->company->name))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_list_includes_shipment_id_for_converted_booking(): void
    {
        $booking = $this->createBooking('approved');
        $shipment = \App\Models\Shipment::create([
            'booking_id' => $booking->id,
            'company_id' => $booking->company_id,
            'origin_location_id' => $booking->origin_location_id,
            'destination_location_id' => $booking->destination_location_id,
            'transport_mode_id' => $booking->transport_mode_id,
            'service_type_id' => $booking->service_type_id,
            'status' => 'created',
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/admin/bookings?search='.$booking->booking_number)->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $booking->id);

        $this->assertNotNull($row);
        $this->assertSame($shipment->id, $row['shipment_id']);
        $this->assertTrue($row['shipment_exists']);
    }

    public function test_store_derives_cargo_category_from_packages(): void
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'company_id' => $this->company->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'transport_mode_id' => $this->mode->id,
            'service_type_id' => $this->lclService->id,
            'shipment_coverage' => 'port_to_port',
            'shipper_name' => $this->shipperLocation->name,
            'shipper_address' => $this->shipperLocation->address,
            'shipper_phone' => $this->shipperLocation->pic_mobile,
            'shipper_location_id' => $this->shipperLocation->id,
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Addr',
            'consignee_phone' => '08222',
            'is_draft' => 0,
            'packages' => [
                [
                    'description' => 'Carton Elektronik',
                    'package_type' => 'Carton',
                    'piece_count' => 1,
                    'weight_kg' => 120,
                    'length' => 100,
                    'width' => 80,
                    'height' => 60,
                    'cargo_category_id' => $this->generalCargo->id,
                ],
            ],
        ];

        $response = $this->postJson('/api/admin/bookings', $payload)->assertCreated();
        $booking = Booking::findOrFail($response->json('data.id'));

        $this->assertSame($this->generalCargo->id, $booking->cargo_category_id);
    }

    public function test_editing_dangerous_goods_booking_keeps_stored_msds_without_reupload(): void
    {
        Sanctum::actingAs($this->admin);

        $dgCargo = CargoCategory::where('code', 'DG')->firstOrFail();
        $dgClass = $this->dgClass();
        $booking = $this->createBooking('draft');

        $package = BookingPackage::create([
            'booking_id' => $booking->id,
            'sequence' => 1,
            'description' => 'Cairan Mudah Terbakar',
            'piece_count' => 2,
            'weight_kg' => 50,
            'cargo_category_id' => $dgCargo->id,
            'is_dangerous_goods' => true,
            'dg_class_id' => $dgClass->id,
            'un_number' => 'UN1203',
            'packing_group' => 'II',
            'proper_shipping_name' => 'Gasoline',
            'msds_file_path' => 'msds_files/existing-msds.pdf',
        ]);

        $this->putJson("/api/admin/bookings/{$booking->id}", [
            'company_id' => $this->company->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'transport_mode_id' => $this->mode->id,
            'service_type_id' => $this->lclService->id,
            'shipment_coverage' => 'port_to_port',
            'shipper_name' => $this->shipperLocation->name,
            'shipper_address' => $this->shipperLocation->address,
            'shipper_phone' => $this->shipperLocation->pic_mobile,
            'shipper_location_id' => $this->shipperLocation->id,
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Addr',
            'consignee_phone' => '08222',
            'packages' => [
                [
                    'description' => 'Cairan Mudah Terbakar (revisi)',
                    'piece_count' => 3,
                    'weight_kg' => 75,
                    'cargo_category_id' => $dgCargo->id,
                    'is_dangerous_goods' => 1,
                    'dg_class_id' => $dgClass->id,
                    'un_number' => 'UN1203',
                    'packing_group' => 'II',
                    'proper_shipping_name' => 'Gasoline',
                    'msds_file_path' => $package->msds_file_path,
                ],
            ],
        ])->assertOk();

        $updated = $booking->packages()->firstOrFail();
        $this->assertSame('Cairan Mudah Terbakar (revisi)', $updated->description);
        $this->assertSame('msds_files/existing-msds.pdf', $updated->msds_file_path);
    }

    public function test_editing_dangerous_goods_booking_without_any_msds_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $dgCargo = CargoCategory::where('code', 'DG')->firstOrFail();
        $dgClass = $this->dgClass();
        $booking = $this->createBooking('draft');

        $this->putJson("/api/admin/bookings/{$booking->id}", [
            'company_id' => $this->company->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'transport_mode_id' => $this->mode->id,
            'service_type_id' => $this->lclService->id,
            'shipment_coverage' => 'port_to_port',
            'shipper_name' => $this->shipperLocation->name,
            'shipper_address' => $this->shipperLocation->address,
            'shipper_phone' => $this->shipperLocation->pic_mobile,
            'shipper_location_id' => $this->shipperLocation->id,
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Addr',
            'consignee_phone' => '08222',
            'packages' => [
                [
                    'description' => 'Cairan Mudah Terbakar',
                    'piece_count' => 1,
                    'weight_kg' => 10,
                    'cargo_category_id' => $dgCargo->id,
                    'is_dangerous_goods' => 1,
                    'dg_class_id' => $dgClass->id,
                    'un_number' => 'UN1203',
                    'packing_group' => 'II',
                    'proper_shipping_name' => 'Gasoline',
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('packages_msds_files.0');
    }

    private function dgClass(): DgClass
    {
        return DgClass::firstOrCreate(
            ['code' => '3'],
            ['name' => 'Flammable Liquids', 'is_active' => true],
        );
    }

    private function createBooking(string $status): Booking
    {
        return Booking::create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'service_type_id' => $this->lclService->id,
            'transport_mode_id' => $this->mode->id,
            'shipment_coverage' => 'port_to_port',
            'status' => $status,
            'shipper_name' => $this->shipperLocation->name,
            'shipper_address' => $this->shipperLocation->address,
            'shipper_phone' => $this->shipperLocation->pic_mobile,
            'shipper_location_id' => $this->shipperLocation->id,
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Addr',
            'consignee_phone' => '08222',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function adminUpdatePayload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'transport_mode_id' => $this->mode->id,
            'service_type_id' => $this->lclService->id,
            'shipment_coverage' => 'port_to_port',
            'cargo_category_id' => $this->generalCargo->id,
            'shipper_name' => $this->shipperLocation->name,
            'shipper_address' => $this->shipperLocation->address,
            'shipper_phone' => $this->shipperLocation->pic_mobile,
            'shipper_location_id' => $this->shipperLocation->id,
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Addr',
            'consignee_phone' => '08222',
            'packages' => [
                [
                    'description' => 'Carton Elektronik',
                    'piece_count' => 1,
                    'weight_kg' => 120,
                    'cargo_category_id' => $this->generalCargo->id,
                ],
            ],
        ], $overrides);
    }
}
