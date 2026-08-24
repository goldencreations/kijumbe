<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentProviderEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider', 'event_key', 'event_name', 'order_reference', 'provider_reference',
        'verified', 'payload', 'processed_at', 'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
