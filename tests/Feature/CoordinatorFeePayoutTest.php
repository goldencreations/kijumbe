<?php

namespace Tests\Feature;

use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PayoutStatus;
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
use App\Services\CoordinatorFeePayoutService;
use App\Services\PayoutService;
use App\Services\Whatsapp\MetaWhatsAppClient;
use App\Services\Whatsapp\WhatsappConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class CoordinatorFeePayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_cycle_creates_one_secure_coordinator_fee_payout_and_supports_pokea_ada(): void
    {
        Carbon::setTestNow('2026-09-19 16:15:00');
        [$cycle, $coordinator] = $this->completedCycleWithCollectedFees();

        $readyMessage = '';
        $whatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $whatsapp->shouldReceive('sendText')->once()->andReturnUsing(function (User $user, string $text) use ($coordinator, &$readyMessage): WhatsappMessage {
            $this->assertTrue($user->is($coordinator));
            $readyMessage = $text;

            return new WhatsappMessage;
        });
        $this->app->instance(MetaWhatsAppClient::class, $whatsapp);

        $service = app(CoordinatorFeePayoutService::class);
        $first = $service->ensureForCompletedCycle($cycle);
        $second = $service->ensureForCompletedCycle($cycle->fresh());

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(Payout::PURPOSE_COORDINATOR_FEE, $first->purpose);
        $this->assertSame(140, $first->amount);
        $this->assertSame($coordinator->id, $first->recipient_user_id);
        $this->assertSame(PayoutStatus::Pending, $first->status);
        $this->assertNotNull($first->fresh()->ready_notified_at);
        $this->assertStringContainsString('ADA YAKO YA KIJUMBE IKO TAYARI', $readyMessage);
        $this->assertStringContainsString('TZS 140', $readyMessage);
        $this->assertStringContainsString('POKEA ADA', $readyMessage);
        $this->assertDatabaseCount('payouts', 2);

        $flowMessage = '';
        $flowWhatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $flowWhatsapp->shouldReceive('sendText')->once()->andReturnUsing(function (User $user, string $text) use (&$flowMessage): WhatsappMessage {
            $flowMessage = $text;

            return new WhatsappMessage;
        });
        $this->app->instance(MetaWhatsAppClient::class, $flowWhatsapp);
        app(WhatsappConversationService::class)->handle($coordinator, [
            'id' => 'wamid.coordinator.fee.claim',
            'text' => ['body' => 'POKEA ADA'],
        ]);

        $this->assertStringContainsString('ada yako ya Kijumbe ya TZS 140', $flowMessage);
        $this->assertDatabaseHas('whatsapp_flow_sessions', [
            'user_id' => $coordinator->id,
            'flow_key' => 'payout',
            'step_key' => 'await_payment_phone',
        ]);
        Carbon::setTestNow();
    }

    public function test_paid_coordinator_fee_keeps_cycle_completed_and_notifies_group(): void
    {
        Carbon::setTestNow('2026-09-19 16:20:00');
        [$cycle, $coordinator] = $this->completedCycleWithCollectedFees();

        $readyWhatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $readyWhatsapp->shouldReceive('sendText')->once()->andReturnUsing(fn () => new WhatsappMessage);
        $this->app->instance(MetaWhatsAppClient::class, $readyWhatsapp);
        $payout = app(CoordinatorFeePayoutService::class)->ensureForCompletedCycle($cycle);
        $payout->update(['status' => PayoutStatus::Processing, 'initiated_at' => now()]);

        $paidMessage = '';
        $paidWhatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $paidWhatsapp->shouldReceive('sendText')->once()->andReturnUsing(function (User $user, string $text) use ($coordinator, &$paidMessage): WhatsappMessage {
            $this->assertTrue($user->is($coordinator));
            $paidMessage = $text;

            return new WhatsappMessage;
        });
        $this->app->instance(MetaWhatsAppClient::class, $paidWhatsapp);

        $completed = app(PayoutService::class)->completeFromProvider($payout->fresh(), 'po_fee_paid_001', [
            'id' => 'po_fee_paid_001',
            'status' => 'paid',
        ]);

        $this->assertSame(PayoutStatus::Paid, $completed->status);
        $this->assertSame('completed', $cycle->fresh()->status->value);
        $this->assertNotNull($cycle->fresh()->completed_at);
        $this->assertStringContainsString('ADA YA KIJUMBE IMELIPWA', $paidMessage);
        $this->assertStringContainsString('Kijumbe: Derick', $paidMessage);
        $this->assertStringContainsString('TZS 140', $paidMessage);
        Carbon::setTestNow();
    }

    /** @return array{Cycle, User} */
    private function completedCycleWithCollectedFees(): array
    {
        $coordinator = User::factory()->create([
            'name' => 'WhatsApp 255748281701',
            'phone' => '255748281701',
            'whatsapp_wa_id' => '255748281701',
            'whatsapp_opted_in_at' => now(),
        ]);
        $member = User::factory()->create([
            'name' => 'Office Member',
            'phone' => '255700000002',
            'whatsapp_wa_id' => '255700000002',
        ]);
        $group = Group::create([
            'name' => 'Fee settlement game',
            'status' => GroupStatus::Active,
            'created_by' => $coordinator->id,
            'contribution_amount' => 200,
            'currency' => 'TZS',
            'member_slots' => 2,
            'cycle_starts_at' => now()->subDay(),
            'kijumbe_fee' => 50,
            'platform_fee_percent' => 30,
        ]);

        $memberships = collect([$coordinator, $member])->map(function (User $user, int $index) use ($group): GroupMember {
            $membership = GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $user->id,
                'member_name' => $index === 0 ? 'Derick' : $user->name,
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

            return $membership;
        });

        $cycle = Cycle::create([
            'group_id' => $group->id,
            'name' => 'Michezo ledger',
            'contribution_amount' => 200,
            'currency' => 'TZS',
            'period_days' => 1,
            'grace_period_hours' => 2,
            'starts_at' => now()->subDay(),
            'member_count' => 2,
            'status' => 'completed',
            'activated_at' => now()->subDay(),
            'completed_at' => now(),
        ]);
        foreach ($memberships as $index => $membership) {
            CycleMember::create([
                'cycle_id' => $cycle->id,
                'group_member_id' => $membership->id,
                'group_member_slot_id' => $membership->slots()->firstOrFail()->id,
                'payout_position' => $index + 1,
                'status' => 'active',
            ]);
        }

        foreach (range(1, 2) as $roundNumber) {
            foreach ($memberships as $index => $membership) {
                $obligation = MemberPaymentObligation::create([
                    'group_id' => $group->id,
                    'group_member_slot_id' => $membership->slots()->firstOrFail()->id,
                    'round_number' => $roundNumber,
                    'due_at' => now()->subDay()->addHours($roundNumber),
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
                $attempt = PaymentAttempt::create([
                    'initiated_by' => $membership->user_id,
                    'provider' => 'ecofy',
                    'method' => 'mobile_money',
                    'order_reference' => "FEE-{$roundNumber}-{$index}",
                    'idempotency_key' => "fee-{$roundNumber}-{$index}",
                    'phone_e164' => $membership->user->phone,
                    'amount' => 250,
                    'currency' => 'TZS',
                    'status' => PaymentAttemptStatus::Successful,
                    'payment_scope' => 'member_ledger',
                    'completed_at' => now(),
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
            }
        }

        $round = Round::create([
            'cycle_id' => $cycle->id,
            'number' => 1,
            'recipient_cycle_member_id' => $cycle->members()->orderBy('payout_position')->firstOrFail()->id,
            'due_at' => now()->subDay(),
            'grace_ends_at' => now()->subDay()->addHours(2),
            'required_amount' => 400,
            'collected_amount' => 400,
            'payout_amount' => 300,
            'gross_payout_amount' => 400,
            'kijumbe_fee_amount' => 100,
            'platform_fee_amount' => 30,
            'coordinator_fee_amount' => 70,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        Payout::create([
            'purpose' => Payout::PURPOSE_MEMBER,
            'round_id' => $round->id,
            'recipient_cycle_member_id' => $round->recipient_cycle_member_id,
            'amount' => 300,
            'gross_amount' => 400,
            'kijumbe_fee_amount' => 100,
            'platform_fee_amount' => 30,
            'coordinator_fee_amount' => 70,
            'status' => PayoutStatus::Paid,
            'processed_at' => now(),
        ]);

        return [$cycle, $coordinator];
    }
}
