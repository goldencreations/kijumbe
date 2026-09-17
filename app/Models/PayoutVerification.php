<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'payout_id', 'recipient_user_id', 'payment_phone', 'otp_hash', 'otp_expires_at',
        'otp_attempts', 'otp_verified_at', 'pin_verified_at', 'status', 'expires_at',
        'payment_quote_id', 'payment_quote_name', 'payment_quote_fee', 'payment_quote_total',
        'payment_quote_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'otp_expires_at' => 'datetime',
            'otp_verified_at' => 'datetime',
            'pin_verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'payment_quote_fee' => 'integer',
            'payment_quote_total' => 'integer',
            'payment_quote_expires_at' => 'datetime',
            'otp_attempts' => 'integer',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
