<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMessageTemplate extends Model
{
    protected $fillable = ['key', 'body', 'interaction', 'updated_by'];

    protected function casts(): array
    {
        return ['interaction' => 'array'];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
