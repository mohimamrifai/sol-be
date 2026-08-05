<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ChangePasswordRequest;
use App\Http\Requests\Customer\UpdateMyProfileRequest;
use App\Http\Requests\Customer\UploadProfilePhotoRequest;
use App\Http\Resources\Customer\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MyProfileController extends Controller
{
    /**
     * Photo is stored on the public disk so that <img> tags can load it
     * directly without an Authorization header.
     */
    private const PHOTO_DISK = 'public';

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['roles:id,name', 'locationAccess:id,code,name,type,status', 'company:id,name,company_code']);

        return response()->json([
            'data' => (new UserResource($user))->resolve(),
        ]);
    }

    public function update(UpdateMyProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $before = $user->only(['name', 'phone']);
        $user->update($data);
        $after = $user->fresh()->only(['name', 'phone']);

        $changes = [];
        foreach ($before as $key => $val) {
            if (($val ?? '') !== ($after[$key] ?? '')) {
                $changes[$key] = ['old' => $val, 'new' => $after[$key]];
            }
        }
        if (! empty($changes)) {
            $user->activities()->create([
                'event_key' => 'my_profile_updated',
                'description' => 'Profile diperbarui.',
                'meta' => ['changes' => $changes],
                'actor_user_id' => $user->id,
                'occurred_at' => now(),
            ]);
        }

        $user->load(['roles:id,name', 'locationAccess:id,code,name,type,status', 'company:id,name,company_code']);

        return response()->json([
            'message' => 'Profile berhasil diperbarui.',
            'data' => (new UserResource($user))->resolve(),
        ]);
    }

    public function uploadPhoto(UploadProfilePhotoRequest $request): JsonResponse
    {
        $user = $request->user();
        $file = $request->file('photo');
        $disk = Storage::disk(self::PHOTO_DISK);

        $photoUrl = DB::transaction(function () use ($user, $file, $disk) {
            if ($user->profile_photo_path) {
                $disk->delete($user->profile_photo_path);
            }

            $extension = $file->getClientOriginalExtension() ?: 'jpg';
            $filename = Str::uuid()->toString().'.'.$extension;
            $path = $file->storeAs(
                "profile-photos/{$user->id}",
                $filename,
                self::PHOTO_DISK
            );

            $user->update(['profile_photo_path' => $path]);

            return $path;
        });

        $base = $request->getSchemeAndHttpHost();
        $absoluteUrl = $base.'/storage/'.$photoUrl;

        return response()->json([
            'message' => 'Foto profile berhasil diperbarui.',
            'data' => [
                'profile_photo_url' => $absoluteUrl,
            ],
        ]);
    }

    public function deletePhoto(Request $request): JsonResponse
    {
        $user = $request->user();
        $disk = Storage::disk(self::PHOTO_DISK);

        if ($user->profile_photo_path && $disk->exists($user->profile_photo_path)) {
            $disk->delete($user->profile_photo_path);
        }
        $user->update(['profile_photo_path' => null]);

        return response()->json(['message' => 'Foto profile berhasil dihapus.']);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            return response()->json([
                'message' => 'Password saat ini salah.',
                'errors' => ['current_password' => ['Password saat ini tidak cocok.']],
            ], 422);
        }

        $user->update(['password' => Hash::make($request->validated('password'))]);

        $user->activities()->create([
            'event_key' => 'my_password_changed',
            'description' => 'Password diubah.',
            'meta' => [],
            'actor_user_id' => $user->id,
            'occurred_at' => now(),
        ]);

        return response()->json(['message' => 'Password berhasil diperbarui.']);
    }
}
