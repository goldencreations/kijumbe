<?php

namespace Tests\Feature;

use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMemberSlot;
use App\Models\MemberPaymentObligation;
use App\Models\User;
use App\Services\MemberPaymentLedgerService;
use App\Services\Whatsapp\GroupMemberFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GroupMemberSlotLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_names_are_relabelled_in_payout_order_without_moving_any_position(): void
    {
        Carbon::setTestNow('2026-09-20 09:00:00');
        $group = $this->utwahStyleGroup();
        $before = $group->slots()->pluck('id', 'payout_position');

        $changed = app(GroupMemberFlowService::class)->normalizeSlotLabels($group);

        $this->assertSame(3, $changed);
        $this->assertSame($before->all(), $group->slots()->pluck('id', 'payout_position')->all());
        $this->assertSame([
            1 => ['Ashraf Madobe', 1],
            2 => ['Derick', 1],
            3 => ['Shaban Ally', 1],
            4 => ['Utwah', 1],
            5 => ['Shaban Ally', 2],
            6 => ['Shaban Ally', 3],
            7 => ['Derick', 2],
        ], $group->slots()
            ->with('groupMember')
            ->orderBy('payout_position')
            ->get()
            ->mapWithKeys(fn (GroupMemberSlot $slot): array => [
                $slot->payout_position => [$slot->groupMember->member_name, $slot->slot_number],
            ])
            ->all());

        $report = app(GroupMemberFlowService::class)->finalReport($group);
        $this->assertStringContainsString('3. Shaban Ally - Jina 1', $report);
        $this->assertStringContainsString('5. Shaban Ally - Jina 2', $report);
        $this->assertStringContainsString('6. Shaban Ally - Jina 3', $report);
        Carbon::setTestNow();
    }

    public function test_every_name_gets_a_round_even_when_member_count_is_smaller_than_name_count(): void
    {
        Carbon::setTestNow('2026-09-20 09:00:00');
        $group = $this->utwahStyleGroup();

        app(MemberPaymentLedgerService::class)->materialize($group);

        $obligations = MemberPaymentObligation::query()->where('group_id', $group->id);
        $this->assertSame(7, (int) (clone $obligations)->max('round_number'));
        $this->assertSame(49, (clone $obligations)->count());
        Carbon::setTestNow();
    }

    public function test_contribution_rounds_cover_the_full_payout_schedule(): void
    {
        Carbon::setTestNow('2026-09-27 09:00:00');
        $group = $this->utwahStyleGroup();
        $group->update(['cycle_starts_at' => now()->subDays(13)]);

        app(MemberPaymentLedgerService::class)->materialize($group->fresh());

        $obligations = MemberPaymentObligation::query()->where('group_id', $group->id);
        $this->assertSame(14, (int) (clone $obligations)->max('round_number'));
        $this->assertSame(98, (clone $obligations)->count());
        Carbon::setTestNow();
    }

    private function utwahStyleGroup(): Group
    {
        $creator = User::factory()->create();
        $group = Group::create([
            'name' => 'Utwah grp',
            'status' => GroupStatus::Active,
            'created_by' => $creator->id,
            'contribution_amount' => 200,
            'currency' => 'TZS',
            'contribution_interval_days' => 1,
            'payout_interval_days' => 2,
            'member_slots' => 4,
            'cycle_starts_at' => now()->subDays(6),
            'kijumbe_fee' => 50,
        ]);

        $members = [];
        foreach ([
            ['Ashraf Madobe', 1],
            ['Derick', 2],
            ['Shaban Ally', 3],
            ['Utwah', 1],
        ] as $index => [$name, $names]) {
            $user = $index === 0 ? $creator : User::factory()->create();
            $members[$name] = GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $user->id,
                'member_name' => $name,
                'majina_count' => $names,
                'role' => $index === 0 ? GroupRole::Coordinator : GroupRole::Member,
                'is_active' => true,
                'joined_at' => now(),
            ]);
        }

        foreach ([
            [1, 'Ashraf Madobe', 1],
            [2, 'Derick', 1],
            [3, 'Shaban Ally', 2],
            [4, 'Utwah', 1],
            [5, 'Shaban Ally', 1],
            [6, 'Shaban Ally', 3],
            [7, 'Derick', 2],
        ] as [$position, $name, $slotNumber]) {
            GroupMemberSlot::create([
                'group_id' => $group->id,
                'group_member_id' => $members[$name]->id,
                'slot_number' => $slotNumber,
                'payout_position' => $position,
                'status' => 'active',
            ]);
        }

        return $group;
    }
}
