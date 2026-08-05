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
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:30',
            'role' => ['required', Rule::enum(UserRole::class)],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'integer|exists:customer_locations,id',
            'feature_access' => 'nullable|array',
            'feature_access.*' => 'string',
        ];
    }
}
