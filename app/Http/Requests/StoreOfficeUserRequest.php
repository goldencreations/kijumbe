<?php

namespace App\Http\Requests;

use App\Enums\SystemRole;
use App\Services\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class StoreOfficeUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! filled($this->input('phone'))) {
            return;
        }

        try {
            $this->merge(['phone' => app(PhoneNumber::class)->toTanzanianWaId($this->input('phone'))]);
        } catch (InvalidArgumentException) {
            // Keep the submitted value so the validation rule returns a 422 response.
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^255\d{9}$/', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8'],
            'system_role' => ['sometimes', Rule::enum(SystemRole::class)],
        ];
    }
}
