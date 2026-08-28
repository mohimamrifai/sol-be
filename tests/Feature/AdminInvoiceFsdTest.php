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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminInvoiceFsdTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Location $origin;

    private Location $destination;

    private ServiceType $service;

    private TransportMode $mode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->admin = User::factory()->create(['user_type' => 'internal', 'status' => 'active']);
        $this->admin->syncRoles(['super_admin']);
        $this->company = Company::create([
            'name' => 'Invoice FSD Customer',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'IFC',
            'npwp' => '01.234.567.8-999.000',
            'address' => 'Snapshot Street',
            'payment_term' => 'net_14',
            'postpaid_term_days' => 60,
        ]);
        $this->origin = Location::firstOrFail();
        $this->destination = Location::skip(1)->first() ?? $this->origin;
        $this->service = ServiceType::where('code', 'LCL')->firstOrFail();
        $this->mode = TransportMode::firstOrFail();
        Sanctum::actingAs($this->admin);
    }

    public function test_eligible_shipments_are_completed_without_an_invoice(): void
    {
        $eligible = $this->createShipment('completed');
        $eligible->update(['completion_verified_at' => '2026-08-20 14:30:00']);
        $cancelled = $this->createShipment('cancelled');
        $alreadyInvoiced = $this->createShipment('completed');
        $this->createInvoice($alreadyInvoiced, 'draft');

        $rows = collect($this->getJson('/api/admin/invoices/eligible-shipments')
            ->assertOk()
            ->json('data'));
        $ids = $rows->pluck('id');

        $this->assertTrue($ids->contains($eligible->id));
        $this->assertFalse($ids->contains($cancelled->id));
        $this->assertFalse($ids->contains($alreadyInvoiced->id));
        $this->assertSame(
            $eligible->fresh()->completion_verified_at->toIso8601String(),
            $rows->firstWhere('id', $eligible->id)['completion_date'],
        );
    }

    public function test_dashboard_search_includes_customer_name(): void
    {
        $invoice = $this->createInvoice($this->createShipment('completed'), 'issued');

        $response = $this->getJson('/api/admin/invoices?search=FSD%20Customer')->assertOk();

        $this->assertSame([$invoice->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_generation_snapshots_data_numbers_invoice_and_uses_current_payment_term(): void
    {
        $shipment = $this->createShipment('completed');

        $response = $this->postJson("/api/admin/shipments/{$shipment->id}/generate-invoice", [
            'invoice_date' => '2026-08-01',
            'remark' => 'Optional remark',
        ])->assertCreated();

        $invoice = Invoice::findOrFail($response->json('data.id'));
        $this->assertNotEmpty($invoice->invoice_number);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('2026-08-15', $invoice->due_date->toDateString());
        $this->assertSame('Invoice FSD Customer', $invoice->company_snapshot['name']);
        $this->assertSame('net_14', $invoice->company_snapshot['payment_term']);
        $this->assertSame($shipment->shipment_number, $invoice->shipment_snapshot['shipment_no']);
        $this->assertSame('Optional remark', $invoice->notes);
        $this->assertDatabaseHas('invoice_activities', [
            'invoice_id' => $invoice->id,
            'event_key' => 'invoice_created',
        ]);
    }

    public function test_legacy_payment_term_fallback_is_supported(): void
    {
        $this->company->update(['payment_term' => null, 'postpaid_term_days' => 45]);
        $shipment = $this->createShipment('completed');

        $invoiceId = $this->postJson("/api/admin/shipments/{$shipment->id}/generate-invoice", [
            'invoice_date' => '2026-08-01',
        ])->assertCreated()->json('data.id');

        $invoice = Invoice::findOrFail($invoiceId);
        $this->assertSame('2026-09-15', $invoice->due_date->toDateString());
        $this->assertSame('net_45', $invoice->company_snapshot['payment_term']);
    }

    public function test_draft_edit_recalculates_totals_and_issued_business_data_is_readonly(): void
    {
        $shipment = $this->createShipment('completed');
        $invoiceId = $this->postJson('/api/admin/invoices', [
            'shipment_id' => $shipment->id,
            'invoice_date' => '2026-08-01',
            'items' => [
                ['description' => 'Rail Freight', 'quantity' => 2, 'unit_price' => 500_000],
            ],
        ])->assertCreated()->json('data.id');

        $updated = $this->putJson("/api/admin/invoices/{$invoiceId}", [
            'invoice_date' => '2026-08-03',
            'remark' => 'Edited',
            'items' => [
                ['description' => 'Rail Freight', 'quantity' => 2, 'unit_price' => 500_000],
                ['description' => 'Discount', 'quantity' => 1, 'unit_price' => -100_000],
            ],
        ])->assertOk();

        $updated->assertJsonPath('data.notes', 'Edited');
        $invoice = Invoice::findOrFail($invoiceId);
        $this->assertSame('2026-08-17', $invoice->due_date->toDateString());
        $this->assertEquals(1_000_000, $invoice->subtotal);
        $this->assertEquals(999_000, $invoice->total_amount);
        $this->getJson("/api/admin/invoices/{$invoiceId}")
            ->assertOk()
            ->assertJsonPath('data.summary.subtotal', 1_000_000)
            ->assertJsonPath('data.summary.discount', 100_000)
            ->assertJsonPath('data.summary.ppn', 99_000)
            ->assertJsonPath('data.summary.grand_total', 999_000);

        $this->postJson("/api/admin/invoices/{$invoiceId}/issue")->assertOk();
        $this->putJson("/api/admin/invoices/{$invoiceId}", [
            'remark' => 'Must fail',
        ])->assertUnprocessable();
        $this->postJson("/api/admin/invoices/{$invoiceId}/issue")->assertUnprocessable();
    }

    public function test_cancel_transitions_draft_without_deleting_it(): void
    {
        $invoice = $this->createInvoice($this->createShipment('completed'), 'draft');

        $this->postJson("/api/admin/invoices/{$invoice->id}/cancel")->assertOk();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'cancelled']);
        $this->assertNotSoftDeleted($invoice);
        $this->assertDatabaseHas('invoice_activities', [
            'invoice_id' => $invoice->id,
            'event_key' => 'invoice_cancelled',
        ]);
    }

    public function test_documents_and_detail_contract_are_exposed(): void
    {
        Storage::fake('public');
        $invoice = $this->createInvoice($this->createShipment('completed'), 'issued');

        $document = $this->postJson("/api/admin/invoices/{$invoice->id}/documents", [
            'kind' => 'tax_invoice',
            'file' => UploadedFile::fake()->create('tax-invoice.pdf', 20, 'application/pdf'),
        ])->assertCreated();

        $documentId = $document->json('data.id');
        $detail = $this->getJson("/api/admin/invoices/{$invoice->id}")->assertOk();
        $detail->assertJsonStructure(['data' => [
            'header',
            'invoice_info' => ['currency', 'payment_term', 'remark'],
            'shipment' => ['shipment_no', 'cn_no', 'route', 'service', 'shipment_coverage'],
            'items',
            'summary' => ['subtotal', 'discount', 'ppn', 'grand_total'],
            'documents' => ['invoice_pdf', 'tax_invoices', 'supporting_documents'],
            'payment_summary',
            'payment_history',
            'activity_log',
            'actions',
        ]]);
        $this->assertSame($documentId, $detail->json('data.documents.tax_invoices.0.id'));
        $this->get("/api/admin/invoices/{$invoice->id}/documents/{$documentId}")
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="tax-invoice.pdf"');
    }

    public function test_invoice_pdf_uses_snapshot_sections_and_blocks_draft(): void
    {
        $invoice = $this->createInvoice($this->createShipment('completed'), 'draft');
        $invoice->update([
            'company_snapshot' => [
                'name' => 'Snapshot Customer',
                'company_code' => 'SNP',
                'currency' => 'IDR',
                'payment_terms' => 'Net 14',
                'address' => 'Jl. Snapshot 1',
                'city' => 'Jakarta',
                'province' => 'DKI Jakarta',
                'postal_code' => '10110',
                'npwp' => '01.234.567.8-999.000',
            ],
            'shipment_snapshot' => [
                'shipment_no' => 'SHP-SNAPSHOT',
                'cn_no' => 'CN-SNAPSHOT',
                'origin' => 'Jakarta',
                'destination' => 'Surabaya',
                'service_type' => 'LCL',
                'shipment_coverage' => 'door_to_door',
            ],
            'subtotal' => 1_000_000,
            'tax_amount' => 99_000,
            'total_amount' => 999_000,
            'notes' => 'Remark snapshot',
        ]);
        $invoice->items()->create([
            'description' => 'Rail Freight',
            'quantity' => 1,
            'unit_price' => 1_000_000,
            'total_price' => 1_000_000,
        ]);
        $invoice->items()->create([
            'description' => 'Discount',
            'quantity' => 1,
            'unit_price' => -100_000,
            'total_price' => -100_000,
        ]);

        $this->get("/api/admin/invoices/{$invoice->id}/pdf?view=1")->assertUnprocessable();

        $invoice->update(['status' => 'paid']);
        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 999_000,
            'status' => 'success',
            'method' => 'transfer',
            'manual_reference_number' => 'TRF-PRINT-001',
            'paid_at' => now(),
        ]);

        $response = $this->get("/api/admin/invoices/{$invoice->id}/pdf?view=1")->assertOk();
        $content = $response->getContent() ?? '';

        $this->assertStringContainsString('%PDF', $content);
        $this->assertGreaterThan(1000, strlen($content));
        $this->assertDatabaseHas('invoice_activities', [
            'invoice_id' => $invoice->id,
            'description' => 'Invoice PDF dilihat.',
        ]);
    }

    public function test_payment_history_only_contains_successful_payments(): void
    {
        $invoice = $this->createInvoice($this->createShipment('completed'), 'partially_paid');
        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 250_000,
            'status' => 'success',
            'method' => 'transfer',
            'manual_reference_number' => 'SUCCESS-001',
            'paid_at' => now(),
        ]);
        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 500_000,
            'status' => 'pending',
            'method' => 'midtrans',
            'midtrans_order_id' => 'PENDING-001',
        ]);
        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 300_000,
            'status' => 'failed',
            'method' => 'midtrans',
            'midtrans_order_id' => 'FAILED-001',
        ]);

        $detail = $this->getJson("/api/admin/invoices/{$invoice->id}")->assertOk();

        $this->assertSame(250_000, $detail->json('data.payment_summary.paid_amount'));
        $this->assertSame(1, count($detail->json('data.payment_history')));
        $this->assertSame('SUCCESS-001', $detail->json('data.payment_history.0.reference_number'));
        $this->assertSame('success', $detail->json('data.payment_history.0.status'));
    }

    private function createShipment(string $status): Shipment
    {
        $booking = Booking::create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'service_type_id' => $this->service->id,
            'transport_mode_id' => $this->mode->id,
            'shipment_coverage' => 'door_to_door',
            'status' => 'approved',
            'booking_number' => 'BK'.fake()->unique()->numerify('######'),
            'shipper_name' => 'Shipper',
            'shipper_address' => 'Origin',
            'shipper_phone' => '08111',
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Destination',
            'consignee_phone' => '08222',
        ]);

        return Shipment::create([
            'booking_id' => $booking->id,
            'company_id' => $this->company->id,
            'service_type_id' => $this->service->id,
            'transport_mode_id' => $this->mode->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'shipment_coverage' => 'door_to_door',
            'status' => $status,
            'shipment_number' => 'SHP'.fake()->unique()->numerify('######'),
            'waybill_number' => 'CN'.fake()->unique()->numerify('######'),
        ]);
    }

    private function createInvoice(Shipment $shipment, string $status): Invoice
    {
        return Invoice::create([
            'shipment_id' => $shipment->id,
            'company_id' => $shipment->company_id,
            'issued_date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'subtotal' => 1_000_000,
            'tax_amount' => 110_000,
            'total_amount' => 1_110_000,
            'status' => $status,
            'company_snapshot' => [
                'name' => $this->company->name,
                'currency' => 'IDR',
                'payment_term' => 'net_14',
                'payment_terms' => 'Net 14',
                'payment_term_days' => 14,
            ],
            'shipment_snapshot' => [
                'shipment_no' => $shipment->shipment_number,
                'cn_no' => $shipment->waybill_number,
                'origin' => $this->origin->name,
                'destination' => $this->destination->name,
                'service_type' => $this->service->name,
                'shipment_coverage' => 'door_to_door',
            ],
        ]);
    }
}
