<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\LocationStatus;
use App\Enums\LocationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ChangeLocationStatusRequest;
use App\Http\Requests\Customer\StoreLocationRequest;
use App\Http\Requests\Customer\UpdateLocationRequest;
use App\Http\Resources\Customer\CustomerLocationResource;
use App\Models\Company;
use App\Models\CustomerLocation;
use App\Services\CompanyActivityLogger;
use App\Services\LocationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerLocationController extends Controller
{
    public function __construct(
        private CompanyActivityLogger $activityLogger,
        private LocationCodeService $locationCode,
    ) {}

    public function index(Request $request, Company $company): JsonResponse
    {
        $this->ensureCustomerCompany($company);

        $params = $request->validate([
            'search' => 'nullable|string|max:100',
            'type' => 'nullable|in:head_office,branch_office,warehouse',
            'status' => 'nullable|in:active,inactive',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = CustomerLocation::where('company_id', $company->id)
            ->orderBy('type')
            ->orderBy('code');

        if (! empty($params['search'])) {
            $term = '%'.$params['search'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('code', 'like', $term));
        }
        if (! empty($params['type'])) {
            $query->where('type', $params['type']);
        }
        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        return response()->json($query->paginate($params['per_page'] ?? 15));
    }

    public function store(StoreLocationRequest $request, Company $company): JsonResponse
    {
        $this->ensureCustomerCompany($company);

        $data = $request->validated();

        if ($data['type'] === LocationType::HeadOffice->value) {
            $exists = CustomerLocation::where('company_id', $company->id)
                ->where('type', LocationType::HeadOffice)
                ->exists();
            if ($exists) {
                return response()->json([
                    'message' => 'Maksimal 1 Head Office per customer.',
                    'errors' => ['type' => ['Sudah ada Head Office.']],
                ], 422);
            }
        }

        $data['status'] = $data['status'] ?? LocationStatus::Active->value;
        $data['country'] = $data['country'] ?? 'Indonesia';
        $data['company_id'] = $company->id;
        $data['code'] = $this->locationCode->next($company->id, $data['name'], $data['type']);

        $location = CustomerLocation::create($data);

        $this->activityLogger->log(
            $location,
            'location_created',
            'Location dibuat oleh admin.',
            ['code' => $location->code, 'name' => $location->name],
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Location berhasil ditambahkan.',
            'data' => new CustomerLocationResource($location),
        ], 201);
    }

    public function update(UpdateLocationRequest $request, Company $company, CustomerLocation $location): JsonResponse
    {
        $this->ensureCustomerCompany($company);
        $this->ensureLocationBelongsToCompany($company, $location);

        $data = $request->validated();

        if (isset($data['type']) && $data['type'] !== $location->type->value) {
            if ($data['type'] === LocationType::HeadOffice->value) {
                $exists = CustomerLocation::where('company_id', $company->id)
                    ->where('id', '!=', $location->id)
                    ->where('type', LocationType::HeadOffice)
                    ->exists();
                if ($exists) {
                    return response()->json(['message' => 'Maksimal 1 Head Office per customer.'], 422);
                }
            } elseif ($location->isOnlyHeadOffice()) {
                return response()->json([
                    'message' => 'Tidak dapat mengubah tipe dari satu-satunya Head Office.',
                ], 422);
            }
        }

        if (($data['status'] ?? null) === LocationStatus::Inactive->value
            && $location->isHeadOffice()
            && $location->status === LocationStatus::Active
        ) {
            $activeHeadOffices = CustomerLocation::where('company_id', $company->id)
                ->where('type', LocationType::HeadOffice)
                ->where('status', LocationStatus::Active)
                ->count();
            if ($activeHeadOffices <= 1) {
                return response()->json([
                    'message' => 'Tidak dapat menonaktifkan satu-satunya Head Office aktif.',
                ], 422);
            }
        }

        $location->update($data);

        return response()->json([
            'message' => 'Location berhasil diperbarui.',
            'data' => new CustomerLocationResource($location->fresh()),
        ]);
    }

    public function changeStatus(
        ChangeLocationStatusRequest $request,
        Company $company,
        CustomerLocation $location
    ): JsonResponse {
        $this->ensureCustomerCompany($company);
        $this->ensureLocationBelongsToCompany($company, $location);

        $data = $request->validated();

        if ($data['status'] === LocationStatus::Inactive->value && $location->isHeadOffice()) {
            $activeHeadOffices = CustomerLocation::where('company_id', $company->id)
                ->where('type', LocationType::HeadOffice)
                ->where('status', LocationStatus::Active)
                ->count();
            if ($activeHeadOffices <= 1) {
                return response()->json([
                    'message' => 'Tidak dapat menonaktifkan satu-satunya Head Office aktif.',
                ], 422);
            }
        }

        $location->update(['status' => $data['status']]);

        return response()->json([
            'message' => 'Status location diperbarui.',
            'data' => new CustomerLocationResource($location->fresh()),
        ]);
    }

    public function destroy(Company $company, CustomerLocation $location): JsonResponse
    {
        $this->ensureCustomerCompany($company);
        $this->ensureLocationBelongsToCompany($company, $location);

        if ($location->isOnlyHeadOffice()) {
            return response()->json([
                'message' => 'Tidak dapat menghapus satu-satunya Head Office.',
            ], 422);
        }

        $location->delete();

        return response()->json(['message' => 'Location berhasil dihapus.']);
    }

    private function ensureCustomerCompany(Company $company): void
    {
        if (! $company->isCustomer()) {
            abort(404);
        }
    }

    private function ensureLocationBelongsToCompany(Company $company, CustomerLocation $location): void
    {
        if ((int) $location->company_id !== (int) $company->id) {
            abort(404);
        }
    }
}
