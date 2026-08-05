<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => 'sometimes|string|max:255',
            'email' => "sometimes|email|max:255|unique:users,email,{$userId}",
            'phone' => 'nullable|string|max:30',
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'location_ids' => 'sometimes|array',
            'location_ids.*' => 'integer|exists:customer_locations,id',
            'feature_access' => 'sometimes|array',
            'feature_access.*' => 'string',
        ];
    }
}
