<?php

namespace App\Models;

use App\Enums\CycleStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id', 'name', 'contribution_amount', 'currency', 'period_days',
        'grace_period_hours', 'starts_at', 'member_count', 'status',
        'activated_at', 'completed_at', 'completion_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CycleStatus::class,
            'starts_at' => 'datetime',
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
            'completion_notified_at' => 'datetime',
            'contribution_amount' => 'integer',
            'member_count' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(CycleMember::class);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }
}
