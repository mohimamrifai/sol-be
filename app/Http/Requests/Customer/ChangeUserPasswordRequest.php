<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class ChangeUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('edit_users');
    }

    public function rules(): array
    {
        return [
            'password' => 'required|string|min:8',
        ];
    }
}
