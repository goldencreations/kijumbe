<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemberPaymentObligation extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id', 'group_member_slot_id', 'round_number', 'due_at', 'principal_amount',
        'kijumbe_fee_amount', 'platform_fee_percent', 'platform_fee_amount',
        'coordinator_fee_amount', 'penalty_amount', 'paid_amount', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'due_at' => 'datetime',
            'principal_amount' => 'integer',
            'kijumbe_fee_amount' => 'integer',
            'platform_fee_percent' => 'integer',
            'platform_fee_amount' => 'integer',
            'coordinator_fee_amount' => 'integer',
            'penalty_amount' => 'integer',
            'paid_amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(GroupMemberSlot::class, 'group_member_slot_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
