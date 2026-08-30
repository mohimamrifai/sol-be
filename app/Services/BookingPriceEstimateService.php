<?php

namespace App\Services;

use App\Models\AdditionalService;
use App\Models\CustomerDiscount;
use App\Models\Pricing;
use App\Models\ServiceType;
use App\Models\VendorService;
use Illuminate\Support\Carbon;

class BookingPriceEstimateService
{
    /**
     * Calculate estimated price for a booking based on route, service type, and optional extras.
     *
     * @param  array{origin_location_id: int, destination_location_id: int, transport_mode_id: int, service_type_id: int, shipment_coverage?: string, container_type_id?: int, container_count?: int, estimated_weight?: float, estimated_cbm?: float, length?: float, width?: float, height?: float, additional_services?: array<int>, company_id?: int}  $params
     * @return array{estimated_price: float, breakdown: array{base_freight: float, pickup: float, delivery: float, discount_amount: float, additional_services_total: float, additional_services_detail: array, total: float}, vendor_service_id: int|null}
     */
    public function estimate(array $params): array
    {
        $companyId = $params['company_id'] ?? null;
        $additionalServiceIds = $params['additional_services'] ?? [];
        $coverage = $params['shipment_coverage'] ?? null;
        $containerTypeId = $params['container_type_id'] ?? null;
        $containerCount = (int) ($params['container_count'] ?? 1);
        $serviceType = isset($params['service_type_id'])
            ? ServiceType::query()->find($params['service_type_id'])
            : null;
        $isFcl = $serviceType && strtoupper((string) $serviceType->code) === 'FCL';
        if (! $isFcl) {
            $containerTypeId = null;
            $containerCount = 0;
        }

        // Find all matching vendor services (routes)
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
                (float) ($params['estimated_weight'] ?? 0),
                (float) ($params['estimated_cbm'] ?? 0)
            );

            if ($pricing) {
                $freight = $this->calculateFreightFromPricing(
                    $pricing,
                    $containerTypeId,
                    $containerCount,
                    (float) ($params['estimated_weight'] ?? 0),
                    (float) ($params['estimated_cbm'] ?? 0)
                );

                if ($lowestFreight === null || $freight < $lowestFreight) {
                    $lowestFreight = $freight;
                    $bestVendorServiceId = $vendorService->id;
                }
            }
        }

        $baseFreight = $lowestFreight ?? 0.0;
        $vendorServiceId = $bestVendorServiceId;

        // Apply customer discount
        $discountAmount = 0.0;
        if ($companyId && $baseFreight > 0 && $vendorServiceId) {
            $discountAmount = $this->resolveDiscount($companyId, $vendorServiceId, $baseFreight);
        }
        $afterDiscount = max(0, $baseFreight - $discountAmount);

        // Pickup / delivery surcharges (spec L62-65).
        // The current pricing model does not have explicit pickup & delivery
        // surcharge tables; we surface them as zero so the breakdown keeps
        // the same line items the UI expects. Operations can override them
        // out-of-band on the invoice.
        $pickupCharge = 0.0;
        $deliveryCharge = 0.0;
        if ($coverage === 'door_to_port' || $coverage === 'door_to_door') {
            // The "from-door" leg is metered separately; default to 0.
            $pickupCharge = 0.0;
        }
        if ($coverage === 'port_to_door' || $coverage === 'door_to_door') {
            $deliveryCharge = 0.0;
        }

        // Additional services
        $additionalTotal = 0.0;
        $additionalDetail = [];
        if (! empty($additionalServiceIds)) {
            $services = AdditionalService::query()
                ->whereIn('id', $additionalServiceIds)
                ->where('is_active', true)
                ->get();
            foreach ($services as $svc) {
                $price = (float) $svc->base_price;
                $additionalTotal += $price;
                $additionalDetail[] = ['id' => $svc->id, 'name' => $svc->name, 'base_price' => $price];
            }
        }

        $total = $afterDiscount + $additionalTotal + $pickupCharge + $deliveryCharge;

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
        ];
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
            // New logic for LCL: Minimum charge applies for the first min_kg.
            // Any weight exceeding min_kg is charged at nextPrice per kg.
            if ($weight <= $minKg) {
                $subtotal = $minCharge;
            } else {
                $extraWeight = $weight - $minKg;
                $subtotal = $minCharge + ($extraWeight * $nextPrice);
            }

            // Compare with CBM if CBM is provided and price_per_cbm is set
            $byCbm = $cbm > 0 ? (float) $pricing->price_per_cbm * $cbm : 0.0;

            return max($subtotal, $byCbm);
        }

        // Old fallback logic
        $byWeight = $weight > 0 ? (float) $pricing->price_per_kg * $weight : 0.0;
        $byCbm = $cbm > 0 ? (float) $pricing->price_per_cbm * $cbm : 0.0;
        $subtotal = max($byWeight, $byCbm);
        $minimum = (float) $pricing->minimum_charge;

        return $minimum > 0 ? max($subtotal, $minimum) : $subtotal;
    }

    private function resolveDiscount(int $companyId, int $vendorServiceId, float $amount): float
    {
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

        if (! $discount) {
            return 0.0;
        }
        if ($discount->discount_type === 'percentage') {
            return $amount * ((float) $discount->discount_value / 100);
        }

        return min((float) $discount->discount_value, $amount);
    }

    /**
     * Build cost breakdown for display. Recalculates from vendor sell pricing when
     * possible; falls back to the stored estimated_price on the booking row.
     */
    public function breakdownForBooking(\App\Models\Booking $booking): ?array
    {
        if (! $booking->origin_location_id || ! $booking->destination_location_id) {
            return $booking->estimated_price > 0 ? $this->storedBreakdown($booking) : null;
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
                'container_type_id' => $booking->container_type_id,
                'container_count' => $booking->container_count ?? 1,
                'estimated_weight' => (float) ($booking->estimated_weight ?? 0),
                'estimated_cbm' => (float) ($booking->estimated_cbm ?? 0),
                'additional_services' => $booking->additionalServices->pluck('id')->all(),
            ]);

            $breakdown = $result['breakdown'] ?? null;
            if ($breakdown && ($breakdown['total'] ?? 0) > 0) {
                return $breakdown;
            }
        } catch (\Throwable) {
            // fall through to stored estimate
        }

        return $booking->estimated_price > 0 ? $this->storedBreakdown($booking) : null;
    }

    /**
     * @return array{freight: float, pickup: float, delivery: float, discount: float, additional_services: float, total: float}
     */
    private function storedBreakdown(\App\Models\Booking $booking): array
    {
        $total = (float) $booking->estimated_price;

        return [
            'freight' => round($total, 2),
            'pickup' => 0.0,
            'delivery' => 0.0,
            'discount' => 0.0,
            'additional_services' => 0.0,
            'total' => round($total, 2),
        ];
    }
}
