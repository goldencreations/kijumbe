<?php

namespace App\Models;

use App\Enums\GroupStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Group extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'description',
        'status',
        'created_by',
        'created_by_phone',
        'created_by_wa_id',
        'language',
        'contribution_amount',
        'currency',
        'period_days',
        'member_slots',
        'cycle_starts_at',
        'payout_order',
        'grace_period_hours',
        'platform_fee_amount',
        'resolution_threshold_percent',
        'flow_payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => GroupStatus::class,
            'cycle_starts_at' => 'datetime',
            'contribution_amount' => 'integer',
            'member_slots' => 'integer',
            'period_days' => 'integer',
            'grace_period_hours' => 'integer',
            'platform_fee_amount' => 'integer',
            'resolution_threshold_percent' => 'integer',
            'flow_payload' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(Cycle::class);
    }

}
