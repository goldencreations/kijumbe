<?php

namespace App\Models;

use App\Enums\ContributionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'round_id', 'cycle_member_id', 'amount', 'status', 'due_at', 'grace_ends_at',
        'paid_at', 'payment_reference', 'idempotency_key', 'recorded_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContributionStatus::class,
            'amount' => 'integer',
            'due_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function cycleMember(): BelongsTo
    {
        return $this->belongsTo(CycleMember::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }
}
