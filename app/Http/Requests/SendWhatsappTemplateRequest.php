<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendWhatsappTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'template_name' => ['required', 'string', 'max:191'],
            'language' => ['required', 'string', 'max:16'],
            'components' => ['sometimes', 'array'],
        ];
    }
}
