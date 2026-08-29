<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerRegistrationReceivedMail;
use App\Mail\CustomerRegistrationRejectedMail;
use App\Models\Company;
use App\Models\CustomerLocation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerRegistrationFsdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_public_registration_creates_pending_company_inactive_admin_and_head_office(): void
    {
        Mail::fake();

        $payload = $this->validRegistrationPayload();

        $response = $this->postJson('/api/register', $payload)->assertCreated();

        $companyId = (int) $response->json('data.company.id');
        $userId = (int) $response->json('data.user.id');

        $company = Company::findOrFail($companyId);
        $user = User::findOrFail($userId);

        $this->assertSame('customer', $company->type);
        $this->assertSame('pending', $company->status);
        $this->assertSame('SOL', $company->company_code);
        $this->assertSame('inactive', $user->status->value);
        $this->assertTrue($user->hasRole('company_admin'));

        $ho = CustomerLocation::query()
            ->where('company_id', $company->id)
            ->where('type', 'head_office')
            ->where('status', 'active')
            ->first();

        $this->assertNotNull($ho);
        $this->assertSame('Jakarta Pusat', $ho->district);
        $this->assertTrue($user->locationAccess()->where('customer_locations.id', $ho->id)->exists());

        Mail::assertSentCount(1);
        Mail::assertSent(CustomerRegistrationReceivedMail::class);
    }

    public function test_inactive_user_cannot_login(): void
    {
        Mail::fake();
        $payload = $this->validRegistrationPayload(['admin_email' => 'blocked@test.com']);

        $this->postJson('/api/register', $payload)->assertCreated();

        $this->postJson('/api/login', [
            'email' => 'blocked@test.com',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_admin_can_approve_registration_with_existing_head_office(): void
    {
        Mail::fake();

        $payload = $this->validRegistrationPayload(['company_code' => 'APR']);
        $reg = $this->postJson('/api/register', $payload)->assertCreated();
        $companyId = (int) $reg->json('data.company.id');

        $admin = User::factory()->create(['user_type' => 'internal', 'status' => 'active']);
        $admin->syncRoles(['super_admin']);
        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/companies/{$companyId}", ['status' => 'active'])->assertOk();

        $company = Company::findOrFail($companyId);
        $user = User::where('company_id', $companyId)->first();

        $this->assertSame('active', $company->status);
        $this->assertSame('active', $user?->status->value);
    }

    public function test_admin_reject_stores_reason_and_sends_email(): void
    {
        Mail::fake();

        $payload = $this->validRegistrationPayload(['company_code' => 'REJ']);
        $reg = $this->postJson('/api/register', $payload)->assertCreated();
        $companyId = (int) $reg->json('data.company.id');

        $admin = User::factory()->create(['user_type' => 'internal', 'status' => 'active']);
        $admin->syncRoles(['super_admin']);
        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/companies/{$companyId}", [
            'status' => 'rejected',
            'rejection_reason' => 'NPWP tidak valid.',
        ])->assertOk();

        $company = Company::findOrFail($companyId);
        $this->assertSame('rejected', $company->status);
        $this->assertSame('NPWP tidak valid.', $company->rejection_reason);

        Mail::assertSentCount(1);
        Mail::assertNotSent(CustomerRegistrationRejectedMail::class);
    }

    public function test_check_company_code_endpoint(): void
    {
        Mail::fake();
        $this->postJson('/api/register', $this->validRegistrationPayload(['company_code' => 'ZZZ']));

        $this->getJson('/api/register/check-company-code?code=ZZZ')
            ->assertOk()
            ->assertJsonPath('exists', true);

        $this->getJson('/api/register/check-company-code?code=ABC')
            ->assertOk()
            ->assertJsonPath('exists', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validRegistrationPayload(array $overrides = []): array
    {
        return array_merge([
            'business_entity_type' => 'PT',
            'company_name' => 'PT Solusi Logistik',
            'company_code' => 'SOL',
            'npwp' => '12.345.678.9-012.345',
            'company_email' => 'info@sol.test',
            'company_phone' => '0211234567',
            'country' => 'Indonesia',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta Pusat',
            'district' => 'Jakarta Pusat',
            'postal_code' => '10110',
            'address' => 'Jl. Sudirman No. 1',
            'business_category' => 'logistics',
            'monthly_shipment_estimate' => '10-50',
            'admin_name' => 'Admin SOL',
            'admin_email' => 'admin@sol.test',
            'admin_phone' => '08123456789',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
        ], $overrides);
    }
}
