<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Enums\CompanyDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('view_company');
    }

    public function rules(): array
    {
        $typeEnum = CompanyDocumentType::Npwp;
        $maxSize = $typeEnum->maxSizeKb();

        return [
            'type' => ['required', Rule::enum(CompanyDocumentType::class)],
            'label' => 'nullable|string|max:255',
            'file' => "required|file|mimes:pdf,jpg,jpeg,png|max:{$maxSize}",
        ];
    }

    public function messages(): array
    {
        return [
            'type.enum' => 'Tipe dokumen tidak valid.',
            'file.mimes' => 'File harus berformat PDF, JPG, JPEG, atau PNG.',
            'file.max' => 'Ukuran file maksimal 5 MB.',
        ];
    }
}
