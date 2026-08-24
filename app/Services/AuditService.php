<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function record(
        string $action,
        Model $subject,
        ?User $actor,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
    ): AuditLog {
        return AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'metadata' => $metadata ?: null,
        ]);
    }
}
