<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RegistrationController extends Controller
{
    /**
     * Registrasi perusahaan baru (status = pending).
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Data Perusahaan
            'company_entity_type' => 'required|string|max:20|in:PT,CV,Firma,UD,Koperasi,Yayasan,Lainnya',
            'company_name' => 'required|string|max:255|unique:companies,name',
            'npwp' => 'required|string|max:30',
            'nib' => 'required|string|max:30',
            'company_address' => 'required|string',
            'city' => 'required|string|max:255',
            'province' => 'required|string|max:255',
            'postal_code' => 'required|string|max:10',
            'company_phone' => 'required|string|max:20',
            // Data User (Company Admin)
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',
        ], [
            'company_name.unique' => 'Perusahaan dengan nama yang sama sudah terdaftar.',
        ]);

        $company = Company::create([
            'name' => $data['company_name'],
            'business_entity_type' => $data['company_entity_type'],
            'npwp' => $data['npwp'] ?? null,
            'nib' => $data['nib'] ?? null,
            'address' => $data['company_address'] ?? null,
            'city' => $data['city'] ?? null,
            'province' => $data['province'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'phone' => $data['company_phone'] ?? null,
            'contact_person' => $data['name'],
            'email' => $data['email'],
            'status' => 'pending',
            'payment_type' => 'prepaid',
            'billing_cycle' => 'end_of_month', // default value, will be ignored for prepaid
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'user_type' => 'customer',
            'company_id' => $company->id,
            'status' => 'pending',
        ]);

        $user->assignRole('company_admin');

        return response()->json([
            'message' => 'Registrasi berhasil. Akun Anda akan direview oleh tim kami.',
            'data' => [
                'company' => $company,
                'user' => $user->load('roles'),
            ],
        ], 201);
    }
}
