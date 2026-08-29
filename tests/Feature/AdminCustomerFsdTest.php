<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomerLocation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCustomerFsdTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create([
            'user_type' => 'internal',
            'status' => 'active',
        ]);
        $this->admin->syncRoles(['super_admin']);
    }

    public function test_admin_can_list_customers_with_fsd_columns(): void
    {
        Company::create([
            'name' => 'FSD Customer Co',
            'type' => 'customer',
            'status' => 'pending',
            'company_code' => 'FSD',
            'billing_cycle' => null,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/admin/companies?status=pending')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $row = $response->json('data.0');
        $this->assertSame('FSD', $row['company_code']);
        $this->assertSame('FSD Customer Co', $row['name']);
        $this->assertSame('pending', $row['status']);
    }

    public function test_admin_can_create_customer_with_status_and_business_entity_other(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/admin/companies', [
            'business_entity_type' => 'Lainnya',
            'business_entity_other' => 'Koperasi Khusus',
            'name' => 'New FSD Customer',
            'company_code' => 'NFC',
            'npwp' => '1234567890123456',
            'email' => 'new@fsd.test',
            'phone' => '08123456789',
            'country' => 'Indonesia',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta Selatan',
            'district' => 'Setiabudi',
            'postal_code' => '12910',
            'address' => 'Jl. FSD No. 1',
            'business_category' => 'logistics',
            'monthly_shipment_estimate' => '10_to_50',
            'status' => 'pending',
            'pic_email' => 'admin@fsd.test',
            'pic_name' => 'Admin FSD',
            'pic_phone' => '08123456789',
            'password' => 'password123',
        ])->assertCreated();

        $this->assertSame('pending', $response->json('data.status'));
        $this->assertDatabaseHas('companies', [
            'company_code' => 'NFC',
            'business_entity_other' => 'Koperasi Khusus',
            'billing_cycle' => null,
        ]);

        $this->assertDatabaseHas('customer_locations', [
            'company_id' => $response->json('data.id'),
            'type' => 'head_office',
        ]);
    }

    public function test_admin_can_suspend_active_customer(): void
    {
        $company = Company::create([
            'name' => 'Suspend Me',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'SUS',
            'billing_cycle' => null,
        ]);

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/admin/companies/{$company->id}", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
    }

    public function test_admin_can_reject_customer_with_reason_via_status_update(): void
    {
        $company = Company::create([
            'name' => 'Reject Me',
            'type' => 'customer',
            'status' => 'pending',
            'company_code' => 'REJ',
            'billing_cycle' => null,
        ]);

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/admin/companies/{$company->id}", [
            'status' => 'rejected',
        ])->assertUnprocessable();

        $this->putJson("/api/admin/companies/{$company->id}", [
            'status' => 'rejected',
            'rejection_reason' => 'Dokumen tidak lengkap.',
        ])->assertOk();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'status' => 'rejected',
            'rejection_reason' => 'Dokumen tidak lengkap.',
        ]);
    }

    public function test_create_requires_complete_admin_account_and_minimum_password(): void
    {
        Sanctum::actingAs($this->admin);
        $payload = $this->validCustomerPayload();
        $payload['password'] = 'short';

        $this->postJson('/api/admin/companies', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        unset($payload['password'], $payload['pic_email'], $payload['pic_phone']);
        $this->postJson('/api/admin/companies', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password', 'pic_email', 'pic_phone']);
    }

    public function test_direct_status_activation_requires_active_head_office_and_syncs_users(): void
    {
        $company = Company::create([
            'name' => 'Activation Guard Co',
            'type' => 'customer',
            'status' => 'pending',
            'company_code' => 'AGC',
            'billing_cycle' => null,
        ]);
        $customerUser = User::factory()->create([
            'company_id' => $company->id,
            'user_type' => 'customer',
            'status' => 'inactive',
        ]);
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/admin/companies/{$company->id}", ['status' => 'active'])
            ->assertUnprocessable();

        CustomerLocation::create([
            'company_id' => $company->id,
            'code' => 'HO',
            'name' => 'Head Office',
            'type' => 'head_office',
            'status' => 'active',
            'country' => 'Indonesia',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta Selatan',
            'address' => 'Jl. FSD',
            'pic_name' => 'PIC',
            'pic_email' => 'pic@guard.test',
            'pic_mobile' => '08123456789',
        ]);

        $this->putJson("/api/admin/companies/{$company->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->assertSame('active', $customerUser->fresh()->status->value);
    }

    public function test_operations_can_manage_admin_customer_locations(): void
    {
        $operations = User::factory()->create(['user_type' => 'internal', 'status' => 'active']);
        $operations->syncRoles(['operations']);
        $company = Company::create([
            'name' => 'Operations Location Co',
            'type' => 'customer',
            'status' => 'pending',
            'company_code' => 'OLC',
            'billing_cycle' => null,
        ]);
        Sanctum::actingAs($operations);

        $this->postJson("/api/admin/companies/{$company->id}/locations", [
            'type' => 'head_office',
            'name' => 'Jakarta Head Office',
            'phone' => '021123456',
            'status' => 'active',
            'country' => 'Indonesia',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta Selatan',
            'postal_code' => '12910',
            'address' => 'Jl. FSD',
            'pic_name' => 'PIC Ops',
            'pic_email' => 'ops-pic@fsd.test',
            'pic_mobile' => '08123456789',
        ])->assertCreated();
    }

    public function test_location_edit_cannot_deactivate_only_active_head_office(): void
    {
        $company = Company::create([
            'name' => 'Protected HO Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'PHO',
            'billing_cycle' => null,
        ]);
        $location = CustomerLocation::create([
            'company_id' => $company->id,
            'code' => 'HO',
            'name' => 'Head Office',
            'type' => 'head_office',
            'status' => 'active',
            'country' => 'Indonesia',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta',
            'address' => 'Jl. FSD',
            'pic_name' => 'PIC',
            'pic_email' => 'pic@protected.test',
            'pic_mobile' => '08123456789',
        ]);
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/admin/companies/{$company->id}/locations/{$location->id}", [
            'status' => 'inactive',
        ])->assertUnprocessable();
    }

    public function test_review_assignees_must_be_internal_users(): void
    {
        $company = Company::create([
            'name' => 'Review Assignment Co',
            'type' => 'customer',
            'status' => 'pending',
            'company_code' => 'RAC',
            'billing_cycle' => null,
        ]);
        $customerUser = User::factory()->create([
            'company_id' => $company->id,
            'user_type' => 'customer',
        ]);
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/admin/companies/{$company->id}", [
            'sales_pic_id' => $customerUser->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('sales_pic_id');
    }

    public function test_company_code_stays_locked_after_customer_was_active(): void
    {
        $company = Company::create([
            'name' => 'Locked Code Co',
            'type' => 'customer',
            'status' => 'suspended',
            'company_code' => 'LCC',
            'approved_at' => now(),
            'billing_cycle' => null,
        ]);

        $company->update(['company_code' => 'NEW']);

        $this->assertSame('LCC', $company->fresh()->company_code);
    }

    public function test_postal_code_options_are_filtered_by_region(): void
    {
        Http::fake([
            'kodepos.vercel.app/*' => Http::response([
                'data' => [
                    ['code' => 12910, 'village' => 'Karet', 'district' => 'Setiabudi', 'province' => 'DKI Jakarta'],
                    ['code' => 40111, 'village' => 'Braga', 'district' => 'Sumur Bandung', 'province' => 'Jawa Barat'],
                ],
            ]),
        ]);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/customer-postal-codes?province=DKI%20Jakarta&city=Jakarta&district=Setiabudi')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', '12910');
    }

    /**
     * @return array<string, mixed>
     */
    private function validCustomerPayload(): array
    {
        return [
            'business_entity_type' => 'PT',
            'name' => 'Complete FSD Customer',
            'company_code' => 'CFC',
            'npwp' => '1234567890123456',
            'email' => 'complete@fsd.test',
            'phone' => '08123456789',
            'country' => 'Indonesia',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta Selatan',
            'district' => 'Setiabudi',
            'postal_code' => '12910',
            'address' => 'Jl. Complete FSD',
            'business_category' => 'logistics',
            'monthly_shipment_estimate' => '10_to_50',
            'status' => 'pending',
            'pic_name' => 'Admin Complete',
            'pic_email' => 'admin-complete@fsd.test',
            'pic_phone' => '08123456789',
            'password' => 'password123',
        ];
    }
}
