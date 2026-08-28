<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceActivity;
use App\Models\Shipment;
use App\Models\User;
use App\Support\SystemConfig;
use Illuminate\Support\Carbon;
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

    /**
     * @param  array{invoice_date?: string, notes?: ?string, items?: list<array{description: string, quantity: int, unit_price: float|int}>}  $attributes
     */
    public function generateDraftInvoice(Shipment $shipment, User $user, array $attributes = []): Invoice
    {
        if (strtolower((string) $shipment->status) !== 'completed') {
            throw new \InvalidArgumentException('Hanya shipment Completed yang dapat di-invoice.');
        }

        if ($shipment->invoice()->withTrashed()->exists()) {
            throw new \InvalidArgumentException('Shipment sudah memiliki invoice.');
        }

        return DB::transaction(function () use ($shipment, $user, $attributes) {
            $shipment->loadMissing([
                'company:id,name,company_code,npwp,address,city,province,postal_code,payment_term,postpaid_term_days',
                'booking:id,booking_number',
                'originLocation:id,name',
                'destinationLocation:id,name',
                'serviceType:id,name',
            ]);

            $items = $attributes['items'] ?? $this->buildLineItemsFromShipment($shipment);
            $totals = $this->calculateTotals($items);
            $term = $this->resolvePaymentTerm(
                $shipment->company?->payment_term,
                $shipment->company?->postpaid_term_days,
            );
            $invoiceDate = Carbon::parse($attributes['invoice_date'] ?? now()->toDateString())->startOfDay();
            $dueDate = $invoiceDate->copy()->addDays($term['days']);

            $invoice = Invoice::create([
                'shipment_id' => $shipment->id,
                'company_id' => $shipment->company_id,
                'issued_date' => $invoiceDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax_amount'],
                'total_amount' => $totals['grand_total'],
                'notes' => $attributes['notes'] ?? null,
                'status' => 'draft',
                'created_by' => $user->id,
                'company_snapshot' => [
                    'name' => $shipment->company?->name,
                    'company_code' => $shipment->company?->company_code,
                    'npwp' => $shipment->company?->npwp,
                    'address' => $shipment->company?->address,
                    'city' => $shipment->company?->city,
                    'province' => $shipment->company?->province,
                    'postal_code' => $shipment->company?->postal_code,
                    'payment_term' => $term['key'],
                    'payment_terms' => $term['label'],
                    'payment_term_days' => $term['days'],
                    'currency' => 'IDR',
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
                'description' => 'Invoice dibuat.',
                'meta' => ['shipment_number' => $shipment->shipment_number],
                'actor_user_id' => $user->id,
                'occurred_at' => now(),
            ]);

            return $invoice->load('items');
        });
    }

    /**
     * @param  list<array{description: string, quantity: int, unit_price: float|int}>  $items
     * @return array{subtotal: float, discount: float, taxable_amount: float, tax_amount: float, grand_total: float}
     */
    public function calculateTotals(array $items): array
    {
        $subtotal = 0.0;
        $discount = 0.0;

        foreach ($items as $item) {
            $amount = round((int) $item['quantity'] * (float) $item['unit_price'], 2);
            if ($amount < 0 || str_contains(strtolower($item['description']), 'discount')) {
                $discount += abs($amount);
            } else {
                $subtotal += $amount;
            }
        }

        $taxableAmount = max(0.0, $subtotal - $discount);
        $tax = SystemConfig::applyTax($taxableAmount);

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'taxable_amount' => round($taxableAmount, 2),
            'tax_amount' => $tax['tax_amount'],
            'grand_total' => $tax['total_amount'],
        ];
    }

    /**
     * Resolve current payment_term first, then legacy postpaid days, then system default.
     *
     * @return array{key: string, label: string, days: int}
     */
    public function resolvePaymentTerm(?string $paymentTerm, mixed $legacyDays = null): array
    {
        $normalized = strtolower(trim((string) $paymentTerm));
        $normalized = str_replace([' ', '-'], '_', $normalized);
        $normalized = preg_replace('/^net_?/', 'net_', $normalized) ?? $normalized;

        $allowed = [
            'cod' => 0,
            'net_7' => 7,
            'net_14' => 14,
            'net_30' => 30,
            'net_45' => 45,
            'net_60' => 60,
        ];

        if (! array_key_exists($normalized, $allowed)) {
            $legacy = is_numeric($legacyDays) ? (int) $legacyDays : null;
            $days = in_array($legacy, [0, 7, 14, 30, 45, 60], true)
                ? $legacy
                : SystemConfig::defaultPostpaidTermDays();
            $days = in_array($days, [0, 7, 14, 30, 45, 60], true) ? $days : 30;
            $normalized = $days === 0 ? 'cod' : "net_{$days}";
        }

        $days = $allowed[$normalized];

        return [
            'key' => $normalized,
            'label' => $days === 0 ? 'COD' : "Net {$days}",
            'days' => $days,
        ];
    }
}
