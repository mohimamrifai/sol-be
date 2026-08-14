<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceActivity;
use App\Models\Shipment;
use App\Models\User;
use App\Support\SystemConfig;
use Illuminate\Support\Facades\DB;

final class InvoiceGenerationService
{
    public function __construct(
        private readonly BookingPriceEstimateService $priceEstimate,
    ) {}

    /**
     * @return list<array{description: string, quantity: int, unit_price: float}>
     */
    public function buildLineItemsFromShipment(Shipment $shipment): array
    {
        $shipment->loadMissing([
            'booking.additionalServices',
            'booking.packages',
            'booking.containers',
            'company',
        ]);

        $booking = $shipment->booking;
        if (! $booking) {
            return [
                ['description' => 'Freight / Tarif Pengiriman', 'quantity' => 1, 'unit_price' => 0.0],
            ];
        }

        $estimate = $this->priceEstimate->estimate([
            'company_id' => $booking->company_id,
            'origin_location_id' => $booking->origin_location_id,
            'destination_location_id' => $booking->destination_location_id,
            'transport_mode_id' => $booking->transport_mode_id,
            'service_type_id' => $booking->service_type_id,
            'shipment_coverage' => $booking->shipment_coverage,
            'container_type_id' => $booking->container_type_id,
            'container_count' => $booking->container_count ?? 1,
            'estimated_weight' => (float) ($booking->chargeable_weight_kg ?? $booking->estimated_weight ?? 0),
            'estimated_cbm' => (float) ($booking->total_volume_cbm ?? $booking->estimated_cbm ?? 0),
            'additional_services' => $booking->additionalServices->pluck('id')->all(),
        ]);

        $breakdown = $estimate['breakdown'] ?? [];
        $items = [];

        $freight = (float) ($breakdown['freight'] ?? $breakdown['base_freight'] ?? 0);
        if ($freight > 0) {
            $items[] = ['description' => 'Freight / Tarif Pengiriman', 'quantity' => 1, 'unit_price' => $freight];
        }

        $pickup = (float) ($breakdown['pickup'] ?? 0);
        if ($pickup > 0) {
            $items[] = ['description' => 'Pickup', 'quantity' => 1, 'unit_price' => $pickup];
        }

        $delivery = (float) ($breakdown['delivery'] ?? 0);
        if ($delivery > 0) {
            $items[] = ['description' => 'Delivery', 'quantity' => 1, 'unit_price' => $delivery];
        }

        $discount = (float) ($breakdown['discount'] ?? $breakdown['discount_amount'] ?? 0);
        if ($discount > 0) {
            $items[] = ['description' => 'Discount', 'quantity' => 1, 'unit_price' => -$discount];
        }

        foreach ($booking->additionalServices as $svc) {
            $price = (float) ($svc->pivot->price ?? $svc->default_price ?? 0);
            if ($price > 0) {
                $items[] = [
                    'description' => $svc->name ?? 'Additional Service',
                    'quantity' => 1,
                    'unit_price' => $price,
                ];
            }
        }

        if ($items === []) {
            $fallback = (float) ($estimate['estimated_price'] ?? $booking->estimated_price ?? 0);
            $items[] = [
                'description' => 'Freight / Tarif Pengiriman',
                'quantity' => 1,
                'unit_price' => max(0, $fallback),
            ];
        }

        return $items;
    }

    public function generateDraftInvoice(Shipment $shipment, User $user, string $status = 'draft'): Invoice
    {
        if ($shipment->invoice()->exists()) {
            throw new \InvalidArgumentException('Shipment sudah memiliki invoice.');
        }

        return DB::transaction(function () use ($shipment, $user, $status) {
            $items = $this->buildLineItemsFromShipment($shipment);

            $subtotal = 0.0;
            foreach ($items as $item) {
                $subtotal += $item['quantity'] * $item['unit_price'];
            }
            $subtotal = max(0, $subtotal);
            $taxBreakdown = SystemConfig::applyTax($subtotal);
            $taxAmount = $taxBreakdown['tax_amount'];
            $totalAmount = $taxBreakdown['total_amount'];

            $shipment->loadMissing([
                'company:id,name,npwp,address,postpaid_term_days',
                'booking:id,booking_number',
                'originLocation:id,name',
                'destinationLocation:id,name',
                'serviceType:id,name',
            ]);

            $issuedDate = now()->toDateString();
            $termDays = (int) ($shipment->company?->postpaid_term_days ?? 30);
            $dueDate = now()->addDays($termDays)->toDateString();

            $invoice = Invoice::create([
                'shipment_id' => $shipment->id,
                'company_id' => $shipment->company_id,
                'issued_date' => $status === 'draft' ? null : $issuedDate,
                'due_date' => $status === 'draft' ? null : $dueDate,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'status' => $status,
                'created_by' => $user->id,
                'company_snapshot' => [
                    'name' => $shipment->company?->name,
                    'npwp' => $shipment->company?->npwp,
                    'address' => $shipment->company?->address,
                    'payment_terms' => $termDays > 0 ? "Net {$termDays}" : 'COD',
                ],
                'shipment_snapshot' => [
                    'shipment_no' => $shipment->shipment_number,
                    'booking_no' => $shipment->booking?->booking_number,
                    'cn_no' => $shipment->waybill_number,
                    'origin' => $shipment->originLocation?->name,
                    'destination' => $shipment->destinationLocation?->name,
                    'service_type' => $shipment->serviceType?->name,
                    'shipment_coverage' => $shipment->shipment_coverage,
                ],
            ]);

            foreach ($items as $item) {
                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => $item['quantity'] * $item['unit_price'],
                ]);
            }

            InvoiceActivity::create([
                'invoice_id' => $invoice->id,
                'event_key' => 'invoice_created',
                'description' => 'Invoice dibuat dari shipment '.$shipment->shipment_number.'.',
                'actor_user_id' => $user->id,
                'occurred_at' => now(),
            ]);

            return $invoice->load('items');
        });
    }
}
