<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CustomerDiscount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerDiscountController extends Controller
{
    public function index(Company $company, Request $request): JsonResponse
    {
        $query = $company->customerDiscounts()->with('vendorService.vendor');

        if ($request->filled('vendor_service_id')) {
            $query->where('vendor_service_id', $request->vendor_service_id);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $discounts = $query->orderBy('effective_from', 'desc')->paginate($request->per_page ?? 15);

        return response()->json($discounts);
    }

    public function store(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'vendor_service_id' => 'nullable|exists:vendor_services,id',
            'discount_type' => 'required|in:percentage,fixed',
            'discount_value' => 'required|numeric|min:0',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'is_active' => 'boolean',
        ]);

        $data['company_id'] = $company->id;
        $discount = CustomerDiscount::create($data);

        return response()->json([
            'message' => 'Diskon customer berhasil ditambahkan.',
            'data' => $discount->load('vendorService'),
        ], 201);
    }

    public function show(Company $company, CustomerDiscount $customerDiscount): JsonResponse
    {
        if ($customerDiscount->company_id !== $company->id) {
            return response()->json(['message' => 'Diskon tidak ditemukan.'], 404);
        }

        $customerDiscount->load('vendorService.vendor');

        return response()->json(['data' => $customerDiscount]);
    }

    public function update(Request $request, Company $company, CustomerDiscount $customerDiscount): JsonResponse
    {
        if ($customerDiscount->company_id !== $company->id) {
            return response()->json(['message' => 'Diskon tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'vendor_service_id' => 'nullable|exists:vendor_services,id',
            'discount_type' => 'sometimes|in:percentage,fixed',
            'discount_value' => 'sometimes|numeric|min:0',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'is_active' => 'boolean',
        ]);

        $customerDiscount->update($data);

        return response()->json([
            'message' => 'Diskon customer berhasil diperbarui.',
            'data' => $customerDiscount->fresh('vendorService'),
        ]);
    }

    public function destroy(Company $company, CustomerDiscount $customerDiscount): JsonResponse
    {
        if ($customerDiscount->company_id !== $company->id) {
            return response()->json(['message' => 'Diskon tidak ditemukan.'], 404);
        }

        $customerDiscount->delete();

        return response()->json(['message' => 'Diskon customer berhasil dihapus.']);
    }
}
