<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingPackage;
use App\Models\CargoCategory;
use App\Models\Company;
use App\Models\CustomerLocation;
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
}
