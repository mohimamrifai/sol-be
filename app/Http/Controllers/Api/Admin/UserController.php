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

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'user_type' => $validated['user_type'],
            'company_id' => $validated['company_id'] ?? null,
            'status' => $validated['status'] ?? 'active',
            'feature_access' => $featureAccess ?? $this->defaultFeatureAccessForRole($validated['role']),
        ]);

        $user->assignRole($validated['role']);

        if (! empty($locationIds)) {
            $user->locationAccess()->sync($locationIds);
        }

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'data' => $user->load(['roles', 'locationAccess:id,code,name']),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['company', 'roles', 'locationAccess:id,code,name']);

        return response()->json(['data' => $user]);
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
            $user->update(['feature_access' => $featureAccess]);
        }

        if ($locationIds !== null) {
            $user->locationAccess()->sync($locationIds);
        }

        return response()->json([
            'message' => 'User berhasil diperbarui.',
            'data' => $user->load(['roles', 'locationAccess:id,code,name']),
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

        $user->update(['status' => $validated['status']]);

        return response()->json([
            'message' => 'Status user berhasil diperbarui.',
            'data' => $user->fresh(['roles', 'locationAccess:id,code,name']),
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

    /**
     * @return list<string>
     */
    private function defaultFeatureAccessForRole(string $role): array
    {
        $enum = UserRole::tryFrom($role);

        return $enum?->defaultFeatureAccess() ?? [];
    }
}
