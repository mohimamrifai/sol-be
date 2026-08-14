<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::with(['company', 'roles', 'locationAccess:id,code,name']);

        if ($request->filled('user_type')) {
            $query->where('user_type', $request->user_type);
        }

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('role')) {
            $query->role($request->role);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        $users->getCollection()->transform(fn (User $user) => $this->transformUser($user));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
            'user_type' => 'required|in:internal,customer',
            'company_id' => 'nullable|exists:companies,id',
            'status' => 'nullable|in:active,inactive,pending',
            'role' => 'required|string|exists:roles,name',
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'integer|exists:customer_locations,id',
            'feature_access' => 'nullable|array',
            'feature_access.*' => 'string',
        ]);

        $locationIds = $validated['location_ids'] ?? [];
        $featureAccess = $validated['feature_access'] ?? null;
        unset($validated['location_ids'], $validated['feature_access']);

        $roleName = $validated['role'];
        $resolvedFeatureAccess = $roleName === 'super_admin'
            ? UserRole::SuperAdmin->defaultFeatureAccess()
            : ($featureAccess ?? $this->defaultFeatureAccessForRole($roleName));

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'user_type' => $validated['user_type'],
            'company_id' => $validated['company_id'] ?? null,
            'status' => $validated['status'] ?? 'active',
            'feature_access' => $resolvedFeatureAccess,
        ]);

        $user->assignRole($validated['role']);

        if (! empty($locationIds)) {
            $user->locationAccess()->sync($locationIds);
        }

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'data' => $this->transformUser($user->load(['roles', 'locationAccess:id,code,name'])),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['company', 'roles', 'locationAccess:id,code,name']);

        return response()->json(['data' => $this->transformUser($user)]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,'.$user->id,
            'phone' => 'nullable|string|max:20',
            'user_type' => 'sometimes|in:internal,customer',
            'company_id' => 'nullable|exists:companies,id',
            'status' => 'sometimes|in:active,inactive,pending',
            'role' => 'sometimes|string|exists:roles,name',
            'password' => 'sometimes|string|min:8',
            'location_ids' => 'sometimes|array',
            'location_ids.*' => 'integer|exists:customer_locations,id',
            'feature_access' => 'sometimes|array',
            'feature_access.*' => 'string',
        ]);

        if (isset($validated['role']) && $this->isLastActiveSuperAdmin($user) && $validated['role'] !== 'super_admin') {
            return response()->json(['message' => 'Role Super Admin terakhir tidak dapat diubah.'], 422);
        }

        if (isset($validated['status']) && $validated['status'] === 'inactive' && $this->isLastActiveSuperAdmin($user)) {
            return response()->json(['message' => 'Super Admin terakhir tidak dapat dinonaktifkan.'], 422);
        }

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $role = $validated['role'] ?? null;
        $locationIds = $validated['location_ids'] ?? null;
        $featureAccess = $validated['feature_access'] ?? null;
        unset($validated['role'], $validated['location_ids'], $validated['feature_access']);

        $user->update($validated);

        if ($role) {
            $user->syncRoles([$role]);
            if ($featureAccess === null) {
                $user->update(['feature_access' => $this->defaultFeatureAccessForRole($role)]);
            }
        }

        if ($featureAccess !== null) {
            if ($user->hasRole('super_admin')) {
                $featureAccess = UserRole::SuperAdmin->defaultFeatureAccess();
            }
            $user->update(['feature_access' => $featureAccess]);
        }

        if ($locationIds !== null) {
            $user->locationAccess()->sync($locationIds);
        }

        return response()->json([
            'message' => 'User berhasil diperbarui.',
            'data' => $this->transformUser($user->load(['roles', 'locationAccess:id,code,name'])),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->user_type === 'customer' && $user->company_id) {
            $user->delete();

            return response()->json(['message' => 'User customer berhasil dihapus.']);
        }

        return response()->json(['message' => 'Fitur hapus pengguna dinonaktifkan.'], 403);
    }

    public function changeStatus(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:active,inactive,pending',
        ]);

        if ($validated['status'] === 'inactive' && $this->isLastActiveSuperAdmin($user)) {
            return response()->json(['message' => 'Super Admin terakhir tidak dapat dinonaktifkan.'], 422);
        }

        $user->update(['status' => $validated['status']]);

        return response()->json([
            'message' => 'Status user berhasil diperbarui.',
            'data' => $this->transformUser($user->fresh(['roles', 'locationAccess:id,code,name'])),
        ]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->update(['password' => Hash::make($validated['password'])]);

        return response()->json(['message' => 'Password berhasil direset.']);
    }

    private function transformUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'user_type' => $user->user_type,
            'company_id' => $user->company_id,
            'company' => $user->company,
            'roles' => $user->roles,
            'role' => $user->roles->first()?->name,
            'feature_access' => $user->feature_access,
            'location_access' => $user->relationLoaded('locationAccess') ? $user->locationAccess : [],
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }

    private function isLastActiveSuperAdmin(User $user): bool
    {
        if (! $user->hasRole('super_admin') || ! $user->isActive()) {
            return false;
        }

        return User::query()
            ->role('super_admin')
            ->where('status', 'active')
            ->where('user_type', 'internal')
            ->count() <= 1;
    }

    /**
     * @return list<string>
     */
    private function defaultFeatureAccessForRole(string $role): array
    {
        $enum = UserRole::tryFrom($role);

        return $enum?->defaultFeatureAccess() ?? [];
    }
}
