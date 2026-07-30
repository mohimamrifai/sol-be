<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            // ---- Section 1: Company Information ----
            'business_entity_type' => ['required', 'string', Rule::in([
                'PT', 'CV', 'UD', 'Koperasi', 'Yayasan', 'Firma', 'Perorangan', 'Lainnya',
            ])],
            'business_entity_other' => ['nullable', 'required_if:business_entity_type,Lainnya', 'string', 'max:100'],

            'company_name' => ['required', 'string', 'max:255', 'unique:companies,name'],

            'company_code' => [
                'required', 'string', 'size:3',
                'regex:/^[A-Z]{3}$/',
                'unique:companies,company_code',
            ],

            // NPWP: 16 digit dengan/tanpa format (titik, dash). Sanitize ke 16 digit numerik.
            'npwp' => ['required', 'string', 'max:30', 'regex:/^[0-9.\- ]{9,30}$/'],

            'company_email' => ['required', 'email', 'max:255', 'unique:companies,email'],
            'company_phone' => ['required', 'string', 'max:30'],
            'website' => ['nullable', 'string', 'max:255'],

            // ---- Section 2: Company Address (cascading) ----
            'country' => ['required', 'string', 'max:80'],
            'province' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'district' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:10'],
            'address' => ['required', 'string', 'max:500'],

            // ---- Section 3: Operational Information ----
            'business_category' => ['required', Rule::in([
                'trading', 'manufacturing', 'retail', 'distributor',
                'e_commerce', 'logistics', 'others',
            ])],
            'business_category_other' => ['nullable', 'required_if:business_category,others', 'string', 'max:100'],
            'monthly_shipment_estimate' => ['required', Rule::in([
                '<10', '10-50', '50-100', '>100',
            ])],

            // ---- Section 4: Admin Account ----
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'terms_accepted' => ['accepted'],
        ], [
            'company_name.unique' => 'Perusahaan dengan nama yang sama sudah terdaftar.',
            'company_code.regex' => 'Customer Code harus 3 huruf A–Z.',
            'company_code.unique' => 'Customer Code sudah digunakan. Silakan pilih kode lain.',
            'npwp.regex' => 'NPWP hanya boleh berisi angka, titik, dan strip.',
            'business_entity_other.required_if' => 'Mohon jelaskan bentuk badan usaha pada kolom "Lainnya".',
            'business_category_other.required_if' => 'Mohon jelaskan kategori bisnis pada kolom "Lainnya".',
            'terms_accepted.accepted' => 'Anda harus menyetujui Syarat & Ketentuan.',
        ]);

        // Enforce NPWP = exactly 16 digits (after stripping punctuation).
        $npwpDigits = preg_replace('/\D/', '', $data['npwp']) ?? '';
        if (strlen($npwpDigits) !== 15 && strlen($npwpDigits) !== 16) {
            throw ValidationException::withMessages([
                'npwp' => 'NPWP harus terdiri dari 15 atau 16 digit angka.',
            ]);
        }

        // Resolve final business_entity_type for storage: 'Lainnya' is a UI sentinel.
        $businessEntityType = $data['business_entity_type'] === 'Lainnya'
            ? 'Lainnya'  // stored literally, free text lives in business_entity_other
            : $data['business_entity_type'];

        $company = Company::create([
            'name' => $data['company_name'],
            'business_entity_type' => $businessEntityType,
            'business_entity_other' => $data['business_entity_other'] ?? null,
            'company_code' => strtoupper($data['company_code']),
            'npwp' => $npwpDigits,
            'address' => $data['address'],
            'city' => $data['city'],
            'province' => $data['province'],
            'country' => $data['country'],
            'district' => $data['district'],
            'postal_code' => $data['postal_code'],
            'phone' => $data['company_phone'],
            'email' => $data['company_email'],
            'website' => $data['website'] ?? null,
            'business_category' => $data['business_category'],
            'business_category_other' => $data['business_category_other'] ?? null,
            'monthly_shipment_estimate' => $data['monthly_shipment_estimate'],
            'contact_person' => $data['admin_name'],
            'status' => 'pending',
            'payment_type' => 'prepaid',
            'billing_cycle' => 'end_of_month',
        ]);

        $user = User::create([
            'name' => $data['admin_name'],
            'email' => $data['admin_email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['admin_phone'] ?? null,
            'user_type' => 'customer',
            'company_id' => $company->id,
            // User is inactive until the company registration is approved by internal reviewer.
            'status' => 'inactive',
        ]);

        // Role is pre-assigned so that approval only needs to flip the user.status to 'active'.
        $user->assignRole('company_admin');

        return response()->json([
            'message' => 'Registrasi berhasil. Akun Anda akan direview oleh tim kami dan email konfirmasi akan dikirim.',
            'data' => [
                'company' => $company,
                'user' => $user->load('roles'),
            ],
        ], 201);
    }

    /**
     * Cek ketersediaan Customer Code (3 char A–Z, unik global).
     * Dipakai FE untuk validasi live saat customer mengetik.
     */
    public function checkCompanyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'ignore_id' => ['nullable', 'integer'],
        ]);

        $code = strtoupper($data['code']);
        $query = Company::withTrashed()->where('company_code', $code);
        if (! empty($data['ignore_id'])) {
            $query->where('id', '!=', $data['ignore_id']);
        }
        $exists = $query->exists();

        return response()->json([
            'code' => $code,
            'exists' => $exists,
            'message' => $exists ? 'Kode sudah digunakan.' : 'Kode tersedia.',
        ]);
    }
}
