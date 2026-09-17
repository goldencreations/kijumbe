<?php

namespace App\Models;

use App\Enums\SystemRole;
use App\Services\PhoneNumber;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'phone', 'whatsapp_wa_id', 'password', 'system_role', 'is_active',
    'whatsapp_opted_in_at', 'whatsapp_opted_out_at',
])]
#[Hidden(['password', 'remember_token', 'payout_pin_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $appends = ['phone_local', 'whatsapp_phone_local'];

    public function getPhoneLocalAttribute(): ?string
    {
        return app(PhoneNumber::class)->forDisplay($this->phone);
    }

    public function getWhatsappPhoneLocalAttribute(): ?string
    {
        return app(PhoneNumber::class)->forDisplay($this->whatsapp_wa_id ?: $this->phone);
    }

    public function groupMemberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function isAdmin(): bool
    {
        return $this->system_role === SystemRole::Admin;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'system_role' => SystemRole::class,
            'is_active' => 'boolean',
            'whatsapp_opted_in_at' => 'datetime',
            'whatsapp_opted_out_at' => 'datetime',
            'payout_pin_set_at' => 'datetime',
        ];
    }

    public function hasPayoutPin(): bool
    {
        return filled($this->payout_pin_hash);
    }
}
