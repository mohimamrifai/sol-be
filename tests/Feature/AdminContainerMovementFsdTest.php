<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperationType;
use App\Models\Booking;
use App\Models\Company;
use App\Models\ContainerAsset;
use App\Models\ContainerMovement;
use App\Models\ContainerType;
use App\Models\Location;
use App\Models\OperationTask;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Models\TransportMode;
use App\Models\User;
use App\Models\Yard;
use App\Services\ContainerAssetService;
use App\Services\OperationTaskService;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\NumberingFormatSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminContainerMovementFsdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MasterDataSeeder::class);
        $this->seed(NumberingFormatSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
    }

    public function test_fsd_movement_activity_codes(): void
    {
        $this->assertSame(
            ['registered', 'assigned', 'loaded', 'arrived', 'released'],
            ContainerAssetService::MOVEMENT_ACTIVITIES,
        );
    }

    public function test_container_movements_endpoint_is_readonly(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/container-movements')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'current_page',
                'per_page',
                'total',
            ]);

        $this->postJson('/api/admin/container-movements', [])->assertMethodNotAllowed();
    }

    public function test_company_container_creation_records_registered_movement(): void
    {
        $admin = $this->actingAsAdmin();
        $type = ContainerType::firstOrFail();
        $yard = Yard::query()->where('status', 'active')->firstOrFail();

        $response = $this->postJson('/api/admin/containers', [
            'container_number' => 'SOLU-MOV-01',
            'container_type_id' => $type->id,
            'current_yard_id' => $yard->id,
            'manufacture_year' => 2021,
        ])->assertCreated();

        $assetId = (int) $response->json('data.id');

        $movement = ContainerMovement::query()
            ->where('container_asset_id', $assetId)
            ->where('activity', ContainerAssetService::ACTIVITY_REGISTERED)
            ->first();

        $this->assertNotNull($movement);
        $this->assertNull($movement->location_from);
        $this->assertSame($yard->name, $movement->location_to);
        $this->assertSame($yard->id, $movement->yard_id);
        $this->assertSame($admin->id, $movement->created_by_id);

        $this->getJson('/api/admin/container-movements?container_asset_id='.$assetId)
            ->assertOk()
            ->assertJsonFragment([
                'container_number' => 'SOLU-MOV-01',
                'activity' => 'registered',
                'location_from' => null,
                'location_to' => $yard->name,
                'created_by' => $admin->name,
            ]);
    }

    public function test_assigning_container_records_assigned_movement(): void
    {
        $admin = $this->actingAsAdmin();
        $asset = $this->createAvailableContainer('SOLU-MOV-02');
        $shipment = $this->createPlanningShipment('SHP-MOV-ASSIGN');

        $slot = $shipment->containers()->create([
            'container_type_id' => $asset->container_type_id,
        ]);

        $this->postJson("/api/admin/shipments/{$shipment->id}/containers/{$slot->id}/assign", [
            'container_asset_id' => $asset->id,
        ])->assertOk();

        $movement = ContainerMovement::query()
            ->where('container_asset_id', $asset->id)
            ->where('shipment_id', $shipment->id)
            ->where('activity', ContainerAssetService::ACTIVITY_ASSIGNED)
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame($admin->id, $movement->created_by_id);

        $this->getJson('/api/admin/container-movements?shipment_id='.$shipment->id.'&activity=assigned')
            ->assertOk()
            ->assertJsonFragment([
                'container_number' => 'SOLU-MOV-02',
                'shipment_number' => 'SHP-MOV-ASSIGN',
                'activity' => 'assigned',
            ]);
    }

    public function test_shipment_in_transit_records_loaded_movement(): void
    {
        $asset = $this->createAvailableContainer('SOLU-MOV-03');
        $shipment = $this->assignAssetToShipment($asset, 'SHP-MOV-LOAD');

        $shipment->update(['status' => 'train_departed']);

        $movement = ContainerMovement::query()
            ->where('container_asset_id', $asset->id)
            ->where('shipment_id', $shipment->id)
            ->where('activity', ContainerAssetService::ACTIVITY_LOADED)
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame('Train', $movement->location_to);

        $this->actingAsAdmin();
        $this->getJson('/api/admin/container-movements?activity=loaded&container_asset_id='.$asset->id)
            ->assertOk()
            ->assertJsonFragment(['activity' => 'loaded']);
    }

    public function test_train_arrival_task_records_arrived_movement(): void
    {
        $asset = $this->createAvailableContainer('SOLU-MOV-04');
        $shipment = $this->assignAssetToShipment($asset, 'SHP-MOV-ARRIVE');
        $shipment->update(['status' => 'train_departed']);

        $task = OperationTask::query()
            ->where('shipment_id', $shipment->id)
            ->where('operation_type', OperationType::TrainArrival)
            ->firstOrFail();

        app(OperationTaskService::class)->complete($task, $this->actingAsAdmin()->id);

        $movement = ContainerMovement::query()
            ->where('container_asset_id', $asset->id)
            ->where('shipment_id', $shipment->id)
            ->where('activity', ContainerAssetService::ACTIVITY_ARRIVED)
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame('Train', $movement->location_from);
    }

    public function test_shipment_completed_records_released_movement_with_empty_destination(): void
    {
        $asset = $this->createAvailableContainer('SOLU-MOV-05');
        $shipment = $this->assignAssetToShipment($asset, 'SHP-MOV-RELEASE');
        $shipment->update(['status' => 'train_departed']);

        $shipment->update(['status' => 'completed']);

        $movement = ContainerMovement::query()
            ->where('container_asset_id', $asset->id)
            ->where('shipment_id', $shipment->id)
            ->where('activity', ContainerAssetService::ACTIVITY_RELEASED)
            ->first();

        $this->assertNotNull($movement);
        $this->assertNull($movement->location_to);
    }

    public function test_container_movements_support_fsd_filters(): void
    {
        $admin = $this->actingAsAdmin();
        $yard = Yard::query()->where('status', 'active')->firstOrFail();
        $type = ContainerType::firstOrFail();

        $this->postJson('/api/admin/containers', [
            'container_number' => 'SOLU-MOV-FLT-A',
            'container_type_id' => $type->id,
            'current_yard_id' => $yard->id,
        ])->assertCreated();

        $this->postJson('/api/admin/containers', [
            'container_number' => 'SOLU-MOV-FLT-B',
            'container_type_id' => $type->id,
            'current_yard_id' => $yard->id,
        ])->assertCreated();

        $assetA = ContainerAsset::query()->where('container_number', 'SOLU-MOV-FLT-A')->firstOrFail();
        $assetB = ContainerAsset::query()->where('container_number', 'SOLU-MOV-FLT-B')->firstOrFail();
        $shipment = $this->assignAssetToShipment($assetB, 'SHP-MOV-FILTER');

        $today = now()->toDateString();

        $this->getJson('/api/admin/container-movements?container_asset_id='.$assetA->id)
            ->assertOk()
            ->assertJsonFragment(['container_number' => 'SOLU-MOV-FLT-A'])
            ->assertJsonMissing(['container_number' => 'SOLU-MOV-FLT-B']);

        $this->getJson('/api/admin/container-movements?shipment_id='.$shipment->id)
            ->assertOk()
            ->assertJsonFragment(['shipment_number' => 'SHP-MOV-FILTER'])
            ->assertJsonFragment(['activity' => 'assigned']);

        $this->getJson('/api/admin/container-movements?activity=registered')
            ->assertOk()
            ->assertJsonFragment(['container_number' => 'SOLU-MOV-FLT-A'])
            ->assertJsonFragment(['container_number' => 'SOLU-MOV-FLT-B'])
            ->assertJsonMissing(['activity' => 'assigned']);

        $this->getJson('/api/admin/container-movements?yard_id='.$yard->id)
            ->assertOk()
            ->assertJsonFragment(['container_number' => 'SOLU-MOV-FLT-A']);

        $this->getJson('/api/admin/container-movements?date_from='.$today.'&date_to='.$today)
            ->assertOk()
            ->assertJsonFragment(['created_by' => $admin->name]);
    }

    public function test_container_movements_api_exposes_fsd_grid_fields(): void
    {
        $this->actingAsAdmin();
        $asset = $this->createAvailableContainer('SOLU-MOV-GRID');
        $this->assignAssetToShipment($asset, 'SHP-MOV-GRID');

        $this->getJson('/api/admin/container-movements?container_asset_id='.$asset->id)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'occurred_at',
                        'container_number',
                        'shipment_number',
                        'location_from',
                        'location_to',
                        'activity',
                        'created_by',
                    ],
                ],
            ]);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['user_type' => 'internal', 'status' => 'active']);
        $admin->syncRoles(['super_admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function createAvailableContainer(string $number): ContainerAsset
    {
        $type = ContainerType::firstOrFail();
        $yard = Yard::query()->where('status', 'active')->first();

        return ContainerAsset::query()->create([
            'container_number' => $number,
            'container_type_id' => $type->id,
            'ownership' => 'company',
            'current_yard_id' => $yard?->id,
            'status' => 'available',
        ]);
    }

    private function createPlanningShipment(string $shipmentNumber): Shipment
    {
        $company = Company::create([
            'name' => 'Movement Test Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'MV'.random_int(10, 99),
        ]);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'user_type' => 'customer',
            'status' => 'active',
        ]);

        $origin = Location::firstOrFail();
        $destination = Location::skip(1)->first() ?? $origin;
        $mode = TransportMode::firstOrFail();
        $service = ServiceType::where('code', 'LCL')->firstOrFail();

        $booking = Booking::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $destination->id,
            'service_type_id' => $service->id,
            'transport_mode_id' => $mode->id,
            'shipment_coverage' => 'port_to_port',
            'status' => 'approved',
            'booking_number' => 'BK-MOV-'.uniqid(),
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
            'service_type_id' => $service->id,
            'transport_mode_id' => $mode->id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $destination->id,
            'shipment_coverage' => 'port_to_port',
            'status' => 'created',
            'shipment_number' => $shipmentNumber,
            'waybill_number' => 'CN-MOV-'.uniqid(),
        ]);
    }

    private function assignAssetToShipment(ContainerAsset $asset, string $shipmentNumber): Shipment
    {
        $this->actingAsAdmin();
        $shipment = $this->createPlanningShipment($shipmentNumber);

        $slot = $shipment->containers()->create([
            'container_type_id' => $asset->container_type_id,
        ]);

        $this->postJson("/api/admin/shipments/{$shipment->id}/containers/{$slot->id}/assign", [
            'container_asset_id' => $asset->id,
        ])->assertOk();

        return $shipment->fresh();
    }
}
