<?php

namespace App\Http\Requests;

use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'language' => ['nullable', 'string', 'max:12'],
            'group_type' => ['nullable', Rule::in([Group::TYPE_MONEY, Group::TYPE_PRODUCT])],
            'contribution_amount' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'period_days' => ['nullable', 'integer', 'min:1'],
            'member_slots' => ['nullable', 'integer', 'min:1'],
            'cycle_starts_at' => ['nullable', 'date'],
            'payout_order' => ['nullable', 'string', 'max:32'],
            'grace_period_hours' => ['nullable', 'integer', 'min:0'],
            'platform_fee_amount' => ['nullable', 'integer', 'min:0'],
            'platform_fee_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'resolution_threshold_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
