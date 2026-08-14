<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerPricing;
use App\Models\CustomerPricingCharge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCustomerPricingController extends Controller
{
    public function stats(): JsonResponse
    {
        $base = CustomerPricing::query();

        return response()->json([
            'data' => [
                'active' => (clone $base)->where('status', 'active')->count(),
                'inactive' => (clone $base)->where('status', 'inactive')->count(),
                'customers' => (clone $base)->distinct('company_id')->count('company_id'),
                'total' => (clone $base)->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = CustomerPricing::query()->with([
            'company:id,name',
            'originLocation:id,code,name',
            'destinationLocation:id,code,name',
            'cargoCategory:id,name',
            'containerType:id,name',
        ]);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->whereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('originLocation', fn ($lq) => $lq->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
                    ->orWhereHas('destinationLocation', fn ($lq) => $lq->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
            });
        }
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->filled('service_type')) {
            $query->where('service_type', $request->service_type);
        }
        if ($request->filled('shipment_coverage')) {
            $query->where('shipment_coverage', $request->shipment_coverage);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $paginated = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 15));
        $paginated->getCollection()->transform(fn (CustomerPricing $p) => $this->transformRow($p));

        return response()->json($paginated);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePricing($request);
        $charges = $data['charges'] ?? [];
        unset($data['charges']);

        $pricing = DB::transaction(function () use ($data, $charges) {
            $pricing = CustomerPricing::create($data);
            $this->syncCharges($pricing, $charges);

            return $pricing;
        });

        return response()->json(['message' => 'Customer pricing berhasil dibuat.', 'data' => $this->transformRow($pricing->fresh(['company', 'originLocation', 'destinationLocation', 'cargoCategory', 'containerType', 'charges.additionalCharge']))], 201);
    }

    public function show(CustomerPricing $customerPricing): JsonResponse
    {
        $customerPricing->load(['company', 'originLocation', 'destinationLocation', 'cargoCategory', 'containerType', 'charges.additionalCharge']);

        return response()->json(['data' => $this->transformRow($customerPricing, true)]);
    }

    public function update(Request $request, CustomerPricing $customerPricing): JsonResponse
    {
        $data = $this->validatePricing($request, $customerPricing->id);
        $charges = $data['charges'] ?? null;
        unset($data['charges']);

        DB::transaction(function () use ($customerPricing, $data, $charges) {
            $customerPricing->update($data);
            if (is_array($charges)) {
                $this->syncCharges($customerPricing, $charges);
            }
        });

        return response()->json(['message' => 'Customer pricing diperbarui.', 'data' => $this->transformRow($customerPricing->fresh(['company', 'originLocation', 'destinationLocation', 'cargoCategory', 'containerType', 'charges.additionalCharge']), true)]);
    }

    public function deactivate(CustomerPricing $customerPricing): JsonResponse
    {
        $customerPricing->update(['status' => 'inactive']);

        return response()->json(['message' => 'Customer pricing dinonaktifkan.', 'data' => $customerPricing]);
    }

    private function validatePricing(Request $request, ?int $ignoreId = null): array
    {
        $rules = [
            'company_id' => 'required|exists:companies,id',
            'origin_location_id' => 'required|exists:locations,id|different:destination_location_id',
            'destination_location_id' => 'required|exists:locations,id',
            'cargo_category_id' => 'required|exists:cargo_categories,id',
            'service_type' => 'required|in:lcl,fcl',
            'shipment_coverage' => 'required|in:port_to_port,door_to_port,port_to_door,door_to_door',
            'pricing_basis' => 'required|in:per_kg,per_ton,per_cbm,per_container',
            'rate' => 'required|numeric|min:0',
            'minimum_charge' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'container_type_id' => 'nullable|exists:container_types,id',
            'status' => 'sometimes|in:active,inactive',
            'remark' => 'nullable|string|max:5000',
            'charges' => 'nullable|array',
            'charges.*.additional_charge_id' => 'required|exists:additional_charges,id',
            'charges.*.charge_type' => 'required|in:fixed,percentage',
            'charges.*.amount' => 'required|numeric|min:0',
        ];

        $data = $request->validate($rules);
        $data['currency'] = $data['currency'] ?? 'IDR';
        $data['status'] = $data['status'] ?? 'active';

        if ($data['service_type'] === 'fcl' && empty($data['container_type_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'container_type_id' => ['Container type wajib untuk FCL.'],
            ]);
        }

        return $data;
    }

    private function syncCharges(CustomerPricing $pricing, array $charges): void
    {
        $pricing->charges()->delete();
        foreach ($charges as $charge) {
            CustomerPricingCharge::create([
                'customer_pricing_id' => $pricing->id,
                'additional_charge_id' => $charge['additional_charge_id'],
                'charge_type' => $charge['charge_type'],
                'amount' => $charge['amount'],
            ]);
        }
    }

    private function transformRow(CustomerPricing $p, bool $detailed = false): array
    {
        $row = [
            'id' => $p->id,
            'customer' => $p->company?->name,
            'company_id' => $p->company_id,
            'service_type' => $p->service_type,
            'cargo_category' => $p->cargoCategory?->name,
            'route' => trim(($p->originLocation?->code ?? $p->originLocation?->name).' → '.($p->destinationLocation?->code ?? $p->destinationLocation?->name)),
            'origin_location_id' => $p->origin_location_id,
            'destination_location_id' => $p->destination_location_id,
            'shipment_coverage' => $p->shipment_coverage,
            'pricing_basis' => $p->pricing_basis,
            'rate' => $p->rate,
            'minimum_charge' => $p->minimum_charge,
            'currency' => $p->currency,
            'container_type' => $p->containerType?->name,
            'container_type_id' => $p->container_type_id,
            'status' => $p->status,
            'remark' => $p->remark,
            'created_at' => $p->created_at?->toIso8601String(),
        ];

        if ($detailed) {
            $row['charges'] = $p->relationLoaded('charges')
                ? $p->charges->map(fn (CustomerPricingCharge $c) => [
                    'id' => $c->id,
                    'additional_charge_id' => $c->additional_charge_id,
                    'additional_charge' => $c->additionalCharge?->name,
                    'charge_type' => $c->charge_type,
                    'amount' => $c->amount,
                ])
                : [];
        }

        return $row;
    }
}
