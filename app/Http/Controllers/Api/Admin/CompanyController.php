<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CustomerLocation;
use App\Models\User;
use App\Enums\LocationStatus;
use App\Enums\LocationType;
use App\Services\CustomerRegistrationMailer;
use App\Services\LocationCodeService;
use App\Support\SystemConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function __construct(
        private CustomerRegistrationMailer $registrationMailer,
        private LocationCodeService $locationCode,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Company::query()
            ->customer()
            ->withCount(['users', 'customerLocations', 'bookings']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('company_code', 'like', "%{$search}%")
                    ->orWhere('npwp', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $companies = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json($companies);
    }

    public function stats(): JsonResponse
    {
        $base = Company::query()->customer();
        $counts = (clone $base)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'data' => [
                'total' => (clone $base)->count(),
                'pending' => (int) ($counts['pending'] ?? 0),
                'active' => (int) ($counts['active'] ?? 0),
                'suspended' => (int) ($counts['suspended'] ?? 0),
                'inactive' => (int) ($counts['inactive'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
            ],
        ]);
    }

    public function postalCodes(Request $request): JsonResponse
    {
        $params = $request->validate([
            'province' => 'nullable|string|max:120',
            'city' => 'required|string|max:120',
            'district' => 'nullable|string|max:120',
        ]);
        $query = trim($params['district'] ?? '') ?: trim($params['city']);
        $cacheKey = 'postal-codes:'.sha1(json_encode($params));

        $data = Cache::remember($cacheKey, now()->addDay(), function () use ($params, $query) {
            try {
                $response = Http::timeout(8)
                    ->retry(1, 200)
                    ->get('https://kodepos.vercel.app/search/', ['q' => $query]);
            } catch (\Throwable) {
                return [];
            }

            if (! $response->successful()) {
                return [];
            }

            $province = mb_strtolower(trim($params['province'] ?? ''));
            $district = mb_strtolower(trim($params['district'] ?? ''));

            return collect($response->json('data', []))
                ->filter(function (array $row) use ($province, $district) {
                    $rowProvince = mb_strtolower((string) ($row['province'] ?? ''));
                    $rowDistrict = mb_strtolower((string) ($row['district'] ?? ''));

                    return ($province === '' || $rowProvince === $province)
                        && ($district === '' || $rowDistrict === $district);
                })
                ->map(fn (array $row) => [
                    'value' => (string) $row['code'],
                    'label' => sprintf(
                        '%s — %s',
                        $row['code'],
                        $row['village'] ?? $row['district'] ?? ''
                    ),
                ])
                ->unique('value')
                ->values()
                ->all();
        });

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->companyRules());

        $validated['type'] = Company::TYPE_CUSTOMER;
        $validated['status'] = $validated['status'] ?? 'pending';
        if ($validated['status'] === 'active') {
            $validated['reviewed_at'] = now();
            $validated['reviewed_by'] = $request->user()?->id;
            $validated['approved_at'] = now();
            $validated['approved_by'] = $request->user()?->id;
        }
        $validated['billing_type'] = $validated['billing_type'] ?? 'prepaid';
        $validated['pricing_type'] = $validated['pricing_type'] ?? 'standard';
        if (($validated['billing_type'] ?? 'prepaid') === 'postpaid') {
            if (empty($validated['billing_cycle'])) {
                $validated['billing_cycle'] = 'monthly';
            }
            if (empty($validated['payment_term'])) {
                $validated['payment_term'] = SystemConfig::defaultPaymentTerm();
            }
        } else {
            $validated['billing_cycle'] = null;
            $validated['payment_term'] = null;
        }

        $adminPayload = [
            'pic_name' => $validated['pic_name'] ?? $validated['contact_person'] ?? null,
            'pic_email' => $validated['pic_email'] ?? null,
            'pic_phone' => $validated['pic_phone'] ?? null,
            'password' => $validated['password'] ?? null,
        ];
        unset($validated['pic_name'], $validated['pic_email'], $validated['pic_phone'], $validated['password']);

        $company = DB::transaction(function () use ($validated, $adminPayload) {
            $company = Company::create($validated);
            $headOffice = $this->createHeadOffice($company, $validated, $adminPayload);
            $user = User::create([
                'name' => $adminPayload['pic_name'],
                'email' => $adminPayload['pic_email'],
                'password' => Hash::make($adminPayload['password']),
                'phone' => $adminPayload['pic_phone'],
                'user_type' => 'customer',
                'company_id' => $company->id,
                'status' => in_array($company->status, ['active', 'suspended'], true)
                    ? 'active'
                    : 'inactive',
            ]);
            $user->assignRole('company_admin');
            $user->locationAccess()->sync([$headOffice->id]);

            return $company;
        });

        return response()->json([
            'message' => 'Customer berhasil dibuat.',
            'data' => $company,
        ], 201);
    }

    public function show(Company $company): JsonResponse
    {
        if (! $company->isCustomer()) {
            abort(404);
        }

        $company->load([
            'users.roles',
            'users.locationAccess:id,code,name',
            'customerLocations',
            'customerDiscounts',
            'salesPic:id,name,email',
            'accountManager:id,name,email',
            'reviewedByUser:id,name,email',
            'approvedByUser:id,name,email',
        ]);
        $company->loadCount(['bookings', 'invoices']);

        return response()->json(['data' => $company]);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        if (! $company->isCustomer()) {
            abort(404);
        }

        $validated = $request->validate($this->companyRules($company->id, partial: true));

        if (($validated['status'] ?? null) === 'active') {
            if (! $this->hasActiveHeadOffice($company)) {
                return $this->missingHeadOfficeResponse();
            }
            if (! $company->reviewed_at) {
                $validated['reviewed_at'] = now();
                $validated['reviewed_by'] = $request->user()?->id;
            }
            if (! $company->approved_at) {
                $validated['approved_at'] = now();
                $validated['approved_by'] = $request->user()?->id;
            }
        }

        DB::transaction(function () use ($company, $validated) {
            $company->update($validated);
            if (isset($validated['status'])) {
                $this->synchronizeUsersForStatus($company, $validated['status']);
            }
        });

        return response()->json([
            'message' => 'Customer berhasil diperbarui.',
            'data' => $company->fresh([
                'salesPic:id,name,email',
                'accountManager:id,name,email',
                'reviewedByUser:id,name,email',
                'approvedByUser:id,name,email',
            ]),
        ]);
    }

    public function destroy(Company $company): JsonResponse
    {
        if (! $company->isCustomer()) {
            abort(404);
        }

        $company->delete();

        return response()->json(['message' => 'Customer berhasil dihapus.']);
    }

    public function approve(Company $company): JsonResponse
    {
        if (! $company->isCustomer()) {
            abort(404);
        }

        if (! $this->hasActiveHeadOffice($company)) {
            return $this->missingHeadOfficeResponse();
        }

        $now = now();
        $approverId = auth()->id();

        $company->update([
            'status' => 'active',
            'reviewed_at' => $company->reviewed_at ?? $now,
            'reviewed_by' => $company->reviewed_by ?? $approverId,
            'approved_at' => $company->approved_at ?? $now,
            'approved_by' => $company->approved_by ?? $approverId,
        ]);

        $this->synchronizeUsersForStatus($company, 'active');

        return response()->json([
            'message' => 'Customer berhasil diaktifkan.',
            'data' => $company->fresh(),
        ]);
    }

    public function reject(Request $request, Company $company): JsonResponse
    {
        if (! $company->isCustomer()) {
            abort(404);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $now = now();
        $reviewerId = auth()->id();

        $company->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['reason'],
            'reviewed_at' => $company->reviewed_at ?? $now,
            'reviewed_by' => $company->reviewed_by ?? $reviewerId,
        ]);

        $users = User::where('company_id', $company->id)->get();

        foreach ($users as $user) {
            if ($user->status !== 'inactive') {
                $user->update(['status' => 'inactive']);
            }
            $user->tokens()->delete();
        }

        $adminUser = $users->first(fn (User $u) => $u->hasRole('company_admin')) ?? $users->first();
        if ($adminUser) {
            $this->registrationMailer->sendRejected($company, $adminUser, $validated['reason']);
        }

        return response()->json([
            'message' => 'Customer berhasil ditolak.',
            'data' => [
                'company' => $company->fresh(),
                'reason' => $validated['reason'],
            ],
        ]);
    }

    public function suspend(Company $company): JsonResponse
    {
        if (! $company->isCustomer()) {
            abort(404);
        }

        if ($company->status !== 'active') {
            return response()->json(['message' => 'Hanya customer aktif yang dapat di-suspend.'], 422);
        }

        $company->update(['status' => 'suspended']);
        $this->synchronizeUsersForStatus($company, 'suspended');

        return response()->json([
            'message' => 'Customer berhasil di-suspend.',
            'data' => $company,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function companyRules(?int $companyId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255', Rule::unique('companies', 'name')->ignore($companyId)],
            'business_entity_type' => [$required, 'string', Rule::in(['PT', 'CV', 'UD', 'Koperasi', 'Yayasan', 'Firma', 'Perorangan', 'Lainnya'])],
            'business_entity_other' => ['nullable', 'required_if:business_entity_type,Lainnya', 'string', 'max:255'],
            'company_code' => [$required, 'string', 'size:3', 'regex:/^[A-Z]{3}$/', Rule::unique('companies', 'company_code')->ignore($companyId)],
            'npwp' => [$required, 'string', 'max:16', 'regex:/^\d{1,16}$/'],
            'address' => [$required, 'string', 'max:500'],
            'city' => [$required, 'string', 'max:255'],
            'province' => [$required, 'string', 'max:255'],
            'country' => [$required, 'string', 'max:80'],
            'district' => [$required, 'string', 'max:120'],
            'postal_code' => [$required, 'string', 'max:10'],
            'business_category' => [$required, Rule::in(['trading', 'manufacturing', 'retail', 'distributor', 'e_commerce', 'logistics', 'others'])],
            'business_category_other' => ['nullable', 'required_if:business_category,others', 'string', 'max:255'],
            'monthly_shipment_estimate' => [$required, Rule::in(['under_10', '10_to_50', '50_to_100', 'over_100', '<10', '10-50', '50-100', '>100'])],
            'contact_person' => 'nullable|string|max:255',
            'email' => [$required, 'email', 'max:255', Rule::unique('companies', 'email')->ignore($companyId)],
            'phone' => [$required, 'regex:/^(0|62)\d+$/', 'max:20'],
            'website' => 'nullable|string|max:255',
            'status' => [$required, Rule::in(['pending', 'active', 'suspended', 'inactive', 'rejected'])],
            'billing_type' => 'nullable|in:prepaid,postpaid',
            'pricing_type' => 'nullable|in:standard,discount',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'billing_cycle' => 'nullable|required_if:billing_type,postpaid|in:per_shipment,semi_monthly,monthly',
            'payment_term' => 'nullable|required_if:billing_type,postpaid|in:cod,net_7,net_14,net_30,net_45,net_60',
            'credit_limit' => 'nullable|numeric|min:0',
            'sales_pic_id' => ['nullable', Rule::exists('users', 'id')->where('user_type', 'internal')],
            'account_manager_id' => ['nullable', Rule::exists('users', 'id')->where('user_type', 'internal')],
            'review_notes' => 'nullable|string|max:5000',
            'rejection_reason' => 'nullable|string|max:500',
            'pic_name' => $partial ? 'sometimes|string|max:255' : 'required|string|max:255',
            'pic_email' => $partial
                ? ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')]
                : ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'pic_phone' => $partial
                ? ['sometimes', 'regex:/^(0|62)\d+$/', 'max:20']
                : ['required', 'regex:/^(0|62)\d+$/', 'max:20'],
            'password' => $partial ? 'sometimes|string|min:8' : 'required|string|min:8',
        ];
    }

    private function hasActiveHeadOffice(Company $company): bool
    {
        return $company->customerLocations()
            ->where('type', LocationType::HeadOffice->value)
            ->where('status', LocationStatus::Active->value)
            ->exists();
    }

    private function missingHeadOfficeResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Customer wajib memiliki minimal 1 Head Office aktif sebelum diaktifkan.',
            'errors' => ['locations' => ['Tambahkan Head Office aktif pada tab Locations.']],
        ], 422);
    }

    private function synchronizeUsersForStatus(Company $company, string $status): void
    {
        if ($status === 'active') {
            $company->users()->where('status', '!=', 'active')->update(['status' => 'active']);

            return;
        }

        if (! in_array($status, ['pending', 'inactive', 'rejected'], true)) {
            return;
        }

        $users = $company->users()->get();
        foreach ($users as $user) {
            if ($user->status !== 'inactive') {
                $user->update(['status' => 'inactive']);
            }
            $user->tokens()->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $adminPayload
     */
    private function createHeadOffice(Company $company, array $validated, array $adminPayload): CustomerLocation
    {
        return CustomerLocation::create([
            'company_id' => $company->id,
            'code' => $this->locationCode->next($company->id, $company->name, LocationType::HeadOffice->value),
            'name' => $company->name,
            'type' => LocationType::HeadOffice->value,
            'status' => LocationStatus::Active->value,
            'country' => $validated['country'] ?? 'Indonesia',
            'province' => $validated['province'] ?? null,
            'city' => $validated['city'] ?? null,
            'district' => $validated['district'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'address' => $validated['address'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'pic_name' => $adminPayload['pic_name'] ?? $validated['contact_person'] ?? null,
            'pic_email' => $adminPayload['pic_email'] ?? null,
            'pic_mobile' => $adminPayload['pic_phone'] ?? '',
        ]);
    }
}
