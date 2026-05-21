<?php

namespace App\Services;

use App\Models\AdditionalService;
use App\Models\CustomerDiscount;
use App\Models\Pricing;
use App\Models\VendorService;
use Illuminate\Support\Carbon;

class BookingPriceEstimateService
{
    /**
     * Calculate estimated price for a booking based on route, service type, and optional extras.
     *
     * @param  array{origin_location_id: int, destination_location_id: int, transport_mode_id: int, service_type_id: int, container_type_id?: int, container_count?: int, estimated_weight?: float, estimated_cbm?: float, additional_services?: array<int>, company_id?: int}  $params
     * @return array{estimated_price: float, breakdown: array{base_freight: float, discount_amount: float, additional_services_total: float, additional_services_detail: array, total: float}, vendor_service_id: int|null}
     */
    public function estimate(array $params): array
    {
        $companyId = $params['company_id'] ?? null;
        $additionalServiceIds = $params['additional_services'] ?? [];

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
                $params['container_type_id'] ?? null,
                $params['container_count'] ?? 1,
                (float) ($params['estimated_weight'] ?? 0),
                (float) ($params['estimated_cbm'] ?? 0)
            );

            if ($pricing) {
                $freight = $this->calculateFreightFromPricing(
                    $pricing,
                    $params['container_type_id'] ?? null,
                    $params['container_count'] ?? 1,
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

        $total = $afterDiscount + $additionalTotal;

        return [
            'estimated_price' => round($total, 2),
            'breakdown' => [
                'base_freight' => round($baseFreight, 2),
                'discount_amount' => round($discountAmount, 2),
                'additional_services_total' => round($additionalTotal, 2),
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
}
