<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Payment;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Models\TransportMode;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerPaymentFsdTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $viewer;

    private Location $origin;

    private Location $destination;

    private ServiceType $lclService;

    private TransportMode $mode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->company = Company::create([
            'name' => 'Payment Customer Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'PC01',
            'manual_payment_enabled' => true,
            'bank_name' => 'BCA',
            'bank_account_number' => '1234567890',
            'bank_account_name' => 'Payment Customer Co',
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

        $this->origin = Location::firstOrFail();
        $this->destination = Location::skip(1)->first() ?? Location::firstOrFail();
        $this->mode = TransportMode::firstOrFail();
        $this->lclService = ServiceType::where('code', 'LCL')->firstOrFail();
    }

    public function test_payment_stats_count_unpaid_invoices_without_payments(): void
    {
        $this->createInvoice(now()->addDays(7)->toDateString(), 'unpaid', 500_000);
        $this->createInvoice(now()->addDays(7)->toDateString(), 'paid', 300_000);

        Sanctum::actingAs($this->admin);

        $stats = $this->getJson('/api/customer/payments/stats')->assertOk()->json('data');

        $this->assertSame(1, $stats['unpaid']);
        $this->assertSame(1, $stats['paid']);
    }

    public function test_payment_list_includes_unpaid_invoice_without_payment_record(): void
    {
        $invoice = $this->createInvoice(now()->addDays(7)->toDateString(), 'unpaid', 750_000);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/customer/payments?status=unpaid')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $row = $response->json('data.0');
        $this->assertSame($invoice->id, $row['invoice_id']);
        $this->assertSame('unpaid', $row['status']);
        $this->assertTrue($row['actions']['detail_invoice_only']);
        $this->assertNull($row['payment_id']);
    }

    public function test_payment_list_search_by_customer_name(): void
    {
        $this->createInvoice(now()->addDays(7)->toDateString(), 'unpaid', 500_000);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/customer/payments?search=Payment+Customer')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_payment_list_overdue_filter_requires_outstanding(): void
    {
        $openOverdue = $this->createInvoice(now()->subDays(3)->toDateString(), 'unpaid', 900_000);
        $paidOverdue = $this->createInvoice(now()->subDays(5)->toDateString(), 'unpaid', 800_000);
        Payment::create([
            'invoice_id' => $paidOverdue->id,
            'amount' => 800_000,
            'status' => 'success',
            'paid_at' => now()->subDay(),
        ]);
        $paidOverdue->update(['status' => 'paid']);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/customer/payments?status=overdue')->assertOk();
        $ids = collect($response->json('data'))->pluck('invoice_id')->all();

        $this->assertContains($openOverdue->id, $ids);
        $this->assertNotContains($paidOverdue->id, $ids);
    }

    public function test_payment_detail_includes_online_and_manual_sections(): void
    {
        $invoice = $this->createInvoice(now()->addDays(14)->toDateString(), 'unpaid', 1_000_000);
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 1_000_000,
            'status' => 'pending',
            'method' => 'midtrans',
            'midtrans_order_id' => 'INV-'.$invoice->id.'-TEST01',
            'midtrans_response' => [
                'token' => 'snap-token',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/test',
            ],
            'expired_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/customer/payments/{$payment->id}")->assertOk();
        $data = $response->json('data');

        $this->assertSame('unpaid', $data['status']);
        $this->assertSame('pending', $data['payment_record_status']);
        $this->assertArrayHasKey('online_payment', $data);
        $this->assertSame('active', $data['online_payment']['link_status']);
        $this->assertSame('pending', $data['online_payment']['payment_status']);
        $this->assertTrue($data['manual_payment']['enabled']);
        $this->assertNotEmpty($data['payment_history']);
        $this->assertNotEmpty($data['activity_timeline']);
        $this->assertTrue($data['actions']['can_pay_now']);
    }

    public function test_viewer_cannot_sync_or_submit_manual_payment(): void
    {
        $invoice = $this->createInvoice(now()->addDays(14)->toDateString(), 'unpaid', 500_000);
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 500_000,
            'status' => 'pending',
            'method' => 'midtrans',
            'midtrans_order_id' => 'INV-'.$invoice->id.'-VIEW01',
        ]);

        Sanctum::actingAs($this->viewer);

        $this->getJson("/api/customer/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_pay_now', false)
            ->assertJsonPath('data.actions.can_sync_midtrans', false)
            ->assertJsonPath('data.actions.can_submit_manual', false);

        $this->postJson("/api/customer/payments/{$payment->id}/sync-midtrans")
            ->assertForbidden();
    }

    public function test_cross_company_payment_returns_not_found(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Payment Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'PC02',
        ]);

        $invoice = $this->createInvoice(now()->addDays(7)->toDateString(), 'unpaid', 500_000, $otherCompany);
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 500_000,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/customer/payments/{$payment->id}")
            ->assertNotFound();
    }

    public function test_payment_detail_timeline_logs_view_activity(): void
    {
        $invoice = $this->createInvoice(now()->addDays(7)->toDateString(), 'unpaid', 500_000);
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 500_000,
            'status' => 'pending',
            'midtrans_order_id' => 'INV-'.$invoice->id.'-ACT01',
        ]);

        Sanctum::actingAs($this->admin);

        $timeline = $this->getJson("/api/customer/payments/{$payment->id}")
            ->assertOk()
            ->json('data.activity_timeline');

        $this->assertTrue(
            collect($timeline)->contains(fn ($e) => str_contains($e['activity'], 'Customer membuka Payment'))
        );
    }

    private function createInvoice(
        string $dueDate,
        string $status,
        float $total,
        ?Company $company = null,
    ): Invoice {
        $company ??= $this->company;
        $shipment = $this->createShipment($company);

        return Invoice::create([
            'company_id' => $company->id,
            'shipment_id' => $shipment->id,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total_amount' => $total,
            'issued_date' => now()->toDateString(),
            'due_date' => $dueDate,
            'status' => $status,
        ]);
    }

    private function createShipment(Company $company): Shipment
    {
        $user = $company->is($this->company)
            ? $this->admin
            : User::factory()->create([
                'company_id' => $company->id,
                'user_type' => 'customer',
                'status' => 'active',
            ]);

        $booking = Booking::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'service_type_id' => $this->lclService->id,
            'transport_mode_id' => $this->mode->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'approved',
            'booking_number' => 'BK'.random_int(1000, 9999),
            'shipper_name' => 'Shipper',
            'shipper_address' => 'Addr',
            'shipper_phone' => '08111',
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Addr2',
            'consignee_phone' => '08222',
        ]);

        return Shipment::create([
            'booking_id' => $booking->id,
            'company_id' => $company->id,
            'service_type_id' => $this->lclService->id,
            'transport_mode_id' => $this->mode->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'completed',
            'shipment_number' => 'SHP'.random_int(1000, 9999),
            'waybill_number' => 'CN'.random_int(1000, 9999),
        ]);
    }
}
