<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class CargoModuleTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->seed(\Database\Seeders\OperationalDataSeeder::class);
    }

    public function test_residual_equipment_condition_sets_dangerous_goods_to_true()
    {
        $company = \App\Models\Company::create(['name' => 'Test Co', 'status' => 'active']);
        $user = \App\Models\User::factory()->create(['company_id' => $company->id]);
        
        $origin = \App\Models\Location::first();
        $dest = \App\Models\Location::skip(1)->first();
        $mode = \App\Models\TransportMode::first();
        $service = \App\Models\ServiceType::first();
        $category = \App\Models\CargoCategory::first();

        $booking = \App\Models\Booking::create([
            'booking_number' => 'TEST-001',
            'company_id' => $company->id,
            'user_id' => $user->id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $dest->id,
            'transport_mode_id' => $mode->id,
            'service_type_id' => $service->id,
            'cargo_category_id' => $category->id,
            'equipment_condition' => 'RESIDUAL',
            'is_dangerous_goods' => false,
            'shipper_name' => 'Test',
            'shipper_address' => 'Test',
            'shipper_phone' => 'Test',
            'consignee_name' => 'Test',
            'consignee_address' => 'Test',
            'consignee_phone' => 'Test',
        ]);

        $this->assertTrue($booking->fresh()->is_dangerous_goods);
    }

    public function test_cargo_category_flags_trigger_additional_charges()
    {
        $company = \App\Models\Company::create(['name' => 'Test Co 2', 'status' => 'active']);
        $user = \App\Models\User::factory()->create(['company_id' => $company->id]);
        
        $origin = \App\Models\Location::first();
        $dest = \App\Models\Location::skip(1)->first();
        $mode = \App\Models\TransportMode::first();
        $service = \App\Models\ServiceType::first();
        $category = \App\Models\CargoCategory::where('code', 'REF')->first();
        
        $booking = \App\Models\Booking::create([
            'booking_number' => 'TEST-002',
            'company_id' => $company->id,
            'user_id' => $user->id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $dest->id,
            'transport_mode_id' => $mode->id,
            'service_type_id' => $service->id,
            'cargo_category_id' => $category->id,
            'shipper_name' => 'Test',
            'shipper_address' => 'Test',
            'shipper_phone' => 'Test',
            'consignee_name' => 'Test',
            'consignee_address' => 'Test',
            'consignee_phone' => 'Test',
        ]);

        $this->assertTrue($booking->additionalCharges()->where('code', 'REF')->exists());
    }
}
