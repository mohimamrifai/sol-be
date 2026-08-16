<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('create_users');
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:30',
            'role' => ['required', Rule::in([
                UserRole::CompanyAdmin->value,
                UserRole::OpsPic->value,
                UserRole::FinancePic->value,
                UserRole::Viewer->value,
            ])],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'location_ids' => 'required|array|min:1',
            'location_ids.*' => 'integer|exists:customer_locations,id',
            'feature_access' => 'nullable|array',
            'feature_access.*' => 'string',
        ];
    }
}
