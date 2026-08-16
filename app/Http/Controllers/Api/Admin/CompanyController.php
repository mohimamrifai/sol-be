<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\CustomerRegistrationMailer;
use App\Support\SystemConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function __construct(private CustomerRegistrationMailer $registrationMailer) {}

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->companyRules());

        $validated['type'] = Company::TYPE_CUSTOMER;
        $validated['status'] = $validated['status'] ?? 'pending';
        if (empty($validated['payment_term'])) {
            $validated['payment_term'] = SystemConfig::defaultPaymentTerm();
        }
        if (! array_key_exists('postpaid_term_days', $validated) || $validated['postpaid_term_days'] === null) {
            $validated['postpaid_term_days'] = SystemConfig::defaultPostpaidTermDays();
        }

        $company = Company::create($validated);

        if (! empty($validated['pic_email']) && ! empty($validated['password'])) {
            $user = User::create([
                'name' => $validated['pic_name'] ?? $validated['contact_person'] ?? $company->name,
                'email' => $validated['pic_email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['pic_phone'] ?? null,
                'user_type' => 'customer',
                'company_id' => $company->id,
                'status' => 'active',
            ]);
            $user->assignRole('company_admin');
        }

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

        if (isset($validated['status']) && $validated['status'] === 'active' && ! $company->reviewed_at) {
            $validated['reviewed_at'] = now();
            $validated['reviewed_by'] = $request->user()?->id;
        }

        if (isset($validated['status']) && $validated['status'] === 'active' && ! $company->approved_at) {
            $validated['approved_at'] = now();
            $validated['approved_by'] = $request->user()?->id;
        }

        $company->update($validated);

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

        $activeHoCount = $company->customerLocations()
            ->where('type', 'head_office')
            ->where('status', 'active')
            ->count();

        if ($activeHoCount < 1) {
            return response()->json([
                'message' => 'Customer wajib memiliki minimal 1 Head Office aktif sebelum diaktifkan.',
                'errors' => ['locations' => ['Tambahkan Head Office aktif pada tab Locations.']],
            ], 422);
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

        User::where('company_id', $company->id)
            ->where('status', '!=', 'active')
            ->update(['status' => 'active']);

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
        $sometimes = $partial ? 'sometimes|' : '';

        return [
            'name' => $sometimes.'required|string|max:255|'.Rule::unique('companies', 'name')->ignore($companyId),
            'business_entity_type' => 'nullable|string|max:20|in:PT,CV,UD,Koperasi,Yayasan,Firma,Perorangan,Lainnya',
            'business_entity_other' => 'nullable|string|max:255',
            'company_code' => 'nullable|string|size:3|alpha|'.Rule::unique('companies', 'company_code')->ignore($companyId),
            'npwp' => 'nullable|string|max:16',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:80',
            'district' => 'nullable|string|max:120',
            'postal_code' => 'nullable|string|max:20',
            'business_category' => 'nullable|string|max:50',
            'business_category_other' => 'nullable|string|max:255',
            'monthly_shipment_estimate' => 'nullable|string|max:30',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'website' => 'nullable|string|max:255',
            'status' => 'nullable|in:pending,active,suspended,inactive,rejected',
            'billing_type' => 'nullable|in:prepaid,postpaid',
            'pricing_type' => 'nullable|in:standard,discount',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'billing_cycle' => 'nullable|in:per_shipment,semi_monthly,monthly,half_monthly_1,half_monthly_2,both_half,end_of_month',
            'payment_term' => 'nullable|in:cod,net_7,net_14,net_30,net_45,net_60',
            'credit_limit' => 'nullable|numeric|min:0',
            'payment_type' => 'nullable|in:prepaid,postpaid',
            'postpaid_term_days' => 'nullable|integer|min:0|max:365',
            'sales_pic_id' => 'nullable|exists:users,id',
            'account_manager_id' => 'nullable|exists:users,id',
            'review_notes' => 'nullable|string|max:5000',
            'rejection_reason' => 'nullable|string|max:500',
            'pic_name' => 'nullable|string|max:255',
            'pic_email' => 'nullable|email|max:255|'.Rule::unique('users', 'email'),
            'pic_phone' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:8',
        ];
    }
}
