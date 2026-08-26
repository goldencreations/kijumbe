<?php

namespace App\Models;

use App\Enums\GroupStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['name', 'description', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['status' => GroupStatus::class];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function cycles(): HasMany
    {
        return $this->hasMany(Cycle::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $group): void {
            if ($group->getKey()) {
                return;
            }

            $group->setAttribute($group->getKeyName(), (string) str()->uuid());
        });
    }
}
