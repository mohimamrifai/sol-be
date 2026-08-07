<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\CompanyActivity;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MyProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['company', 'locationAccess', 'roles']);

        $base = $request->getSchemeAndHttpHost();
        $photoUrl = $user->profile_photo_path
            ? ($base ?? config('app.url')).'/storage/'.$user->profile_photo_path
            : null;

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'user_type' => $user->user_type,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'feature_access' => $user->feature_access ?? [],
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'profile_photo_path' => $user->profile_photo_path,
            'profile_photo_url' => $photoUrl,
            'company' => $user->company ? [
                'id' => $user->company->id,
                'name' => $user->company->name,
                'type' => $user->company->type,
                'company_code' => $user->company->company_code,
            ] : null,
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'phone' => 'nullable|string|max:30',
        ]);
        $user->update($validated);

        CompanyActivity::create([
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'event_key' => 'vendor_profile_updated',
            'description' => 'Profil vendor user diperbarui.',
            'actor_user_id' => $user->id,
            'occurred_at' => now(),
        ]);

        return response()->json(['message' => 'Profil berhasil diperbarui.', 'data' => $this->show($request)->getData(true)['data']]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);
        if (! password_verify($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'Password saat ini salah.'], 422);
        }
        $user->update(['password' => bcrypt($validated['new_password'])]);

        CompanyActivity::create([
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'event_key' => 'vendor_password_changed',
            'description' => 'Password vendor user berhasil diganti.',
            'actor_user_id' => $user->id,
            'occurred_at' => now(),
        ]);

        return response()->json(['message' => 'Password berhasil diganti.']);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png|max:5120',
        ]);
        if ($user->profile_photo_path && Storage::disk('public')->exists($user->profile_photo_path)) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }
        $path = $request->file('photo')->store("profile-photos/{$user->id}", 'public');
        $user->update(['profile_photo_path' => $path]);

        return response()->json([
            'message' => 'Foto profil berhasil diperbarui.',
            'profile_photo_path' => $path,
            'profile_photo_url' => $request->getSchemeAndHttpHost().'/storage/'.$path,
        ]);
    }

    public function deletePhoto(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->profile_photo_path && Storage::disk('public')->exists($user->profile_photo_path)) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }
        $user->update(['profile_photo_path' => null]);

        return response()->json(['message' => 'Foto profil berhasil dihapus.']);
    }

    public function activities(Request $request): JsonResponse
    {
        $user = $request->user();
        $activities = CompanyActivity::where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'event_key' => $a->event_key,
                'description' => $a->description,
                'occurred_at' => $a->occurred_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $activities]);
    }
}
