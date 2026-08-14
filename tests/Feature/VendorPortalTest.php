<?php

namespace Tests\Feature;

use App\Enums\VendorInvoiceStatus;
use App\Enums\VendorJobStatus;
use App\Enums\VendorPaymentStatus;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Location;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Models\TransportMode;
use App\Models\User;
use App\Models\VendorInvoice;
use App\Models\VendorPayment;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VendorPortalTest extends TestCase
{
    use RefreshDatabase;

    protected Company $vendorCompany;

    protected User $vendorAdmin;

    protected User $vendorOps;

    protected User $vendorViewer;

    protected Company $customerCompany;

    protected User $customerAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MasterDataSeeder::class);

        Storage::fake('public');

        $this->customerCompany = Company::create(['name' => 'Customer Co', 'type' => 'customer', 'status' => 'active', 'company_code' => 'C-CUST']);
        $this->customerAdmin = User::factory()->create([
            'company_id' => $this->customerCompany->id,
            'user_type' => 'customer',
            'status' => 'active',
        ]);
        $this->customerAdmin->syncRoles(['company_admin']);

        $this->vendorCompany = Company::create([
            'name' => 'Vendor Co',
            'type' => 'vendor',
            'status' => 'active',
            'company_code' => 'V-TEST',
            'service_categories' => ['trucking'],
        ]);
        $this->vendorAdmin = $this->makeVendorUser('admin@v.test', 'vendor_company_admin');
        $this->vendorOps = $this->makeVendorUser('ops@v.test', 'vendor_ops_pic');
        $this->vendorViewer = $this->makeVendorUser('viewer@v.test', 'vendor_viewer');
    }

    private function makeVendorUser(string $email, string $role): User
    {
        $user = User::factory()->create([
            'company_id' => $this->vendorCompany->id,
            'user_type' => 'vendor',
            'email' => $email,
            'status' => 'active',
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    private function createJobOrder(VendorJobStatus $status = VendorJobStatus::PendingAcceptance): Shipment
    {
        $origin = Location::first() ?? Location::create(['code' => 'O1', 'name' => 'Origin', 'city' => 'X', 'is_active' => true]);
        $dest = Location::skip(1)->first() ?? Location::create(['code' => 'D1', 'name' => 'Dest', 'city' => 'Y', 'is_active' => true]);
        $service = ServiceType::first() ?? ServiceType::create(['code' => 'TRK', 'name' => 'Trucking', 'is_active' => true]);
        $mode = TransportMode::first() ?? TransportMode::create(['code' => 'ROAD', 'name' => 'Road', 'is_active' => true]);

        $booking = Booking::create([
            'company_id' => $this->customerCompany->id,
            'user_id' => $this->customerAdmin->id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $dest->id,
            'service_type_id' => $service->id,
            'transport_mode_id' => $mode->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'approved',
            'shipper_name' => 'Shipper',
            'shipper_address' => 'Shipper Address',
            'shipper_phone' => '08111',
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Consignee Address',
            'consignee_phone' => '08222',
        ]);

        return Shipment::create([
            'shipment_no' => 'SHP-T-'.random_int(1000, 9999),
            'shipment_number' => 'JO-'.now()->format('Ymd').'-'.random_int(1000, 9999),
            'booking_id' => $booking->id,
            'company_id' => $this->customerCompany->id,
            'service_type_id' => $service->id,
            'transport_mode_id' => $mode->id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $dest->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'planning',
            'vendor_company_id' => $this->vendorCompany->id,
            'vendor_status' => $status->value,
            'accepted_at' => in_array($status, [VendorJobStatus::Accepted, VendorJobStatus::InProgress, VendorJobStatus::WaitingVerification, VendorJobStatus::Completed], true) ? now()->subDays(2) : null,
            'completion_submitted_at' => in_array($status, [VendorJobStatus::WaitingVerification, VendorJobStatus::Completed], true) ? now()->subDay() : null,
            'completion_verified_at' => $status === VendorJobStatus::Completed ? now() : null,
            'estimated_arrival' => now()->addDays(7),
        ]);
    }

    private function createStandaloneJobOrder(VendorJobStatus $status = VendorJobStatus::PendingAcceptance, ?Company $otherVendor = null): Shipment
    {
        $origin = Location::first();
        $dest = Location::skip(1)->first();
        $service = ServiceType::first();
        $mode = TransportMode::first();

        $booking = Booking::create([
            'company_id' => $this->customerCompany->id,
            'user_id' => $this->customerAdmin->id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $dest->id,
            'service_type_id' => $service->id,
            'transport_mode_id' => $mode->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'approved',
            'shipper_name' => 'Shipper',
            'shipper_address' => 'Shipper Address',
            'shipper_phone' => '08111',
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Consignee Address',
            'consignee_phone' => '08222',
        ]);

        return Shipment::create([
            'shipment_no' => 'SHP-O-'.random_int(1000, 9999),
            'shipment_number' => 'OTHER-'.random_int(1000, 9999),
            'booking_id' => $booking->id,
            'company_id' => $this->customerCompany->id,
            'service_type_id' => $service->id,
            'transport_mode_id' => $mode->id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $dest->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'planning',
            'vendor_company_id' => $otherVendor?->id ?? $this->vendorCompany->id,
            'vendor_status' => $status->value,
        ]);
    }

    public function test_dashboard_requires_vendor_role(): void
    {
        Sanctum::actingAs($this->customerAdmin);
        $this->getJson('/api/vendor/dashboard')->assertStatus(404);
    }

    public function test_dashboard_returns_vendor_stats(): void
    {
        $this->createJobOrder(VendorJobStatus::PendingAcceptance);
        $this->createJobOrder(VendorJobStatus::InProgress);
        $this->createJobOrder(VendorJobStatus::Completed);

        Sanctum::actingAs($this->vendorAdmin);
        $res = $this->getJson('/api/vendor/dashboard')->assertOk();
        $res->assertJsonStructure([
            'data' => [
                'stats' => ['pending_acceptance', 'in_progress', 'completed', 'pending_invoice'],
                'my_job_orders',
                'performance',
                'upcoming_deadlines',
                'recent_activities',
                'pending_documents',
            ],
        ]);
        $this->assertSame(1, $res->json('data.stats.pending_acceptance'));
        $this->assertSame(1, $res->json('data.stats.in_progress'));
    }

    public function test_vendor_cannot_access_other_vendor_job_order(): void
    {
        $otherVendor = Company::create(['name' => 'Other Vendor', 'type' => 'vendor', 'status' => 'active', 'company_code' => 'V-OTHER']);
        $otherJob = $this->createStandaloneJobOrder(VendorJobStatus::PendingAcceptance, $otherVendor);

        Sanctum::actingAs($this->vendorAdmin);
        $this->getJson("/api/vendor/job-orders/{$otherJob->id}")->assertStatus(404);
    }

    public function test_vendor_can_view_own_job_order_detail(): void
    {
        $job = $this->createJobOrder(VendorJobStatus::Completed);
        Sanctum::actingAs($this->vendorAdmin);

        $res = $this->getJson("/api/vendor/job-orders/{$job->id}")->assertOk();
        $res->assertJsonStructure(['data' => ['id', 'jo_number', 'vendor_status', 'progress_updates', 'supporting_documents', 'timeline', 'activities']]);
        $this->assertSame($job->id, $res->json('data.id'));
    }

    public function test_vendor_can_accept_pending_job(): void
    {
        $job = $this->createJobOrder(VendorJobStatus::PendingAcceptance);
        Sanctum::actingAs($this->vendorAdmin);

        $res = $this->postJson("/api/vendor/job-orders/{$job->id}/accept")->assertOk();
        $this->assertSame(VendorJobStatus::Accepted->value, $job->fresh()->vendor_status);
        $this->assertNotNull($job->fresh()->accepted_at);
        $res->assertJsonPath('data.vendor_status', VendorJobStatus::Accepted->value);
    }

    public function test_cannot_accept_non_pending_job(): void
    {
        $job = $this->createJobOrder(VendorJobStatus::InProgress);
        Sanctum::actingAs($this->vendorAdmin);

        $this->postJson("/api/vendor/job-orders/{$job->id}/accept")->assertStatus(422);
    }

    public function test_vendor_can_submit_completion(): void
    {
        $job = $this->createJobOrder(VendorJobStatus::InProgress);
        Sanctum::actingAs($this->vendorOps);

        $res = $this->postJson("/api/vendor/job-orders/{$job->id}/submit-completion", [
            'completion_remark' => 'All tasks done.',
        ])->assertOk();

        $this->assertSame(VendorJobStatus::WaitingVerification->value, $job->fresh()->vendor_status);
        $this->assertNotNull($job->fresh()->completion_submitted_at);
    }

    public function test_vendor_can_create_invoice_draft(): void
    {
        $job = $this->createJobOrder(VendorJobStatus::Completed);
        Sanctum::actingAs($this->vendorAdmin);

        $file = UploadedFile::fake()->create('invoice.pdf', 100);
        $res = $this->postJson('/api/vendor/invoices', [
            'shipment_id' => $job->id,
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'invoice_amount' => 1000000,
            'invoice_file' => $file,
        ])->assertCreated();

        $inv = VendorInvoice::findOrFail($res->json('data.id'));
        $this->assertSame(VendorInvoiceStatus::Draft->value, $inv->statusValue());
        $this->assertEquals(1000000.0, (float) $inv->total_amount);
        $this->assertSame($job->id, $inv->shipment_id);
    }

    public function test_invoice_number_is_generated_with_vendor_prefix(): void
    {
        $job = $this->createJobOrder(VendorJobStatus::Completed);
        Sanctum::actingAs($this->vendorAdmin);

        $file = UploadedFile::fake()->create('inv.pdf', 50);
        $res = $this->postJson('/api/vendor/invoices', [
            'shipment_id' => $job->id,
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'invoice_amount' => 500000,
            'invoice_file' => $file,
        ])->assertCreated();

        $this->assertStringStartsWith('INV-V-', $res->json('data.invoice_number'));
    }

    public function test_vendor_cannot_create_duplicate_invoice_for_same_job(): void
    {
        $job = $this->createJobOrder(VendorJobStatus::Completed);
        Sanctum::actingAs($this->vendorAdmin);

        $file = UploadedFile::fake()->create('inv.pdf', 50);
        $this->postJson('/api/vendor/invoices', [
            'shipment_id' => $job->id,
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'invoice_amount' => 1000,
            'invoice_file' => $file,
        ])->assertCreated();

        $file2 = UploadedFile::fake()->create('inv2.pdf', 50);
        $this->postJson('/api/vendor/invoices', [
            'shipment_id' => $job->id,
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'invoice_amount' => 1000,
            'invoice_file' => $file2,
        ])->assertStatus(422);
    }

    public function test_invoice_submit_transitions_to_submitted(): void
    {
        $job = $this->createJobOrder(VendorJobStatus::Completed);
        Sanctum::actingAs($this->vendorAdmin);

        $file = UploadedFile::fake()->create('inv.pdf', 50);
        $res = $this->postJson('/api/vendor/invoices', [
            'shipment_id' => $job->id,
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'invoice_amount' => 1000,
            'invoice_file' => $file,
        ])->assertCreated();
        $invId = $res->json('data.id');

        $this->postJson("/api/vendor/invoices/{$invId}/submit")->assertOk();
        $this->assertSame(VendorInvoiceStatus::Submitted->value, VendorInvoice::find($invId)?->statusValue());
    }

    public function test_company_only_shows_own_company(): void
    {
        $other = Company::create(['name' => 'Other', 'type' => 'vendor', 'status' => 'active', 'company_code' => 'V-O']);
        Sanctum::actingAs($this->vendorAdmin);
        $res = $this->getJson('/api/vendor/company')->assertOk();
        $this->assertSame($this->vendorCompany->id, $res->json('data.id'));
    }

    public function test_company_update_readonly_fields_are_ignored(): void
    {
        Sanctum::actingAs($this->vendorAdmin);
        $res = $this->putJson('/api/vendor/company', [
            'company_code' => 'HACKED', // should be ignored (read-only)
            'business_entity_type' => 'CV', // should be ignored (read-only)
            'service_categories' => ['warehouse'], // should be ignored (read-only)
            'address' => 'New Address', // editable
            'phone' => '021-999', // editable
        ]);
        $res->assertOk();
        $this->assertSame('V-TEST', $this->vendorCompany->fresh()->company_code);
        $this->assertSame('Vendor Co', $this->vendorCompany->fresh()->name);
        $this->assertSame('New Address', $this->vendorCompany->fresh()->address);
        $this->assertSame('021-999', $this->vendorCompany->fresh()->phone);
    }

    public function test_vendor_user_list_scoped_to_company(): void
    {
        $other = Company::create(['name' => 'Other', 'type' => 'vendor', 'status' => 'active', 'company_code' => 'V-O']);
        $otherUser = $this->makeVendorUser('other@v.test', 'vendor_company_admin');
        $otherUser->update(['company_id' => $other->id]);

        Sanctum::actingAs($this->vendorAdmin);
        $res = $this->getJson('/api/vendor/users')->assertOk();
        foreach ($res->json('data') as $u) {
            $this->assertSame($this->vendorCompany->id, $u['company_id']);
        }
    }

    public function test_last_vendor_admin_cannot_be_deactivated(): void
    {
        Sanctum::actingAs($this->vendorAdmin);
        $this->patchJson("/api/vendor/users/{$this->vendorAdmin->id}/status", [
            'status' => 'inactive',
        ])->assertStatus(422);
    }

    public function test_last_vendor_admin_cannot_be_role_changed(): void
    {
        Sanctum::actingAs($this->vendorAdmin);
        $this->patchJson("/api/vendor/users/{$this->vendorAdmin->id}/role", [
            'role' => 'vendor_viewer',
        ])->assertStatus(422);
    }

    public function test_user_cannot_deactivate_self(): void
    {
        Sanctum::actingAs($this->vendorOps);
        $this->patchJson("/api/vendor/users/{$this->vendorOps->id}/status", [
            'status' => 'inactive',
        ])->assertStatus(422);
    }

    public function test_create_user_generates_temporary_password(): void
    {
        Sanctum::actingAs($this->vendorAdmin);
        $res = $this->postJson('/api/vendor/users', [
            'name' => 'New User',
            'email' => 'new@v.test',
            'role' => 'vendor_ops_pic',
        ])->assertCreated();
        $this->assertNotEmpty($res->json('temporary_password'));
    }

    public function test_reset_password_returns_temporary_password(): void
    {
        Sanctum::actingAs($this->vendorAdmin);
        $res = $this->postJson("/api/vendor/users/{$this->vendorOps->id}/reset-password")->assertOk();
        $this->assertNotEmpty($res->json('temporary_password'));
    }

    public function test_payment_list_is_readonly(): void
    {
        Sanctum::actingAs($this->vendorAdmin);
        $res = $this->getJson('/api/vendor/payments')->assertOk();
        $this->assertIsArray($res->json('data'));
    }

    public function test_payment_returns_history(): void
    {
        $job = $this->createJobOrder(VendorJobStatus::Completed);
        $inv = VendorInvoice::create([
            'invoice_number' => 'INV-V-XXXXXX',
            'vendor_company_id' => $this->vendorCompany->id,
            'shipment_id' => $job->id,
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'invoice_amount' => 1000,
            'tax_amount' => 0,
            'total_amount' => 1000,
            'status' => VendorInvoiceStatus::Approved,
            'created_by' => $this->vendorAdmin->id,
        ]);
        $payment = VendorPayment::create([
            'payment_number' => 'PAY-V-XXXXXX',
            'vendor_invoice_id' => $inv->id,
            'payment_date' => '2026-08-20',
            'amount' => 1000,
            'payment_method' => 'transfer',
            'status' => VendorPaymentStatus::Paid,
        ]);

        Sanctum::actingAs($this->vendorAdmin);
        $res = $this->getJson("/api/vendor/payments/{$payment->id}")->assertOk();
        $this->assertIsArray($res->json('data.history'));
    }

    public function test_my_profile_update_works(): void
    {
        Sanctum::actingAs($this->vendorAdmin);
        $res = $this->putJson('/api/vendor/my-profile', [
            'name' => 'Updated Name',
            'phone' => '08123',
        ])->assertOk();
        $this->assertSame('Updated Name', $res->json('data.name'));
    }

    public function test_my_profile_email_is_readonly(): void
    {
        Sanctum::actingAs($this->vendorAdmin);
        $originalEmail = $this->vendorAdmin->email;
        $this->putJson('/api/vendor/my-profile', [
            'email' => 'hacked@v.test',
            'name' => 'New',
        ])->assertOk();
        $this->assertSame($originalEmail, $this->vendorAdmin->fresh()->email);
    }

    public function test_change_password_requires_min_length(): void
    {
        Sanctum::actingAs($this->vendorAdmin);
        $this->postJson('/api/vendor/my-profile/change-password', [
            'current_password' => 'password',
            'new_password' => 'short',
            'new_password_confirmation' => 'short',
        ])->assertStatus(422);
    }

    public function test_change_password_success(): void
    {
        $this->vendorAdmin->update(['password' => bcrypt('oldpassword')]);
        Sanctum::actingAs($this->vendorAdmin);
        $this->postJson('/api/vendor/my-profile/change-password', [
            'current_password' => 'oldpassword',
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ])->assertOk();
    }

    public function test_eligible_job_orders_filters_out_non_completed_and_already_invoiced(): void
    {
        $pending = $this->createJobOrder(VendorJobStatus::PendingAcceptance);
        $inProgress = $this->createJobOrder(VendorJobStatus::InProgress);
        $completedWithInvoice = $this->createJobOrder(VendorJobStatus::Completed);
        $completedEligible = $this->createJobOrder(VendorJobStatus::Completed);

        VendorInvoice::create([
            'vendor_company_id' => $this->vendorCompany->id,
            'shipment_id' => $completedWithInvoice->id,
            'invoice_number' => 'INV-TEST-0001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'invoice_amount' => 1000,
            'tax_amount' => 0,
            'total_amount' => 1000,
            'status' => VendorInvoiceStatus::Paid->value,
            'created_by' => $this->vendorAdmin->id,
        ]);

        Sanctum::actingAs($this->vendorAdmin);
        $res = $this->getJson('/api/vendor/invoices/eligible-job-orders')->assertOk();
        $items = $res->json('data');
        $this->assertIsArray($items);
        $this->assertCount(1, $items);
        $this->assertSame($completedEligible->id, $items[0]['id']);
        $this->assertNotContains($pending->id, collect($items)->pluck('id'));
        $this->assertNotContains($inProgress->id, collect($items)->pluck('id'));
        $this->assertNotContains($completedWithInvoice->id, collect($items)->pluck('id'));
    }

    public function test_seeder_leaves_at_least_one_completed_job_eligible(): void
    {
        $origin = Location::first();
        $dest = Location::skip(1)->first();
        $service = ServiceType::first();
        $mode = TransportMode::first();

        $booking = Booking::create([
            'company_id' => $this->customerCompany->id,
            'user_id' => $this->customerAdmin->id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $dest->id,
            'service_type_id' => $service->id,
            'transport_mode_id' => $mode->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'approved',
            'shipper_name' => 'S', 'shipper_address' => 'A', 'shipper_phone' => '1',
            'consignee_name' => 'C', 'consignee_address' => 'A', 'consignee_phone' => '2',
        ]);

        $completedA = Shipment::create([
            'shipment_no' => 'SHP-A', 'shipment_number' => 'JO-A',
            'booking_id' => $booking->id,
            'company_id' => $this->customerCompany->id,
            'service_type_id' => $service->id,
            'transport_mode_id' => $mode->id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $dest->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'completed',
            'vendor_company_id' => $this->vendorCompany->id,
            'vendor_status' => VendorJobStatus::Completed->value,
            'completion_verified_at' => now(),
            'estimated_arrival' => now()->addDays(7),
        ]);

        $completedB = Shipment::create([
            'shipment_no' => 'SHP-B', 'shipment_number' => 'JO-B',
            'booking_id' => $booking->id,
            'company_id' => $this->customerCompany->id,
            'service_type_id' => $service->id,
            'transport_mode_id' => $mode->id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $dest->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'completed',
            'vendor_company_id' => $this->vendorCompany->id,
            'vendor_status' => VendorJobStatus::Completed->value,
            'completion_verified_at' => now(),
            'estimated_arrival' => now()->addDays(7),
        ]);

        // Simulate seeder: create invoice for ONLY the first Completed JO,
        // leaving the second one eligible for new invoice creation.
        VendorInvoice::create([
            'vendor_company_id' => $this->vendorCompany->id,
            'shipment_id' => $completedA->id,
            'invoice_number' => 'INV-V-TEST-0001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'invoice_amount' => 1000,
            'tax_amount' => 0,
            'total_amount' => 1000,
            'status' => VendorInvoiceStatus::Paid->value,
            'created_by' => $this->vendorAdmin->id,
        ]);

        Sanctum::actingAs($this->vendorAdmin);
        $res = $this->getJson('/api/vendor/invoices/eligible-job-orders')->assertOk();
        $items = $res->json('data');

        $this->assertIsArray($items);
        $this->assertCount(1, $items, 'Exactly one Completed job should be eligible (the second one).');
        $this->assertSame($completedB->id, $items[0]['id']);
    }

    public function test_viewer_cannot_create_invoice(): void
    {
        $job = $this->createJobOrder(VendorJobStatus::Completed);
        Sanctum::actingAs($this->vendorViewer);
        $file = UploadedFile::fake()->create('inv.pdf', 50);
        $this->postJson('/api/vendor/invoices', [
            'shipment_id' => $job->id,
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'invoice_amount' => 100,
            'invoice_file' => $file,
        ])->assertStatus(403);
    }
}
