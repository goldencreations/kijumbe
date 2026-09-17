<?php

namespace Tests\Feature;

use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\RoundStatus;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMemberSlot;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\MemberPaymentLedgerService;
use App\Services\MichezoPayoutSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MichezoPayoutSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_principal_creates_an_eat_payout_round_without_fees_or_penalties(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 16:20:00', 'Africa/Dar_es_Salaam'));
        $users = User::factory()->count(2)->create();
        $group = Group::create([
            'name' => 'Payout sync test',
            'status' => GroupStatus::Active,
            'created_by' => $users[0]->id,
            'contribution_amount' => 200,
            'currency' => 'TZS',
            'contribution_interval_days' => 1,
            'payout_interval_days' => 1,
            'cycle_starts_at' => '2026-09-16 16:10:00',
            'kijumbe_fee' => 50,
            'penalty_amount' => 50,
            'penalty_timing_hours' => 2,
        ]);

        foreach ($users as $index => $user) {
            $membership = GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $user->id,
                'member_name' => $user->name,
                'majina_count' => 1,
                'role' => $index === 0 ? GroupRole::Coordinator : GroupRole::Member,
                'is_active' => true,
                'joined_at' => now(),
            ]);
            GroupMemberSlot::create([
                'group_id' => $group->id,
                'group_member_id' => $membership->id,
                'slot_number' => 1,
                'payout_position' => $index + 1,
                'status' => 'active',
            ]);
        }

        $ledger = app(MemberPaymentLedgerService::class);
        $ledger->materialize($group);
        $obligations = $group->fresh()->slots()->with('groupMember')->get()
            ->map(fn ($slot) => $slot->obligations()->firstOrFail());

        foreach ($obligations as $index => $obligation) {
            $attempt = PaymentAttempt::create([
                'contribution_id' => null,
                'payment_scope' => 'member_ledger',
                'initiated_by' => $users[$index]->id,
                'provider' => 'ecofy',
                'method' => 'mobile_money',
                'order_reference' => 'TEST-'.$index,
                'idempotency_key' => 'test-payment-'.$index,
                'phone_e164' => '25570000000'.$index,
                'amount' => 250,
                'currency' => 'TZS',
                'status' => PaymentAttemptStatus::Successful,
                'provider_reference' => 'PROVIDER-'.$index,
                'completed_at' => now(),
                'metadata' => ['group_id' => $group->id, 'member_id' => $users[$index]->id],
            ]);
            PaymentAllocation::create([
                'payment_attempt_id' => $attempt->id,
                'member_payment_obligation_id' => $obligation->id,
                'amount' => 250,
                'principal_amount' => 200,
                'kijumbe_fee_amount' => 50,
                'penalty_amount' => 0,
            ]);
            $obligation->update(['paid_amount' => 250, 'status' => 'paid', 'paid_at' => now()]);
        }

        $this->assertSame(1, app(MichezoPayoutSyncService::class)->sync($group->fresh()));

        $round = $group->cycles()->firstOrFail()->rounds()->firstOrFail();
        $this->assertSame(RoundStatus::ReadyForPayout, $round->status);
        $this->assertSame(400, $round->required_amount);
        $this->assertSame(400, $round->collected_amount);
        $this->assertSame(400, $round->payout_amount);
        $this->assertSame('2026-09-16 16:10:00', $round->due_at->format('Y-m-d H:i:s'));
        $this->assertSame($users[0]->id, $round->recipient->groupMember->user_id);
        $this->assertDatabaseCount('contributions', 2);
        $this->assertDatabaseMissing('contributions', ['status' => 'pending']);
        Carbon::setTestNow();
    }
}
