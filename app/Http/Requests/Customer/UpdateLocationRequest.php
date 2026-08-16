<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Enums\LocationStatus;
use App\Enums\LocationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('view_locations');
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::enum(LocationType::class)],
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:30',
            'status' => ['sometimes', Rule::enum(LocationStatus::class)],
            'country' => 'sometimes|string|max:80',
            'province' => 'sometimes|string|max:255',
            'city' => 'sometimes|string|max:255',
            'district' => 'nullable|string|max:120',
            'postal_code' => 'nullable|string|max:20',
            'address' => 'sometimes|string',
            'pic_name' => 'sometimes|string|max:255',
            'pic_email' => 'sometimes|email|max:255',
            'pic_mobile' => 'sometimes|string|max:30',
        ];
    }
}
