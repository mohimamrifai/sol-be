<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPackage;
use App\Models\CargoCategory;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Models\ShipmentTracking;
use App\Models\TransportMode;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerShipmentFsdTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $viewer;

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
            'name' => 'Shipment Customer Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'SH01',
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
    }

    public function test_shipment_list_is_scoped_to_company(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Shipment Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'SH02',
        ]);

        $this->createShipment($this->company, 'cargo_received');
        $this->createShipment($otherCompany, 'completed');

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/customer/shipments')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('in_progress', $response->json('data.0.high_level_status'));
    }

    public function test_shipment_stats_returns_four_buckets(): void
    {
        $this->createShipment($this->company, 'created');
        $this->createShipment($this->company, 'cargo_received');
        $this->createShipment($this->company, 'completed');
        $this->createShipment($this->company, 'cancelled');

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/customer/shipments/stats')->assertOk();

        $this->assertSame(1, $response->json('data.planning'));
        $this->assertSame(1, $response->json('data.in_progress'));
        $this->assertSame(1, $response->json('data.completed'));
        $this->assertSame(1, $response->json('data.cancelled'));
    }

    public function test_shipment_detail_includes_tracking_timeline_with_occurred_at(): void
    {
        $shipment = $this->createShipment($this->company, 'cargo_received');

        ShipmentTracking::create([
            'shipment_id' => $shipment->id,
            'status' => 'cargo_received',
            'notes' => 'Kargo diterima',
            'location' => 'Surabaya',
            'tracked_at' => Carbon::parse('2026-06-10 10:00:00'),
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/customer/shipments/{$shipment->id}")->assertOk();

        $timeline = $response->json('data.tracking_timeline');
        $this->assertNotEmpty($timeline);
        $this->assertSame(
            '2026-06-10 10:00:00',
            Carbon::parse($timeline[0]['occurred_at'])->format('Y-m-d H:i:s')
        );
        $this->assertSame('cargo_received', $timeline[0]['status']);
    }

    public function test_shipment_cargo_includes_per_item_cargo_category(): void
    {
        $shipment = $this->createShipment($this->company, 'created');

        BookingPackage::create([
            'booking_id' => $shipment->booking_id,
            'sequence' => 1,
            'description' => 'Carton Elektronik',
            'package_type' => 'Carton',
            'piece_count' => 2,
            'weight_kg' => 120,
            'volume_cbm' => 1.5,
            'cargo_category_id' => $this->generalCargo->id,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/customer/shipments/{$shipment->id}")->assertOk();

        $packages = $response->json('data.cargo.packages');
        $this->assertCount(1, $packages);
        $this->assertSame('General Cargo', $packages[0]['cargo_category']);
    }

    public function test_shipment_documents_include_invoice_reference_when_available(): void
    {
        $shipment = $this->createShipment($this->company, 'completed');

        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'shipment_id' => $shipment->id,
            'subtotal' => 1000000,
            'tax_amount' => 0,
            'total_amount' => 1000000,
            'issued_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => 'unpaid',
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/customer/shipments/{$shipment->id}")->assertOk();

        $documents = collect($response->json('data.documents'));
        $invoiceDoc = $documents->firstWhere('key', 'invoice');

        $this->assertTrue($invoiceDoc['available']);
        $this->assertSame($invoice->id, $invoiceDoc['reference_id']);
        $this->assertSame('inv-'.$invoice->id, $invoiceDoc['document_id']);
    }

    public function test_cross_company_shipment_access_denied(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Shipment Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'SH03',
        ]);

        $shipment = $this->createShipment($otherCompany, 'created');

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/customer/shipments/{$shipment->id}")
            ->assertForbidden();
    }

    public function test_viewer_can_view_shipment_detail(): void
    {
        $shipment = $this->createShipment($this->company, 'created');

        Sanctum::actingAs($this->viewer);

        $this->getJson("/api/customer/shipments/{$shipment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $shipment->id);
    }

    private function createShipment(Company $company, string $status): Shipment
    {
        $user = $company->is($this->company)
            ? $this->admin
            : User::factory()->create([
                'company_id' => $company->id,
                'user_type' => 'customer',
                'status' => 'active',
            ]);

        $booking = Booking::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'service_type_id' => $this->lclService->id,
            'transport_mode_id' => $this->mode->id,
            'shipment_coverage' => 'port_to_port',
            'status' => 'approved',
            'shipper_name' => 'Shipper',
            'shipper_address' => 'Addr',
            'shipper_phone' => '08111',
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Addr2',
            'consignee_phone' => '08222',
        ]);

        return Shipment::create([
            'booking_id' => $booking->id,
            'company_id' => $company->id,
            'service_type_id' => $this->lclService->id,
            'transport_mode_id' => $this->mode->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'shipment_coverage' => 'port_to_port',
            'status' => $status,
            'waybill_number' => 'CN-'.uniqid(),
        ]);
    }
}
