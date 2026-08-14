<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\User;
use App\Services\AdminActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleManagementController extends Controller
{
    public function __construct(
        private AdminActivityLogger $activityLogger,
    ) {}
    public function stats(): JsonResponse
    {
        $base = Role::query()->where('guard_name', 'web');

        return response()->json([
            'data' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('is_active', true)->count(),
                'inactive' => (clone $base)->where('is_active', false)->count(),
            ],
        ]);
    }

    /**
     * List all roles with their permissions.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Role::query()->with('permissions')->where('guard_name', 'web');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
        }
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $roles = $query->get()->map(fn (Role $role) => $this->transformRole($role));

        return response()->json(['data' => $roles]);
    }

    public function show(Role $role): JsonResponse
    {
        $role->load('permissions');

        $assignedUsers = User::query()
            ->role($role->name)
            ->select(['id', 'name', 'email', 'status'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
            ]);

        return response()->json([
            'data' => [
                ...$this->transformRole($role),
                'users_count' => $assignedUsers->count(),
                'assigned_users' => $assignedUsers,
                'activity_log' => $this->roleActivityLog($role),
            ],
        ]);
    }

    /**
     * List all available permissions.
     */
    public function permissions(): JsonResponse
    {
        $permissions = Permission::all();

        return response()->json(['data' => $permissions]);
    }

    /**
     * Update permissions for a specific role.
     */
    public function updateRolePermissions(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $role->syncPermissions($data['permissions']);
        $after = collect($data['permissions'])->sort()->values()->all();

        if ($before !== $after) {
            $this->activityLogger->log(
                'roles',
                "Permission role {$role->name} diperbarui.",
                null,
                'permissions_updated',
                ['role_id' => $role->id],
                $request->user()?->id
            );
        }

        return response()->json([
            'message' => "Hak akses untuk role {$role->name} berhasil diperbarui.",
            'data' => $role->load('permissions'),
        ]);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255|unique:roles,name,'.$role->id,
            'description' => 'nullable|string|max:5000',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($role->name === 'super_admin' && isset($data['is_active']) && $data['is_active'] === false) {
            return response()->json(['message' => 'Role Super Admin tidak dapat dinonaktifkan.'], 422);
        }

        $role->update($data);

        $this->activityLogger->log(
            'roles',
            "Role {$role->name} diperbarui.",
            null,
            'updated',
            ['role_id' => $role->id],
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Role berhasil diperbarui.',
            'data' => $this->transformRole($role->fresh('permissions')),
        ]);
    }

    public function deactivate(Role $role): JsonResponse
    {
        if ($role->name === 'super_admin') {
            return response()->json(['message' => 'Role Super Admin tidak dapat dinonaktifkan.'], 422);
        }

        if (User::role($role->name)->exists()) {
            return response()->json(['message' => 'Role masih digunakan oleh user dan tidak dapat dinonaktifkan.'], 422);
        }

        $role->update(['is_active' => false]);

        $this->activityLogger->log(
            'roles',
            "Role {$role->name} dinonaktifkan.",
            null,
            'deactivated',
            ['role_id' => $role->id],
            request()->user()?->id
        );

        return response()->json([
            'message' => 'Role berhasil dinonaktifkan.',
            'data' => $this->transformRole($role),
        ]);
    }

    /**
     * Store new role (optional if user wants to add custom roles).
     */
    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|unique:roles,name',
            'description' => 'nullable|string|max:5000',
            'guard_name' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => $data['guard_name'] ?? 'web',
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->activityLogger->log(
            'roles',
            "Role {$role->name} dibuat.",
            null,
            'created',
            ['role_id' => $role->id],
            $request->user()?->id
        );

        return response()->json(['message' => 'Role berhasil dibuat.', 'data' => $this->transformRole($role)], 201);
    }

    private function transformRole(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_active' => (bool) ($role->is_active ?? true),
            'guard_name' => $role->guard_name,
            'permissions' => $role->relationLoaded('permissions') ? $role->permissions : [],
            'users_count' => User::role($role->name)->count(),
            'created_at' => $role->created_at?->toIso8601String(),
            'updated_at' => $role->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function roleActivityLog(Role $role): array
    {
        return AdminActivityLog::query()
            ->with('actor:id,name')
            ->where('module', 'roles')
            ->where('meta->role_id', $role->id)
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get()
            ->map(fn (AdminActivityLog $log) => [
                'description' => $log->description,
                'user' => $log->actor?->name,
                'occurred_at' => $log->occurred_at?->toIso8601String(),
            ])
            ->all();
    }
}
