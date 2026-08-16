<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Enums\LocationStatus;
use App\Enums\LocationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ChangeLocationStatusRequest;
use App\Http\Requests\Customer\StoreLocationRequest;
use App\Http\Requests\Customer\UpdateLocationRequest;
use App\Http\Resources\Customer\CompanyActivityResource;
use App\Http\Resources\Customer\CustomerLocationResource;
use App\Http\Resources\Customer\LocationStatsResource;
use App\Models\CustomerLocation;
use App\Services\CompanyActivityLogger;
use App\Services\LocationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerLocationController extends Controller
{
    private const ADDRESS_FIELDS = [
        'country',
        'province',
        'city',
        'district',
        'postal_code',
        'address',
    ];

    private const PIC_FIELDS = [
        'pic_name',
        'pic_email',
        'pic_mobile',
    ];

    public function __construct(
        private CompanyActivityLogger $activityLogger,
        private LocationCodeService $locationCode,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $base = CustomerLocation::where('company_id', $companyId);
        $payload = [
            'total' => (clone $base)->count(),
            'head_office' => (clone $base)->where('type', LocationType::HeadOffice)->count(),
            'branch_office' => (clone $base)->where('type', LocationType::BranchOffice)->count(),
            'warehouse' => (clone $base)->where('type', LocationType::Warehouse)->count(),
            'active' => (clone $base)->where('status', LocationStatus::Active)->count(),
        ];

        return response()->json(['data' => new LocationStatsResource($payload)]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $params = $request->validate([
            'search' => 'nullable|string|max:100',
            'type' => 'nullable|in:head_office,branch_office,warehouse',
            'status' => 'nullable|in:active,inactive',
            'province' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = CustomerLocation::where('company_id', $user->company_id)
            ->orderBy('type')
            ->orderBy('code');

        if (! empty($params['search'])) {
            $term = '%'.$params['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('code', 'like', $term);
            });
        }
        if (! empty($params['type'])) {
            $query->where('type', $params['type']);
        }
        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }
        if (! empty($params['province'])) {
            $query->where('province', $params['province']);
        }
        if (! empty($params['city'])) {
            $query->where('city', $params['city']);
        }

        $paginator = $query->paginate($params['per_page'] ?? 15);

        return response()->json($paginator);
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $user = $request->user();
        $companyId = (int) $user->company_id;
        if (! $companyId) {
            return response()->json(['message' => 'User tidak memiliki perusahaan.'], 422);
        }

        $data = $request->validated();

        if ($data['type'] === LocationType::HeadOffice->value) {
            $existing = CustomerLocation::where('company_id', $companyId)
                ->where('type', LocationType::HeadOffice)
                ->exists();
            if ($existing) {
                return response()->json([
                    'message' => 'Maksimal 1 Head Office per perusahaan.',
                    'errors' => ['type' => ['Sudah ada Head Office. Gunakan tipe lain.']],
                ], 422);
            }
        }

        $data['status'] = $data['status'] ?? LocationStatus::Active->value;
        $data['country'] = $data['country'] ?? 'Indonesia';
        $data['company_id'] = $companyId;
        $data['code'] = $this->locationCode->next($companyId);

        $location = CustomerLocation::create($data);

        $this->activityLogger->log(
            $location,
            'location_created',
            'Location dibuat.',
            ['code' => $location->code, 'name' => $location->name, 'type' => $location->type->value],
            $user->id
        );

        return response()->json([
            'message' => 'Location berhasil ditambahkan.',
            'data' => new CustomerLocationResource($location),
        ], 201);
    }

    public function show(Request $request, CustomerLocation $location): JsonResponse
    {
        $user = $request->user();
        if ((int) $location->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Location not found.'], 404);
        }

        $location->load('company:id,name,company_code');

        return response()->json([
            'data' => new CustomerLocationResource($location),
        ]);
    }

    public function update(UpdateLocationRequest $request, CustomerLocation $location): JsonResponse
    {
        $user = $request->user();
        if ((int) $location->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Location not found.'], 404);
        }

        $data = $request->validated();

        if (isset($data['type']) && $data['type'] !== $location->type->value) {
            if ($data['type'] === LocationType::HeadOffice->value) {
                $existing = CustomerLocation::where('company_id', $location->company_id)
                    ->where('id', '!=', $location->id)
                    ->where('type', LocationType::HeadOffice)
                    ->exists();
                if ($existing) {
                    return response()->json([
                        'message' => 'Maksimal 1 Head Office per perusahaan.',
                        'errors' => ['type' => ['Sudah ada Head Office lain.']],
                    ], 422);
                }
            } else {
                $headOfficeCount = CustomerLocation::where('company_id', $location->company_id)
                    ->where('type', LocationType::HeadOffice)
                    ->count();
                if ($headOfficeCount <= 1 && $location->isHeadOffice()) {
                    return response()->json([
                        'message' => 'Tidak dapat mengubah tipe dari Head Office ketika ini satu-satunya.',
                        'errors' => ['type' => ['Tambahkan Head Office lain sebelum mengubah tipe ini.']],
                    ], 422);
                }
            }
        }

        $before = $location->only(array_keys($data));
        DB::transaction(function () use ($location, $data) {
            $location->update($data);
        });
        $after = $location->fresh()->only(array_keys($data));

        $changes = [];
        foreach ($before as $key => $val) {
            $newVal = $after[$key] instanceof \BackedEnum ? $after[$key]->value : $after[$key];
            $oldVal = $val instanceof \BackedEnum ? $val->value : $val;
            if (($oldVal ?? '') !== ($newVal ?? '')) {
                $changes[$key] = ['old' => $oldVal, 'new' => $newVal];
            }
        }

        if ($changes !== []) {
            $this->logLocationChanges($location, $changes, $user->id);
        }

        return response()->json([
            'message' => 'Location berhasil diperbarui.',
            'data' => new CustomerLocationResource($location->fresh()),
        ]);
    }

    public function changeStatus(ChangeLocationStatusRequest $request, CustomerLocation $location): JsonResponse
    {
        $user = $request->user();
        if ((int) $location->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Location not found.'], 404);
        }

        $data = $request->validated();
        $oldStatus = $location->status->value;

        if ($oldStatus === $data['status']) {
            return response()->json([
                'message' => 'Status Location berhasil diperbarui.',
                'data' => new CustomerLocationResource($location),
            ]);
        }

        $location->update(['status' => $data['status']]);

        $this->activityLogger->log(
            $location,
            'location_status_changed',
            $this->statusActivityMessage($data['status']),
            ['old' => $oldStatus, 'new' => $data['status']],
            $user->id
        );

        return response()->json([
            'message' => 'Status Location berhasil diperbarui.',
            'data' => new CustomerLocationResource($location->fresh()),
        ]);
    }

    public function activities(Request $request, CustomerLocation $location): JsonResponse
    {
        $user = $request->user();
        if ((int) $location->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Location not found.'], 404);
        }

        $activities = $location->activities()
            ->with('actor:id,name,email')
            ->orderByDesc('occurred_at')
            ->paginate($request->integer('per_page', 15));

        return CompanyActivityResource::collection($activities)->response();
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    private function logLocationChanges(CustomerLocation $location, array $changes, int $userId): void
    {
        $addressChanges = array_intersect_key($changes, array_flip(self::ADDRESS_FIELDS));
        $picChanges = array_intersect_key($changes, array_flip(self::PIC_FIELDS));
        $statusChange = $changes['status'] ?? null;

        if ($addressChanges !== []) {
            $this->activityLogger->log(
                $location,
                'location_address_updated',
                'Alamat Location diperbarui.',
                ['changes' => $addressChanges],
                $userId
            );
        }

        if ($picChanges !== []) {
            $this->activityLogger->log(
                $location,
                'location_pic_updated',
                'PIC diperbarui.',
                ['changes' => $picChanges],
                $userId
            );
        }

        if ($statusChange !== null) {
            $this->activityLogger->log(
                $location,
                'location_status_changed',
                $this->statusActivityMessage((string) $statusChange['new']),
                ['old' => $statusChange['old'], 'new' => $statusChange['new']],
                $userId
            );
        }
    }

    private function statusActivityMessage(string $status): string
    {
        $label = ucfirst($status);

        return "Status diubah menjadi {$label}.";
    }
}
