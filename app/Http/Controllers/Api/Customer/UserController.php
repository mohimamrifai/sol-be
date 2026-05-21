<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Display a listing of the company users.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        
        if (!$companyId) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $query = User::with('roles')
            ->where('company_id', $companyId)
            ->where('user_type', 'customer');

        $users = $query->orderBy('created_at', 'desc')->get();

        return response()->json(['data' => $users]);
    }

    /**
     * Store a newly created company user in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        if (!$companyId) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:ops_pic,finance_pic,company_admin',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'user_type' => 'customer',
            'company_id' => $companyId,
            'status' => 'active',
        ]);

        $user->assignRole($validated['role']);

        return response()->json([
            'message' => 'Akun pengguna berhasil ditambahkan.',
            'data' => $user->load('roles'),
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        if ($user->company_id !== $request->user()->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        return response()->json(['data' => $user->load('roles')]);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        if ($user->company_id !== $request->user()->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => "sometimes|email|unique:users,email,{$user->id}",
            'phone' => 'nullable|string|max:20',
            'role' => 'sometimes|in:ops_pic,finance_pic,company_admin',
            'password' => 'nullable|string|min:8',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $updateData = [];
        if (isset($validated['name'])) $updateData['name'] = $validated['name'];
        if (isset($validated['email'])) $updateData['email'] = $validated['email'];
        if (array_key_exists('phone', $validated)) $updateData['phone'] = $validated['phone'];
        if (isset($validated['status'])) $updateData['status'] = $validated['status'];
        if (!empty($validated['password'])) $updateData['password'] = Hash::make($validated['password']);

        if (!empty($updateData)) {
            $user->update($updateData);
        }

        if (isset($validated['role'])) {
            $user->syncRoles([$validated['role']]);
        }

        return response()->json([
            'message' => 'Akun pengguna berhasil diperbarui.',
            'data' => $user->load('roles'),
        ]);
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->company_id !== $request->user()->company_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Tidak dapat menghapus akun Anda sendiri.'], 400);
        }

        $user->delete();

        return response()->json(['message' => 'Akun pengguna berhasil dihapus.']);
    }
}
