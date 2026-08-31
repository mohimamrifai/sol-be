<?php

namespace App\Services;

use App\Models\AdditionalService;
use App\Models\Company;
use App\Models\CustomerDiscount;
use App\Models\CustomerPricing;
use App\Models\CustomerPricingCharge;
use App\Models\Pricing;
use App\Models\ServiceType;
use App\Models\VendorService;
use Illuminate\Support\Carbon;

class BookingPriceEstimateService
{
    /**
     * Calculate estimated price for a booking.
     *
     * Prefers active Customer Pricing (FSD). Falls back to vendor sell pricing
     * when no customer tariff exists (e.g. public estimate without company).
     *
     * @param  array{origin_location_id: int, destination_location_id: int, transport_mode_id: int, service_type_id: int, shipment_coverage?: string, cargo_category_id?: int, container_type_id?: int, container_count?: int, estimated_weight?: float, estimated_cbm?: float, length?: float, width?: float, height?: float, additional_services?: array<int>, company_id?: int}  $params
     * @return array{estimated_price: float, breakdown: array{freight: float, pickup: float, delivery: float, discount: float, additional_services: float, additional_services_detail: array, total: float}, vendor_service_id: int|null, customer_pricing_id: int|null}
     */
    public function estimate(array $params): array
    {
        $companyId = $params['company_id'] ?? null;
        $additionalServiceIds = $params['additional_services'] ?? [];
        $coverage = $params['shipment_coverage'] ?? null;
        $containerTypeId = $params['container_type_id'] ?? null;
        $containerCount = (int) ($params['container_count'] ?? 1);
        $weight = (float) ($params['estimated_weight'] ?? 0);
        $cbm = (float) ($params['estimated_cbm'] ?? 0);

        $serviceType = isset($params['service_type_id'])
            ? ServiceType::query()->find($params['service_type_id'])
            : null;
        $isFcl = $serviceType && strtoupper((string) $serviceType->code) === 'FCL';
        if (! $isFcl) {
            $containerTypeId = null;
            $containerCount = 0;
        }

        $customerPricing = $this->findCustomerPricing($params, $isFcl);
        $vendorServiceId = null;
        $customerPricingId = $customerPricing?->id;

        if ($customerPricing) {
            $baseFreight = $this->calculateFreightFromCustomerPricing(
                $customerPricing,
                $containerCount > 0 ? $containerCount : 1,
                $weight,
                $cbm
            );
            $surcharges = $this->resolveCustomerPricingSurcharges($customerPricing, $coverage, $baseFreight);
            $pickupCharge = $surcharges['pickup'];
            $deliveryCharge = $surcharges['delivery'];
            $pricingOtherCharges = $surcharges['other'];

            [, $vendorServiceId] = $this->estimateVendorFreight(
                $params,
                $containerTypeId,
                $containerCount,
                $weight,
                $cbm
            );
        } else {
            [$baseFreight, $vendorServiceId] = $this->estimateVendorFreight(
                $params,
                $containerTypeId,
                $containerCount,
                $weight,
                $cbm
            );
            $pickupCharge = 0.0;
            $deliveryCharge = 0.0;
            $pricingOtherCharges = 0.0;
        }

        $discountAmount = 0.0;
        if ($companyId && $baseFreight > 0) {
            $discountAmount = $this->resolveDiscount($companyId, $vendorServiceId, $baseFreight);
        }

        $afterDiscount = max(0, $baseFreight - $discountAmount);

        [$additionalTotal, $additionalDetail] = $this->resolveAdditionalServices($additionalServiceIds);
        $additionalTotal += $pricingOtherCharges;

        $total = $afterDiscount + $pickupCharge + $deliveryCharge + $additionalTotal;

        return [
            'estimated_price' => round($total, 2),
            'breakdown' => [
                'freight' => round($baseFreight, 2),
                'pickup' => round($pickupCharge, 2),
                'delivery' => round($deliveryCharge, 2),
                'discount' => round($discountAmount, 2),
                'additional_services' => round($additionalTotal, 2),
                'additional_services_detail' => $additionalDetail,
                'total' => round($total, 2),
            ],
            'vendor_service_id' => $vendorServiceId,
            'customer_pricing_id' => $customerPricingId,
        ];
    }

