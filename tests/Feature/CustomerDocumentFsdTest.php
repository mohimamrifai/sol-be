<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAttachment;
use App\Models\BookingPackage;
use App\Models\CargoCategory;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\ServiceType;
use App\Models\Shipment;
use App\Models\ShipmentTracking;
use App\Models\ShipmentTrackingPhoto;
use App\Models\TransportMode;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerDocumentFsdTest extends TestCase
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
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->company = Company::create([
            'name' => 'Document Customer Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'DC01',
        ]);

        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'user_type' => 'customer',
            'status' => 'active',
        ]);
        $this->admin->syncRoles(['company_admin']);

        $this->origin = Location::firstOrFail();
        $this->destination = Location::skip(1)->first() ?? Location::firstOrFail();
        $this->mode = TransportMode::firstOrFail();
        $this->lclService = ServiceType::where('code', 'LCL')->firstOrFail();
    }

    public function test_document_stats_align_with_list_totals(): void
    {
        $this->seedSampleDocuments();

        Sanctum::actingAs($this->admin);

        $stats = $this->getJson('/api/customer/documents/stats')->assertOk()->json('data');
        $list = $this->getJson('/api/customer/documents?per_page=100')->assertOk();

        $this->assertSame($list->json('total'), $stats['total']);
        $this->assertGreaterThan(0, $stats['booking']);
        $this->assertGreaterThan(0, $stats['shipment']);
        $this->assertGreaterThan(0, $stats['billing']);
    }

    public function test_document_filter_by_pod_type(): void
    {
        $this->seedSampleDocuments();

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/customer/documents?type=pod&per_page=100')->assertOk();

        $this->assertGreaterThan(0, count($response->json('data')));
        foreach ($response->json('data') as $row) {
            $this->assertSame('proof_of_delivery', $row['document_type']);
        }
    }

    public function test_msds_file_appears_in_booking_documents(): void
    {
        $booking = $this->createBooking();
        $msdsPath = 'msds_files/test-msds.pdf';
        Storage::disk('public')->put($msdsPath, '%PDF-1.4 test');

        BookingPackage::create([
            'booking_id' => $booking->id,
            'sequence' => 1,
            'description' => 'DG Carton',
            'package_type' => 'Carton',
            'piece_count' => 1,
            'weight_kg' => 50,
            'volume_cbm' => 0.5,
            'cargo_category_id' => CargoCategory::where('code', 'GEN')->firstOrFail()->id,
            'msds_file_path' => $msdsPath,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/customer/documents?type=booking&per_page=100')->assertOk();

        $msds = collect($response->json('data'))->firstWhere('document_type', 'msds_file');
        $this->assertNotNull($msds);

        $detail = $this->getJson('/api/customer/documents/'.$msds['id'])->assertOk();
        $this->assertSame('Document Customer Co', $detail->json('data.info.customer'));

        $this->getJson('/api/customer/documents/'.$msds['id'].'/download')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_other_supporting_attachment_uses_other_type(): void
    {
        $booking = $this->createBooking();
        $path = 'booking_attachments/supporting.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4 test');

        BookingAttachment::create([
            'booking_id' => $booking->id,
            'uploaded_by' => $this->admin->id,
            'file_path' => $path,
            'original_name' => 'Internal Memo.pdf',
            'mime_type' => 'application/pdf',
            'category' => 'others',
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/customer/documents?type=other&per_page=100')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('other_supporting', $response->json('data.0.document_type'));
        $this->assertStringStartsWith('oth-', $response->json('data.0.id'));
    }

    public function test_document_detail_includes_related_shipment_context(): void
    {
        $docs = $this->seedSampleDocuments();
        $cnId = $docs['cn_id'];

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/customer/documents/{$cnId}")->assertOk();

        $this->assertNotNull($response->json('data.related_shipment.id'));
        $this->assertNotNull($response->json('data.info.shipment_no'));
        $this->assertNotNull($response->json('data.info.booking_no'));
    }

    public function test_cross_company_document_access_denied(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Document Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'DC02',
        ]);

        $booking = Booking::create([
            'company_id' => $otherCompany->id,
            'user_id' => User::factory()->create(['company_id' => $otherCompany->id, 'user_type' => 'customer'])->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'service_type_id' => $this->lclService->id,
            'transport_mode_id' => $this->mode->id,
            'shipment_coverage' => 'port_to_port',
            'status' => 'approved',
            'shipper_name' => 'S',
            'shipper_address' => 'A',
            'shipper_phone' => '081',
            'consignee_name' => 'C',
            'consignee_address' => 'B',
            'consignee_phone' => '082',
        ]);

        $path = 'booking_attachments/other.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4');

        $attachment = BookingAttachment::create([
            'booking_id' => $booking->id,
            'uploaded_by' => $this->admin->id,
            'file_path' => $path,
            'original_name' => 'secret.pdf',
            'mime_type' => 'application/pdf',
            'category' => 'general',
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/customer/documents/ba-'.$attachment->id)
            ->assertNotFound();
    }

    /** @return array{cn_id: string} */
    private function seedSampleDocuments(): array
    {
        $booking = $this->createBooking();
        $path = 'booking_attachments/sample.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4 sample');

        BookingAttachment::create([
            'booking_id' => $booking->id,
            'uploaded_by' => $this->admin->id,
            'file_path' => $path,
            'original_name' => 'Booking Doc.pdf',
            'mime_type' => 'application/pdf',
            'category' => 'general',
        ]);

        $shipment = Shipment::create([
            'booking_id' => $booking->id,
            'company_id' => $this->company->id,
            'service_type_id' => $this->lclService->id,
            'transport_mode_id' => $this->mode->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'shipment_coverage' => 'port_to_port',
            'status' => 'completed',
            'waybill_number' => 'CN-'.uniqid(),
        ]);

        $tracking = ShipmentTracking::create([
            'shipment_id' => $shipment->id,
            'status' => 'completed',
            'tracked_at' => now(),
        ]);

        $photoPath = 'tracking/pod.jpg';
        Storage::disk('public')->put($photoPath, 'jpeg-content');

        ShipmentTrackingPhoto::create([
            'shipment_tracking_id' => $tracking->id,
            'path' => $photoPath,
            'caption' => 'POD Photo',
        ]);

        Invoice::create([
            'company_id' => $this->company->id,
            'shipment_id' => $shipment->id,
            'subtotal' => 500000,
            'tax_amount' => 0,
            'total_amount' => 500000,
            'issued_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => 'unpaid',
        ]);

        return ['cn_id' => 'cn-'.$shipment->id];
    }

    private function createBooking(): Booking
    {
        return Booking::create([
            'company_id' => $this->company->id,
            'user_id' => $this->admin->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'service_type_id' => $this->lclService->id,
            'transport_mode_id' => $this->mode->id,
            'shipment_coverage' => 'port_to_port',
            'status' => 'approved',
            'booking_number' => 'BK'.random_int(1000, 9999),
            'shipper_name' => 'Shipper',
            'shipper_address' => 'Addr',
            'shipper_phone' => '08111',
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Addr2',
            'consignee_phone' => '08222',
        ]);
    }
}
