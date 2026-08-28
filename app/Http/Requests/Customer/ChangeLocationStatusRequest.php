<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Enums\LocationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeLocationStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(LocationStatus::class)],
        ];
    }
}
