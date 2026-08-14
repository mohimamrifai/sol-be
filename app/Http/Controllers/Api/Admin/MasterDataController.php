<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdditionalCharge;
use App\Models\AdminActivityLog;
use App\Models\AdditionalService;
use App\Models\CargoCategory;
use App\Models\ContainerType;
use App\Models\DgClass;
use App\Models\Location;
use App\Models\ServiceType;
use App\Models\Train;
use App\Models\TrainCar;
use App\Models\TransportMode;
use App\Services\AdminActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterDataController extends Controller
{
    public function __construct(private AdminActivityLogger $activityLogger) {}

    // ── LOCATIONS ──
    public function locations(Request $request): JsonResponse
    {
        $query = Location::query();
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%");
            });
        }
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }
        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        return response()->json($query->orderBy('name')->paginate($request->per_page ?? 15));
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:20|unique:locations,code',
            'type' => 'required|in:port,city,hub,warehouse,station,airport,terminal',
            'city' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        return response()->json(['data' => Location::create($data)], 201);
    }

    public function updateLocation(Request $request, Location $location): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => "nullable|string|max:20|unique:locations,code,{$location->id}",
            'type' => 'sometimes|in:port,city,hub,warehouse,station,airport,terminal',
            'city' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ]);
        $location->update($data);

        return response()->json(['data' => $location]);
    }

    public function destroyLocation(Location $location): JsonResponse
    {
        $location->delete();

        return response()->json(['message' => 'Lokasi berhasil dihapus.']);
    }

    // ── TRANSPORT MODES ──
    public function transportModes(Request $request): JsonResponse
    {
        $query = TransportMode::with('serviceTypes')->orderBy('name');
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%");
            });
        }
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        return response()->json($query->paginate($request->per_page ?? 15));
    }

    public function storeTransportMode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:10|unique:transport_modes,code',
            'is_active' => 'boolean',
        ]);

        return response()->json(['data' => TransportMode::create($data)], 201);
    }

    public function updateTransportMode(Request $request, TransportMode $transportMode): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => "nullable|string|max:10|unique:transport_modes,code,{$transportMode->id}",
            'is_active' => 'boolean',
        ]);
        $transportMode->update($data);

        // Jika transport mode dinonaktifkan, maka semua service types yang berelasi juga dinonaktifkan
        if (isset($data['is_active']) && $data['is_active'] === false) {
            $transportMode->serviceTypes()->update(['is_active' => false]);
        }

        return response()->json(['data' => $transportMode]);
    }

    public function destroyTransportMode(TransportMode $transportMode): JsonResponse
    {
        $transportMode->delete();

        return response()->json(['message' => 'Transport mode berhasil dihapus.']);
    }

    // ── SERVICE TYPES ──
    public function serviceTypesStats(): JsonResponse
    {
        $base = ServiceType::query();

        return response()->json([
            'data' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('is_active', true)->count(),
                'inactive' => (clone $base)->where('is_active', false)->count(),
            ],
        ]);
    }

    public function serviceTypes(Request $request): JsonResponse
    {
        $query = ServiceType::with('transportMode');
        if ($request->filled('transport_mode_id')) {
            $query->where('transport_mode_id', $request->transport_mode_id);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%");
            });
        }
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        return response()->json($query->orderBy('name')->paginate($request->per_page ?? 15));
    }

    public function storeServiceType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transport_mode_id' => 'required|exists:transport_modes,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:10',
            'service_category' => 'nullable|in:rail_freight,pickup_trucking,delivery_trucking,container_rental,lift_on,lift_off,storage,other',
            'pricing_basis' => 'nullable|in:per_trip,per_container,per_ton,per_kg,per_cbm',
            'description' => 'nullable|string',
            'pricing_multiplier' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ], [], [
            'transport_mode_id' => 'moda transport',
        ]);

        return response()->json(['data' => ServiceType::create($data)], 201);
    }

    public function updateServiceType(Request $request, ServiceType $serviceType): JsonResponse
    {
        $data = $request->validate([
            'transport_mode_id' => 'sometimes|exists:transport_modes,id',
            'name' => 'sometimes|string|max:255',
            'code' => 'nullable|string|max:10',
            'service_category' => 'nullable|in:rail_freight,pickup_trucking,delivery_trucking,container_rental,lift_on,lift_off,storage,other',
            'pricing_basis' => 'nullable|in:per_trip,per_container,per_ton,per_kg,per_cbm',
            'description' => 'nullable|string',
            'pricing_multiplier' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ], [], [
            'transport_mode_id' => 'moda transport',
        ]);
        $serviceType->update($data);

        return response()->json(['data' => $serviceType]);
    }

    public function destroyServiceType(ServiceType $serviceType): JsonResponse
    {
        $serviceType->delete();

        return response()->json(['message' => 'Service type berhasil dihapus.']);
    }

    // ── CONTAINER TYPES ──
    public function containerTypesStats(): JsonResponse
    {
        $base = ContainerType::query();

        return response()->json([
            'data' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('is_active', true)->count(),
                'inactive' => (clone $base)->where('is_active', false)->count(),
            ],
        ]);
    }

    public function containerTypes(Request $request): JsonResponse
    {
        $query = ContainerType::query();
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('size', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%");
            });
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        return response()->json($query->orderBy('size')->paginate($request->per_page ?? 15));
    }

    public function storeContainerType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'size' => 'required|string|max:10',
            'category' => 'required|in:dry,high_cube,reefer,open_top,flat_rack,tank,other',
            'iso_code' => 'nullable|string|max:20',
            'capacity_weight' => 'nullable|numeric',
            'capacity_cbm' => 'nullable|numeric',
            'length' => 'nullable|numeric',
            'width' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'is_active' => 'boolean',
            'remark' => 'nullable|string|max:5000',
        ]);

        return response()->json(['data' => ContainerType::create($data)], 201);
    }

    public function updateContainerType(Request $request, ContainerType $containerType): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'size' => 'sometimes|string|max:10',
            'category' => 'sometimes|in:dry,high_cube,reefer,open_top,flat_rack,tank,other',
            'iso_code' => 'nullable|string|max:20',
            'capacity_weight' => 'nullable|numeric',
            'capacity_cbm' => 'nullable|numeric',
            'length' => 'nullable|numeric',
            'width' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'is_active' => 'boolean',
            'remark' => 'nullable|string|max:5000',
        ]);
        $containerType->update($data);

        return response()->json(['data' => $containerType]);
    }

    public function destroyContainerType(ContainerType $containerType): JsonResponse
    {
        $containerType->delete();

        return response()->json(['message' => 'Container type berhasil dihapus.']);
    }

    // ── ADDITIONAL SERVICES ──
    public function additionalServices(Request $request): JsonResponse
    {
        $query = AdditionalService::query();
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where('name', 'like', "%{$s}%");
        }
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        return response()->json($query->orderBy('category')->orderBy('name')->paginate($request->per_page ?? 15));
    }

    public function storeAdditionalService(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:pickup,packing,handling,other',
            'description' => 'nullable|string',
            'base_price' => 'required|numeric|min:0',
            'is_active' => 'boolean',
        ], [], [
            'name' => 'nama',
            'category' => 'kategori',
            'base_price' => 'harga dasar',
        ]);

        return response()->json(['data' => AdditionalService::create($data)], 201);
    }

    public function updateAdditionalService(Request $request, AdditionalService $additionalService): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|in:pickup,packing,handling,other',
            'description' => 'nullable|string',
            'base_price' => 'sometimes|required|numeric|min:0',
            'is_active' => 'boolean',
        ], [], [
            'name' => 'nama',
            'category' => 'kategori',
            'base_price' => 'harga dasar',
        ]);
        $additionalService->update($data);

        return response()->json(['data' => $additionalService]);
    }

    public function destroyAdditionalService(AdditionalService $additionalService): JsonResponse
    {
        $additionalService->delete();

        return response()->json(['message' => 'Additional service berhasil dihapus.']);
    }

    // ── TRAINS ──
    public function trains(Request $request): JsonResponse
    {
        $query = Train::query();
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%");
            });
        }
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        return response()->json($query->orderBy('name')->paginate($request->per_page ?? 15));
    }

    public function storeTrain(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:30|unique:trains,code',
            'is_active' => 'boolean',
        ]);

        return response()->json(['data' => Train::create($data)], 201);
    }

    public function updateTrain(Request $request, Train $train): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => "nullable|string|max:30|unique:trains,code,{$train->id}",
            'is_active' => 'boolean',
        ]);
        $train->update($data);

        return response()->json(['data' => $train]);
    }

    public function destroyTrain(Train $train): JsonResponse
    {
        $train->delete();

        return response()->json(['message' => 'Kereta berhasil dihapus.']);
    }

    // ── TRAIN CARS ──
    public function trainCars(Request $request): JsonResponse
    {
        $query = TrainCar::query()->with('train:id,name');

        if ($request->filled('train_id')) {
            $query->where('train_id', $request->train_id);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%");
            });
        }

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        return response()->json($query->orderBy('train_id')->orderBy('name')->paginate($request->per_page ?? 15));
    }

    public function storeTrainCar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'train_id' => 'required|exists:trains,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:30',
            'capacity_weight' => 'nullable|numeric|min:0',
            'capacity_cbm' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        if (! empty($data['code'])) {
            $request->validate([
                'code' => "unique:train_cars,code,NULL,id,train_id,{$data['train_id']}",
            ]);
        }

        return response()->json(['data' => TrainCar::create($data)], 201);
    }

    public function updateTrainCar(Request $request, TrainCar $trainCar): JsonResponse
    {
        $data = $request->validate([
            'train_id' => 'sometimes|exists:trains,id',
            'name' => 'sometimes|string|max:255',
            'code' => 'nullable|string|max:30',
            'capacity_weight' => 'nullable|numeric|min:0',
            'capacity_cbm' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $trainId = $data['train_id'] ?? $trainCar->train_id;
        $code = array_key_exists('code', $data) ? $data['code'] : $trainCar->code;
        if (! empty($code)) {
            $request->validate([
                'code' => "unique:train_cars,code,{$trainCar->id},id,train_id,{$trainId}",
            ]);
        }

        $trainCar->update($data);

        return response()->json(['data' => $trainCar->load('train:id,name')]);
    }

    public function destroyTrainCar(TrainCar $trainCar): JsonResponse
    {
        $trainCar->delete();

        return response()->json(['message' => 'Gerbong berhasil dihapus.']);
    }

    // ── CARGO CATEGORIES ──
    public function cargoCategories(Request $request): JsonResponse
    {
        $query = CargoCategory::query();
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%");
            });
        }
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        return response()->json($query->orderBy('name')->paginate($request->per_page ?? 15));
    }

    public function storeCargoCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:20|unique:cargo_categories,code',
            'description' => 'nullable|string',
            'pricing_multiplier' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'requires_temperature' => 'boolean',
            'is_project_cargo' => 'boolean',
            'is_liquid' => 'boolean',
            'is_food' => 'boolean',
        ], [], [
            'name' => 'nama',
            'code' => 'kode',
        ]);

        return response()->json(['data' => CargoCategory::create($data)], 201);
    }

    public function updateCargoCategory(Request $request, CargoCategory $cargoCategory): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => "nullable|string|max:20|unique:cargo_categories,code,{$cargoCategory->id}",
            'description' => 'nullable|string',
            'pricing_multiplier' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'requires_temperature' => 'boolean',
            'is_project_cargo' => 'boolean',
            'is_liquid' => 'boolean',
            'is_food' => 'boolean',
        ], [], [
            'name' => 'nama',
            'code' => 'kode',
        ]);
        $cargoCategory->update($data);

        return response()->json(['data' => $cargoCategory]);
    }

    public function destroyCargoCategory(CargoCategory $cargoCategory): JsonResponse
    {
        $cargoCategory->delete();

        return response()->json(['message' => 'Kategori kargo berhasil dihapus.']);
    }

    // ── DG CLASSES ──
    public function dgClasses(Request $request): JsonResponse
    {
        $query = DgClass::query();
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%");
        }

        return response()->json($query->orderBy('code')->paginate($request->per_page ?? 15));
    }

    public function storeDgClass(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:20|unique:dg_classes,code',
            'description' => 'nullable|string',
            'pricing_multiplier' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        return response()->json(['data' => DgClass::create($data)], 201);
    }

    public function updateDgClass(Request $request, DgClass $dgClass): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => "sometimes|string|max:20|unique:dg_classes,code,{$dgClass->id}",
            'description' => 'nullable|string',
            'pricing_multiplier' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);
        $dgClass->update($data);

        return response()->json(['data' => $dgClass]);
    }

    public function destroyDgClass(DgClass $dgClass): JsonResponse
    {
        $dgClass->delete();

        return response()->json(['message' => 'DG Class berhasil dihapus.']);
    }

    // ── ADDITIONAL CHARGES ──
    public function additionalChargesStats(): JsonResponse
    {
        $base = AdditionalCharge::query();

        return response()->json([
            'data' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('is_active', true)->count(),
                'inactive' => (clone $base)->where('is_active', false)->count(),
            ],
        ]);
    }

    public function additionalCharges(Request $request): JsonResponse
    {
        $query = AdditionalCharge::query();
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%");
            });
        }
        if ($request->filled('charge_category')) {
            $query->where('charge_category', $request->charge_category);
        }
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $paginated = $query->orderBy('name')->paginate($request->per_page ?? 15);
        $paginated->getCollection()->transform(fn (AdditionalCharge $charge) => $this->transformAdditionalCharge($charge));

        return response()->json($paginated);
    }

    public function showAdditionalCharge(AdditionalCharge $additionalCharge): JsonResponse
    {
        return response()->json([
            'data' => [
                ...$this->transformAdditionalCharge($additionalCharge),
                'activity_log' => AdminActivityLog::query()
                    ->with('actor:id,name')
                    ->where('module', 'additional_charge')
                    ->where('subject_type', $additionalCharge->getMorphClass())
                    ->where('subject_id', $additionalCharge->id)
                    ->orderByDesc('occurred_at')
                    ->limit(20)
                    ->get()
                    ->map(fn (AdminActivityLog $log) => [
                        'description' => $log->description,
                        'user' => $log->actor?->name,
                        'occurred_at' => $log->occurred_at?->toIso8601String(),
                    ]),
            ],
        ]);
    }

    public function storeAdditionalCharge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'charge_category' => 'required|in:handling,storage,documentation,container,trucking,rail,other',
            'pricing_basis' => 'required|in:per_shipment,per_container,per_trip,per_ton,per_kg,per_cbm,per_day,per_hour,per_seal,per_document',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $charge = AdditionalCharge::create($data);

        $this->activityLogger->log(
            'additional_charge',
            'Additional Charge dibuat.',
            $charge,
            'created',
            null,
            $request->user()?->id
        );

        return response()->json(['data' => $this->transformAdditionalCharge($charge)], 201);
    }

    public function updateAdditionalCharge(Request $request, AdditionalCharge $additionalCharge): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'charge_category' => 'sometimes|in:handling,storage,documentation,container,trucking,rail,other',
            'pricing_basis' => 'sometimes|in:per_shipment,per_container,per_trip,per_ton,per_kg,per_cbm,per_day,per_hour,per_seal,per_document',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $changes = [];
        foreach (['name', 'charge_category', 'pricing_basis', 'description', 'is_active'] as $field) {
            if (array_key_exists($field, $data) && (string) ($additionalCharge->{$field}?->value ?? $additionalCharge->{$field}) !== (string) ($data[$field]?->value ?? $data[$field])) {
                $changes[] = $field;
            }
        }

        $additionalCharge->update($data);

        if (in_array('pricing_basis', $changes, true)) {
            $this->activityLogger->log(
                'additional_charge',
                'Pricing Basis diperbarui.',
                $additionalCharge,
                'updated',
                null,
                $request->user()?->id
            );
        }

        if (in_array('is_active', $changes, true) && ! ($data['is_active'] ?? $additionalCharge->is_active)) {
            $this->activityLogger->log(
                'additional_charge',
                'Status diubah menjadi Inactive.',
                $additionalCharge,
                'deactivated',
                null,
                $request->user()?->id
            );
        } elseif ($changes !== []) {
            $this->activityLogger->log(
                'additional_charge',
                'Additional Charge diperbarui.',
                $additionalCharge,
                'updated',
                null,
                $request->user()?->id
            );
        }

        return response()->json(['data' => $this->transformAdditionalCharge($additionalCharge)]);
    }

    public function deactivateAdditionalCharge(Request $request, AdditionalCharge $additionalCharge): JsonResponse
    {
        $additionalCharge->update(['is_active' => false]);

        $this->activityLogger->log(
            'additional_charge',
            'Status diubah menjadi Inactive.',
            $additionalCharge,
            'deactivated',
            null,
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Additional Charge dinonaktifkan.',
            'data' => $this->transformAdditionalCharge($additionalCharge),
        ]);
    }

    public function destroyAdditionalCharge(AdditionalCharge $additionalCharge): JsonResponse
    {
        return response()->json([
            'message' => 'Additional Charge tidak dapat dihapus. Gunakan Inactive.',
        ], 422);
    }

    /** @return array<string, mixed> */
    private function transformAdditionalCharge(AdditionalCharge $charge): array
    {
        return [
            'id' => $charge->id,
            'name' => $charge->name,
            'code' => $charge->code,
            'charge_category' => $charge->charge_category?->value,
            'charge_category_label' => $charge->charge_category?->label(),
            'pricing_basis' => $charge->pricing_basis?->value,
            'pricing_basis_label' => $charge->pricing_basis?->label(),
            'description' => $charge->description,
            'is_active' => $charge->is_active,
        ];
    }
}
