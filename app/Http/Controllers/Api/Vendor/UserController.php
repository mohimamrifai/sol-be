<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Vendor;

use App\Enums\UserStatus;
use App\Enums\VendorUserRole;
use App\Http\Controllers\Controller;
use App\Models\CompanyActivity;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $active = User::where('company_id', $companyId)->where('user_type', 'vendor')->where('status', UserStatus::Active)->count();
        $inactive = User::where('company_id', $companyId)->where('user_type', 'vendor')->where('status', '!=', UserStatus::Active)->count();

        return response()->json(['data' => [
            'total' => $active + $inactive,
            'active' => $active,
            'inactive' => $inactive,
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $query = User::where('company_id', $companyId)
            ->where('user_type', 'vendor')
            ->with('roles:id,name');

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if ($role = $request->string('role')->toString()) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        $page = $query->orderBy('name')->paginate(min((int) $request->integer('per_page', 15) ?: 15, 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($u) => $this->formatUser($u)),
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeVendorAccess($request, $user);
        $user->load('roles:id,name');

        $activities = CompanyActivity::where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'event_key' => $a->event_key,
                'description' => $a->description,
                'actor_name' => $a->actor?->name,
                'occurred_at' => $a->occurred_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => array_merge(
                $this->formatUser($user),
                ['activities' => $activities]
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:120|unique:users,email',
            'phone' => 'nullable|string|max:30',
            'role' => 'required|string|in:'.implode(',', VendorUserRole::values()),
        ]);

        $temporaryPassword = Str::random(10);

        $user = DB::transaction(function () use ($validated, $request, $temporaryPassword) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => bcrypt($temporaryPassword),
                'phone' => $validated['phone'] ?? null,
                'status' => UserStatus::Active,
                'user_type' => 'vendor',
                'company_id' => $request->user()->company_id,
                'feature_access' => null,
            ]);
            $user->syncRoles([$validated['role']]);

            CompanyActivity::create([
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'event_key' => 'vendor_user_created',
                'description' => 'User vendor baru ditambahkan oleh '.$request->user()->name.'.',
                'actor_user_id' => $request->user()->id,
                'occurred_at' => now(),
            ]);

            return $user;
        });

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'data' => $this->formatUser($user->fresh('roles')),
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeVendorAccess($request, $user);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'phone' => 'nullable|string|max:30',
        ]);

        $user->update($validated);

        CompanyActivity::create([
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'event_key' => 'vendor_user_updated',
            'description' => 'Data user vendor diperbarui.',
            'actor_user_id' => $request->user()->id,
            'occurred_at' => now(),
        ]);

        return response()->json([
            'message' => 'User berhasil diperbarui.',
            'data' => $this->formatUser($user->fresh('roles')),
        ]);
    }

    public function changeRole(Request $request, User $user): JsonResponse
    {
        $this->authorizeVendorAccess($request, $user);

        $validated = $request->validate([
            'role' => 'required|string|in:'.implode(',', VendorUserRole::values()),
        ]);

        // Last company admin check
        if ($user->hasRole(VendorUserRole::VendorCompanyAdmin->value)
            && $validated['role'] !== VendorUserRole::VendorCompanyAdmin->value) {
            $activeAdmins = User::where('company_id', $request->user()->company_id)
                ->where('user_type', 'vendor')
                ->where('status', UserStatus::Active)
                ->where('id', '!=', $user->id)
                ->whereHas('roles', fn ($q) => $q->where('name', VendorUserRole::VendorCompanyAdmin->value))
                ->count();
            if ($activeAdmins === 0) {
                return response()->json(['message' => 'Tidak dapat mengubah role Company Admin terakhir.'], 422);
            }
        }

        $user->syncRoles([$validated['role']]);

        CompanyActivity::create([
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'event_key' => 'vendor_user_role_changed',
            'description' => 'Role user diubah menjadi '.VendorUserRole::from($validated['role'])->label().'.',
            'actor_user_id' => $request->user()->id,
            'occurred_at' => now(),
        ]);

        return response()->json([
            'message' => 'Role berhasil diubah.',
            'data' => $this->formatUser($user->fresh('roles')),
        ]);
    }

    public function changeStatus(Request $request, User $user): JsonResponse
    {
        $this->authorizeVendorAccess($request, $user);

        $validated = $request->validate([
            'status' => 'required|string|in:active,inactive',
        ]);

        if ((int) $user->id === (int) $request->user()->id) {
            return response()->json(['message' => 'Tidak dapat menonaktifkan akun sendiri.'], 422);
        }

        // Last active company admin check
        if ($user->hasRole(VendorUserRole::VendorCompanyAdmin->value)
            && $validated['status'] === 'inactive') {
            $activeAdmins = User::where('company_id', $request->user()->company_id)
                ->where('user_type', 'vendor')
                ->where('status', UserStatus::Active)
                ->where('id', '!=', $user->id)
                ->whereHas('roles', fn ($q) => $q->where('name', VendorUserRole::VendorCompanyAdmin->value))
                ->count();
            if ($activeAdmins === 0) {
                return response()->json(['message' => 'Tidak dapat menonaktifkan Company Admin terakhir.'], 422);
            }
        }

        $user->update(['status' => $validated['status']]);

        CompanyActivity::create([
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'event_key' => 'vendor_user_status_changed',
            'description' => 'Status user diubah menjadi '.$validated['status'].'.',
            'actor_user_id' => $request->user()->id,
            'occurred_at' => now(),
        ]);

        return response()->json([
            'message' => 'Status berhasil diubah.',
            'data' => $this->formatUser($user->fresh('roles')),
        ]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorizeVendorAccess($request, $user);

        $temporaryPassword = Str::random(10);
        $user->update(['password' => bcrypt($temporaryPassword)]);

        CompanyActivity::create([
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'event_key' => 'vendor_user_password_reset',
            'description' => 'Password user di-reset oleh '.$request->user()->name.'.',
            'actor_user_id' => $request->user()->id,
            'occurred_at' => now(),
        ]);

        return response()->json([
            'message' => 'Password berhasil direset.',
            'temporary_password' => $temporaryPassword,
        ]);
    }

    public function activities(Request $request, User $user): JsonResponse
    {
        $this->authorizeVendorAccess($request, $user);

        return $this->show($request, $user);
    }

    private function authorizeVendorAccess(Request $request, User $user): void
    {
        if ($user->company_id !== $request->user()->company_id
            || $user->user_type !== 'vendor') {
            abort(response()->json(['message' => 'Resource tidak ditemukan.'], 404));
        }
    }

    private function formatUser(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'status' => $u->status,
            'user_type' => $u->user_type,
            'company_id' => $u->company_id,
            'roles' => $u->roles->pluck('name'),
            'primary_role' => optional($u->roles->first())->name,
            'primary_role_label' => $u->roles->first() ? VendorUserRole::tryFrom($u->roles->first()->name)?->label() : null,
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
