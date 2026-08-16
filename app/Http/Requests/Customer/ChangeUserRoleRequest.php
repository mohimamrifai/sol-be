<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('edit_users');
    }

    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in([
                UserRole::CompanyAdmin->value,
                UserRole::OpsPic->value,
                UserRole::FinancePic->value,
                UserRole::Viewer->value,
            ])],
        ];
    }
}
