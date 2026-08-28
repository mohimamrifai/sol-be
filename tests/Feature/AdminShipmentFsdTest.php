<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Location;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Models\TrainSchedule;
use App\Models\TransportMode;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminShipmentFsdTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Location $origin;

    private Location $destination;

    private ServiceType $lclService;

    private TransportMode $mode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->admin = User::factory()->create(['user_type' => 'internal', 'status' => 'active']);
        $this->admin->syncRoles(['super_admin']);
        $this->company = Company::create([
            'name' => 'Shipment FSD Customer',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'SFC',
            'npwp' => '01.234.567.8-888.000',
            'address' => 'Shipment Street',
            'payment_term' => 'net_14',
            'postpaid_term_days' => 30,
        ]);
        $this->origin = Location::firstOrFail();
        $this->destination = Location::skip(1)->first() ?? $this->origin;
        $this->lclService = ServiceType::where('code', 'LCL')->firstOrFail();
        $this->mode = TransportMode::firstOrFail();
        Sanctum::actingAs($this->admin);
    }

    public function test_shipment_list_exposes_fsd_status(): void
    {
        $shipment = $this->createShipment('created');

        $row = collect($this->getJson('/api/admin/shipments')
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $shipment->id);

        $this->assertNotNull($row);
        $this->assertSame('planning', $row['fsd_status']);
    }

    public function test_ready_for_departure_requires_complete_planning(): void
    {
        $booking = $this->createApprovedBooking();
        $shipment = Shipment::create([
            'booking_id' => $booking->id,
            'company_id' => $this->company->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'transport_mode_id' => $this->mode->id,
            'service_type_id' => $this->lclService->id,
            'shipment_coverage' => 'port_to_port',
            'status' => 'created',
            'waybill_number' => 'CN-TEST-'.uniqid(),
        ]);

        $this->postJson("/api/admin/shipments/{$shipment->id}/ready-for-departure")
            ->assertStatus(422)
            ->assertJsonPath('errors.internal_pic_id', fn ($v) => is_string($v));
    }

    public function test_cancel_only_allowed_in_planning_status(): void
    {
        $planning = $this->createShipment('created');
        $ready = $this->createShipment('ready_for_pickup');

        $this->postJson("/api/admin/shipments/{$planning->id}/cancel", ['reason' => 'Customer request'])
            ->assertOk();

        $this->postJson("/api/admin/shipments/{$ready->id}/cancel", ['reason' => 'Too late'])
            ->assertStatus(422);
    }

    public function test_completed_shipment_is_readonly(): void
    {
        $shipment = $this->createShipment('completed');

        $this->putJson("/api/admin/shipments/{$shipment->id}", [
            'planning_notes' => 'Should fail',
        ])->assertStatus(422);
    }

    public function test_convert_to_shipment_stores_cargo_snapshot(): void
    {
        $booking = $this->createApprovedBooking();

        $this->postJson("/api/admin/bookings/{$booking->id}/convert-to-shipment")
            ->assertCreated();

        $shipment = Shipment::where('booking_id', $booking->id)->firstOrFail();
        $this->assertIsArray($shipment->cargo_snapshot);
        $this->assertSame('LCL', $shipment->cargo_snapshot['service_kind'] ?? null);
        $this->assertNotEmpty($shipment->waybill_number);
    }

    public function test_generate_consignment_note_is_idempotent(): void
    {
        $shipment = $this->createShipment('created');
        $existing = $shipment->waybill_number;

        $this->postJson("/api/admin/shipments/{$shipment->id}/generate-consignment-note")
            ->assertOk()
            ->assertJsonPath('data.waybill_number', $existing);

        $this->assertSame($existing, $shipment->fresh()->waybill_number);
    }

    public function test_planning_shipments_excluded_from_operations_index(): void
    {
        $planning = $this->createShipment('created');

        \App\Models\OperationTask::query()->create([
            'shipment_id' => $planning->id,
            'operation_type' => \App\Enums\OperationType::GateInOrigin,
            'status' => \App\Enums\OperationTaskStatus::Waiting,
            'planned_date' => now()->toDateString(),
        ]);

        $ids = collect($this->getJson('/api/admin/operation-tasks/gate_in_origin')
            ->assertOk()
            ->json('data'))
            ->pluck('shipment_id');

        $this->assertFalse($ids->contains($planning->id));
    }

    public function test_supporting_document_upload_allowed_before_completed(): void
    {
        $ready = $this->createShipment('ready_for_pickup');

        $this->postJson("/api/admin/shipments/{$ready->id}/documents", [
            'file' => \Illuminate\Http\UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $completed = $this->createShipment('completed');
        $this->postJson("/api/admin/shipments/{$completed->id}/documents", [
            'file' => \Illuminate\Http\UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_fsd_status_maps_operations_to_in_transit(): void
    {
        $shipment = $this->createShipment('cargo_received');
        $this->assertSame('in_transit', $shipment->fsd_status);

        $ready = $this->createShipment('ready_for_pickup');
        $this->assertSame('ready_for_departure', $ready->fsd_status);

        $pod = $this->createShipment('proof_of_delivery');
        $this->assertSame('in_transit', $pod->fsd_status);
    }

    public function test_detail_exposes_capabilities(): void
    {
        $shipment = $this->createShipment('created');

        $data = $this->getJson("/api/admin/shipments/{$shipment->id}")
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('capabilities', $data);
        $this->assertTrue($data['capabilities']['can_edit_planning']);
    }

    public function test_detail_returns_admin_documents_and_tracking_timeline(): void
    {
        $shipment = $this->createShipment('created');

        $data = $this->getJson("/api/admin/shipments/{$shipment->id}")
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('tracking_timeline', $data);
        $this->assertArrayHasKey('documents', $data);
        $this->assertSame('consignment_note', $data['documents'][0]['key'] ?? null);
    }

    private function createApprovedBooking(): Booking
    {
        return Booking::create([
            'booking_number' => 'BK-FSD-'.uniqid(),
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'transport_mode_id' => $this->mode->id,
            'service_type_id' => $this->lclService->id,
            'shipment_coverage' => 'port_to_port',
            'status' => 'approved',
            'shipper_name' => 'Shipper',
            'shipper_phone' => '08123456789',
            'shipper_address' => 'Origin address',
            'consignee_name' => 'Consignee',
            'consignee_phone' => '08198765432',
            'consignee_address' => 'Destination address',
            'estimated_weight' => 100,
            'estimated_cbm' => 1.5,
            'cargo_description' => 'General cargo',
        ]);
    }

    private function createShipment(string $status): Shipment
    {
        $booking = $this->createApprovedBooking();

        return Shipment::create([
            'booking_id' => $booking->id,
            'company_id' => $this->company->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'transport_mode_id' => $this->mode->id,
            'service_type_id' => $this->lclService->id,
            'shipment_coverage' => 'port_to_port',
            'status' => $status,
            'waybill_number' => 'CN-TEST-'.uniqid(),
            'estimated_departure' => now()->addDay(),
            'estimated_arrival' => now()->addDays(3),
            'internal_pic_id' => $this->admin->id,
            'origin_yard_id' => $this->origin->id,
            'destination_yard_id' => $this->destination->id,
            'train_schedule_id' => TrainSchedule::query()->value('id'),
            'cargo_snapshot' => [
                'service_kind' => 'LCL',
                'packages' => [['description' => 'Box', 'piece_count' => 1]],
                'containers' => [],
                'summary' => null,
            ],
        ]);
    }
}
