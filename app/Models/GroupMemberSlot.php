<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupMemberSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id', 'group_member_id', 'slot_number', 'payout_position', 'status',
    ];

    protected function casts(): array
    {
        return [
            'slot_number' => 'integer',
            'payout_position' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function groupMember(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class);
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(MemberPaymentObligation::class);
    }
}
