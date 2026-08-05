<?php

namespace Tests\Feature;

use App\Models\AdditionalCharge;
use App\Models\Booking;
use App\Models\CargoCategory;
use App\Models\Company;
use App\Models\Location;
use App\Models\ServiceType;
use App\Models\TransportMode;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
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
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MasterDataSeeder::class);
    }

    public function test_residual_equipment_condition_sets_dangerous_goods_to_true()
    {
        $company = Company::create(['name' => 'Test Co', 'status' => 'active']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $origin = Location::first();
        $dest = Location::skip(1)->first();
        $mode = TransportMode::first();
        $service = ServiceType::first();
        $category = CargoCategory::first();

        $booking = Booking::create([
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
        $company = Company::create(['name' => 'Test Co 2', 'status' => 'active']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $origin = Location::first();
        $dest = Location::skip(1)->first();
        $mode = TransportMode::first();
        $service = ServiceType::first();
        $category = CargoCategory::where('requires_temperature', true)->first()
            ?? CargoCategory::create([
                'name' => 'Refrigerated',
                'code' => 'REF',
                'requires_temperature' => true,
                'is_active' => true,
            ]);

        AdditionalCharge::firstOrCreate([
            'code' => 'REF',
        ], [
            'name' => 'Refrigerated',
            'is_active' => true,
        ]);

        $booking = Booking::create([
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
