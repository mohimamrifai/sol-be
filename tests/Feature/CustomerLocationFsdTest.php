<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyActivity;
use App\Models\CustomerLocation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerLocationFsdTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $viewer;

    private CustomerLocation $headOffice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::create([
            'name' => 'Location FSD Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'LF01',
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

        $this->headOffice = CustomerLocation::create([
            'company_id' => $this->company->id,
            'code' => 'HO',
            'name' => 'Head Office Jakarta',
            'type' => 'head_office',
            'phone' => '021-1234567',
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

    public function test_location_stats_returns_five_counts(): void
    {
        CustomerLocation::create([
            'company_id' => $this->company->id,
            'code' => 'SWH',
            'name' => 'Surabaya Warehouse',
            'type' => 'warehouse',
            'status' => 'active',
            'country' => 'Indonesia',
            'province' => 'Jawa Timur',
            'city' => 'Surabaya',
            'address' => 'Jl. Warehouse 1',
            'pic_name' => 'Siti',
            'pic_email' => 'siti@example.com',
            'pic_mobile' => '08111111111',
        ]);

        Sanctum::actingAs($this->admin);

        $data = $this->getJson('/api/customer/locations/stats')->assertOk()->json('data');

        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['head_office']);
        $this->assertSame(0, $data['branch_office']);
        $this->assertSame(1, $data['warehouse']);
        $this->assertSame(2, $data['active']);
    }

    public function test_location_store_generates_code_and_logs_created_activity(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/customer/locations', $this->branchPayload())->assertCreated();

        $locationId = $response->json('data.id');
        $code = $response->json('data.code');

        $this->assertNotEmpty($code);
        $this->assertLessThanOrEqual(3, strlen($code));
        $this->assertDoesNotMatchRegularExpression('/-/', $code);

        $this->assertTrue(
            CompanyActivity::query()
                ->where('subject_type', CustomerLocation::class)
                ->where('subject_id', $locationId)
                ->where('description', 'Location dibuat.')
                ->exists()
        );
    }

    public function test_location_update_logs_address_pic_and_status_separately(): void
    {
        Sanctum::actingAs($this->admin);

        $location = CustomerLocation::create([
            'company_id' => $this->company->id,
            'code' => 'BRA',
            'name' => 'Branch Bandung',
            'type' => 'branch_office',
            'status' => 'active',
            'country' => 'Indonesia',
            'province' => 'Jawa Barat',
            'city' => 'Bandung',
            'address' => 'Jl. Lama',
            'pic_name' => 'Andi',
            'pic_email' => 'andi@example.com',
            'pic_mobile' => '08222222222',
        ]);

        $this->putJson('/api/customer/locations/'.$location->id, [
            'address' => 'Jl. Baru No. 99',
            'pic_name' => 'Andi Updated',
            'status' => 'inactive',
        ])->assertOk();

        $descriptions = CompanyActivity::query()
            ->where('subject_type', CustomerLocation::class)
            ->where('subject_id', $location->id)
            ->orderBy('id')
            ->pluck('description')
            ->all();

        $this->assertContains('Alamat Location diperbarui.', $descriptions);
        $this->assertContains('PIC diperbarui.', $descriptions);
        $this->assertContains('Status diubah menjadi Inactive.', $descriptions);
    }

    public function test_max_one_head_office_on_create(): void
    {
        Sanctum::actingAs($this->admin);

        $payload = $this->branchPayload();
        $payload['type'] = 'head_office';

        $this->postJson('/api/customer/locations', $payload)->assertStatus(422);
    }

    public function test_cannot_change_sole_head_office_type(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/customer/locations/'.$this->headOffice->id, [
            'type' => 'branch_office',
        ])->assertStatus(422);
    }

    public function test_location_show_scoped_to_own_company(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Location Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'OL01',
        ]);

        $foreign = CustomerLocation::create([
            'company_id' => $otherCompany->id,
            'code' => 'OLH',
            'name' => 'Foreign HO',
            'type' => 'head_office',
            'status' => 'active',
            'country' => 'Indonesia',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta',
            'address' => 'Jl. Foreign',
            'pic_name' => 'X',
            'pic_email' => 'x@example.com',
            'pic_mobile' => '08333333333',
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/customer/locations/'.$foreign->id)->assertNotFound();
    }

    public function test_viewer_can_create_and_update_location_per_fsd(): void
    {
        Sanctum::actingAs($this->viewer);

        $created = $this->postJson('/api/customer/locations', $this->branchPayload([
            'name' => 'Viewer Branch',
        ]))->assertCreated();

        $id = $created->json('data.id');

        $this->putJson('/api/customer/locations/'.$id, [
            'name' => 'Viewer Branch Updated',
        ])->assertOk();

        $this->assertSame('Viewer Branch Updated', CustomerLocation::find($id)?->name);
    }

    public function test_location_activities_returns_title_and_actor_name(): void
    {
        Sanctum::actingAs($this->admin);

        CompanyActivity::create([
            'subject_type' => CustomerLocation::class,
            'subject_id' => $this->headOffice->id,
            'event_key' => 'location_created',
            'description' => 'Location dibuat.',
            'actor_user_id' => $this->admin->id,
            'occurred_at' => now(),
        ]);

        $entry = $this->getJson('/api/customer/locations/'.$this->headOffice->id.'/activities')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('Location dibuat.', $entry['title']);
        $this->assertSame($this->admin->name, $entry['actor_name']);
    }

    public function test_location_index_filters_by_search_and_status(): void
    {
        CustomerLocation::create([
            'company_id' => $this->company->id,
            'code' => 'INW',
            'name' => 'Inactive Warehouse',
            'type' => 'warehouse',
            'status' => 'inactive',
            'country' => 'Indonesia',
            'province' => 'Jawa Timur',
            'city' => 'Malang',
            'address' => 'Jl. Inactive',
            'pic_name' => 'Rina',
            'pic_email' => 'rina@example.com',
            'pic_mobile' => '08444444444',
        ]);

        Sanctum::actingAs($this->admin);

        $inactive = $this->getJson('/api/customer/locations?status=inactive')->assertOk()->json('data');
        $this->assertCount(1, $inactive);
        $this->assertSame('Inactive Warehouse', $inactive[0]['name']);

        $search = $this->getJson('/api/customer/locations?search=Jakarta')->assertOk()->json('data');
        $this->assertCount(1, $search);
        $this->assertSame('Head Office Jakarta', $search[0]['name']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function branchPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'branch_office',
            'name' => 'Branch Semarang',
            'phone' => '024-123456',
            'status' => 'active',
            'country' => 'Indonesia',
            'province' => 'Jawa Tengah',
            'city' => 'Semarang',
            'district' => 'Semarang Tengah',
            'postal_code' => '50132',
            'address' => 'Jl. Pemuda No. 10',
            'pic_name' => 'Dewi',
            'pic_email' => 'dewi@example.com',
            'pic_mobile' => '08555555555',
        ], $overrides);
    }
}
