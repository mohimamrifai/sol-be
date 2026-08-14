<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminVendorInvoiceStatus;
use App\Enums\AdminVendorPaymentRequestStatus;
use App\Enums\VendorJobOrderStatus;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Location;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Models\TransportMode;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorJobOrder;
use App\Models\VendorPaymentRequest;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminVendorFsdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MasterDataSeeder::class);
        Storage::fake('public');
    }

    public function test_admin_vendor_flow_from_completed_jo_to_paid_payment(): void
    {
        $admin = User::factory()->create(['user_type' => 'internal', 'status' => 'active']);
        $admin->syncRoles(['super_admin']);
        Sanctum::actingAs($admin);

        $customer = Company::create(['name' => 'Customer Co', 'type' => 'customer', 'status' => 'active', 'company_code' => 'C-001']);
        $customerUser = User::factory()->create(['company_id' => $customer->id, 'user_type' => 'customer', 'status' => 'active']);

        $origin = Location::first() ?? Location::create(['code' => 'ORG', 'name' => 'Origin', 'city' => 'Jakarta', 'is_active' => true]);
        $dest = Location::skip(1)->first() ?? Location::create(['code' => 'DST', 'name' => 'Dest', 'city' => 'Surabaya', 'is_active' => true]);
        $service = ServiceType::first();
        $mode = TransportMode::first();

        $booking = Booking::create([
            'company_id' => $customer->id,
            'user_id' => $customerUser->id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $dest->id,
            'service_type_id' => $service->id,
            'transport_mode_id' => $mode->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'approved',
            'shipper_name' => 'Shipper',
            'shipper_address' => 'Addr',
            'shipper_phone' => '08111',
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Addr2',
            'consignee_phone' => '08222',
        ]);

        $shipment = Shipment::create([
            'shipment_no' => 'SHP-ADM-001',
            'shipment_number' => 'SHP-ADM-001',
            'booking_id' => $booking->id,
            'company_id' => $customer->id,
            'service_type_id' => $service->id,
            'transport_mode_id' => $mode->id,
            'origin_location_id' => $origin->id,
            'destination_location_id' => $dest->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'planning',
        ]);

        $vendor = Vendor::create([
            'name' => 'Vendor Test',
            'code' => 'VND00099',
            'business_entity' => 'company',
            'vendor_types' => ['trucking'],
            'npwp' => '123456789',
            'email' => 'vendor@test.com',
            'phone' => '08123456789',
            'address' => 'Jl Test',
            'is_active' => true,
            'payment_terms' => '30_days',
            'payment_method' => 'transfer',
        ]);

        $jo = VendorJobOrder::create([
            'shipment_id' => $shipment->id,
            'vendor_id' => $vendor->id,
            'service_type' => 'pickup',
            'status' => VendorJobOrderStatus::InProgress,
            'vendor_rate' => 1000000,
            'additional_cost' => 0,
            'total_cost' => 1000000,
        ]);

        $this->postJson("/api/admin/vendor-job-orders/{$jo->id}/verify-completion")->assertOk();
        $this->assertSame(VendorJobOrderStatus::Completed, $jo->fresh()->status);

        $this->getJson("/api/admin/vendor-invoices/eligible-job-orders?vendor_id={$vendor->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

        $create = $this->post('/api/admin/vendor-invoices', [
            'vendor_id' => $vendor->id,
            'vendor_external_number' => 'INV-VENDOR-001',
            'invoice_date' => now()->toDateString(),
            'invoice_amount' => 1000000,
            'tax_amount' => 0,
            'job_order_ids' => [$jo->id],
            'invoice_file' => $file,
        ])->assertCreated();

        $invoiceId = (int) $create->json('data.id');

        $this->postJson("/api/admin/vendor-invoices/{$invoiceId}/start-verification")->assertOk();
        $this->postJson("/api/admin/vendor-invoices/{$invoiceId}/verify", ['verification_notes' => 'OK'])->assertOk();

        $paymentId = (int) VendorPaymentRequest::query()->where('vendor_invoice_id', $invoiceId)->value('id');
        $this->assertTrue($paymentId > 0);

        $this->postJson("/api/admin/vendor-payments/{$paymentId}/approve", ['approval_remark' => 'Approved'])->assertOk();

        $this->post("/api/admin/vendor-payments/{$paymentId}/record-payment", [
            'payment_method' => 'transfer',
            'company_bank' => 'BCA - PT SOL Logistics',
            'payment_date' => now()->toDateString(),
            'payment_amount' => 1000000,
            'reference_no' => 'TRX-001',
        ])->assertOk();

        $this->assertSame(AdminVendorPaymentRequestStatus::Paid->value, VendorPaymentRequest::find($paymentId)?->status?->value);
        $this->assertSame(AdminVendorInvoiceStatus::Paid->value, VendorInvoice::find($invoiceId)?->statusValue());
    }
}
