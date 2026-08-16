<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\CustomerRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    public function __construct(private CustomerRegistrationService $registrationService) {}

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

        $npwpDigits = preg_replace('/\D/', '', $data['npwp']) ?? '';
        if (strlen($npwpDigits) !== 15 && strlen($npwpDigits) !== 16) {
            throw ValidationException::withMessages([
                'npwp' => 'NPWP harus terdiri dari 15 atau 16 digit angka.',
            ]);
        }

        $result = $this->registrationService->register($data);

        return response()->json([
            'message' => 'Registrasi berhasil. Akun Anda akan direview oleh tim kami. Email konfirmasi telah dikirim.',
            'data' => $result,
        ], 201);
    }

    /**
     * Cek ketersediaan Customer Code (3 char A–Z, unik global).
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