    /**
     * Persistable snapshot for FSD: booking stores selling-price breakdown at submit time.
     *
     * @param  array{estimated_price?: float, breakdown?: array<string, mixed>, customer_pricing_id?: int|null}  $estimate
     * @return array<string, mixed>|null
     */
    public function buildSnapshot(?array $estimate): ?array
    {
        if (! is_array($estimate)) {
            return null;
        }

        $breakdown = $estimate['breakdown'] ?? null;
        if (! is_array($breakdown)) {
            return null;
        }

        $total = (float) ($breakdown['total'] ?? $estimate['estimated_price'] ?? 0);
        if ($total <= 0) {
            return null;
        }

        return [
            'freight' => round((float) ($breakdown['freight'] ?? 0), 2),
            'pickup' => round((float) ($breakdown['pickup'] ?? 0), 2),
            'delivery' => round((float) ($breakdown['delivery'] ?? 0), 2),
            'discount' => round((float) ($breakdown['discount'] ?? 0), 2),
            'additional_services' => round((float) ($breakdown['additional_services'] ?? 0), 2),
            'total' => round($total, 2),
            'customer_pricing_id' => $estimate['customer_pricing_id'] ?? null,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array{estimated_price?: float, breakdown?: array<string, mixed>, customer_pricing_id?: int|null}|null  $estimate
     * @return array{estimated_price: float|null, cost_estimate_snapshot: array<string, mixed>|null}
     */
    public function estimateBookingFields(?array $estimate): array
    {
        if (! is_array($estimate)) {
            return [
                'estimated_price' => null,
                'cost_estimate_snapshot' => null,
            ];
        }

        return [
            'estimated_price' => $estimate['estimated_price'] ?? null,
            'cost_estimate_snapshot' => $this->buildSnapshot($estimate),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function findCustomerPricing(array $params, bool $isFcl): ?CustomerPricing
    {
        $companyId = $params['company_id'] ?? null;
        if (! $companyId) {
            return null;
        }

        $serviceType = ServiceType::query()->find($params['service_type_id'] ?? null);
        $serviceCode = $serviceType ? strtolower((string) $serviceType->code) : null;
        if (! in_array($serviceCode, ['lcl', 'fcl'], true)) {
            return null;
        }

        $baseQuery = CustomerPricing::query()
            ->with(['charges.additionalCharge'])
            ->where('company_id', $companyId)
            ->where('origin_location_id', $params['origin_location_id'])
            ->where('destination_location_id', $params['destination_location_id'])
            ->where('service_type', $serviceCode)
            ->where('status', 'active');

        if (! empty($params['shipment_coverage'])) {
            $baseQuery->where('shipment_coverage', $params['shipment_coverage']);
        }

        if ($isFcl && ! empty($params['container_type_id'])) {
            $baseQuery->where('container_type_id', $params['container_type_id']);
        } elseif ($isFcl) {
            $baseQuery->whereNotNull('container_type_id');
        } else {
            $baseQuery->whereNull('container_type_id');
        }

        if (! empty($params['cargo_category_id'])) {
            $pricing = (clone $baseQuery)
                ->where('cargo_category_id', $params['cargo_category_id'])
                ->first();
            if ($pricing) {
                return $pricing;
            }
        }

        return $baseQuery->first();
    }

    private function calculateFreightFromCustomerPricing(
        CustomerPricing $pricing,
        int $containerCount,
        float $weight,
        float $cbm
    ): float {
        $rate = (float) $pricing->rate;
        $minimum = (float) ($pricing->minimum_charge ?? 0);

        $subtotal = match ($pricing->pricing_basis) {
            'per_container' => $rate * max(1, $containerCount),
            'per_kg' => $this->applyMinimum($rate * $weight, $minimum),
            'per_ton' => $this->applyMinimum($rate * ($weight / 1000), $minimum),
            'per_cbm' => $this->applyMinimum($rate * $cbm, $minimum),
            default => 0.0,
        };

        if ($pricing->pricing_basis === 'per_kg' && $cbm > 0) {
            $byCbm = $this->applyMinimum($rate * $cbm, $minimum);

            return max($subtotal, $byCbm);
        }

        return $subtotal;
    }

    private function applyMinimum(float $amount, float $minimum): float
    {
        return $minimum > 0 ? max($amount, $minimum) : $amount;
    }

    /**
     * @return array{pickup: float, delivery: float, other: float}
     */
    private function resolveCustomerPricingSurcharges(
        CustomerPricing $pricing,
        ?string $coverage,
        float $baseFreight
    ): array {
        $needsPickup = in_array($coverage, ['door_to_port', 'door_to_door'], true);
        $needsDelivery = in_array($coverage, ['port_to_door', 'door_to_door'], true);

        $pickup = 0.0;
        $delivery = 0.0;
        $other = 0.0;

        foreach ($pricing->charges as $charge) {
            $amount = $this->chargeAmount($charge, $baseFreight);
            $bucket = $this->classifyPricingCharge($charge);

            if ($bucket === 'pickup') {
                if ($needsPickup) {
                    $pickup += $amount;
                }

                continue;
            }

            if ($bucket === 'delivery') {
                if ($needsDelivery) {
                    $delivery += $amount;
                }

                continue;
            }

            $other += $amount;
        }

        return compact('pickup', 'delivery', 'other');
    }

    private function classifyPricingCharge(CustomerPricingCharge $charge): string
    {
        $code = strtoupper((string) ($charge->additionalCharge?->code ?? ''));
        $name = strtoupper((string) ($charge->additionalCharge?->name ?? ''));

        if ($code === 'PICKUP' || str_contains($name, 'PICKUP')) {
            return 'pickup';
        }

        if ($code === 'DELIVERY' || str_contains($name, 'DELIVERY')) {
            return 'delivery';
        }

        return 'other';
    }

    private function chargeAmount(CustomerPricingCharge $charge, float $baseFreight): float
    {
        if ($charge->charge_type === 'percentage') {
            return $baseFreight * ((float) $charge->amount / 100);
        }

        return (float) $charge->amount;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{0: float, 1: int|null}
     */
    private function estimateVendorFreight(
        array $params,
        ?int $containerTypeId,
        int $containerCount,
        float $weight,
        float $cbm
    ): array {
        $vendorServices = VendorService::query()
            ->where('origin_location_id', $params['origin_location_id'])
            ->where('destination_location_id', $params['destination_location_id'])
            ->where('transport_mode_id', $params['transport_mode_id'])
            ->where('service_type_id', $params['service_type_id'])
            ->where('is_active', true)
            ->get();

        $lowestFreight = null;
        $bestVendorServiceId = null;

        foreach ($vendorServices as $vendorService) {
            $pricing = $this->findSellPricing(
                $vendorService,
                $containerTypeId,
                $containerCount,
                $weight,
                $cbm
            );

            if (! $pricing) {
                continue;
            }

            $freight = $this->calculateFreightFromPricing(
                $pricing,
                $containerTypeId,
                $containerCount,
                $weight,
                $cbm
            );

            if ($lowestFreight === null || $freight < $lowestFreight) {
                $lowestFreight = $freight;
                $bestVendorServiceId = $vendorService->id;
            }
        }

        return [$lowestFreight ?? 0.0, $bestVendorServiceId];
    }

    /**
     * @param  list<int>  $additionalServiceIds
     * @return array{0: float, 1: list<array{id: int, name: string, base_price: float}>}
     */
    private function resolveAdditionalServices(array $additionalServiceIds): array
    {
        if ($additionalServiceIds === []) {
            return [0.0, []];
        }

        $additionalTotal = 0.0;
        $additionalDetail = [];
        $services = AdditionalService::query()
            ->whereIn('id', $additionalServiceIds)
            ->where('is_active', true)
            ->get();

        foreach ($services as $svc) {
            $price = (float) $svc->base_price;
            $additionalTotal += $price;
            $additionalDetail[] = ['id' => $svc->id, 'name' => $svc->name, 'base_price' => $price];
        }

        return [$additionalTotal, $additionalDetail];
    }

    private function findSellPricing(
        VendorService $vendorService,
        ?int $containerTypeId,
        int $containerCount,
        float $weight,
        float $cbm
    ): ?Pricing {
        $query = $vendorService->pricings()
            ->where('price_type', 'sell')
            ->where('is_active', true)
            ->currentlyEffective();

        if ($containerTypeId && $containerCount > 0) {
            $query->where('container_type_id', $containerTypeId);
        } else {
            $query->whereNull('container_type_id');
        }

        return $query->first();
    }

    private function calculateFreightFromPricing(
        Pricing $pricing,
        ?int $containerTypeId,
        int $containerCount,
        float $weight,
        float $cbm
    ): float {
        if ($containerTypeId && $containerCount > 0) {
            return (float) $pricing->price_per_container * $containerCount;
        }

        $minKg = (float) ($pricing->min_kg ?? 0);
        $minCharge = (float) ($pricing->minimum_charge ?? 0);
        $nextPrice = (float) ($pricing->price_per_kg ?? 0);

        if ($minKg > 0 && $minCharge > 0) {
            if ($weight <= $minKg) {
                $subtotal = $minCharge;
            } else {
                $extraWeight = $weight - $minKg;
                $subtotal = $minCharge + ($extraWeight * $nextPrice);
            }

            $byCbm = $cbm > 0 ? (float) $pricing->price_per_cbm * $cbm : 0.0;

            return max($subtotal, $byCbm);
        }

        $byWeight = $weight > 0 ? (float) $pricing->price_per_kg * $weight : 0.0;
        $byCbm = $cbm > 0 ? (float) $pricing->price_per_cbm * $cbm : 0.0;
        $subtotal = max($byWeight, $byCbm);
        $minimum = (float) $pricing->minimum_charge;

        return $minimum > 0 ? max($subtotal, $minimum) : $subtotal;
    }

    private function resolveDiscount(int $companyId, ?int $vendorServiceId, float $amount): float
    {
        if ($vendorServiceId) {
            $today = Carbon::today()->toDateString();
            $discount = CustomerDiscount::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where(function ($q) use ($vendorServiceId) {
                    $q->where('vendor_service_id', $vendorServiceId)
                        ->orWhereNull('vendor_service_id');
                })
                ->where(function ($q) use ($today) {
                    $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today);
                })
                ->where(function ($q) use ($today) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
                })
                ->orderByRaw('vendor_service_id IS NOT NULL DESC')
                ->first();

            if ($discount) {
                if ($discount->discount_type === 'percentage') {
                    return $amount * ((float) $discount->discount_value / 100);
                }

                return min((float) $discount->discount_value, $amount);
            }
        }

        $company = Company::query()->find($companyId);
        if (
            $company
            && $company->pricing_type === 'discount'
            && (float) ($company->discount_percent ?? 0) > 0
        ) {
            return $amount * ((float) $company->discount_percent / 100);
        }

        return 0.0;
    }

    /**
     * Build cost breakdown for display. Recalculates when possible; falls back
     * to the stored estimated_price when live pricing is unavailable.
     */
    public function breakdownForBooking(\App\Models\Booking $booking): ?array
    {
        $storedTotal = (float) ($booking->estimated_price ?? 0);
        $snapshot = $booking->cost_estimate_snapshot;

        if (is_array($snapshot) && (float) ($snapshot['total'] ?? 0) > 0) {
            return $this->normalizeBreakdown($snapshot);
        }

        if (! $booking->origin_location_id || ! $booking->destination_location_id) {
            return $storedTotal > 0 ? $this->storedBreakdown($booking) : null;
        }

        try {
            $booking->loadMissing('additionalServices');
            $result = $this->estimate([
                'company_id' => $booking->company_id,
                'origin_location_id' => $booking->origin_location_id,
                'destination_location_id' => $booking->destination_location_id,
                'transport_mode_id' => $booking->transport_mode_id,
                'service_type_id' => $booking->service_type_id,
                'shipment_coverage' => $booking->shipment_coverage,
                'cargo_category_id' => $booking->cargo_category_id,
                'container_type_id' => $booking->container_type_id,
                'container_count' => $booking->container_count ?? 1,
                'estimated_weight' => (float) ($booking->estimated_weight ?? 0),
                'estimated_cbm' => (float) ($booking->estimated_cbm ?? 0),
                'additional_services' => $booking->additionalServices->pluck('id')->all(),
            ]);

            $breakdown = $result['breakdown'] ?? null;
            if (is_array($breakdown) && ($breakdown['total'] ?? 0) > 0) {
                return $breakdown;
            }
        } catch (\Throwable) {
            // fall through to stored estimate
        }

        return $storedTotal > 0 ? $this->storedBreakdown($booking) : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{freight: float, pickup: float, delivery: float, discount: float, additional_services: float, total: float}
     */
    private function normalizeBreakdown(array $snapshot): array
    {
        return [
            'freight' => round((float) ($snapshot['freight'] ?? 0), 2),
            'pickup' => round((float) ($snapshot['pickup'] ?? 0), 2),
            'delivery' => round((float) ($snapshot['delivery'] ?? 0), 2),
            'discount' => round((float) ($snapshot['discount'] ?? 0), 2),
            'additional_services' => round((float) ($snapshot['additional_services'] ?? 0), 2),
            'total' => round((float) ($snapshot['total'] ?? 0), 2),
        ];
    }

    /**
     * @return array{freight: float, pickup: float, delivery: float, discount: float, additional_services: float, total: float}
     */
    private function storedBreakdown(\App\Models\Booking $booking): array
    {
        $total = (float) $booking->estimated_price;
        $booking->loadMissing('additionalServices');
        $additionalTotal = (float) $booking->additionalServices->sum(fn ($svc) => (float) $svc->base_price);
        $freight = max(0, $total - $additionalTotal);

        return [
            'freight' => round($freight, 2),
            'pickup' => 0.0,
            'delivery' => 0.0,
            'discount' => 0.0,
            'additional_services' => round($additionalTotal, 2),
            'total' => round($total, 2),
        ];
    }
}
