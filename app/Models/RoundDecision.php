<?php

namespace App\Models;

use App\Enums\DecisionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoundDecision extends Model
{
    use HasFactory;

    protected $fillable = ['round_id', 'type', 'payload', 'reason', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return [
            'type' => DecisionType::class,
            'payload' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
