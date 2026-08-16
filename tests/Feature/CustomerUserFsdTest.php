<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyActivity;
use App\Models\CustomerLocation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerUserFsdTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $viewer;

    private CustomerLocation $activeLocation;

    private CustomerLocation $inactiveLocation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::create([
            'name' => 'User FSD Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'UF01',
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

        $this->activeLocation = CustomerLocation::create([
            'company_id' => $this->company->id,
            'code' => 'UF0-0001',
            'name' => 'Head Office',
            'type' => 'head_office',
            'status' => 'active',
            'country' => 'Indonesia',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta',
            'address' => 'Jl. HO 1',
            'pic_name' => 'Admin',
            'pic_email' => 'admin@example.com',
            'pic_mobile' => '08111111111',
        ]);

        $this->inactiveLocation = CustomerLocation::create([
            'company_id' => $this->company->id,
            'code' => 'UF0-0002',
            'name' => 'Closed Branch',
            'type' => 'branch_office',
            'status' => 'inactive',
            'country' => 'Indonesia',
            'province' => 'Jawa Barat',
            'city' => 'Bandung',
            'address' => 'Jl. Inactive',
            'pic_name' => 'X',
            'pic_email' => 'x@example.com',
            'pic_mobile' => '08222222222',
        ]);

        $this->admin->locationAccess()->sync([$this->activeLocation->id]);
    }

    public function test_user_stats_returns_four_counts(): void
    {
        Sanctum::actingAs($this->admin);

        $data = $this->getJson('/api/customer/users/stats')->assertOk()->json('data');

        $this->assertSame(2, $data['total']);
        $this->assertSame(2, $data['active']);
        $this->assertSame(0, $data['inactive']);
        $this->assertSame(1, $data['company_admin']);
    }

    public function test_user_store_logs_created_activity(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/customer/users', $this->userPayload())->assertCreated();
        $userId = $response->json('data.id');

        $this->assertTrue(
            CompanyActivity::query()
                ->where('subject_type', User::class)
                ->where('subject_id', $userId)
                ->where('description', 'User dibuat.')
                ->exists()
        );
    }

    public function test_user_update_logs_granular_activities(): void
    {
        Sanctum::actingAs($this->admin);

        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'user_type' => 'customer',
            'status' => 'active',
            'password' => Hash::make('password123'),
        ]);
        $user->syncRoles(['ops_pic']);
        $user->locationAccess()->sync([$this->activeLocation->id]);

        $this->putJson('/api/customer/users/'.$user->id, [
            'role' => 'finance_pic',
            'location_ids' => [$this->activeLocation->id],
            'feature_access' => ['view_company', 'view_invoices', 'view_payments', 'view_documents'],
            'status' => 'inactive',
            'password' => 'newpassword123',
        ])->assertOk();

        $descriptions = CompanyActivity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->orderBy('id')
            ->pluck('description')
            ->all();

        $this->assertContains('Role diubah menjadi Finance PIC.', $descriptions);
        $this->assertContains('Feature Access diperbarui.', $descriptions);
        $this->assertContains('User dinonaktifkan.', $descriptions);
        $this->assertContains('Password direset.', $descriptions);
    }

    public function test_location_access_requires_active_customer_location(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/customer/users', $this->userPayload([
            'location_ids' => [$this->inactiveLocation->id],
        ]))->assertStatus(422);
    }

    public function test_location_access_requires_minimum_one(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/customer/users', $this->userPayload([
            'location_ids' => [],
        ]))->assertStatus(422);
    }

    public function test_email_must_be_unique_system_wide(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/customer/users', $this->userPayload([
            'email' => $this->viewer->email,
        ]))->assertStatus(422);
    }

    public function test_cannot_deactivate_last_company_admin(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/customer/users/'.$this->admin->id, [
            'status' => 'inactive',
        ])->assertStatus(422);
    }

    public function test_user_show_scoped_to_own_company(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other User Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'OU01',
        ]);

        $foreign = User::factory()->create([
            'company_id' => $otherCompany->id,
            'user_type' => 'customer',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/customer/users/'.$foreign->id)->assertNotFound();
    }

    public function test_viewer_cannot_create_user_without_permission(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->postJson('/api/customer/users', $this->userPayload())->assertForbidden();
    }

    public function test_user_activities_returns_title_and_actor_name(): void
    {
        Sanctum::actingAs($this->admin);

        CompanyActivity::create([
            'subject_type' => User::class,
            'subject_id' => $this->viewer->id,
            'event_key' => 'user_created',
            'description' => 'User dibuat.',
            'actor_user_id' => $this->admin->id,
            'occurred_at' => now(),
        ]);

        $entry = $this->getJson('/api/customer/users/'.$this->viewer->id.'/activities')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('User dibuat.', $entry['title']);
        $this->assertSame($this->admin->name, $entry['actor_name']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $inactive = User::factory()->create([
            'company_id' => $this->company->id,
            'user_type' => 'customer',
            'status' => 'inactive',
            'password' => Hash::make('password123'),
        ]);
        $inactive->syncRoles(['viewer']);

        $this->postJson('/api/login', [
            'email' => $inactive->email,
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_user_index_filters_by_search_and_role(): void
    {
        Sanctum::actingAs($this->admin);

        $search = $this->getJson('/api/customer/users?search='.$this->viewer->email)->assertOk()->json('data');
        $this->assertCount(1, $search);
        $this->assertSame($this->viewer->email, $search[0]['email']);

        $role = $this->getJson('/api/customer/users?role=company_admin')->assertOk()->json('data');
        $this->assertCount(1, $role);
        $this->assertSame('company_admin', $role[0]['role']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function userPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Ops User',
            'email' => 'ops.new@example.com',
            'password' => 'password123',
            'phone' => '08333333333',
            'role' => 'ops_pic',
            'status' => 'active',
            'location_ids' => [$this->activeLocation->id],
        ], $overrides);
    }
}
