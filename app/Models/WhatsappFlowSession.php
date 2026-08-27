<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappFlowSession extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'flow_key',
        'step_key',
        'payload',
        'last_message_id',
        'last_interaction_at',
        'expires_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_interaction_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
