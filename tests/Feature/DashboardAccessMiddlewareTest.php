<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Smoke: admin vs customer API separation (selaras brief / rencana akses MVP).
 */
class DashboardAccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_internal_user_cannot_access_customer_dashboard(): void
    {
        $user = User::factory()->create(['user_type' => 'internal']);
        $user->assignRole('super_admin');

        Sanctum::actingAs($user);

        $this->getJson('/api/customer/dashboard')->assertStatus(403);
    }

    public function test_customer_user_cannot_access_admin_dashboard(): void
    {
        $company = Company::create(['name' => 'Test Co', 'status' => 'active']);
        $user = User::factory()->create([
            'user_type' => 'customer',
            'company_id' => $company->id,
        ]);
        $user->assignRole('company_admin');

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/dashboard')->assertStatus(403);
    }
}
