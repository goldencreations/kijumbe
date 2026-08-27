<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappInboundContact extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'wa_id',
        'phone_number_id',
        'last_message_id',
        'last_message_type',
        'first_seen_at',
        'last_seen_at',
        'message_count',
        'last_payload',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_payload' => 'array',
            'message_count' => 'integer',
        ];
    }
}
