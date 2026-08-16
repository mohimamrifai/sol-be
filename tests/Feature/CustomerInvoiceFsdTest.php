<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
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

class CustomerInvoiceFsdTest extends TestCase
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
            'name' => 'Invoice Customer Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'IV01',
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

    public function test_invoice_stats_returns_five_buckets(): void
    {
        $shipment = $this->createShipment();
        $this->createInvoice($shipment, 'unpaid', now()->addDays(7)->toDateString(), 600_000);
        $this->createInvoice($shipment, 'paid', now()->addDays(7)->toDateString(), 800_000);
        $this->createInvoice($shipment, 'unpaid', now()->subDays(3)->toDateString(), 900_000);

        Sanctum::actingAs($this->admin);

        $stats = $this->getJson('/api/customer/invoices/stats')->assertOk()->json('data');

        $this->assertSame(1, $stats['issued']);
        $this->assertSame(1, $stats['paid']);
        $this->assertSame(1, $stats['overdue']);
        $this->assertArrayHasKey('draft', $stats);
        $this->assertArrayHasKey('partially_paid', $stats);
    }

    public function test_invoice_list_is_scoped_to_company(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Invoice Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'IV02',
        ]);

        $this->createInvoice($this->createShipment(), 'unpaid', now()->addDays(7)->toDateString(), 500_000);
        $this->createInvoice($this->createShipment($otherCompany), 'unpaid', now()->addDays(7)->toDateString(), 600_000);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/customer/invoices')->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_overdue_filter_requires_outstanding_balance(): void
    {
        $shipment = $this->createShipment();
        $fullyPaidOverdue = $this->createInvoice($shipment, 'unpaid', now()->subDays(5)->toDateString(), 1_000_000);
        Payment::create([
            'invoice_id' => $fullyPaidOverdue->id,
            'amount' => 1_000_000,
            'status' => 'success',
            'paid_at' => now()->subDay(),
        ]);

        $openOverdue = $this->createInvoice($shipment, 'unpaid', now()->subDays(2)->toDateString(), 750_000);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/customer/invoices?status=overdue')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($openOverdue->id, $ids);
        $this->assertNotContains($fullyPaidOverdue->id, $ids);
    }

    public function test_issued_filter_excludes_past_due_invoices(): void
    {
        $shipment = $this->createShipment();
        $current = $this->createInvoice($shipment, 'unpaid', now()->addDays(10)->toDateString(), 500_000);
        $pastDue = $this->createInvoice($shipment, 'unpaid', now()->subDays(2)->toDateString(), 600_000);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/customer/invoices?status=issued')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($current->id, $ids);
        $this->assertNotContains($pastDue->id, $ids);
    }

    public function test_invoice_detail_includes_all_sections(): void
    {
        $shipment = $this->createShipment();
        $invoice = $this->createInvoice($shipment, 'unpaid', now()->addDays(14)->toDateString(), 1_000_000);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Rail Freight',
            'quantity' => 1,
            'unit_price' => 1_000_000,
            'total_price' => 1_000_000,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/customer/invoices/{$invoice->id}")->assertOk();
        $data = $response->json('data');

        $this->assertSame($invoice->invoice_number, $data['invoice_number']);
        $this->assertArrayHasKey('shipment', $data);
        $this->assertSame($shipment->id, $data['shipment']['id']);
        $this->assertSame($shipment->booking_id, $data['shipment']['booking_id']);
        $this->assertNotEmpty($data['items']);
        $this->assertArrayHasKey('summary', $data);
        $this->assertNotEmpty($data['supporting_documents']);
        $this->assertArrayHasKey('payment_summary', $data);
        $this->assertArrayHasKey('payment_history', $data);
        $this->assertArrayHasKey('activity_timeline', $data);
        $this->assertTrue($data['actions']['can_pay_now']);
    }

    public function test_invoice_detail_summary_derives_discount_from_items(): void
    {
        $shipment = $this->createShipment();
        $invoice = $this->createInvoice($shipment, 'unpaid', now()->addDays(14)->toDateString(), 950_000);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Rail Freight',
            'quantity' => 1,
            'unit_price' => 1_000_000,
            'total_price' => 1_000_000,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Discount',
            'quantity' => 1,
            'unit_price' => -50_000,
            'total_price' => -50_000,
        ]);

        Sanctum::actingAs($this->admin);

        $summary = $this->getJson("/api/customer/invoices/{$invoice->id}")
            ->assertOk()
            ->json('data.summary');

        $this->assertEquals(1_000_000, $summary['subtotal']);
        $this->assertEquals(50_000, $summary['discount']);
    }

    public function test_paid_timeline_uses_last_payment_date(): void
    {
        Carbon::setTestNow('2026-08-16 12:00:00');

        $shipment = $this->createShipment();
        $invoice = $this->createInvoice($shipment, 'paid', now()->subDays(10)->toDateString(), 500_000);

        $paidAt = Carbon::parse('2026-08-14 13:20:00');
        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 500_000,
            'status' => 'success',
            'paid_at' => $paidAt,
        ]);

        Sanctum::actingAs($this->admin);

        $timeline = $this->getJson("/api/customer/invoices/{$invoice->id}")
            ->assertOk()
            ->json('data.activity_timeline');

        $paidEvent = collect($timeline)->firstWhere('activity', 'Status menjadi Paid');
        $this->assertNotNull($paidEvent);
        $this->assertStringContainsString('2026-08-14', $paidEvent['occurred_at']);

        Carbon::setTestNow();
    }

    public function test_viewer_cannot_pay_invoice(): void
    {
        $invoice = $this->createInvoice(
            $this->createShipment(),
            'unpaid',
            now()->addDays(14)->toDateString(),
            500_000,
        );

        Sanctum::actingAs($this->viewer);

        $this->postJson("/api/customer/invoices/{$invoice->id}/pay")
            ->assertForbidden();
    }

    public function test_viewer_detail_has_can_pay_now_false(): void
    {
        $invoice = $this->createInvoice(
            $this->createShipment(),
            'unpaid',
            now()->addDays(14)->toDateString(),
            500_000,
        );

        Sanctum::actingAs($this->viewer);

        $this->getJson("/api/customer/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_pay_now', false);
    }

    public function test_cross_company_invoice_access_denied(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Invoice Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'IV03',
        ]);

        $invoice = $this->createInvoice(
            $this->createShipment($otherCompany),
            'unpaid',
            now()->addDays(14)->toDateString(),
            500_000,
        );

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/customer/invoices/{$invoice->id}")
            ->assertForbidden();
    }

    private function createShipment(?Company $company = null): Shipment
    {
        $company ??= $this->company;

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

    private function createInvoice(
        Shipment $shipment,
        string $status,
        string $dueDate,
        float $total,
    ): Invoice {
        return Invoice::create([
            'company_id' => $shipment->company_id,
            'shipment_id' => $shipment->id,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total_amount' => $total,
            'issued_date' => now()->toDateString(),
            'due_date' => $dueDate,
            'status' => $status,
        ]);
    }
}
