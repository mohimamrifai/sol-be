<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\Vendor\Concerns\AuthorizesVendorRoles;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyController extends Controller
{
    use AuthorizesVendorRoles;

    public function show(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        if (! $company || $company->type !== Company::TYPE_VENDOR) {
            return response()->json(['message' => 'Resource tidak ditemukan.'], 404);
        }

        return response()->json(['data' => $this->formatCompany($company)]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeCompanyAdmin($request);
        $company = $request->user()->company;
        if (! $company || $company->type !== Company::TYPE_VENDOR) {
            return response()->json(['message' => 'Resource tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'npwp' => 'nullable|string|max:60',
            'email' => 'nullable|email|max:120',
            'phone' => 'nullable|string|max:30',
            'website' => 'nullable|string|max:160',
            'address' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:80',
            'province' => 'nullable|string|max:80',
            'city' => 'nullable|string|max:80',
            'district' => 'nullable|string|max:80',
            'postal_code' => 'nullable|string|max:20',
            'pic_name' => 'nullable|string|max:120',
            'pic_email' => 'nullable|email|max:120',
            'pic_mobile' => 'nullable|string|max:30',
            'bank_name' => 'nullable|string|max:80',
            'bank_account_number' => 'nullable|string|max:60',
            'bank_account_name' => 'nullable|string|max:120',
        ]);

        DB::transaction(function () use ($company, $validated, $request) {
            $company->update($validated);

            CompanyActivity::create([
                'subject_type' => Company::class,
                'subject_id' => $company->id,
                'event_key' => 'vendor_company_updated',
                'description' => 'Vendor company profile diperbarui oleh '.$request->user()->name.'.',
                'actor_user_id' => $request->user()->id,
                'occurred_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Profile perusahaan berhasil diperbarui.',
            'data' => $this->formatCompany($company->fresh()),
        ]);
    }

    public function activities(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        if (! $company) {
            return response()->json(['message' => 'Resource tidak ditemukan.'], 404);
        }

        $activities = CompanyActivity::where('subject_type', Company::class)
            ->where('subject_id', $company->id)
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'event_key' => $a->event_key,
                'description' => $a->description,
                'actor_name' => $a->actor?->name,
                'occurred_at' => $a->occurred_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $activities]);
    }

    private function formatCompany(Company $c): array
    {
        return [
            'id' => $c->id,
            'type' => $c->type,
            'name' => $c->name,
            'business_entity_type' => $c->business_entity_type,
            'company_code' => $c->company_code,
            'npwp' => $c->npwp,
            'nib' => $c->nib,
            'address' => $c->address,
            'city' => $c->city,
            'province' => $c->province,
            'country' => $c->country,
            'district' => $c->district,
            'postal_code' => $c->postal_code,
            'service_categories' => $c->service_categories ?? [],
            'business_category' => $c->business_category,
            'website' => $c->website,
            'email' => $c->email,
            'phone' => $c->phone,
            'pic_name' => $c->pic_name,
            'pic_email' => $c->pic_email,
            'pic_mobile' => $c->pic_mobile,
            'status' => $c->status,
            'bank_name' => $c->bank_name,
            'bank_account_number' => $c->bank_account_number,
            'bank_account_name' => $c->bank_account_name,
        ];
    }
}
