<?php

namespace Tests\Feature;

use App\Enums\CycleMemberStatus;
use App\Enums\CycleStatus;
use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Enums\PayoutStatus;
use App\Enums\RoundStatus;
use App\Models\Cycle;
use App\Models\CycleMember;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payout;
use App\Models\Round;
use App\Models\User;
use App\Services\GoodsPayoutAggregationService;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class GoodsPayoutAggregationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_confirmed_goods_receipts_in_one_group_become_one_principal_payout(): void
    {
        [$coordinator, $sources] = $this->goodsReceipts();

        $service = app(GoodsPayoutAggregationService::class);
        $service->aggregateFor($coordinator);

        $aggregate = Payout::query()->whereNull('round_id')->where('purpose', Payout::PURPOSE_GOODS_PROCEEDS)->firstOrFail();
        $this->assertSame(30000, $aggregate->amount);
        $this->assertSame(0, $aggregate->kijumbe_fee_amount);
        $this->assertSame(0, $aggregate->platform_fee_amount);
        $this->assertSame($sources->pluck('id')->sort()->values()->all(), $aggregate->goodsSources()->pluck('id')->sort()->values()->all());
        $this->assertSame(['Asha', 'Juma'], $service->summary($aggregate)->pluck('name')->sort()->values()->all());

        $service->aggregateFor($coordinator);
        $this->assertDatabaseCount('payouts', 3);
    }

    public function test_provider_success_marks_every_source_and_round_paid(): void
    {
        [$coordinator] = $this->goodsReceipts();
        app(GoodsPayoutAggregationService::class)->aggregateFor($coordinator);
        $aggregate = Payout::query()->whereNull('round_id')->firstOrFail();
        $aggregate->update(['status' => PayoutStatus::Processing]);

        app(PayoutService::class)->completeFromProvider($aggregate, 'goods-total-001', ['status' => 'paid']);

        $this->assertSame(PayoutStatus::Paid, $aggregate->fresh()->status);
        $this->assertFalse($aggregate->goodsSources()->where('status', '!=', PayoutStatus::Paid->value)->exists());
        $this->assertFalse(Round::query()->where('status', '!=', RoundStatus::Paid->value)->exists());
    }

    /** @return array{User, Collection<int, Payout>} */
    private function goodsReceipts(): array
    {
        $coordinator = User::factory()->create();
        $group = Group::query()->create([
            'name' => 'Bidhaa Yetu',
            'group_type' => Group::TYPE_PRODUCT,
            'status' => GroupStatus::Active,
            'created_by' => $coordinator->id,
        ]);
        $cycle = Cycle::query()->create([
            'group_id' => $group->id,
            'name' => 'Mzunguko wa bidhaa',
            'contribution_amount' => 15000,
            'period_days' => 1,
            'grace_period_hours' => 0,
            'starts_at' => now()->subDay(),
            'member_count' => 2,
            'status' => CycleStatus::Active,
        ]);

        $sources = collect(['Asha', 'Juma'])->map(function (string $name, int $index) use ($group, $cycle, $coordinator): Payout {
            $user = User::factory()->create();
            $member = GroupMember::query()->create([
                'group_id' => $group->id,
                'user_id' => $user->id,
                'member_name' => $name,
                'role' => GroupRole::Member,
                'is_active' => true,
                'joined_at' => now(),
            ]);
            $cycleMember = CycleMember::query()->create([
                'cycle_id' => $cycle->id,
                'group_member_id' => $member->id,
                'payout_position' => $index + 1,
                'status' => CycleMemberStatus::Active,
            ]);
            $round = Round::query()->create([
                'cycle_id' => $cycle->id,
                'number' => $index + 1,
                'recipient_cycle_member_id' => $cycleMember->id,
                'due_at' => now()->subHour(),
                'grace_ends_at' => now(),
                'required_amount' => 15000,
                'collected_amount' => 15000,
                'payout_amount' => 15000,
                'gross_payout_amount' => 15000,
                'status' => RoundStatus::ReadyForPayout,
            ]);

            return Payout::query()->create([
                'purpose' => Payout::PURPOSE_GOODS_PROCEEDS,
                'round_id' => $round->id,
                'recipient_user_id' => $coordinator->id,
                'amount' => 15000,
                'gross_amount' => 15000,
                'status' => PayoutStatus::Pending,
            ]);
        });

        return [$coordinator, $sources];
    }
}
