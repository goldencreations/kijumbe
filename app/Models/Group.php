<?php

namespace App\Models;

use App\Enums\GroupStatus;
use App\Services\PhoneNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    use HasFactory, HasUuids;

    public const TYPE_MONEY = 'money';

    public const TYPE_PRODUCT = 'product';

    public const PAYOUT_MODE_ROTATING = 'rotating';

    public const PAYOUT_MODE_END_OF_GAME = 'end_of_game';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $appends = ['created_by_phone_local', 'leader_phone_local'];

    public function getCreatedByPhoneLocalAttribute(): ?string
    {
        return app(PhoneNumber::class)->forDisplay($this->created_by_phone);
    }

    public function getLeaderPhoneLocalAttribute(): ?string
    {
        return app(PhoneNumber::class)->forDisplay($this->leader_phone);
    }

    protected $fillable = [
        'name',
        'group_type',
        'description',
        'status',
        'created_by',
        'created_by_phone',
        'created_by_wa_id',
        'leader_name',
        'leader_phone',
        'language',
        'contribution_amount',
        'amount_per_day',
        'contribution_interval_days',
        'currency',
        'period_days',
        'payment_frequency',
        'payout_interval_days',
        'payout_mode',
        'member_slots',
        'cycle_starts_at',
        'payout_order',
        'grace_period_hours',
        'platform_fee_amount',
        'kijumbe_fee',
        'platform_fee_percent',
        'coordinator_fee_amount',
        'penalty_amount',
        'penalty_timing_hours',
        'group_code',
        'resolution_threshold_percent',
        'flow_payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => GroupStatus::class,
            'group_type' => 'string',
            'cycle_starts_at' => 'datetime',
            'contribution_amount' => 'integer',
            'amount_per_day' => 'integer',
            'contribution_interval_days' => 'integer',
            'member_slots' => 'integer',
            'period_days' => 'integer',
            'payment_frequency' => 'string',
            'payout_interval_days' => 'integer',
            'payout_mode' => 'string',
            'grace_period_hours' => 'integer',
            'platform_fee_amount' => 'integer',
            'kijumbe_fee' => 'integer',
            'platform_fee_percent' => 'integer',
            'coordinator_fee_amount' => 'integer',
            'penalty_amount' => 'integer',
            'penalty_timing_hours' => 'integer',
            'resolution_threshold_percent' => 'integer',
            'flow_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $group): void {
            $group->group_type = self::normalizeGroupType($group->group_type);
            $group->payout_mode = self::normalizePayoutMode($group->payout_mode);
            $fee = max((int) ($group->kijumbe_fee ?? $group->platform_fee_amount ?? 0), 0);
            $percent = max(0, min(100, (int) ($group->platform_fee_percent ?? config('kijumbe.platform_fee_percent', 30))));
            [$platform, $coordinator] = self::splitKijumbeFee($fee, $percent);
            $group->kijumbe_fee = $fee;
            $group->platform_fee_percent = $percent;
            $group->platform_fee_amount = $platform;
            $group->coordinator_fee_amount = $coordinator;
        });
    }

    public static function normalizeGroupType(mixed $value): string
    {
        return match (strtolower(trim((string) $value))) {
            self::TYPE_PRODUCT, 'good', 'goods', 'bidhaa' => self::TYPE_PRODUCT,
            default => self::TYPE_MONEY,
        };
    }

    public static function normalizePayoutMode(mixed $value): string
    {
        return match (strtolower(trim((string) $value))) {
            self::PAYOUT_MODE_END_OF_GAME, 'end', 'mwisho', 'mwisho wa mchezo' => self::PAYOUT_MODE_END_OF_GAME,
            default => self::PAYOUT_MODE_ROTATING,
        };
    }

    public function getPayoutModeLabelAttribute(): string
    {
        return self::normalizePayoutMode($this->payout_mode) === self::PAYOUT_MODE_END_OF_GAME
            ? 'Marejesho mwisho wa mchezo'
            : 'Marejesho ya kawaida kwa zamu';
    }

    /** @return array{0: int, 1: int} */
    public static function splitKijumbeFee(int $fee, int $platformPercent): array
    {
        $fee = max($fee, 0);
        $platformPercent = max(0, min(100, $platformPercent));
        $platform = intdiv(($fee * $platformPercent) + 50, 100);

        return [$platform, $fee - $platform];
    }

    public function getGroupTypeLabelAttribute(): string
    {
        return self::normalizeGroupType($this->group_type) === self::TYPE_PRODUCT ? 'Bidhaa' : 'Pesa';
    }

    public function isProductGroup(): bool
    {
        return self::normalizeGroupType($this->group_type) === self::TYPE_PRODUCT;
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

    public function slots(): HasMany
    {
        return $this->hasMany(GroupMemberSlot::class);
    }
}
