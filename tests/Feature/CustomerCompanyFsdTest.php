<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyActivity;
use App\Models\CompanyDocument;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerCompanyFsdTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::create([
            'name' => 'Company FSD Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'CF01',
            'business_entity_type' => 'PT',
            'npwp' => '12.345.678.9-012.000',
            'email' => 'info@companyfsd.test',
            'phone' => '021-1234567',
            'country' => 'Indonesia',
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta Selatan',
            'address' => 'Jl. Test No. 1',
            'business_category' => 'trading',
            'monthly_shipment_estimate' => 'under_10',
            'billing_type' => 'postpaid',
            'pricing_type' => 'discount',
            'discount_percent' => 5,
            'billing_cycle' => 'per_shipment',
            'payment_term' => 'net_30',
            'credit_limit' => 10_000_000,
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
    }

    public function test_company_show_returns_readonly_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $data = $this->getJson('/api/customer/company')->assertOk()->json('data');

        $this->assertSame('Company FSD Co', $data['name']);
        $this->assertSame('CF01', $data['company_code']);
        $this->assertSame('PT', $data['business_entity_type']);
    }

    public function test_company_update_logs_profile_and_address_separately(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/customer/company', [
            'name' => 'Company FSD Updated',
            'address' => 'Jl. Baru No. 99',
        ])->assertOk();

        $descriptions = CompanyActivity::query()
            ->where('subject_id', $this->company->id)
            ->orderBy('id')
            ->pluck('description')
            ->all();

        $this->assertContains('Company Profile diperbarui.', $descriptions);
        $this->assertContains('Company Address diperbarui.', $descriptions);
    }

    public function test_viewer_can_update_company_profile_per_fsd(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->putJson('/api/customer/company', [
            'name' => 'Viewer Updated Name',
        ])->assertOk();

        $this->assertSame('Viewer Updated Name', $this->company->fresh()->name);
    }

    public function test_company_logo_upload_logs_activity(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin);

        $logo = UploadedFile::fake()->create('logo.png', 100, 'image/png');

        $this->postJson('/api/customer/company/logo', [
            'logo' => $logo,
        ])->assertOk()->assertJsonStructure(['data' => ['logo_url']]);

        $this->assertNotNull($this->company->fresh()->logo_path);

        $this->assertTrue(
            CompanyActivity::query()
                ->where('subject_id', $this->company->id)
                ->where('description', 'Company Logo diperbarui.')
                ->exists()
        );
    }

    public function test_company_documents_index_and_upload(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/customer/company/documents')
            ->assertOk()
            ->assertJsonPath('data', []);

        $file = UploadedFile::fake()->create('npwp.pdf', 100, 'application/pdf');

        $this->postJson('/api/customer/company/documents', [
            'type' => 'npwp',
            'file' => $file,
        ])->assertCreated();

        $this->assertTrue(
            CompanyActivity::query()
                ->where('subject_id', $this->company->id)
                ->where('description', 'Dokumen NPWP diperbarui.')
                ->exists()
        );

        $docs = $this->getJson('/api/customer/company/documents')->assertOk()->json('data');
        $this->assertCount(1, $docs);
        $this->assertSame('npwp', $docs[0]['type']);
        $this->assertArrayHasKey('uploaded_at', $docs[0]);
    }

    public function test_viewer_can_upload_company_document_per_fsd(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->viewer);

        $file = UploadedFile::fake()->create('npwp.pdf', 100, 'application/pdf');

        $this->postJson('/api/customer/company/documents', [
            'type' => 'npwp',
            'file' => $file,
        ])->assertCreated();
    }

    public function test_company_activities_returns_title_and_actor_name(): void
    {
        Sanctum::actingAs($this->admin);

        CompanyActivity::create([
            'subject_type' => Company::class,
            'subject_id' => $this->company->id,
            'event_key' => 'company_profile_updated',
            'description' => 'Company Profile diperbarui.',
            'actor_user_id' => $this->admin->id,
            'occurred_at' => now(),
        ]);

        $entry = $this->getJson('/api/customer/company/activities')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('Company Profile diperbarui.', $entry['title']);
        $this->assertSame($this->admin->name, $entry['actor_name']);
    }

    public function test_commercial_endpoint_returns_fsd_fields(): void
    {
        Sanctum::actingAs($this->admin);

        $data = $this->getJson('/api/customer/company/commercial')->assertOk()->json('data');

        $this->assertSame('postpaid', $data['billing_type']);
        $this->assertSame('discount', $data['pricing_type']);
        $this->assertSame('5.00', $data['discount_percent']);
        $this->assertSame('per_shipment', $data['billing_cycle']);
        $this->assertSame('net_30', $data['payment_term']);
    }

    public function test_document_download_scoped_to_own_company(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->admin);

        $otherCompany = Company::create([
            'name' => 'Other Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'OC01',
        ]);

        $path = 'company-documents/'.$otherCompany->id.'/npwp/test.pdf';
        Storage::disk('local')->put($path, 'secret');

        $foreignDoc = CompanyDocument::create([
            'company_id' => $otherCompany->id,
            'type' => 'npwp',
            'file_path' => $path,
            'file_size' => 100,
            'mime_type' => 'application/pdf',
            'uploaded_by_user_id' => $this->admin->id,
        ]);

        $this->getJson('/api/customer/company/documents/'.$foreignDoc->id.'/download')
            ->assertNotFound();
    }
}
