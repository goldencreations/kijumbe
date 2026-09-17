<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use App\Services\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payout extends Model
{
    use HasFactory;

    protected $appends = ['destination_phone_local'];

    public function getDestinationPhoneLocalAttribute(): ?string
    {
        return app(PhoneNumber::class)->forDisplay($this->destination_phone);
    }

    protected $fillable = [
        'round_id', 'recipient_cycle_member_id', 'amount', 'status',
        'payment_reference', 'destination_phone', 'provider', 'order_reference',
        'provider_reference', 'idempotency_key', 'processed_by', 'requested_by', 'approved_by',
        'processed_at', 'approved_at', 'initiated_at', 'notes', 'response_payload', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'amount' => 'integer',
            'processed_at' => 'datetime',
            'approved_at' => 'datetime',
            'initiated_at' => 'datetime',
            'response_payload' => 'array',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CycleMember::class, 'recipient_cycle_member_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function verification(): HasOne
    {
        return $this->hasOne(PayoutVerification::class);
    }
}
