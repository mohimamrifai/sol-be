<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperationTaskStatus;
use App\Enums\OperationType;
use App\Enums\VendorJobOrderService;
use App\Enums\VendorJobOrderStatus;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Location;
use App\Models\OperationTask;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Models\TransportMode;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorJobOrder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOperationPickupAssignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->admin = User::factory()->create(['user_type' => 'internal', 'status' => 'active']);
        $this->admin->syncRoles(['super_admin']);
        Sanctum::actingAs($this->admin);
    }

    public function test_pickup_detail_exposes_can_reassign_vendor(): void
    {
        [$task] = $this->createPickupTaskWithVendor();

        $data = $this->getJson("/api/admin/operation-tasks/task/{$task->id}")
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['can_reassign_vendor']);
    }

    public function test_assign_vendor_reassigns_pickup_and_creates_new_job_order(): void
    {
        [$task, $shipment, $oldVendor] = $this->createPickupTaskWithVendor();
        $newVendor = $this->createVendor('VND00200', 'New Pickup Vendor');

        $oldJo = VendorJobOrder::query()
            ->where('shipment_id', $shipment->id)
            ->where('service_type', VendorJobOrderService::Pickup->value)
            ->where('vendor_id', $oldVendor->id)
            ->firstOrFail();

        $this->postJson("/api/admin/operation-tasks/{$task->id}/assign-vendor", [
            'vendor_id' => $newVendor->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.can_reassign_vendor', true);

        $shipment->refresh();
        $this->assertSame($newVendor->id, $shipment->pickup_vendor_id);

        $this->assertSame(
            VendorJobOrderStatus::Cancelled->value,
            $oldJo->fresh()->status->value
        );

        $this->assertTrue(
            VendorJobOrder::query()
                ->where('shipment_id', $shipment->id)
                ->where('service_type', VendorJobOrderService::Pickup->value)
                ->where('vendor_id', $newVendor->id)
                ->whereNotIn('status', [VendorJobOrderStatus::Cancelled->value])
                ->exists()
        );
    }

    public function test_assign_vendor_rejected_for_completed_pickup_task(): void
    {
        [$task, , $oldVendor] = $this->createPickupTaskWithVendor(OperationTaskStatus::Completed);
        $newVendor = $this->createVendor('VND00201', 'Another Vendor');

        $this->postJson("/api/admin/operation-tasks/{$task->id}/assign-vendor", [
            'vendor_id' => $newVendor->id,
        ])
            ->assertStatus(422);

        $detail = $this->getJson("/api/admin/operation-tasks/task/{$task->id}")
            ->assertOk()
            ->json('data');

        $this->assertFalse($detail['can_reassign_vendor']);
        $this->assertSame($oldVendor->id, $task->shipment->fresh()->pickup_vendor_id);
    }

    public function test_assign_vendor_rejected_for_non_pickup_delivery_task(): void
    {
        $shipment = $this->createDoorToDoorShipment();
        $task = OperationTask::query()->create([
            'shipment_id' => $shipment->id,
            'operation_type' => OperationType::Loading,
            'status' => OperationTaskStatus::Waiting,
            'planned_date' => now()->toDateString(),
        ]);
        $vendor = $this->createVendor('VND00202', 'Loading Vendor');

        $this->postJson("/api/admin/operation-tasks/{$task->id}/assign-vendor", [
            'vendor_id' => $vendor->id,
        ])->assertStatus(422);
    }

    /**
     * @return array{0: OperationTask, 1: Shipment, 2: Vendor}
     */
    private function createPickupTaskWithVendor(OperationTaskStatus $status = OperationTaskStatus::Waiting): array
    {
        $vendor = $this->createVendor('VND00199', 'Original Pickup Vendor');
        $shipment = $this->createDoorToDoorShipment($vendor->id);

        $task = OperationTask::query()->create([
            'shipment_id' => $shipment->id,
            'operation_type' => OperationType::Pickup,
            'status' => $status,
            'planned_date' => now()->toDateString(),
        ]);

        VendorJobOrder::query()->create([
            'shipment_id' => $shipment->id,
            'vendor_id' => $vendor->id,
            'service_type' => VendorJobOrderService::Pickup->value,
            'status' => VendorJobOrderStatus::Sent->value,
            'vendor_rate' => 100000,
            'additional_cost' => 0,
        ]);

        return [$task, $shipment, $vendor];
    }

    private function createDoorToDoorShipment(?int $pickupVendorId = null): Shipment
    {
        $customer = Company::create([
            'name' => 'Pickup Assign Customer',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'PAC'.random_int(100, 999),
        ]);
        $customerUser = User::factory()->create([
            'company_id' => $customer->id,
            'user_type' => 'customer',
            'status' => 'active',
        ]);
        $origin = Location::firstOrFail();
        $destination = Location::skip(1)->first() ?? $origin;

        $booking = Booking::create([
            'company_id' => $customer->id,
            'user_id' => $customerUser->id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $destination->id,
            'service_type_id' => ServiceType::firstOrFail()->id,
            'transport_mode_id' => TransportMode::firstOrFail()->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'approved',
            'shipper_name' => 'Shipper',
            'shipper_address' => 'Pickup Street',
            'shipper_phone' => '08111',
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Delivery Street',
            'consignee_phone' => '08222',
        ]);

        return Shipment::create([
            'shipment_no' => 'SHP-PICK-'.uniqid(),
            'shipment_number' => 'SHP-PICK-'.uniqid(),
            'booking_id' => $booking->id,
            'company_id' => $customer->id,
            'service_type_id' => $booking->service_type_id,
            'transport_mode_id' => $booking->transport_mode_id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $destination->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'ready_for_pickup',
            'pickup_vendor_id' => $pickupVendorId,
        ]);
    }

    private function createVendor(string $code, string $name): Vendor
    {
        return Vendor::create([
            'name' => $name,
            'code' => $code,
            'business_entity' => 'company',
            'vendor_types' => ['trucking'],
            'npwp' => '12.345.678.9-000'.random_int(100, 999),
            'email' => strtolower(str_replace(' ', '.', $name)).'@test.com',
            'phone' => '08123456789',
            'address' => 'Jl Vendor',
            'is_active' => true,
            'payment_terms' => '30_days',
            'payment_method' => 'transfer',
        ]);
    }
}
