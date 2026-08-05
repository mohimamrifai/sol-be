<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\UpdateCompanyRequest;
use App\Http\Resources\Customer\CompanyCommercialResource;
use App\Http\Resources\Customer\CompanyResource;
use App\Models\Company;
use App\Services\CompanyActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function __construct(
        private CompanyActivityLogger $activityLogger
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;

        if (! $company) {
            return response()->json(['message' => 'Perusahaan tidak ditemukan.'], 404);
        }

        $company->load(['documents', 'customerLocations']);

        return response()->json([
            'data' => (new CompanyResource($company))->resolve(),
        ]);
    }

    public function update(UpdateCompanyRequest $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;

        if (! $company) {
            return response()->json(['message' => 'Perusahaan tidak ditemukan.'], 404);
        }

        $data = $request->validated();

        $before = $company->only(array_keys($data));
        $company->update($data);
        $after = $company->fresh()->only(array_keys($data));

        $changes = [];
        foreach ($before as $key => $val) {
            if (($val ?? '') !== ($after[$key] ?? '')) {
                $changes[$key] = ['old' => $val, 'new' => $after[$key]];
            }
        }
        if (! empty($changes)) {
            $this->activityLogger->log(
                $company,
                'company_profile_updated',
                'Company Profile diperbarui.',
                ['changes' => $changes],
                $user->id
            );
        }

        return response()->json([
            'message' => 'Data perusahaan berhasil diperbarui.',
            'data' => (new CompanyResource($company->fresh()))->resolve(),
        ]);
    }

    public function commercial(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;

        if (! $company) {
            return response()->json(['message' => 'Perusahaan tidak ditemukan.'], 404);
        }

        return response()->json([
            'data' => (new CompanyCommercialResource($company))->resolve(),
        ]);
    }

    public function activities(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        if ($company->id !== $user->company_id) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $activities = $company->activities()->with('actor:id,name,email')->paginate(15);

        return response()->json($activities);
    }
}
