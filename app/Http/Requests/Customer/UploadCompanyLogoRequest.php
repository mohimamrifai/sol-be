<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UploadCompanyLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('view_company');
    }

    public function rules(): array
    {
        return [
            'logo' => 'required|image|mimes:jpg,jpeg,png|max:5120',
        ];
    }
}
