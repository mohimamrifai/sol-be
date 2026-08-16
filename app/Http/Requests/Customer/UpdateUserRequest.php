<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('edit_users');
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:30',
            'role' => ['sometimes', Rule::in([
                UserRole::CompanyAdmin->value,
                UserRole::OpsPic->value,
                UserRole::FinancePic->value,
                UserRole::Viewer->value,
            ])],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'password' => 'sometimes|nullable|string|min:8',
            'location_ids' => 'sometimes|array|min:1',
            'location_ids.*' => 'integer|exists:customer_locations,id',
            'feature_access' => 'sometimes|array',
            'feature_access.*' => 'string',
        ];
    }
}
