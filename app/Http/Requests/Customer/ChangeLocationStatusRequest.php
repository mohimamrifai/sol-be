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
        return $this->user() !== null && $this->user()->can('view_locations');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(LocationStatus::class)],
        ];
    }
}
