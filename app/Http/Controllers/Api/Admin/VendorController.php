<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pricing;
use App\Models\PricingActivity;
use App\Models\Vendor;
use App\Models\VendorContact;
use App\Models\VendorService;
use App\Services\VendorCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    public function __construct(private VendorCodeGenerator $codeGenerator) {}

    // ── VENDORS ──

    public function stats(): JsonResponse
    {
        $base = Vendor::query();
        $active = (clone $base)->where('is_active', true)->count();
        $inactive = (clone $base)->where('is_active', false)->count();

        $trucking = (clone $base)->whereJsonContains('vendor_types', Vendor::TYPE_TRUCKING)->count();
        $rail = (clone $base)->whereJsonContains('vendor_types', Vendor::TYPE_RAIL)->count();
        $container = (clone $base)->whereJsonContains('vendor_types', Vendor::TYPE_CONTAINER)->count();

        return response()->json([
            'data' => [
                'total' => (clone $base)->count(),
                'active' => $active,
                'inactive' => $inactive,
                'trucking' => $trucking,
                'rail' => $rail,
                'container_provider' => $container,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Vendor::query()->withCount('vendorServices');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%")
                    ->orWhere('npwp', 'like', "%{$s}%");
            });
        }

        if ($request->filled('business_entity')) {
            $query->where('business_entity', $request->business_entity);
        }

        if ($request->filled('vendor_type')) {
            if ($request->vendor_type === 'some_both') {
                $jsonLengthFn = $query->getConnection()->getDriverName() === 'sqlite'
                    ? 'json_array_length(vendor_types)'
                    : 'JSON_LENGTH(vendor_types)';
                $query->whereRaw("{$jsonLengthFn} > 1");
            } else {
                $query->whereJsonContains('vendor_types', $request->vendor_type);
            }
        }

        if ($request->filled('status')) {
            $isActive = $request->status === 'active';
            $query->where('is_active', $isActive);
        }

        return response()->json($query->orderBy('name')->paginate($request->per_page ?? 15));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateVendor($request);
        $contacts = $data['contacts'] ?? [];
        unset($data['contacts']);

        $data['code'] = $this->codeGenerator->generate();
        $data['is_active'] = $data['is_active'] ?? true;

        $vendor = DB::transaction(function () use ($data, $contacts) {
            $vendor = Vendor::create($data);
            $this->syncContacts($vendor, $contacts);

            return $vendor->load('contacts');
        });

        return response()->json(['message' => 'Vendor berhasil dibuat.', 'data' => $vendor], 201);
    }

    public function show(Vendor $vendor): JsonResponse
    {
        $vendor->load([
            'contacts',
            'vendorServices.transportMode',
            'vendorServices.serviceType',
            'vendorServices.originLocation',
            'vendorServices.destinationLocation',
            'vendorServices.pricings.containerType',
            'vendorServices.pricings.createdBy:id,name',
        ]);
        $vendor->loadCount('vendorServices');

        return response()->json([
            'data' => array_merge($vendor->toArray(), [
                'is_used_in_transactions' => $vendor->isUsedInTransactions(),
            ]),
        ]);
    }

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $this->validateVendor($request, $vendor->id, partial: true);
        $contacts = $data['contacts'] ?? null;
        unset($data['contacts']);

        DB::transaction(function () use ($vendor, $data, $contacts) {
            $vendor->update($data);
            if ($contacts !== null) {
                $this->syncContacts($vendor, $contacts);
            }
        });

        return response()->json([
            'message' => 'Vendor berhasil diperbarui.',
            'data' => $vendor->fresh(['contacts']),
        ]);
    }

    public function destroy(Vendor $vendor): JsonResponse
    {
        if ($vendor->isUsedInTransactions()) {
            return response()->json([
                'message' => 'Vendor yang sudah dipakai transaksi tidak dapat dihapus. Nonaktifkan vendor terlebih dahulu.',
            ], 422);
        }

        $vendor->delete();

        return response()->json(['message' => 'Vendor dihapus.']);
    }

    public function deactivate(Vendor $vendor): JsonResponse
    {
        $vendor->update(['is_active' => false]);

        return response()->json(['message' => 'Vendor dinonaktifkan.', 'data' => $vendor]);
    }

    // ── VENDOR CONTACTS ──

    public function storeContact(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'position' => 'nullable|string|max:120',
            'email' => 'nullable|email|max:255',
            'mobile' => 'required|string|max:30',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $contact = DB::transaction(function () use ($vendor, $data) {
            if (! empty($data['is_primary'])) {
                $vendor->contacts()->update(['is_primary' => false]);
            }

            return $vendor->contacts()->create($data);
        });

        return response()->json(['data' => $contact], 201);
    }

    public function updateContact(Request $request, Vendor $vendor, VendorContact $contact): JsonResponse
    {
        abort_unless($contact->vendor_id === $vendor->id, 404);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'position' => 'nullable|string|max:120',
            'email' => 'nullable|email|max:255',
            'mobile' => 'sometimes|string|max:30',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ]);

        DB::transaction(function () use ($vendor, $contact, $data) {
            if (! empty($data['is_primary'])) {
                $vendor->contacts()->where('id', '!=', $contact->id)->update(['is_primary' => false]);
            }
            $contact->update($data);
        });

        return response()->json(['data' => $contact->fresh()]);
    }

    public function destroyContact(Vendor $vendor, VendorContact $contact): JsonResponse
    {
        abort_unless($contact->vendor_id === $vendor->id, 404);
        $contact->delete();

        return response()->json(['message' => 'Kontak dihapus.']);
    }

    // ── VENDOR SERVICES (legacy) ──

    public function storeService(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'transport_mode_id' => 'required|exists:transport_modes,id',
            'service_type_id' => 'required|exists:service_types,id',
            'origin_location_id' => 'required|exists:locations,id',
            'destination_location_id' => 'required|exists:locations,id',
            'is_active' => 'boolean',
        ]);

        $exists = $vendor->vendorServices()
            ->where('transport_mode_id', $data['transport_mode_id'])
            ->where('service_type_id', $data['service_type_id'])
            ->where('origin_location_id', $data['origin_location_id'])
            ->where('destination_location_id', $data['destination_location_id'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Layanan (lane & service) ini sudah ada untuk vendor tersebut.'], 422);
        }

        $svc = $vendor->vendorServices()->create($data);

        return response()->json(['data' => $svc], 201);
    }

    // ── PRICING (legacy nested) ──

    public function storePricing(Request $request, VendorService $vendorService): JsonResponse
    {
        $data = $request->validate([
            'container_type_id' => 'nullable|exists:container_types,id',
            'price_type' => 'required|in:buy,sell',
            'price_per_kg' => 'nullable|numeric|min:0',
            'price_per_cbm' => 'nullable|numeric|min:0',
            'price_per_container' => 'nullable|numeric|min:0',
            'minimum_charge' => 'nullable|numeric|min:0',
            'min_kg' => 'nullable|integer|min:0',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        $pricing = $vendorService->pricings()->create(array_merge($data, [
            'created_by_id' => $request->user()?->id,
            'price_type' => $data['price_type'] ?? 'buy',
        ]));
        $pricing->update(['pricing_group_id' => $pricing->id]);
        PricingActivity::create([
            'pricing_group_id' => $pricing->id,
            'pricing_id' => $pricing->id,
            'user_id' => $request->user()?->id,
            'activity' => 'Pricing dibuat.',
        ]);

        return response()->json(['data' => $pricing], 201);
    }

    public function updatePricing(Request $request, Pricing $pricing): JsonResponse
    {
        $data = $request->validate([
            'is_active' => 'sometimes|boolean',
            'remark' => 'nullable|string|max:2000',
        ]);

        $wasActive = $pricing->is_active;
        $oldRemark = $pricing->remark;
        $pricing->update($data);

        if (isset($data['is_active']) && $wasActive && ! $data['is_active']) {
            $this->logPricingActivity($pricing, 'Pricing diubah menjadi Inactive.', $request->user()?->id);
        }
        if (isset($data['remark']) && $data['remark'] !== $oldRemark) {
            $this->logPricingActivity($pricing, 'Remark diperbarui.', $request->user()?->id);
        }

        return response()->json(['data' => $pricing->fresh()]);
    }

    public function destroyPricing(Pricing $pricing): JsonResponse
    {
        return response()->json(['message' => 'Pricing tidak dapat dihapus. Nonaktifkan pricing jika tidak digunakan lagi.'], 403);
    }

    private function validateVendor(Request $request, ?int $vendorId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $vendorTypes = [Vendor::TYPE_TRUCKING, Vendor::TYPE_RAIL, Vendor::TYPE_CONTAINER];

        $rules = [
            'name' => "{$required}|string|max:255",
            'business_entity' => "{$required}|in:company,individual",
            'vendor_types' => "{$required}|array|min:1",
            'vendor_types.*' => ['string', Rule::in($vendorTypes)],
            'vendor_category' => 'nullable|string|max:30',
            'npwp' => "{$required}|string|max:30",
            'email' => "{$required}|email|max:255",
            'phone' => "{$required}|string|max:30",
            'website' => 'nullable|string|max:255',
            'remark' => 'nullable|string|max:2000',
            'is_active' => 'boolean',
            'address' => "{$required}|string",
            'country' => "{$required}|string|max:80",
            'province' => "{$required}|string|max:120",
            'city' => "{$required}|string|max:120",
            'district' => "{$required}|string|max:120",
            'postal_code' => "{$required}|string|max:20",
            'payment_terms' => "{$required}|in:cod,7_days,14_days,30_days,45_days",
            'payment_method' => "{$required}|in:transfer,giro,cash,virtual_account",
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:40',
            'account_holder' => 'nullable|string|max:255',
            'tax_status' => 'nullable|in:pkp,non_pkp',
            'contacts' => 'nullable|array',
            'contacts.*.id' => 'nullable|integer',
            'contacts.*.name' => 'required_with:contacts|string|max:255',
            'contacts.*.position' => 'nullable|string|max:120',
            'contacts.*.email' => 'nullable|email|max:255',
            'contacts.*.mobile' => 'required_with:contacts|string|max:30',
            'contacts.*.is_primary' => 'boolean',
            'contacts.*.is_active' => 'boolean',
        ];

        if ($request->input('payment_method') === 'transfer') {
            $rules['bank_name'] = "{$required}|string|max:255";
            $rules['bank_account_number'] = "{$required}|string|max:40";
            $rules['account_holder'] = "{$required}|string|max:255";
        }

        return $request->validate($rules);
    }

    private function syncContacts(Vendor $vendor, array $contacts): void
    {
        $keepIds = [];
        $hasPrimary = collect($contacts)->contains(fn ($c) => ! empty($c['is_primary']));

        foreach ($contacts as $index => $row) {
            $payload = [
                'name' => $row['name'],
                'position' => $row['position'] ?? null,
                'email' => $row['email'] ?? null,
                'mobile' => $row['mobile'],
                'is_primary' => $hasPrimary ? ! empty($row['is_primary']) : $index === 0,
                'is_active' => $row['is_active'] ?? true,
            ];

            if (! empty($row['id'])) {
                $contact = $vendor->contacts()->where('id', $row['id'])->first();
                if ($contact) {
                    $contact->update($payload);
                    $keepIds[] = $contact->id;

                    continue;
                }
            }

            $created = $vendor->contacts()->create($payload);
            $keepIds[] = $created->id;
        }

        if ($keepIds !== []) {
            $vendor->contacts()->whereNotIn('id', $keepIds)->delete();
        } elseif ($contacts === []) {
            $vendor->contacts()->delete();
        }
    }

    private function logPricingActivity(Pricing $pricing, string $activity, ?int $userId): void
    {
        PricingActivity::create([
            'pricing_group_id' => $pricing->pricing_group_id ?? $pricing->id,
            'pricing_id' => $pricing->id,
            'user_id' => $userId,
            'activity' => $activity,
        ]);
    }
}
