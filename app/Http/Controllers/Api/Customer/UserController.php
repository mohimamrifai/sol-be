<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ChangeUserPasswordRequest;
use App\Http\Requests\Customer\ChangeUserRoleRequest;
use App\Http\Requests\Customer\ChangeUserStatusRequest;
use App\Http\Requests\Customer\StoreUserRequest;
use App\Http\Requests\Customer\UpdateUserRequest;
use App\Http\Resources\Customer\UserResource;
use App\Http\Resources\Customer\UserStatsResource;
use App\Models\CustomerLocation;
use App\Models\User;
use App\Services\CompanyActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct(
        private CompanyActivityLogger $activityLogger
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;
        $base = User::where('company_id', $companyId)->where('user_type', 'customer');

        $payload = [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('status', UserStatus::Active)->count(),
            'inactive' => (clone $base)->where('status', UserStatus::Inactive)->count(),
            'company_admin' => (clone $base)->whereHas('roles', fn ($q) => $q->where('name', UserRole::CompanyAdmin))->count(),
        ];

        return response()->json(['data' => (new UserStatsResource($payload))->resolve()]);
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $params = $request->validate([
            'search' => 'nullable|string|max:100',
            'role' => 'nullable|in:company_admin,ops_pic,finance_pic,viewer',
            'status' => 'nullable|in:active,inactive',
            'location_id' => 'nullable|integer',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = User::with(['roles:id,name', 'locationAccess:id,code,name'])
            ->where('company_id', $companyId)
            ->where('user_type', 'customer');

        if (! empty($params['search'])) {
            $term = '%'.$params['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('email', 'like', $term);
            });
        }
        if (! empty($params['role'])) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $params['role']));
        }
        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }
        if (! empty($params['location_id'])) {
            $query->whereHas('locationAccess', fn ($q) => $q->where('customer_locations.id', $params['location_id']));
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($params['per_page'] ?? 15);

        return response()->json($paginator);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $validated = $request->validated();
        $role = UserRole::from($validated['role']);

        $this->validateLocationsBelongToCompany($companyId, $validated['location_ids'] ?? []);

        $user = DB::transaction(function () use ($validated, $companyId, $role) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'user_type' => 'customer',
                'company_id' => $companyId,
                'status' => $validated['status'] ?? UserStatus::Active,
                'feature_access' => $validated['feature_access'] ?? $role->defaultFeatureAccess(),
            ]);

            $user->assignRole($role);
            $this->syncLocationAccess($user, $validated['location_ids'] ?? []);

            return $user;
        });

        $this->activityLogger->log(
            $user,
            'user_created',
            'User dibuat.',
            [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $role->value,
                'location_ids' => $validated['location_ids'] ?? [],
            ],
            $request->user()->id
        );

        return response()->json([
            'message' => 'Akun pengguna berhasil ditambahkan.',
            'data' => (new UserResource($user->load(['roles:id,name', 'locationAccess:id,code,name'])))->resolve(),
        ], 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeAccess($request, $user);

        return response()->json([
            'data' => (new UserResource($user->load(['roles:id,name', 'locationAccess:id,code,name', 'company:id,name'])))->resolve(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorizeAccess($request, $user);

        $validated = $request->validated();

        $companyId = (int) $request->user()->company_id;
        if (isset($validated['location_ids'])) {
            $this->validateLocationsBelongToCompany($companyId, $validated['location_ids']);
        }

        $before = $user->only(['name', 'phone', 'status']);
        $updateData = [];
        if (isset($validated['name'])) {
            $updateData['name'] = $validated['name'];
        }
        if (array_key_exists('phone', $validated)) {
            $updateData['phone'] = $validated['phone'];
        }
        if (isset($validated['status'])) {
            $this->guardLastAdminStatus($user, $validated['status']);
            $updateData['status'] = $validated['status'];
        }
        if (array_key_exists('feature_access', $validated)) {
            $updateData['feature_access'] = $validated['feature_access'];
        }

        DB::transaction(function () use ($user, $updateData, $validated) {
            if (! empty($updateData)) {
                $user->update($updateData);
            }
            if (isset($validated['location_ids'])) {
                $this->syncLocationAccess($user, $validated['location_ids']);
            }
        });

        $after = $user->fresh()->only(['name', 'phone', 'status']);
        $changes = [];
        foreach ($before as $key => $val) {
            $newVal = $after[$key] instanceof \BackedEnum ? $after[$key]->value : $after[$key];
            if (($val ?? '') !== ($newVal ?? '')) {
                $changes[$key] = ['old' => $val, 'new' => $newVal];
            }
        }
        if (isset($validated['location_ids'])) {
            $changes['location_ids'] = $validated['location_ids'];
        }
        if (! empty($changes)) {
            $this->activityLogger->log(
                $user,
                'user_updated',
                'User diperbarui.',
                ['changes' => $changes],
                $request->user()->id
            );
        }

        return response()->json([
            'message' => 'Akun pengguna berhasil diperbarui.',
            'data' => (new UserResource($user->fresh()->load(['roles:id,name', 'locationAccess:id,code,name'])))->resolve(),
        ]);
    }

    public function changeRole(ChangeUserRoleRequest $request, User $user): JsonResponse
    {
        $this->authorizeAccess($request, $user);

        $validated = $request->validated();
        $newRole = UserRole::from($validated['role']);
        $currentRole = $user->roles->first()?->name;

        if ($currentRole === UserRole::CompanyAdmin->value && $newRole !== UserRole::CompanyAdmin) {
            $this->guardLastCompanyAdmin($user);
        }

        DB::transaction(function () use ($user, $newRole) {
            $user->syncRoles([$newRole->value]);
            if ($newRole !== UserRole::CompanyAdmin) {
                $user->update(['feature_access' => $newRole->defaultFeatureAccess()]);
            }
        });

        $this->activityLogger->log(
            $user,
            'user_role_changed',
            'Role diubah menjadi '.$newRole->value.'.',
            ['old' => $currentRole, 'new' => $newRole->value],
            $request->user()->id
        );

        return response()->json([
            'message' => 'Role berhasil diperbarui.',
            'data' => (new UserResource($user->fresh()->load(['roles:id,name', 'locationAccess:id,code,name'])))->resolve(),
        ]);
    }

    public function changeStatus(ChangeUserStatusRequest $request, User $user): JsonResponse
    {
        $this->authorizeAccess($request, $user);

        $validated = $request->validated();
        $newStatus = UserStatus::from($validated['status']);

        $this->guardLastAdminStatus($user, $newStatus->value);

        $old = $user->status->value;
        $user->update(['status' => $newStatus]);

        $this->activityLogger->log(
            $user,
            $newStatus === UserStatus::Active ? 'user_activated' : 'user_deactivated',
            $newStatus === UserStatus::Active ? 'User diaktifkan.' : 'User dinonaktifkan.',
            ['old' => $old, 'new' => $newStatus->value],
            $request->user()->id
        );

        return response()->json([
            'message' => 'Status user berhasil diperbarui.',
            'data' => (new UserResource($user->fresh()->load(['roles:id,name', 'locationAccess:id,code,name'])))->resolve(),
        ]);
    }

    public function resetPassword(ChangeUserPasswordRequest $request, User $user): JsonResponse
    {
        $this->authorizeAccess($request, $user);

        $validated = $request->validated();
        $user->update(['password' => Hash::make($validated['password'])]);

        $this->activityLogger->log(
            $user,
            'user_password_reset',
            'Password direset.',
            [],
            $request->user()->id
        );

        return response()->json(['message' => 'Password berhasil direset.']);
    }

    public function activities(Request $request, User $user): JsonResponse
    {
        $this->authorizeAccess($request, $user);

        $activities = $user->activities()->with('actor:id,name,email')->paginate(15);

        return response()->json($activities);
    }

    private function authorizeAccess(Request $request, User $user): void
    {
        if ((int) $user->company_id !== (int) $request->user()->company_id) {
            abort(response()->json(['message' => 'User not found.'], 404));
        }
    }

    private function guardLastAdminStatus(User $user, string $newStatus): void
    {
        if ($newStatus === UserStatus::Inactive->value && $user->hasRole(UserRole::CompanyAdmin->value)) {
            $this->guardLastCompanyAdmin($user);
        }
    }

    private function guardLastCompanyAdmin(User $user): void
    {
        $activeAdmins = User::where('company_id', $user->company_id)
            ->where('user_type', 'customer')
            ->where('id', '!=', $user->id)
            ->where('status', UserStatus::Active)
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::CompanyAdmin->value))
            ->count();

        if ($activeAdmins === 0) {
            abort(response()->json([
                'message' => 'Tidak dapat menonaktifkan atau mengubah role Company Admin terakhir.',
            ], 422));
        }
    }

    private function validateLocationsBelongToCompany(int $companyId, array $locationIds): void
    {
        if (empty($locationIds)) {
            return;
        }
        $validCount = CustomerLocation::where('company_id', $companyId)
            ->whereIn('id', $locationIds)
            ->count();
        if ($validCount !== count($locationIds)) {
            abort(response()->json([
                'message' => 'Salah satu Location tidak ditemukan atau bukan milik perusahaan Anda.',
                'errors' => ['location_ids' => ['Invalid location id.']],
            ], 422));
        }
    }

    private function syncLocationAccess(User $user, array $locationIds): void
    {
        $user->locationAccess()->sync($locationIds);
    }
}
