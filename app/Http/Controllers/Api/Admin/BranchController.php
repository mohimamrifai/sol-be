<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Company $company, Request $request): JsonResponse
    {
        $query = $company->branches();

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('name', 'like', "%{$s}%")
                ->orWhere('city', 'like', "%{$s}%")
                ->orWhere('contact_person', 'like', "%{$s}%"));
        }

        $branches = $query->orderBy('name')->paginate($request->per_page ?? 15);

        return response()->json($branches);
    }

    public function store(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'contact_person' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $branch = $company->branches()->create($data);

        return response()->json([
            'message' => 'Cabang berhasil ditambahkan.',
            'data' => $branch,
        ], 201);
    }

    public function show(Company $company, Branch $branch): JsonResponse
    {
        if ($branch->company_id !== $company->id) {
            return response()->json(['message' => 'Cabang tidak ditemukan.'], 404);
        }

        return response()->json(['data' => $branch]);
    }

    public function update(Request $request, Company $company, Branch $branch): JsonResponse
    {
        if ($branch->company_id !== $company->id) {
            return response()->json(['message' => 'Cabang tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'contact_person' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $branch->update($data);

        return response()->json([
            'message' => 'Cabang berhasil diperbarui.',
            'data' => $branch,
        ]);
    }

    public function destroy(Company $company, Branch $branch): JsonResponse
    {
        if ($branch->company_id !== $company->id) {
            return response()->json(['message' => 'Cabang tidak ditemukan.'], 404);
        }

        $branch->delete();

        return response()->json(['message' => 'Cabang berhasil dihapus.']);
    }
}
