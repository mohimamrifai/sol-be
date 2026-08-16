<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Payment;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Models\TransportMode;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerDashboardFsdTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Location $origin;

    private Location $destination;

    private ServiceType $service;

    private TransportMode $mode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->company = Company::create([
            'name' => 'Dashboard Customer Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'DASH01',
        ]);

        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'user_type' => 'customer',
            'status' => 'active',
        ]);
        $this->admin->syncRoles(['company_admin']);

        $this->origin = Location::firstOrFail();
        $this->destination = Location::skip(1)->first() ?? Location::firstOrFail();
        $this->service = ServiceType::firstOrFail();
        $this->mode = TransportMode::firstOrFail();
    }

    public function test_dashboard_returns_cards_and_recent_lists_scoped_to_company(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'OTH01',
        ]);

        $this->createBooking($this->company, 'draft');
        $this->createBooking($this->company, 'submitted');
        $this->createBooking($otherCompany, 'draft');

        $activeShipment = $this->createShipment($this->company, 'departed');
        $completedShipment = $this->createShipment($this->company, 'completed');
        $otherActiveShipment = $this->createShipment($otherCompany, 'departed');

        $issued = $this->createInvoiceForShipment($this->company, $activeShipment, 'unpaid', 1_000_000);
        $this->createInvoiceForShipment($this->company, $completedShipment, 'paid', 500_000);
        $this->createInvoiceForShipment($otherCompany, $otherActiveShipment, 'unpaid', 750_000);

        Payment::create([
            'invoice_id' => $issued->id,
            'amount' => 200_000,
            'status' => 'success',
            'paid_at' => now(),
            'payment_type' => 'transfer',
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/customer/dashboard')->assertOk();

        $cards = $response->json('data.cards');
        $this->assertSame(1, $cards['booking_draft']);
        $this->assertSame(1, $cards['booking_submitted']);
        $this->assertSame(1, $cards['shipment_active']);
        $this->assertSame(1, $cards['shipment_completed']);
        $this->assertSame(1, $cards['invoice_unpaid']);
        $this->assertSame(800_000.0, (float) $cards['invoice_outstanding_amount']);

        $recent = $response->json('data.recent');
        $this->assertCount(1, $recent['shipments']);
        $this->assertSame($activeShipment->id, $recent['shipments'][0]['id']);
        $bookingStatuses = collect($recent['bookings'])->pluck('status')->all();
        $this->assertContains('draft', $bookingStatuses);
        $this->assertContains('submitted', $bookingStatuses);
        $this->assertCount(1, $recent['invoices']);
        $this->assertSame('unpaid', $recent['invoices'][0]['status']);
        $this->assertCount(1, $recent['payments']);
    }

    public function test_notifications_use_submit_activity_not_draft_creation(): void
    {
        $draft = $this->createBooking($this->company, 'draft');
        $submitted = $this->createBooking($this->company, 'submitted');

        BookingActivity::create([
            'booking_id' => $submitted->id,
            'activity_type' => 'submitted',
            'title' => 'Booking disubmit',
            'description' => 'Menunggu review internal.',
            'occurred_at' => Carbon::parse('2026-07-10 09:00:00'),
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/customer/dashboard')->assertOk();
        $notifications = collect($response->json('data.notifications'));

        $this->assertTrue(
            $notifications->contains(
                fn (array $n) => $n['type'] === 'booking_submitted'
                    && (int) $n['ref_id'] === $submitted->id
            )
        );
        $this->assertFalse(
            $notifications->contains(
                fn (array $n) => $n['type'] === 'booking_submitted'
                    && (int) $n['ref_id'] === $draft->id
            )
        );
    }

    public function test_notifications_endpoint_supports_pagination(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $booking = $this->createBooking($this->company, 'submitted');
            BookingActivity::create([
                'booking_id' => $booking->id,
                'activity_type' => 'submitted',
                'title' => 'Booking disubmit',
                'occurred_at' => now()->subMinutes($i),
            ]);
        }

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/customer/dashboard/notifications?per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_viewer_can_access_customer_dashboard(): void
    {
        $viewer = User::factory()->create([
            'company_id' => $this->company->id,
            'user_type' => 'customer',
            'status' => 'active',
        ]);
        $viewer->syncRoles(['viewer']);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/customer/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'cards' => [
                        'booking_draft',
                        'booking_submitted',
                        'shipment_active',
                        'shipment_completed',
                        'invoice_unpaid',
                        'invoice_outstanding_amount',
                    ],
                    'recent',
                    'notifications',
                ],
            ]);
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
            'service_type_id' => $this->service->id,
            'transport_mode_id' => $this->mode->id,
            'shipment_coverage' => 'door_to_door',
            'status' => $status,
            'shipper_name' => 'Shipper',
            'shipper_address' => 'Addr',
            'shipper_phone' => '08111',
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Addr2',
            'consignee_phone' => '08222',
        ]);
    }

    private function createShipment(Company $company, string $status): Shipment
    {
        $booking = $this->createBooking($company, 'approved');

        return Shipment::create([
            'shipment_no' => 'SHP-'.uniqid(),
            'shipment_number' => 'SHP-'.uniqid(),
            'booking_id' => $booking->id,
            'company_id' => $company->id,
            'service_type_id' => $this->service->id,
            'transport_mode_id' => $this->mode->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'shipment_coverage' => 'door_to_door',
            'status' => $status,
        ]);
    }

    private function createInvoiceForShipment(
        Company $company,
        Shipment $shipment,
        string $status,
        float $total,
    ): Invoice {
        return Invoice::create([
            'company_id' => $company->id,
            'shipment_id' => $shipment->id,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total_amount' => $total,
            'issued_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => $status,
        ]);
    }
}
