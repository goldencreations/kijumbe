<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:191'],
            'method' => ['sometimes', 'string', 'in:mobile_money,qr'],
            'payment_phone' => ['nullable', 'string', 'max:32'],
        ];
    }
}
