<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    /**
     * Get current user's company (for Company Settings page).
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;

        if (! $company) {
            return response()->json(['message' => 'Perusahaan tidak ditemukan.'], 404);
        }

        return response()->json(['data' => $company]);
    }

    /**
     * Update current user's company (limited fields – no status/billing_cycle).
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;

        if (! $company) {
            return response()->json(['message' => 'Perusahaan tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'npwp' => 'nullable|string|max:30',
            'nib' => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:10',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        $company->update($data);

        return response()->json([
            'message' => 'Data perusahaan berhasil diperbarui.',
            'data' => $company->fresh(),
        ]);
    }
}
