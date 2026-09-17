<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_attempt_id', 'member_payment_obligation_id', 'amount', 'principal_amount',
        'kijumbe_fee_amount', 'platform_fee_amount', 'coordinator_fee_amount', 'penalty_amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'principal_amount' => 'integer',
            'kijumbe_fee_amount' => 'integer',
            'platform_fee_amount' => 'integer',
            'coordinator_fee_amount' => 'integer',
            'penalty_amount' => 'integer',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(MemberPaymentObligation::class, 'member_payment_obligation_id');
    }
}
