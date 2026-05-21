<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Company::query()->withCount(['users', 'branches', 'bookings']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('npwp', 'like', "%{$search}%")
                    ->orWhere('nib', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $companies = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json($companies);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:companies,name',
            'business_entity_type' => 'nullable|string|max:20|in:PT,CV,Firma,UD,Koperasi,Yayasan,Lainnya',
            'company_code' => 'nullable|string|max:10|unique:companies,company_code',
            'npwp' => 'nullable|string|max:30',
            'nib' => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:10',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'status' => 'nullable|in:pending,active,inactive',
            'billing_cycle' => 'required|in:half_monthly_1,half_monthly_2,both_half,end_of_month',
            'payment_type' => 'required|in:prepaid,postpaid',
            'postpaid_term_days' => 'nullable|integer|min:0|max:365',
            
            // PIC / User Login fields
            'pic_name' => 'nullable|string|max:255',
            'pic_email' => 'nullable|email|max:255|unique:users,email',
            'pic_phone' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:8',
        ]);

        $company = Company::create($validated);

        if (!empty($validated['pic_email']) && !empty($validated['password'])) {
            $user = User::create([
                'name' => $validated['pic_name'] ?? $validated['contact_person'] ?? $company->name,
                'email' => $validated['pic_email'],
                'password' => \Illuminate\Support\Facades\Hash::make($validated['password']),
                'phone' => $validated['pic_phone'] ?? null,
                'user_type' => 'customer',
                'company_id' => $company->id,
                'status' => 'active',
            ]);
            $user->assignRole('company_admin');
        }

        return response()->json([
            'message' => 'Perusahaan berhasil dibuat.',
            'data' => $company,
        ], 201);
    }

    public function show(Company $company): JsonResponse
    {
        $company->load(['branches', 'users.roles', 'customerDiscounts']);
        $company->loadCount(['bookings', 'invoices']);

        return response()->json(['data' => $company]);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'name' => sprintf('sometimes|string|max:255|unique:companies,name,%d', $company->id),
            'business_entity_type' => 'nullable|string|max:20|in:PT,CV,Firma,UD,Koperasi,Yayasan,Lainnya',
            'company_code' => sprintf('nullable|string|max:10|unique:companies,company_code,%d', $company->id),
            'npwp' => 'nullable|string|max:30',
            'nib' => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:10',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'billing_cycle' => 'required|in:half_monthly_1,half_monthly_2,both_half,end_of_month',
            'payment_type' => 'sometimes|required|in:prepaid,postpaid',
            'postpaid_term_days' => 'nullable|integer|min:0|max:365',
        ]);

        $company->update($validated);

        return response()->json([
            'message' => 'Perusahaan berhasil diperbarui.',
            'data' => $company,
        ]);
    }

    public function destroy(Company $company): JsonResponse
    {
        $company->delete();

        return response()->json(['message' => 'Perusahaan berhasil dihapus.']);
    }

    /**
     * Setujui / aktivasi perusahaan beserta semua user-nya.
     */
    public function approve(Company $company): JsonResponse
    {
        $company->update(['status' => 'active']);

        User::where('company_id', $company->id)
            ->where('status', '!=', 'active')
            ->update(['status' => 'active']);

        return response()->json([
            'message' => 'Perusahaan berhasil diaktifkan.',
            'data' => $company,
        ]);
    }

    /**
     * Tolak / nonaktifkan perusahaan beserta semua user-nya.
     */
    public function reject(Company $company): JsonResponse
    {
        $company->update(['status' => 'inactive']);

        $users = User::where('company_id', $company->id)
            ->where('status', '!=', 'inactive')
            ->get();

        foreach ($users as $user) {
            $user->update(['status' => 'inactive']);
            // Revoke all tokens so they are logged out immediately
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => 'Perusahaan berhasil dinonaktifkan.',
            'data' => $company,
        ]);
    }
}
