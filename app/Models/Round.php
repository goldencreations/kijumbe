<?php

namespace App\Models;

use App\Enums\RoundStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Round extends Model
{
    use HasFactory;

    protected $fillable = [
        'cycle_id', 'number', 'recipient_cycle_member_id', 'due_at', 'grace_ends_at',
        'required_amount', 'collected_amount', 'payout_amount', 'gross_payout_amount',
        'kijumbe_fee_amount', 'platform_fee_amount', 'coordinator_fee_amount', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RoundStatus::class,
            'number' => 'integer',
            'due_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'paid_at' => 'datetime',
            'required_amount' => 'integer',
            'collected_amount' => 'integer',
            'payout_amount' => 'integer',
            'gross_payout_amount' => 'integer',
            'kijumbe_fee_amount' => 'integer',
            'platform_fee_amount' => 'integer',
            'coordinator_fee_amount' => 'integer',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CycleMember::class, 'recipient_cycle_member_id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(RoundDecision::class);
    }

    public function payout(): HasOne
    {
        return $this->hasOne(Payout::class);
    }
}
