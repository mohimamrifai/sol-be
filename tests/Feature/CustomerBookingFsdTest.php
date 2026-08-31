<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingActivity;
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
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerBookingFsdTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $viewer;

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

        $this->company = Company::create([
            'name' => 'Booking Customer Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'BK01',
        ]);

        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'user_type' => 'customer',
            'status' => 'active',
        ]);
        $this->admin->syncRoles(['company_admin']);

        $this->viewer = User::factory()->create([
            'company_id' => $this->company->id,
            'user_type' => 'customer',
            'status' => 'active',
        ]);
        $this->viewer->syncRoles(['viewer']);

        $this->origin = Location::firstOrFail();
        $this->destination = Location::skip(1)->first() ?? Location::firstOrFail();
        $this->mode = TransportMode::firstOrFail();
        $this->lclService = ServiceType::where('code', 'LCL')->firstOrFail();
        $this->generalCargo = CargoCategory::where('code', 'GEN')->firstOrFail();

        $this->shipperLocation = CustomerLocation::create([
            'company_id' => $this->company->id,
            'code' => 'HO-001',
            'name' => 'Head Office Jakarta',
            'type' => 'head_office',
            'phone' => '08123456789',
            'status' => 'active',
            'country' => 'Indonesia',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta Pusat',
            'district' => 'Menteng',
            'postal_code' => '10310',
            'address' => 'Jl. Sudirman No. 1',
            'pic_name' => 'Budi',
            'pic_email' => 'budi@example.com',
            'pic_mobile' => '08123456789',
        ]);
    }

    public function test_booking_list_is_scoped_to_company_and_includes_locations(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Booking Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'BK02',
        ]);

        $this->createBooking($this->company, 'draft');
        $this->createBooking($otherCompany, 'submitted');

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/customer/bookings')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertNotNull($response->json('data.0.origin_location'));
        $this->assertNotNull($response->json('data.0.destination_location'));
    }

    public function test_draft_save_generates_booking_number_and_uses_customer_location(): void
    {
        $this->seed(NumberingFormatSeeder::class);
        Sanctum::actingAs($this->admin);

        $payload = $this->basePayload(isDraft: true);

        $response = $this->postJson('/api/customer/bookings', $payload)->assertCreated();

        $bookingId = (int) $response->json('data.id');
        $booking = Booking::findOrFail($bookingId);

        $this->assertNotEmpty($booking->booking_number);
        $this->assertMatchesRegularExpression(
            '/^BK-'.now()->format('Ym').'-\d{5}$/',
            $booking->booking_number
        );
        $this->assertSame('draft', $booking->status);
        $this->assertSame($this->shipperLocation->id, $booking->shipper_location_id);
        $this->assertNotNull($booking->shipper_snapshot);
    }

    public function test_package_cargo_category_is_persisted_per_item(): void
    {
        Sanctum::actingAs($this->admin);

        $payload = $this->basePayload(isDraft: true);
        $payload['packages'] = [[
            'description' => 'Carton Elektronik',
            'package_type' => 'Carton',
            'piece_count' => 1,
            'weight_kg' => 120,
            'length' => 100,
            'width' => 80,
            'height' => 60,
            'cargo_category_id' => $this->generalCargo->id,
        ]];

        $response = $this->postJson('/api/customer/bookings', $payload)->assertCreated();
        $booking = Booking::with('packages.cargoCategory')->findOrFail((int) $response->json('data.id'));

        $this->assertCount(1, $booking->packages);
        $this->assertSame($this->generalCargo->id, $booking->packages->first()->cargo_category_id);
        $this->assertSame('GEN', $booking->packages->first()->cargoCategory?->code);
    }

    public function test_booking_activities_timeline_uses_occurred_at(): void
    {
        $booking = $this->createBooking($this->company, 'submitted');

        BookingActivity::create([
            'booking_id' => $booking->id,
            'activity_type' => 'submitted',
            'title' => 'Booking disubmit',
            'occurred_at' => Carbon::parse('2026-06-10 09:15:00'),
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/customer/bookings/{$booking->id}/activities")->assertOk();

        $this->assertSame(
            '2026-06-10 09:15:00',
            Carbon::parse($response->json('data.0.occurred_at'))->format('Y-m-d H:i:s')
        );
    }

    public function test_viewer_cannot_create_booking(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->postJson('/api/customer/bookings', $this->basePayload(isDraft: true))
            ->assertForbidden();
    }

    public function test_suspended_customer_cannot_create_new_booking(): void
    {
        $this->company->update(['status' => 'suspended']);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/customer/bookings', $this->basePayload(isDraft: true))
            ->assertForbidden();
    }

    public function test_viewer_sees_read_only_actions_on_draft_detail(): void
    {
        $booking = $this->createBooking($this->company, 'draft');

        Sanctum::actingAs($this->viewer);

        $response = $this->getJson("/api/customer/bookings/{$booking->id}")->assertOk();

        $this->assertSame(['view'], $response->json('data.available_actions'));
    }

    public function test_customer_cancel_sets_cancelled_status(): void
    {
        $booking = $this->createBooking($this->company, Booking::STATUS_SUBMITTED);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/customer/bookings/{$booking->id}/cancel", [
            'reason' => 'Cargo is no longer being shipped',
        ])->assertOk();

        $booking->refresh();

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
        $this->assertSame('Cargo is no longer being shipped', $booking->cancellation_reason);
        $this->assertNull($booking->rejection_reason);
        $this->assertDatabaseHas('booking_activities', [
            'booking_id' => $booking->id,
            'activity_type' => 'cancelled',
        ]);
    }

    private function basePayload(bool $isDraft = false): array
    {
        return [
            'is_draft' => $isDraft,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'transport_mode_id' => $this->mode->id,
            'service_type_id' => $this->lclService->id,
            'shipment_coverage' => 'port_to_port',
            'shipper_name' => $this->shipperLocation->name,
            'shipper_address' => $this->shipperLocation->address,
            'shipper_phone' => $this->shipperLocation->pic_mobile,
            'shipper_location_id' => $this->shipperLocation->id,
            'shipper_snapshot' => [
                'company' => $this->shipperLocation->name,
                'pic_name' => $this->shipperLocation->pic_name,
                'address' => $this->shipperLocation->address,
            ],
            'consignee_name' => 'External Consignee',
            'consignee_address' => 'Jl. Consignee 2',
            'consignee_phone' => '0811111111',
            'consignee_type' => 'external',
            'consignee_snapshot' => [
                'company' => 'External Consignee',
                'address' => 'Jl. Consignee 2',
            ],
        ];
    }

    private function createBooking(Company $company, string $status): Booking
    {
        $user = $company->is($this->company)
            ? $this->admin
            : User::factory()->create([
                'company_id' => $company->id,
                'user_type' => 'customer',
                'status' => 'active',
            ]);

        return Booking::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'service_type_id' => $this->lclService->id,
            'transport_mode_id' => $this->mode->id,
            'shipment_coverage' => 'port_to_port',
            'status' => $status,
            'shipper_name' => 'Shipper',
            'shipper_address' => 'Addr',
            'shipper_phone' => '08111',
            'shipper_location_id' => $company->is($this->company) ? $this->shipperLocation->id : null,
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Addr2',
            'consignee_phone' => '08222',
        ]);
    }
}
