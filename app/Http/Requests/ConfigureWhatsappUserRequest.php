<?php

namespace App\Http\Requests;

use App\Services\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

class ConfigureWhatsappUserRequest extends FormRequest
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
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^255\d{9}$/'],
            'opted_in' => ['required', 'boolean'],
        ];
    }
}
