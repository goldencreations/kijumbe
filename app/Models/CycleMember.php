<?php

namespace App\Models;

use App\Enums\CycleMemberStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CycleMember extends Model
{
    use HasFactory;

    protected $fillable = ['cycle_id', 'group_member_id', 'group_member_slot_id', 'payout_position', 'status'];

    protected function casts(): array
    {
        return [
            'status' => CycleMemberStatus::class,
            'payout_position' => 'integer',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function groupMember(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(GroupMemberSlot::class, 'group_member_slot_id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function recipientRounds(): HasMany
    {
        return $this->hasMany(Round::class, 'recipient_cycle_member_id');
    }
}
