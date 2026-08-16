<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('view_company');
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'npwp' => 'nullable|string|max:30',
            'nib' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'website' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'country' => 'nullable|string|max:80',
            'province' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:120',
            'postal_code' => 'nullable|string|max:20',
            'contact_person' => 'nullable|string|max:255',
            'business_category' => 'sometimes|string|in:trading,manufacturing,retail,distributor,e_commerce,logistics,others',
            'business_category_other' => 'nullable|string|max:255',
            'monthly_shipment_estimate' => 'sometimes|string|in:under_10,10_to_50,50_to_100,over_100',
        ];
    }
}
