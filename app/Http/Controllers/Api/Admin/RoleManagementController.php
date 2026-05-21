<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleManagementController extends Controller
{
    /**
     * List all roles with their permissions.
     */
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->get();
        return response()->json(['data' => $roles]);
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

        $role->syncPermissions($data['permissions']);

        return response()->json([
            'message' => "Hak akses untuk role {$role->name} berhasil diperbarui.",
            'data' => $role->load('permissions'),
        ]);
    }

    /**
     * Store new role (optional if user wants to add custom roles).
     */
    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|unique:roles,name',
            'guard_name' => 'nullable|string',
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => $data['guard_name'] ?? 'web',
        ]);

        return response()->json(['message' => 'Role berhasil dibuat.', 'data' => $role], 201);
    }
}
