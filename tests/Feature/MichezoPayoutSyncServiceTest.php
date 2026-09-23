<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\RoundStatus;
use App\Models\Cycle;
use App\Models\CycleMember;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMemberSlot;
use App\Models\MemberPaymentObligation;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Models\Round;
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
            'member_slots' => 4,
            'cycle_starts_at' => '2026-09-16 16:10:00',
            'kijumbe_fee' => 50,
        ]);
        $membership = GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'member_name' => $user->name,
            'majina_count' => 4,
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
        foreach (range(2, 4) as $position) {
            GroupMemberSlot::create([
                'group_id' => $group->id,
                'group_member_id' => $membership->id,
                'slot_number' => $position,
                'payout_position' => $position,
                'status' => 'active',
            ]);
        }

        app(MemberPaymentLedgerService::class)->materialize($group->fresh());

        $this->assertSame(3, $slot->obligations()->count());
        $this->assertSame(
            '2026-09-18 16:10:00',
            $slot->obligations()->where('round_number', 3)->firstOrFail()->due_at->format('Y-m-d H:i:s'),
        );
        $this->assertFalse($slot->obligations()->where('round_number', 4)->exists());
        Carbon::setTestNow();
    }

    public function test_opted_in_member_is_reminded_before_todays_deadline_only_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-17 17:00:00', 'Africa/Dar_es_Salaam'));
        $user = User::factory()->create([
            'phone' => '255700000001',
            'whatsapp_wa_id' => '255700000001',
            'whatsapp_opted_in_at' => now(),
        ]);
        $group = Group::create([
            'name' => 'Reminder test',
            'status' => GroupStatus::Active,
            'created_by' => $user->id,
            'contribution_amount' => 200,
            'currency' => 'TZS',
            'contribution_interval_days' => 1,
            'member_slots' => 2,
            'cycle_starts_at' => '2026-09-18 00:00:00',
            'kijumbe_fee' => 50,
            'penalty_amount' => 50,
            'penalty_timing_hours' => 1,
        ]);
        $membership = GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'member_name' => $user->name,
            'majina_count' => 2,
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
        foreach ([2] as $position) {
            GroupMemberSlot::create([
                'group_id' => $group->id,
                'group_member_id' => $membership->id,
                'slot_number' => $position,
                'payout_position' => $position,
                'status' => 'active',
            ]);
        }

        $ledger = app(MemberPaymentLedgerService::class);
        $ledger->materialize($group->fresh());
        Carbon::setTestNow(Carbon::parse('2026-09-18 09:00:00', 'Africa/Dar_es_Salaam'));
        $message = '';
        $whatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $whatsapp->shouldReceive('sendText')->once()->andReturnUsing(function (User $recipient, string $text) use ($user, &$message): WhatsappMessage {
            $this->assertTrue($recipient->is($user));
            $message = $text;

            return new WhatsappMessage;
        });
        $this->app->instance(MetaWhatsAppClient::class, $whatsapp);

        $this->assertSame(0, Artisan::call('kijumbe:notify-contribution-reminders'));
        $this->assertStringContainsString('Sent 1 contribution reminder(s).', Artisan::output());
        $this->assertSame(
            "Ukumbusho wa mchango wa Reminder test.\n"
                ."Unadaiwa TZS 400 na 100 ya fine ya kuchelewa.\n"
                ."Mchango wa leo unatakiwa kabla ya 00:00 EAT.\n"
                .'Tuma LIPA, chagua mchezo huu, thibitisha kiasi, kisha tuma namba ya simu ya kulipia.',
            $message,
        );
        $this->assertSame(1, $slot->obligations()->count());

        $this->assertSame(0, Artisan::call('kijumbe:notify-contribution-reminders'));
        $this->assertStringContainsString('Sent 0 contribution reminder(s).', Artisan::output());
        Carbon::setTestNow();
    }

    public function test_completed_cycle_is_not_reopened_and_members_are_notified_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19 16:15:00', 'Africa/Dar_es_Salaam'));
        $user = User::factory()->create([
            'phone' => '255700000001',
            'whatsapp_wa_id' => '255700000001',
            'whatsapp_opted_in_at' => now(),
        ]);
        $group = Group::create([
            'name' => 'Completed mchezo',
            'status' => GroupStatus::Active,
            'created_by' => $user->id,
            'contribution_amount' => 200,
            'currency' => 'TZS',
            'contribution_interval_days' => 1,
            'payout_interval_days' => 1,
            'member_slots' => 1,
            'cycle_starts_at' => '2026-09-16 16:10:00',
            'kijumbe_fee' => 50,
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
        $slot = GroupMemberSlot::create([
            'group_id' => $group->id,
            'group_member_id' => $membership->id,
            'slot_number' => 1,
            'payout_position' => 1,
            'status' => 'active',
        ]);
        $obligation = $slot->obligations()->create([
            'group_id' => $group->id,
            'round_number' => 1,
            'due_at' => '2026-09-16 16:10:00',
            'principal_amount' => 200,
            'kijumbe_fee_amount' => 50,
            'platform_fee_percent' => 30,
            'platform_fee_amount' => 15,
            'coordinator_fee_amount' => 35,
            'penalty_amount' => 0,
            'paid_amount' => 250,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        $cycle = Cycle::create([
            'group_id' => $group->id,
            'name' => 'Michezo ledger',
            'contribution_amount' => 200,
            'currency' => 'TZS',
            'period_days' => 1,
            'grace_period_hours' => 2,
            'starts_at' => '2026-09-16 16:10:00',
            'member_count' => 1,
            'status' => 'completed',
            'activated_at' => now()->subDays(3),
            'completed_at' => now(),
        ]);
        $cycleMember = CycleMember::create([
            'cycle_id' => $cycle->id,
            'group_member_id' => $membership->id,
            'group_member_slot_id' => $slot->id,
            'payout_position' => 1,
            'status' => 'active',
        ]);
        Round::create([
            'cycle_id' => $cycle->id,
            'number' => 1,
            'recipient_cycle_member_id' => $cycleMember->id,
            'due_at' => '2026-09-16 16:10:00',
            'grace_ends_at' => '2026-09-16 18:10:00',
            'required_amount' => 200,
            'collected_amount' => 200,
            'payout_amount' => 200,
            'status' => RoundStatus::Paid,
            'paid_at' => now(),
        ]);

        app(MichezoPayoutSyncService::class)->sync($group->fresh());
        $this->assertSame('completed', $cycle->fresh()->status->value);
        $this->assertSame(GroupStatus::Completed, $group->fresh()->status);
        $this->assertNotNull($cycle->fresh()->completed_at);
        $this->assertFalse($slot->obligations()->where('round_number', 2)->exists());
        $this->assertTrue($obligation->fresh()->exists);

        $message = '';
        $whatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $whatsapp->shouldReceive('sendText')->once()->andReturnUsing(function (User $recipient, string $text) use (&$message): WhatsappMessage {
            $message = $text;

            return new WhatsappMessage;
        });
        $this->app->instance(MetaWhatsAppClient::class, $whatsapp);

        $this->assertSame(0, Artisan::call('kijumbe:notify-cycle-completions'));
        $this->assertStringContainsString('MCHEZO UMEISHA', $message);
        $this->assertStringContainsString('Completed mchezo', $message);
        $this->assertNotNull($cycle->fresh()->completion_notified_at);
        $this->assertSame(0, Artisan::call('kijumbe:notify-cycle-completions'));
        Carbon::setTestNow();
    }

    public function test_kijumbe_fee_is_not_added_to_a_member_contribution(): void
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
            200,
            '0748281701',
            'fee-split-001',
        );
        $allocation = $attempt->allocations()->firstOrFail();

        $this->assertSame(200, $allocation->principal_amount);
        $this->assertSame(0, $allocation->kijumbe_fee_amount);
        $this->assertSame(0, $allocation->platform_fee_amount);
        $this->assertSame(0, $allocation->coordinator_fee_amount);
        $this->assertSame(0, $allocation->penalty_amount);
        Carbon::setTestNow();
    }

    public function test_confirmed_principal_creates_a_net_payout_after_deducting_kijumbe_fee(): void
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
        $this->assertSame([0, 0], $obligations->pluck('platform_fee_amount')->all());
        $this->assertSame([0, 0], $obligations->pluck('coordinator_fee_amount')->all());

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
                'amount' => 200,
                'currency' => 'TZS',
                'status' => PaymentAttemptStatus::Successful,
                'provider_reference' => 'PROVIDER-'.$index,
                'completed_at' => now(),
                'metadata' => ['group_id' => $group->id, 'member_id' => $users[$index]->id],
            ]);
            PaymentAllocation::create([
                'payment_attempt_id' => $attempt->id,
                'member_payment_obligation_id' => $obligation->id,
                'amount' => 200,
                'principal_amount' => 200,
                'kijumbe_fee_amount' => 0,
                'platform_fee_amount' => 0,
                'coordinator_fee_amount' => 0,
                'penalty_amount' => 0,
            ]);
            $obligation->update(['paid_amount' => 200, 'status' => 'paid', 'paid_at' => now()]);
        }

        $this->assertSame(1, app(MichezoPayoutSyncService::class)->sync($group->fresh()));

        $round = $group->cycles()->firstOrFail()->rounds()->firstOrFail();
        $this->assertSame(RoundStatus::ReadyForPayout, $round->status);
        $this->assertSame(400, $round->required_amount);
        $this->assertSame(400, $round->collected_amount);
        $this->assertSame(400, $round->gross_payout_amount);
        $this->assertSame(100, $round->kijumbe_fee_amount);
        $this->assertSame(30, $round->platform_fee_amount);
        $this->assertSame(70, $round->coordinator_fee_amount);
        $this->assertSame(300, $round->payout_amount);
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
            'amount' => 300,
            'gross_amount' => 400,
            'kijumbe_fee_amount' => 100,
            'platform_fee_amount' => 30,
            'coordinator_fee_amount' => 70,
            'status' => 'pending',
        ]);
        Carbon::setTestNow();
    }

    public function test_four_names_contributing_ten_thousand_produce_a_thirty_six_thousand_net_payout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-20 09:00:00', 'Africa/Dar_es_Salaam'));
        $users = User::factory()->count(4)->sequence(
            ['phone' => '255700000001', 'whatsapp_wa_id' => '255700000001'],
            ['phone' => '255700000002', 'whatsapp_wa_id' => '255700000002'],
            ['phone' => '255700000003', 'whatsapp_wa_id' => '255700000003'],
            ['phone' => '255700000004', 'whatsapp_wa_id' => '255700000004'],
        )->create();
        $group = Group::create([
            'name' => 'Four member fee example',
            'status' => GroupStatus::Active,
            'created_by' => $users->first()->id,
            'contribution_amount' => 10000,
            'currency' => 'TZS',
            'contribution_interval_days' => 1,
            'payout_interval_days' => 1,
            'member_slots' => 4,
            'cycle_starts_at' => now(),
            'kijumbe_fee' => 1000,
            'platform_fee_percent' => 30,
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
        $obligations = MemberPaymentObligation::query()
            ->where('group_id', $group->id)
            ->where('round_number', 1)
            ->orderBy('group_member_slot_id')
            ->get();

        foreach ($obligations as $index => $obligation) {
            $attempt = PaymentAttempt::create([
                'initiated_by' => $users[$index]->id,
                'provider' => 'ecofy',
                'method' => 'mobile_money',
                'order_reference' => 'EXAMPLE-'.$index,
                'idempotency_key' => 'example-payment-'.$index,
                'phone_e164' => $users[$index]->phone,
                'amount' => 10000,
                'currency' => 'TZS',
                'status' => PaymentAttemptStatus::Successful,
                'payment_scope' => 'member_ledger',
                'completed_at' => now(),
            ]);
            PaymentAllocation::create([
                'payment_attempt_id' => $attempt->id,
                'member_payment_obligation_id' => $obligation->id,
                'amount' => 10000,
                'principal_amount' => 10000,
                'kijumbe_fee_amount' => 0,
                'platform_fee_amount' => 0,
                'coordinator_fee_amount' => 0,
                'penalty_amount' => 0,
            ]);
            $obligation->update(['paid_amount' => 10000, 'status' => 'paid', 'paid_at' => now()]);
        }

        app(MichezoPayoutSyncService::class)->sync($group->fresh());
        $round = $group->cycles()->firstOrFail()->rounds()->firstOrFail();

        $this->assertSame(40000, $round->gross_payout_amount);
        $this->assertSame(4000, $round->kijumbe_fee_amount);
        $this->assertSame(1200, $round->platform_fee_amount);
        $this->assertSame(2800, $round->coordinator_fee_amount);
        $this->assertSame(36000, $round->payout_amount);
        Carbon::setTestNow();
    }

    public function test_fully_funded_future_payout_round_stays_scheduled_with_its_own_grace_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'Africa/Dar_es_Salaam'));
        $users = User::factory()->count(4)->create();
        $group = Group::create([
            'name' => 'Different contribution and payout intervals',
            'status' => GroupStatus::Active,
            'created_by' => $users[0]->id,
            'contribution_amount' => 200,
            'currency' => 'TZS',
            'contribution_interval_days' => 1,
            'payout_interval_days' => 2,
            'cycle_starts_at' => '2026-09-20 00:00:00',
            'penalty_timing_hours' => 1,
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

        app(MemberPaymentLedgerService::class)->materialize($group);
        $obligations = MemberPaymentObligation::query()
            ->where('group_id', $group->id)
            ->where('round_number', 4)
            ->get();

        foreach ($obligations as $index => $obligation) {
            $attempt = PaymentAttempt::create([
                'contribution_id' => null,
                'payment_scope' => 'member_ledger',
                'initiated_by' => $users[$index]->id,
                'provider' => 'ecofy',
                'method' => 'mobile_money',
                'order_reference' => 'FUTURE-'.$index,
                'idempotency_key' => 'future-round-'.$index,
                'phone_e164' => '25570000000'.$index,
                'amount' => 200,
                'currency' => 'TZS',
                'status' => PaymentAttemptStatus::Successful,
                'provider_reference' => 'FUTURE-PROVIDER-'.$index,
                'completed_at' => now(),
                'metadata' => ['group_id' => $group->id, 'member_id' => $users[$index]->id],
            ]);
            PaymentAllocation::create([
                'payment_attempt_id' => $attempt->id,
                'member_payment_obligation_id' => $obligation->id,
                'amount' => 200,
                'principal_amount' => 200,
                'kijumbe_fee_amount' => 0,
                'platform_fee_amount' => 0,
                'coordinator_fee_amount' => 0,
                'penalty_amount' => 0,
            ]);
            $obligation->update(['paid_amount' => 200, 'status' => 'paid', 'paid_at' => now()]);
        }

        app(MichezoPayoutSyncService::class)->sync($group->fresh());

        $round = $group->cycles()->firstOrFail()->rounds()->where('number', 4)->firstOrFail();
        $this->assertSame(RoundStatus::Scheduled, $round->status);
        $this->assertSame('2026-09-26 00:00:00', $round->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-26 01:00:00', $round->grace_ends_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('contributions', 16);
        $this->assertSame(
            4,
            $round->contributions()->where('status', 'paid_late')->count(),
        );
        Carbon::setTestNow();
    }
}
