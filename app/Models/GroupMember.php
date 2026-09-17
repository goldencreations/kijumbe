<?php

namespace App\Models;

use App\Enums\GroupRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id', 'user_id', 'member_name', 'majina_count', 'terms_accepted_at', 'role', 'is_active', 'joined_at', 'left_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => GroupRole::class,
            'majina_count' => 'integer',
            'terms_accepted_at' => 'datetime',
            'is_active' => 'boolean',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cycleMemberships(): HasMany
    {
        return $this->hasMany(CycleMember::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(GroupMemberSlot::class);
    }
}
