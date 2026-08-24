<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'contribution_amount' => ['required', 'integer', 'min:100'],
            'period_days' => ['required', 'integer', 'min:1', 'max:365'],
            'grace_period_hours' => ['required', 'integer', 'min:1', 'max:336'],
            'starts_at' => ['required', 'date'],
            'members' => ['required', 'array', 'min:2', 'max:100'],
            'members.*.group_member_id' => ['required', 'integer', 'distinct', 'exists:group_members,id'],
            'members.*.payout_position' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
