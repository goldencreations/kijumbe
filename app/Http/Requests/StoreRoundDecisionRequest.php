<?php

namespace App\Http\Requests;

use App\Enums\DecisionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoundDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DecisionType::class)],
            'reason' => ['required', 'string', 'max:1000'],
            'new_grace_ends_at' => [
                Rule::requiredIf(fn () => $this->input('type') === DecisionType::ExtendGrace->value),
                'nullable',
                'date',
            ],
        ];
    }
}
