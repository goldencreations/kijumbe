<?php

namespace App\Services;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GoodsPayoutAggregationService
{
    /**
     * Freeze every currently confirmed receipt into one payable record per mchezo.
     * The source rows remain as the permanent member-level audit trail.
     */
    public function aggregateFor(User $coordinator): void
    {
        $groupIds = Payout::query()
            ->where('purpose', Payout::PURPOSE_GOODS_PROCEEDS)
            ->where('recipient_user_id', $coordinator->id)
            ->whereNull('goods_aggregate_payout_id')
            ->whereNotNull('round_id')
            ->whereIn('status', [PayoutStatus::Pending->value, PayoutStatus::Approved->value])
            ->whereHas('round.cycle.group', fn ($query) => $query->where('created_by', $coordinator->id))
            ->with('round.cycle')
            ->get()
            ->map(fn (Payout $payout): ?string => $payout->round?->cycle?->group_id)
            ->filter()
            ->unique();

        foreach ($groupIds as $groupId) {
            $this->aggregateGroup($coordinator, (string) $groupId);
        }
    }

    private function aggregateGroup(User $coordinator, string $groupId): ?Payout
    {
        return DB::transaction(function () use ($coordinator, $groupId): ?Payout {
            $sources = Payout::query()
                ->where('purpose', Payout::PURPOSE_GOODS_PROCEEDS)
                ->where('recipient_user_id', $coordinator->id)
                ->whereNull('goods_aggregate_payout_id')
                ->whereNotNull('round_id')
                ->whereIn('status', [PayoutStatus::Pending->value, PayoutStatus::Approved->value])
                ->whereHas('round.cycle', fn ($query) => $query->where('group_id', $groupId))
                ->with('round.cycle')
                ->lockForUpdate()
                ->get();

            if ($sources->isEmpty()) {
                return null;
            }

            $total = $sources->sum('amount');
            $aggregate = Payout::query()->create([
                'purpose' => Payout::PURPOSE_GOODS_PROCEEDS,
                'cycle_id' => $sources->first()->round->cycle_id,
                'recipient_user_id' => $coordinator->id,
                'amount' => $total,
                'gross_amount' => $total,
                'kijumbe_fee_amount' => 0,
                'platform_fee_amount' => 0,
                'coordinator_fee_amount' => 0,
                'status' => PayoutStatus::Pending,
                'provider' => 'ecofy',
                'order_reference' => 'KJP-GOODS-'.strtoupper((string) str()->ulid()),
                'idempotency_key' => 'goods-aggregate-'.str()->uuid(),
                'notes' => 'Combined confirmed goods principal for '.$sources->count().' member receipt(s).',
            ]);

            Payout::query()->whereKey($sources->pluck('id'))->update([
                'goods_aggregate_payout_id' => $aggregate->id,
                'status' => PayoutStatus::Processing,
            ]);

            return $aggregate;
        }, 3);
    }

    /** @return Collection<int, array{name: string, amount: int, round: int|string}> */
    public function summary(Payout $aggregate): Collection
    {
        return $aggregate->goodsSources()
            ->with('round.recipient.groupMember')
            ->oldest('id')
            ->get()
            ->map(fn (Payout $source): array => [
                'name' => $source->round?->recipient?->groupMember?->member_name ?: 'Mwanachama',
                'amount' => (int) $source->amount,
                'round' => $source->round?->number ?? '-',
            ]);
    }
}
