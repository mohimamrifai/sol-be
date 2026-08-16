<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomerLocation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerMyProfileFsdTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private CustomerLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::create([
            'name' => 'Profile Customer Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'PC01',
        ]);

        $this->user = User::factory()->create([
            'company_id' => $this->company->id,
            'user_type' => 'customer',
            'status' => 'active',
            'password' => Hash::make('password123'),
            'phone' => '08123456789',
        ]);
        $this->user->syncRoles(['ops_pic']);
        $this->user->update([
            'feature_access' => \App\Enums\UserRole::OpsPic->defaultFeatureAccess(),
        ]);

        $this->location = CustomerLocation::create([
            'company_id' => $this->company->id,
            'code' => 'PC0-0001',
            'name' => 'Head Office',
            'type' => 'head_office',
            'status' => 'active',
            'country' => 'Indonesia',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta',
            'address' => 'Jl. Profile 1',
            'pic_name' => 'PIC',
            'pic_email' => 'pic@example.com',
            'pic_mobile' => '08111111111',
        ]);
        $this->user->locationAccess()->sync([$this->location->id]);
    }

    public function test_my_profile_show_returns_fsd_fields(): void
    {
        Sanctum::actingAs($this->user);

        $data = $this->getJson('/api/customer/my-profile')->assertOk()->json('data');

        $this->assertSame($this->user->name, $data['name']);
        $this->assertSame($this->user->email, $data['email']);
        $this->assertSame('08123456789', $data['phone']);
        $this->assertSame('active', $data['status']);
        $this->assertSame('ops_pic', $data['role']);
        $this->assertSame('Profile Customer Co', $data['company']['name']);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('last_login_at', $data);
        $this->assertNotEmpty($data['location_access']);
        $this->assertNotEmpty($data['feature_access']);
    }

    public function test_my_profile_update_name_and_phone(): void
    {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/customer/my-profile', [
            'name' => 'Updated Name',
            'phone' => '08999999999',
        ])->assertOk();

        $fresh = $this->user->fresh();
        $this->assertSame('Updated Name', $fresh->name);
        $this->assertSame('08999999999', $fresh->phone);
    }

    public function test_my_profile_email_is_not_updatable(): void
    {
        Sanctum::actingAs($this->user);
        $originalEmail = $this->user->email;

        $this->putJson('/api/customer/my-profile', [
            'name' => 'Still Me',
            'email' => 'hacked@example.com',
        ])->assertOk();

        $this->assertSame($originalEmail, $this->user->fresh()->email);
    }

    public function test_change_password_requires_correct_current_password(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/customer/my-profile/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('password123', $this->user->fresh()->password));
    }

    public function test_change_password_succeeds_with_valid_current_password(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/customer/my-profile/change-password', [
            'current_password' => 'password123',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->assertTrue(Hash::check('newpassword123', $this->user->fresh()->password));
    }

    public function test_profile_photo_upload_accepts_jpg_png(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->user);

        $photo = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $this->postJson('/api/customer/my-profile/photo', [
            'photo' => $photo,
        ])->assertOk()->assertJsonStructure(['data' => ['profile_photo_url']]);

        $this->assertNotNull($this->user->fresh()->profile_photo_path);
    }

    public function test_profile_photo_rejects_invalid_mime(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->user);

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->postJson('/api/customer/my-profile/photo', [
            'photo' => $file,
        ])->assertStatus(422);
    }
}
