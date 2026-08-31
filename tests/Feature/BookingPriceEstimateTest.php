<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdditionalCharge;
use App\Models\Booking;
use App\Models\CargoCategory;
use App\Models\Company;
use App\Models\CustomerPricing;
use App\Models\CustomerPricingCharge;
use App\Models\Location;
use App\Models\ServiceType;
use App\Models\TransportMode;
use App\Models\User;
use App\Services\BookingPriceEstimateService;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\VendorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingPriceEstimateTest extends TestCase
{
    use RefreshDatabase;

    private BookingPriceEstimateService $service;

    private Company $company;

    private Location $origin;

    private Location $destination;

    private TransportMode $mode;

    private ServiceType $lclService;

    private CargoCategory $generalCargo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->service = app(BookingPriceEstimateService::class);

        $this->company = Company::create([
            'name' => 'Pricing Test Co',
            'type' => 'customer',
            'status' => 'active',
            'company_code' => 'PT01',
        ]);

        $this->origin = Location::where('code', 'JKT')->firstOrFail();
        $this->destination = Location::where('code', 'SUB')->firstOrFail();
        $this->mode = TransportMode::where('code', 'RAIL')->firstOrFail();
        $this->lclService = ServiceType::where('code', 'LCL')->firstOrFail();
        $this->generalCargo = CargoCategory::where('code', 'GEN')->firstOrFail();
    }

    public function test_estimate_uses_customer_pricing_for_freight(): void
    {
        CustomerPricing::create([
            'company_id' => $this->company->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'cargo_category_id' => $this->generalCargo->id,
            'service_type' => 'lcl',
            'shipment_coverage' => 'port_to_port',
            'pricing_basis' => 'per_kg',
            'rate' => 4000,
            'minimum_charge' => 600000,
            'status' => 'active',
        ]);

        $result = $this->service->estimate($this->baseParams([
            'estimated_weight' => 100,
            'shipment_coverage' => 'port_to_port',
        ]));

        $this->assertSame(600000.0, $result['breakdown']['freight']);
        $this->assertSame(600000.0, $result['estimated_price']);
    }

    public function test_estimate_applies_pickup_and_delivery_from_customer_pricing_charges(): void
    {
        $pricing = CustomerPricing::create([
            'company_id' => $this->company->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'cargo_category_id' => $this->generalCargo->id,
            'service_type' => 'lcl',
            'shipment_coverage' => 'door_to_door',
            'pricing_basis' => 'per_kg',
            'rate' => 3000,
            'minimum_charge' => 500000,
            'status' => 'active',
        ]);

        $pickup = AdditionalCharge::where('code', 'PICKUP')->firstOrFail();
        $delivery = AdditionalCharge::where('code', 'DELIVERY')->firstOrFail();

        CustomerPricingCharge::create([
            'customer_pricing_id' => $pricing->id,
            'additional_charge_id' => $pickup->id,
            'charge_type' => 'fixed',
            'amount' => 750000,
        ]);
        CustomerPricingCharge::create([
            'customer_pricing_id' => $pricing->id,
            'additional_charge_id' => $delivery->id,
            'charge_type' => 'fixed',
            'amount' => 450000,
        ]);

        $result = $this->service->estimate($this->baseParams([
            'estimated_weight' => 200,
            'shipment_coverage' => 'door_to_door',
        ]));

        $this->assertSame(600000.0, $result['breakdown']['freight']);
        $this->assertSame(750000.0, $result['breakdown']['pickup']);
        $this->assertSame(450000.0, $result['breakdown']['delivery']);
        $this->assertSame(1800000.0, $result['breakdown']['total']);
    }

    public function test_pickup_and_delivery_are_zero_for_port_to_port_coverage(): void
    {
        $pricing = CustomerPricing::create([
            'company_id' => $this->company->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'cargo_category_id' => $this->generalCargo->id,
            'service_type' => 'lcl',
            'shipment_coverage' => 'port_to_port',
            'pricing_basis' => 'per_kg',
            'rate' => 3000,
            'minimum_charge' => 500000,
            'status' => 'active',
        ]);

        $pickup = AdditionalCharge::where('code', 'PICKUP')->firstOrFail();
        CustomerPricingCharge::create([
            'customer_pricing_id' => $pricing->id,
            'additional_charge_id' => $pickup->id,
            'charge_type' => 'fixed',
            'amount' => 750000,
        ]);

        $result = $this->service->estimate($this->baseParams([
            'estimated_weight' => 200,
            'shipment_coverage' => 'port_to_port',
        ]));

        $this->assertSame(0.0, $result['breakdown']['pickup']);
        $this->assertSame(0.0, $result['breakdown']['delivery']);
    }

    public function test_breakdown_for_booking_falls_back_to_stored_estimated_price(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'user_type' => 'customer',
            'status' => 'active',
        ]);

        $booking = Booking::create([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'transport_mode_id' => $this->mode->id,
            'service_type_id' => $this->lclService->id,
            'shipment_coverage' => 'port_to_port',
            'status' => 'submitted',
            'estimated_price' => 1250000,
            'cost_estimate_snapshot' => [
                'freight' => 1000000,
                'pickup' => 150000,
                'delivery' => 100000,
                'discount' => 0,
                'additional_services' => 0,
                'total' => 1250000,
            ],
            'shipper_name' => 'Shipper',
            'shipper_address' => 'Addr',
            'shipper_phone' => '08111',
            'consignee_name' => 'Consignee',
            'consignee_address' => 'Addr2',
            'consignee_phone' => '08222',
        ]);

        $breakdown = $this->service->breakdownForBooking($booking);

        $this->assertNotNull($breakdown);
        $this->assertSame(1250000.0, $breakdown['total']);
        $this->assertSame(1000000.0, $breakdown['freight']);
        $this->assertSame(150000.0, $breakdown['pickup']);
        $this->assertSame(100000.0, $breakdown['delivery']);
    }

    public function test_company_commercial_discount_percent_is_applied(): void
    {
        $this->company->update([
            'pricing_type' => 'discount',
            'discount_percent' => 10,
        ]);

        CustomerPricing::create([
            'company_id' => $this->company->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'cargo_category_id' => $this->generalCargo->id,
            'service_type' => 'lcl',
            'shipment_coverage' => 'port_to_port',
            'pricing_basis' => 'per_kg',
            'rate' => 4000,
            'minimum_charge' => 1000000,
            'status' => 'active',
        ]);

        $result = $this->service->estimate($this->baseParams([
            'estimated_weight' => 100,
            'shipment_coverage' => 'port_to_port',
        ]));

        $this->assertSame(1000000.0, $result['breakdown']['freight']);
        $this->assertSame(100000.0, $result['breakdown']['discount']);
        $this->assertSame(900000.0, $result['breakdown']['total']);
    }

    public function test_build_snapshot_captures_breakdown_for_booking_storage(): void
    {
        CustomerPricing::create([
            'company_id' => $this->company->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'cargo_category_id' => $this->generalCargo->id,
            'service_type' => 'lcl',
            'shipment_coverage' => 'port_to_port',
            'pricing_basis' => 'per_kg',
            'rate' => 3000,
            'minimum_charge' => 500000,
            'status' => 'active',
        ]);

        $estimate = $this->service->estimate($this->baseParams([
            'estimated_weight' => 200,
            'shipment_coverage' => 'port_to_port',
        ]));

        $snapshot = $this->service->buildSnapshot($estimate);

        $this->assertNotNull($snapshot);
        $this->assertSame(600000.0, $snapshot['freight']);
        $this->assertSame(600000.0, $snapshot['total']);
        $this->assertNotEmpty($snapshot['captured_at']);
    }

    public function test_estimate_falls_back_to_vendor_sell_pricing_without_customer_tariff(): void
    {
        $this->seed(VendorSeeder::class);

        $result = $this->service->estimate([
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'transport_mode_id' => $this->mode->id,
            'service_type_id' => $this->lclService->id,
            'cargo_category_id' => $this->generalCargo->id,
            'shipment_coverage' => 'port_to_port',
            'estimated_weight' => 15_000,
            'estimated_cbm' => 0,
            'additional_services' => [],
        ]);

        $this->assertNull($result['customer_pricing_id']);
        $this->assertNotNull($result['vendor_service_id']);
        $this->assertSame(6_500_000.0, $result['breakdown']['freight']);
        $this->assertSame(6_500_000.0, $result['estimated_price']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function baseParams(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'transport_mode_id' => $this->mode->id,
            'service_type_id' => $this->lclService->id,
            'cargo_category_id' => $this->generalCargo->id,
            'shipment_coverage' => 'port_to_port',
            'estimated_weight' => 0,
            'estimated_cbm' => 0,
            'additional_services' => [],
        ], $overrides);
    }
}
