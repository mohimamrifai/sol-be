<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login – menghasilkan token Sanctum.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::with(['company', 'roles', 'locationAccess:id,code,name,type,status'])->where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Kredensial yang diberikan tidak sesuai.'],
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['Akun Anda belum aktif atau telah dinonaktifkan.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil.',
            'data' => [
                'user' => $this->formatUser($user, $request),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Logout – hapus token saat ini.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout berhasil.']);
    }

    /**
     * Ambil profil user yang sedang login.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user()->load(['company', 'roles', 'locationAccess:id,code,name,type,status']);

        return response()->json([
            'data' => $this->formatUser($user, $request),
        ]);
    }

    /**
     * Update profil sendiri.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|nullable|string|max:20',
            'password' => 'sometimes|string|min:8|confirmed',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'data' => $this->formatUser($user->fresh(), $request),
        ]);
    }

    private function formatUser(User $user, ?Request $request = null): array
    {
        $base = $request?->getSchemeAndHttpHost();
        $photoUrl = null;
        if ($user->profile_photo_path) {
            $photoUrl = ($base ?? config('app.url')).'/storage/'.$user->profile_photo_path;
        }

        $locationAccess = $user->relationLoaded('locationAccess') ? $user->locationAccess : null;
        $company = $user->relationLoaded('company') ? $user->company : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'user_type' => $user->user_type,
            'is_vendor' => $user->isVendor(),
            'is_customer' => $user->isCustomer(),
            'is_internal' => $user->isInternal(),
            'company_id' => $user->company_id,
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
                'type' => $company->type ?? 'customer',
                'company_code' => $company->company_code,
                'business_entity_type' => $company->business_entity_type,
                'npwp' => $company->npwp,
                'status' => $company->status,
                'email' => $company->email,
                'phone' => $company->phone,
                'address' => $company->address,
                'city' => $company->city,
                'province' => $company->province,
                'country' => $company->country,
                'service_categories' => $company->service_categories ?? [],
                'bank_name' => $company->bank_name,
                'bank_account_number' => $company->bank_account_number,
                'bank_account_name' => $company->bank_account_name,
            ] : null,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'feature_access' => $user->feature_access ?? [],
            'location_access' => $locationAccess ? $locationAccess->map(fn ($loc) => [
                'id' => $loc->id,
                'code' => $loc->code,
                'name' => $loc->name,
                'type' => $loc->type,
                'status' => $loc->status,
            ]) : [],
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'profile_photo_path' => $user->profile_photo_path,
            'profile_photo_url' => $photoUrl,
        ];
    }
}
