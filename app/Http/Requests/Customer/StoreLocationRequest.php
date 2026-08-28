<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Enums\LocationStatus;
use App\Enums\LocationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ($user->can('manage_locations')
                || ($user->user_type === 'customer' && $user->can('view_locations'))
                || ($user->user_type === 'internal' && $user->can('edit_companies')));
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(LocationType::class)],
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'status' => ['nullable', Rule::enum(LocationStatus::class)],
            'country' => 'required|string|max:80',
            'province' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'district' => 'nullable|string|max:120',
            'postal_code' => 'required|string|max:10',
            'address' => 'required|string',
            'pic_name' => 'required|string|max:255',
            'pic_email' => 'required|email|max:255',
            'pic_mobile' => 'required|string|max:30',
        ];
    }
}
