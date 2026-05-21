<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::with(['company', 'roles']);

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
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'user_type' => $validated['user_type'],
            'company_id' => $validated['company_id'] ?? null,
            'status' => $validated['status'] ?? 'active',
        ]);

        $user->assignRole($validated['role']);

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'data' => $user->load('roles'),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['company', 'roles']);

        return response()->json(['data' => $user]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'user_type' => 'sometimes|in:internal,customer',
            'company_id' => 'nullable|exists:companies,id',
            'status' => 'sometimes|in:active,inactive,pending',
            'role' => 'sometimes|string|exists:roles,name',
            'password' => 'sometimes|string|min:8',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $role = $validated['role'] ?? null;
        unset($validated['role']);

        $user->update($validated);

        if ($role) {
            $user->syncRoles([$role]);
        }

        return response()->json([
            'message' => 'User berhasil diperbarui.',
            'data' => $user->load('roles'),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        // Hapus pengguna tidak diperbolehkan sesuai instruksi. 
        // Jika perlu bisa dinonaktifkan di update status, namun fungsi destroy dimatikan.
        return response()->json(['message' => 'Fitur hapus pengguna dinonaktifkan.'], 403);
    }
}
