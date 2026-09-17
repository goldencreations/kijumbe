<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use App\Services\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAttempt extends Model
{
    use HasFactory;

    protected $appends = ['phone_local'];

    public function getPhoneLocalAttribute(): ?string
    {
        return app(PhoneNumber::class)->forDisplay($this->phone_e164);
    }

    protected $fillable = [
        'contribution_id', 'initiated_by', 'provider', 'method', 'order_reference', 'idempotency_key',
        'phone_e164', 'amount', 'fee', 'net', 'currency', 'status', 'payment_scope', 'provider_reference',
        'provider_payment_id', 'request_payload', 'response_payload', 'metadata', 'failure_reason',
        'initiated_at', 'completed_at', 'failed_at', 'last_queried_at', 'expires_at', 'available_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentAttemptStatus::class,
            'amount' => 'integer',
            'fee' => 'integer',
            'net' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'metadata' => 'array',
            'initiated_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'last_queried_at' => 'datetime',
            'expires_at' => 'datetime',
            'available_at' => 'datetime',
        ];
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
