<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_key', 'event_type', 'meta_message_id', 'payload', 'processed_at', 'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
