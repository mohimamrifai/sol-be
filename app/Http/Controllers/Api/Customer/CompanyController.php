<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\UpdateCompanyRequest;
use App\Http\Requests\Customer\UploadCompanyLogoRequest;
use App\Http\Resources\Customer\CompanyActivityResource;
use App\Http\Resources\Customer\CompanyCommercialResource;
use App\Http\Resources\Customer\CompanyResource;
use App\Services\CompanyActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    private const ADDRESS_FIELDS = [
        'country',
        'province',
        'city',
        'district',
        'postal_code',
        'address',
    ];

    private const LOGO_DISK = 'public';

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

        $profileChanges = [];
        $addressChanges = [];

        foreach ($before as $key => $val) {
            if (($val ?? '') === ($after[$key] ?? '')) {
                continue;
            }

            $change = ['old' => $val, 'new' => $after[$key]];

            if (in_array($key, self::ADDRESS_FIELDS, true)) {
                $addressChanges[$key] = $change;
            } else {
                $profileChanges[$key] = $change;
            }
        }

        if ($profileChanges !== []) {
            $this->activityLogger->log(
                $company,
                'company_profile_updated',
                'Company Profile diperbarui.',
                ['changes' => $profileChanges],
                $user->id
            );
        }

        if ($addressChanges !== []) {
            $this->activityLogger->log(
                $company,
                'company_address_updated',
                'Company Address diperbarui.',
                ['changes' => $addressChanges],
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

    public function activities(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;

        if (! $company) {
            return response()->json(['message' => 'Perusahaan tidak ditemukan.'], 404);
        }

        $activities = $company->activities()
            ->with('actor:id,name,email')
            ->orderByDesc('occurred_at')
            ->paginate($request->integer('per_page', 15));

        return CompanyActivityResource::collection($activities)->response();
    }

    public function uploadLogo(UploadCompanyLogoRequest $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;

        if (! $company) {
            return response()->json(['message' => 'Perusahaan tidak ditemukan.'], 404);
        }

        $file = $request->file('logo');
        $disk = Storage::disk(self::LOGO_DISK);

        $path = DB::transaction(function () use ($company, $file, $disk) {
            if ($company->logo_path && $disk->exists($company->logo_path)) {
                $disk->delete($company->logo_path);
            }

            $extension = $file->getClientOriginalExtension() ?: 'jpg';
            $filename = Str::uuid()->toString().'.'.$extension;

            $stored = $file->storeAs(
                "company-logos/{$company->id}",
                $filename,
                self::LOGO_DISK
            );

            $company->update(['logo_path' => $stored]);

            return $stored;
        });

        $this->activityLogger->log(
            $company,
            'company_logo_updated',
            'Company Logo diperbarui.',
            ['logo_path' => $path],
            $user->id
        );

        $base = $request->getSchemeAndHttpHost();

        return response()->json([
            'message' => 'Logo perusahaan berhasil diperbarui.',
            'data' => [
                'logo_url' => $base.'/storage/'.$path,
            ],
        ]);
    }
}
