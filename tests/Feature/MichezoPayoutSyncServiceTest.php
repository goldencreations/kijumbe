<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\RoundStatus;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMemberSlot;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\MemberPaymentLedgerService;
use App\Services\MichezoPayoutSyncService;
use App\Services\Whatsapp\MetaWhatsAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class MichezoPayoutSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_obligation_due_later_today_is_materialized_before_its_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-18 09:00:00', 'Africa/Dar_es_Salaam'));
        $user = User::factory()->create(['phone' => '255700000001', 'whatsapp_wa_id' => '255700000001']);
        $group = Group::create([
            'name' => 'Due today test',
            'status' => GroupStatus::Active,
            'created_by' => $user->id,
            'contribution_amount' => 200,
            'currency' => 'TZS',
            'contribution_interval_days' => 1,
            'cycle_starts_at' => '2026-09-16 16:10:00',
            'kijumbe_fee' => 50,
        ]);
        $membership = GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'member_name' => $user->name,
            'majina_count' => 1,
            'role' => GroupRole::Member,
            'is_active' => true,
            'joined_at' => now(),
        ]);
        $slot = GroupMemberSlot::create([
            'group_id' => $group->id,
            'group_member_id' => $membership->id,
            'slot_number' => 1,
            'payout_position' => 1,
            'status' => 'active',
        ]);

        app(MemberPaymentLedgerService::class)->materialize($group->fresh());

        $this->assertSame(3, $slot->obligations()->count());
        $this->assertSame(
            '2026-09-18 16:10:00',
            $slot->obligations()->where('round_number', 3)->firstOrFail()->due_at->format('Y-m-d H:i:s'),
        );
        $this->assertFalse($slot->obligations()->where('round_number', 4)->exists());
        Carbon::setTestNow();
    }

    public function test_kijumbe_fee_is_split_30_percent_to_platform_and_70_percent_to_coordinator(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 09:00:00', 'Africa/Dar_es_Salaam'));
        $user = User::factory()->create(['phone' => '255700000001', 'whatsapp_wa_id' => '255700000001']);
        $group = Group::create([
            'name' => 'Fee split test',
            'status' => GroupStatus::Active,
            'created_by' => $user->id,
            'contribution_amount' => 200,
            'currency' => 'TZS',
            'contribution_interval_days' => 1,
            'cycle_starts_at' => now(),
            'kijumbe_fee' => 50,
            'platform_fee_percent' => 30,
            'penalty_amount' => 50,
            'penalty_timing_hours' => 2,
        ]);
        $membership = GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'member_name' => $user->name,
            'majina_count' => 1,
            'role' => GroupRole::Coordinator,
            'is_active' => true,
            'joined_at' => now(),
        ]);
        GroupMemberSlot::create([
            'group_id' => $group->id,
            'group_member_id' => $membership->id,
            'slot_number' => 1,
            'payout_position' => 1,
            'status' => 'active',
        ]);
        $this->app->instance(PaymentGateway::class, new class implements PaymentGateway
        {
            public function initiateCollection(PaymentAttempt $attempt): array
            {
                return ['id' => 'fee-split-payment'];
            }

            public function queryCollection(PaymentAttempt $attempt): array
            {
                return [];
            }

            public function initiatePayout(Payout $payout): array
            {
                return [];
            }

            public function queryPayout(Payout $payout): array
            {
                return [];
            }
        });

        $attempt = app(MemberPaymentLedgerService::class)->initiate(
            $user,
            $group->fresh(),
            250,
            '0748281701',
            'fee-split-001',
        );
        $allocation = $attempt->allocations()->firstOrFail();

        $this->assertSame(200, $allocation->principal_amount);
        $this->assertSame(50, $allocation->kijumbe_fee_amount);
        $this->assertSame(15, $allocation->platform_fee_amount);
        $this->assertSame(35, $allocation->coordinator_fee_amount);
        $this->assertSame(0, $allocation->penalty_amount);
        Carbon::setTestNow();
    }

    public function test_confirmed_principal_creates_an_eat_payout_round_without_fees_or_penalties(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-16 16:20:00', 'Africa/Dar_es_Salaam'));
        $users = User::factory()->count(2)->sequence(
            ['phone' => '255700000001', 'whatsapp_wa_id' => '255700000001'],
            ['phone' => '255700000002', 'whatsapp_wa_id' => '255700000002'],
        )->create();
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
        $this->assertSame([15, 15], $obligations->pluck('platform_fee_amount')->all());
        $this->assertSame([35, 35], $obligations->pluck('coordinator_fee_amount')->all());

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
                'platform_fee_amount' => 15,
                'coordinator_fee_amount' => 35,
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

        $whatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $whatsapp->shouldReceive('sendText')->once()->andReturnUsing(fn () => new WhatsappMessage);
        $this->app->instance(MetaWhatsAppClient::class, $whatsapp);
        $this->assertSame(0, Artisan::call('kijumbe:notify-due-payouts'));
        $this->assertDatabaseHas('payouts', [
            'round_id' => $round->id,
            'amount' => 400,
            'status' => 'pending',
        ]);
        Carbon::setTestNow();
    }
}
