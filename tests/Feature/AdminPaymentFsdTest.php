<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceActivity;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentActivity;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Models\TransportMode;
use App\Models\User;
use App\Services\MidtransService;
use App\Services\PaymentLinkService;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPaymentFsdTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

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
            'name' => 'Admin Payment Customer',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'AP01',
            'payment_term' => 'NET30',
        ]);

        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'user_type' => 'internal',
            'status' => 'active',
        ]);
        $this->admin->syncRoles(['super_admin']);

        $this->origin = Location::firstOrFail();
        $this->destination = Location::skip(1)->first() ?? Location::firstOrFail();
        $this->mode = TransportMode::firstOrFail();
        $this->lclService = ServiceType::where('code', 'LCL')->firstOrFail();
    }

    public function test_admin_payment_stats_match_invoice_statuses(): void
    {
        $this->createInvoice('unpaid', 500_000);
        $this->createInvoice('paid', 300_000);

        Sanctum::actingAs($this->admin);

        $stats = $this->getJson('/api/admin/payments/stats')->assertOk()->json('data');

        $this->assertSame(1, $stats['unpaid']);
        $this->assertSame(1, $stats['paid']);
    }

    public function test_admin_ar_list_includes_unpaid_invoice_without_payment(): void
    {
        $invoice = $this->createInvoice('unpaid', 750_000);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/admin/payments?view=ar&invoice_status=unpaid')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $row = $response->json('data.0');
        $this->assertSame($invoice->id, $row['invoice_id']);
        $this->assertTrue($row['is_ar_only']);
    }

    public function test_admin_record_payment_updates_invoice_status(): void
    {
        $invoice = $this->createInvoice('unpaid', 1_000_000);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/invoices/{$invoice->id}/record-payment", [
            'payment_method' => 'transfer',
            'company_bank' => 'BCA - PT SOL Logistics',
            'account' => '1234567890',
            'payment_date' => now()->toDateString(),
            'payment_amount' => 1_000_000,
            'payment_reference_no' => 'TRF-001',
        ])->assertCreated();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'method' => 'transfer',
            'status' => 'success',
        ]);
    }

    public function test_admin_record_payment_requires_account_for_manual_method(): void
    {
        $invoice = $this->createInvoice('unpaid', 1_000_000);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/invoices/{$invoice->id}/record-payment", [
            'payment_method' => 'cash',
            'payment_date' => now()->toDateString(),
            'payment_amount' => 1_000_000,
            'payment_reference_no' => 'CASH-001',
        ])->assertStatus(422)->assertJsonValidationErrors('account');
    }

    public function test_print_receipt_is_only_available_once_invoice_is_paid(): void
    {
        $invoice = $this->createInvoice('unpaid', 1_000_000);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/invoices/{$invoice->id}/record-payment", [
            'payment_method' => 'transfer',
            'company_bank' => 'BCA - PT SOL Logistics',
            'account' => '1234567890',
            'payment_date' => now()->toDateString(),
            'payment_amount' => 400_000,
            'payment_reference_no' => 'TRF-PARTIAL',
        ])->assertCreated();

        $invoice->refresh();
        $this->assertSame('partially_paid', $invoice->status);

        $partial = $invoice->payments()->latest('id')->firstOrFail();
        $this->assertFalse(
            $this->getJson("/api/admin/payments/{$partial->id}")->assertOk()->json('data.actions.can_print_receipt')
        );

        $this->postJson("/api/admin/invoices/{$invoice->id}/record-payment", [
            'payment_method' => 'transfer',
            'company_bank' => 'BCA - PT SOL Logistics',
            'account' => '1234567890',
            'payment_date' => now()->toDateString(),
            'payment_amount' => 600_000,
            'payment_reference_no' => 'TRF-FINAL',
        ])->assertCreated();

        $final = $invoice->payments()->latest('id')->firstOrFail();
        $this->assertTrue(
            $this->getJson("/api/admin/payments/{$final->id}")->assertOk()->json('data.actions.can_print_receipt')
        );
    }

    public function test_admin_payment_detail_includes_online_and_supporting_sections(): void
    {
        $invoice = $this->createInvoice('unpaid', 900_000);
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'payment_number' => 42,
            'amount' => 900_000,
            'status' => 'pending',
            'method' => 'midtrans',
            'midtrans_order_id' => 'INV-'.$invoice->id.'-ADMIN1',
            'midtrans_response' => [
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/admin-test',
            ],
            'expired_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($this->admin);

        $data = $this->getJson("/api/admin/payments/{$payment->id}")
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('online_payment', $data);
        $this->assertSame('active', $data['online_payment']['link_status']);
        $this->assertTrue($data['actions']['can_copy_link']);
        $this->assertTrue($data['actions']['can_regenerate_link']);
        $this->assertNotEmpty($data['supporting_documents']);
        $this->assertNotEmpty($data['payment_history']);
    }

    public function test_admin_generate_payment_link_creates_midtrans_payment(): void
    {
        config([
            'midtrans.server_key' => 'SB-Mid-server-test',
            'midtrans.snap_url' => 'https://app.sandbox.midtrans.com/snap/v1/transactions',
        ]);

        Http::fake([
            'app.sandbox.midtrans.com/*' => Http::response([
                'token' => 'snap-token-admin',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/generated',
            ], 200),
        ]);

        $invoice = $this->createInvoice('unpaid', 600_000);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/admin/invoices/{$invoice->id}/generate-payment-link")
            ->assertCreated();

        // The shared link is our own tracked redirect so the customer opening it is logged.
        $paymentUrl = (string) $response->json('data.payment_url');
        $this->assertStringContainsString('/api/payment-links/', $paymentUrl);
        $this->get($paymentUrl)->assertRedirect('https://app.sandbox.midtrans.com/snap/v4/redirection/generated');

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'method' => 'midtrans',
            'status' => 'pending',
        ]);

        $paymentId = Payment::where('invoice_id', $invoice->id)->value('id');
        $this->assertNotNull(PaymentActivity::where('payment_id', $paymentId)->where('event_key', 'payment_link_generated')->first());
    }

    public function test_opening_payment_link_logs_activity_and_redirects_to_midtrans(): void
    {
        $invoice = $this->createInvoice('unpaid', 400_000);
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 400_000,
            'status' => 'pending',
            'method' => 'midtrans',
            'midtrans_order_id' => 'INV-'.$invoice->id.'-OPEN01',
            'expired_at' => now()->addDay(),
            'midtrans_response' => [
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/open-me',
            ],
        ]);

        $trackedUrl = app(PaymentLinkService::class)->trackedUrl($payment);
        $this->assertNotNull($trackedUrl);

        $this->get($trackedUrl)
            ->assertRedirect('https://app.sandbox.midtrans.com/snap/v4/redirection/open-me');

        $activity = PaymentActivity::where('payment_id', $payment->id)
            ->where('event_key', 'payment_link_opened')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('Customer membuka Payment Link.', $activity->description);
    }

    public function test_payment_link_cannot_be_opened_without_valid_signature(): void
    {
        $invoice = $this->createInvoice('unpaid', 400_000);
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 400_000,
            'status' => 'pending',
            'method' => 'midtrans',
            'midtrans_order_id' => 'INV-'.$invoice->id.'-OPEN02',
            'expired_at' => now()->addDay(),
            'midtrans_response' => ['redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/nope'],
        ]);

        $this->get("/api/payment-links/{$payment->id}")->assertForbidden();

        $this->assertSame(
            0,
            PaymentActivity::where('payment_id', $payment->id)->where('event_key', 'payment_link_opened')->count()
        );
    }

    public function test_payment_link_is_unusable_once_payment_succeeded(): void
    {
        $invoice = $this->createInvoice('unpaid', 400_000);
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 400_000,
            'status' => 'success',
            'method' => 'midtrans',
            'midtrans_order_id' => 'INV-'.$invoice->id.'-OPEN03',
            'expired_at' => now()->addDay(),
            'midtrans_response' => ['redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/done'],
        ]);

        $trackedUrl = app(PaymentLinkService::class)->trackedUrl($payment);

        $this->get($trackedUrl)->assertStatus(410);
    }

    public function test_midtrans_settlement_logs_payment_received_on_invoice_activity(): void
    {
        $invoice = $this->createInvoice('unpaid', 750_000);
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 750_000,
            'status' => 'pending',
            'method' => 'midtrans',
            'midtrans_order_id' => 'INV-'.$invoice->id.'-SETTLE',
        ]);

        app(MidtransService::class)->handleNotification([
            'order_id' => $payment->midtrans_order_id,
            'transaction_status' => 'settlement',
            'transaction_id' => 'trx-settle-001',
            'gross_amount' => '750000.00',
            'status_code' => '200',
        ]);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);

        $activity = InvoiceActivity::where('invoice_id', $invoice->id)
            ->where('event_key', 'payment_received')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('midtrans', $activity->meta['channel'] ?? null);
        $this->assertSame('trx-settle-001', $activity->meta['reference_number'] ?? null);
    }

    public function test_admin_regenerate_payment_link_requires_pending_or_expired_status(): void
    {
        config([
            'midtrans.server_key' => 'SB-Mid-server-test',
            'midtrans.snap_url' => 'https://app.sandbox.midtrans.com/snap/v1/transactions',
        ]);

        Http::fake([
            'app.sandbox.midtrans.com/*' => Http::response([
                'token' => 'snap-token-regen',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/regenerated',
            ], 200),
        ]);

        $invoice = $this->createInvoice('unpaid', 500_000);
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 500_000,
            'status' => 'expired',
            'method' => 'midtrans',
            'midtrans_order_id' => 'INV-'.$invoice->id.'-OLD01',
        ]);

        Sanctum::actingAs($this->admin);

        $paymentUrl = (string) $this->postJson("/api/admin/payments/{$payment->id}/regenerate-payment-link")
            ->assertCreated()
            ->json('data.payment_url');

        $this->get($paymentUrl)
            ->assertRedirect('https://app.sandbox.midtrans.com/snap/v4/redirection/regenerated');

        $this->assertSame(2, Payment::where('invoice_id', $invoice->id)->count());
    }

    private function createInvoice(string $status, float $total): Invoice
    {
        $booking = Booking::create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
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

        $shipment = Shipment::create([
            'booking_id' => $booking->id,
            'company_id' => $this->company->id,
            'service_type_id' => $this->lclService->id,
            'transport_mode_id' => $this->mode->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'completed',
            'shipment_number' => 'SHP'.random_int(1000, 9999),
            'waybill_number' => 'CN'.random_int(1000, 9999),
        ]);

        return Invoice::create([
            'company_id' => $this->company->id,
            'shipment_id' => $shipment->id,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total_amount' => $total,
            'issued_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => $status,
        ]);
    }
}
