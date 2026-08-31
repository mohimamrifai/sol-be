<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContainerType;
use App\Models\Route;
use App\Models\Station;
use App\Models\TrainSchedule;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\NumberingFormatSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminFsdPhasesTest extends TestCase
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

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['user_type' => 'internal', 'status' => 'active']);
        $admin->syncRoles(['super_admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_create_and_list_company_containers(): void
    {
        $this->actingAsAdmin();
        $type = ContainerType::first();
        $this->assertNotNull($type);

        $create = $this->postJson('/api/admin/containers', [
            'container_number' => 'SOLU00099',
            'container_type_id' => $type->id,
            'manufacture_year' => 2020,
            'remark' => 'Test container',
        ])->assertCreated();

        $this->assertSame('company', $create->json('data.ownership'));

        $this->getJson('/api/admin/containers?ownership=company')
            ->assertOk()
            ->assertJsonFragment(['container_number' => 'SOLU00099']);
    }

    public function test_admin_can_list_operation_tasks_by_type(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/operation-tasks/pickup')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page']);
    }

    public function test_admin_can_create_route(): void
    {
        $this->actingAsAdmin();

        $origin = Station::create([
            'code' => 'STN-ORG',
            'name' => 'Origin Station',
            'business_entity' => 'company',
            'city' => 'Surabaya',
            'province' => 'Jawa Timur',
            'status' => 'active',
        ]);
        $destination = Station::create([
            'code' => 'STN-DST',
            'name' => 'Destination Station',
            'business_entity' => 'company',
            'city' => 'Jakarta',
            'province' => 'DKI Jakarta',
            'status' => 'active',
        ]);

        $this->postJson('/api/admin/routes', [
            'business_entity' => 'company',
            'origin_station_id' => $origin->id,
            'destination_station_id' => $destination->id,
            'distance_km' => 750,
            'transit_days' => 2,
            'status' => 'active',
            'service_types' => ['lcl', 'fcl'],
            'shipment_coverages' => ['port_to_port', 'door_to_door'],
        ])->assertCreated();
    }

    public function test_admin_can_view_shipment_report_index(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/reports/shipments')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page']);
    }

    public function test_admin_can_export_shipment_report_excel_and_pdf(): void
    {
        $this->actingAsAdmin();

        $this->get('/api/admin/reports/shipments/export?format=excel')
            ->assertOk();

        $this->get('/api/admin/reports/shipments/export?format=pdf')
            ->assertOk();
    }

    public function test_admin_can_export_booking_report(): void
    {
        $this->actingAsAdmin();

        $this->get('/api/admin/reports/bookings/export?format=excel')->assertOk();
        $this->get('/api/admin/reports/vendor-invoices/export?format=pdf')->assertOk();
    }

    public function test_admin_can_list_numbering_formats(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/settings/numbering-formats')
            ->assertOk()
            ->assertJsonCount(8, 'data')
            ->assertJsonFragment(['document_type' => 'BK']);
    }

    public function test_admin_can_create_and_view_train_schedule(): void
    {
        $this->actingAsAdmin();

        $origin = Station::create([
            'code' => 'STN-TRN-ORG',
            'name' => 'Train Origin',
            'business_entity' => 'company',
            'city' => 'Surabaya',
            'province' => 'Jawa Timur',
            'status' => 'active',
        ]);
        $destination = Station::create([
            'code' => 'STN-TRN-DST',
            'name' => 'Train Destination',
            'business_entity' => 'company',
            'city' => 'Jakarta',
            'province' => 'DKI Jakarta',
            'status' => 'active',
        ]);

        $route = Route::create([
            'code' => 'RTE-TRN-001',
            'business_entity' => 'company',
            'origin_station_id' => $origin->id,
            'destination_station_id' => $destination->id,
            'distance_km' => 750,
            'transit_days' => 2,
            'status' => 'active',
            'service_types' => ['lcl', 'fcl'],
            'shipment_coverages' => ['port_to_port'],
        ]);

        $upcomingBefore = (int) $this->getJson('/api/admin/train-schedules/stats')
            ->assertOk()
            ->json('data.upcoming');

        $create = $this->postJson('/api/admin/train-schedules', [
            'business_entity' => 'company',
            'train_number' => 'KA201',
            'route_id' => $route->id,
            'departure_at' => now()->addDay()->toIso8601String(),
            'eta_at' => now()->addDays(2)->toIso8601String(),
            'max_containers' => 20,
            'status' => 'upcoming',
            'remark' => 'Test schedule',
        ])->assertCreated();

        $scheduleId = $create->json('data.id');
        $this->assertNotNull($scheduleId);
        $this->assertSame('KA201', $create->json('data.train_number'));

        $this->getJson('/api/admin/train-schedules/stats')
            ->assertOk()
            ->assertJsonPath('data.upcoming', $upcomingBefore + 1);

        $this->getJson("/api/admin/train-schedules/{$scheduleId}")
            ->assertOk()
            ->assertJsonPath('data.train_number', 'KA201')
            ->assertJsonStructure(['data' => ['assigned_shipments', 'assigned_containers']]);

        $schedule = TrainSchedule::findOrFail($scheduleId);
        $this->assertStringStartsWith('TRS', $schedule->code);
    }

    public function test_admin_system_settings_and_numbering_preview(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/settings/system')
            ->assertOk()
            ->assertJsonStructure(['data' => ['schema', 'values', 'activity_log']]);

        $this->putJson('/api/admin/settings/system', [
            'settings' => [
                ['key' => 'default_tax_rate', 'value' => 12],
                ['key' => 'booking_expired_hours', 'value' => 48],
            ],
        ])->assertOk()
            ->assertJsonPath('data.values.default_tax_rate', 12);

        $tax = \App\Support\SystemConfig::applyTax(1000);
        $this->assertSame(120.0, $tax['tax_amount']);
        $this->assertSame(1120.0, $tax['total_amount']);

        $format = \App\Models\NumberingFormat::first();
        $this->assertNotNull($format);

        $this->postJson('/api/admin/settings/numbering-formats/preview', [
            'prefix' => 'BK',
            'running_digits' => 5,
            'separator' => '-',
            'reset_period' => 'monthly',
        ])->assertOk()
            ->assertJsonStructure(['preview']);
    }

    public function test_non_super_admin_cannot_update_system_settings(): void
    {
        $ops = User::factory()->create(['user_type' => 'internal', 'status' => 'active']);
        $ops->syncRoles(['operations']);
        Sanctum::actingAs($ops);

        $this->getJson('/api/admin/settings/system')->assertForbidden();
        $this->putJson('/api/admin/settings/system', [
            'settings' => [['key' => 'system_name', 'value' => 'Blocked']],
        ])->assertForbidden();
    }

    public function test_admin_can_list_proof_of_deliveries(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/proof-of-deliveries/stats')
            ->assertOk()
            ->assertJsonStructure(['data' => ['waiting_pod', 'received', 'verified', 'rejected']]);

        $this->getJson('/api/admin/proof-of-deliveries')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page']);
    }

    public function test_admin_can_create_additional_charge_with_auto_code(): void
    {
        $this->actingAsAdmin();

        $create = $this->postJson('/api/admin/additional-charges', [
            'name' => 'Lift On',
            'charge_category' => 'handling',
            'pricing_basis' => 'per_container',
            'description' => 'Container lift on',
            'is_active' => true,
        ])->assertCreated();

        $code = $create->json('data.code');
        $this->assertStringStartsWith('ADC', (string) $code);

        $id = (int) $create->json('data.id');
        $this->getJson("/api/admin/additional-charges/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Lift On')
            ->assertJsonStructure(['data' => ['activity_log']]);

        $this->postJson("/api/admin/additional-charges/{$id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_internal_viewer_cannot_create_companies(): void
    {
        $viewer = User::factory()->create([
            'user_type' => 'internal',
            'status' => 'active',
            'feature_access' => \App\Enums\UserRole::InternalViewer->defaultFeatureAccess(),
        ]);
        $viewer->syncRoles(['internal_viewer']);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/admin/companies', [
            'name' => 'Blocked Co',
            'company_code' => 'BLK001',
        ])->assertForbidden();
    }
}
