<?php

namespace Tests\Feature;

use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMemberSlot;
use App\Models\MemberPaymentObligation;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\MemberPaymentLedgerService;
use App\Services\Whatsapp\MetaWhatsAppClient;
use App\Services\Whatsapp\WhatsappConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class WhatsappGroupEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_coordinator_can_start_editing_with_the_global_badili_taarifa_command(): void
    {
        [$group, $coordinator] = $this->startedGroup();
        $messages = [];
        $whatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $whatsapp->shouldReceive('sendText')->once()->andReturnUsing(
            function (User $user, string $text) use (&$messages): WhatsappMessage {
                $messages[] = $text;

                return new WhatsappMessage;
            },
        );
        $this->app->instance(MetaWhatsAppClient::class, $whatsapp);

        app(WhatsappConversationService::class)->handle($coordinator, [
            'id' => 'global-edit-1',
            'text' => ['body' => 'BADILI TAARIFA'],
        ]);

        $this->assertStringContainsString("Mchezo: {$group->name}", $messages[0]);
        $this->assertStringContainsString('11. Muda wa kuanza faini', $messages[0]);
        $this->assertDatabaseHas('whatsapp_flow_sessions', [
            'user_id' => $coordinator->id,
            'flow_key' => 'group_action',
            'step_key' => 'choose_edit_field',
        ]);
    }

    public function test_a_coordinator_can_change_penalty_timing_after_start_and_every_member_is_notified(): void
    {
        Carbon::setTestNow('2026-09-20 02:00:00');
        [$group, $coordinator, $member] = $this->startedGroup();
        app(MemberPaymentLedgerService::class)->materialize($group);
        $currentRound = MemberPaymentObligation::query()
            ->where('group_id', $group->id)
            ->where('round_number', 2);
        $this->assertSame(50, (clone $currentRound)->firstOrFail()->penalty_amount);

        $messages = [];
        $whatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $whatsapp->shouldReceive('sendText')->zeroOrMoreTimes()->andReturnUsing(
            function (User $user, string $text) use (&$messages): WhatsappMessage {
                $messages[$user->id][] = $text;

                return new WhatsappMessage;
            },
        );
        $this->app->instance(MetaWhatsAppClient::class, $whatsapp);

        $service = app(WhatsappConversationService::class);
        $service->handle($coordinator, ['id' => 'edit-1', 'text' => ['body' => 'EDIT01']]);
        $service->handle($coordinator, ['id' => 'edit-2', 'text' => ['body' => '4']]);
        $service->handle($coordinator, ['id' => 'edit-3', 'text' => ['body' => '11']]);
        $service->handle($coordinator, ['id' => 'edit-4', 'text' => ['body' => '3']]);

        $this->assertSame(3, $group->fresh()->penalty_timing_hours);
        $this->assertSame(0, (clone $currentRound)->firstOrFail()->penalty_amount);
        foreach ([$coordinator, $member] as $recipient) {
            $notification = implode("\n", $messages[$recipient->id] ?? []);
            $this->assertStringContainsString('TAARIFA ZA MCHEZO ZIMEBADILISHWA', $notification);
            $this->assertStringContainsString('Kilichobadilishwa: Muda wa kuanza faini', $notification);
            $this->assertStringContainsString('Ilikuwa: 1 saa', $notification);
            $this->assertStringContainsString('Sasa: 3 saa', $notification);
        }
        Carbon::setTestNow();
    }

    public function test_financial_edits_change_unpaid_rounds_without_rewriting_paid_history(): void
    {
        Carbon::setTestNow('2026-09-20 09:00:00');
        [$group, $coordinator] = $this->startedGroup();
        $ledger = app(MemberPaymentLedgerService::class);
        $ledger->materialize($group);

        MemberPaymentObligation::query()
            ->where('group_id', $group->id)
            ->where('round_number', 1)
            ->update(['paid_amount' => 250, 'status' => 'paid', 'paid_at' => now()]);

        $whatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $whatsapp->shouldReceive('sendText')->zeroOrMoreTimes()->andReturn(new WhatsappMessage);
        $this->app->instance(MetaWhatsAppClient::class, $whatsapp);

        $service = app(WhatsappConversationService::class);
        $service->handle($coordinator, ['id' => 'amount-edit-1', 'text' => ['body' => 'EDIT01']]);
        $service->handle($coordinator, ['id' => 'amount-edit-2', 'text' => ['body' => '4']]);
        $service->handle($coordinator, ['id' => 'amount-edit-3', 'text' => ['body' => '5']]);
        $service->handle($coordinator, ['id' => 'amount-edit-4', 'text' => ['body' => '300']]);

        $this->assertSame(300, $group->fresh()->contribution_amount);
        $this->assertSame(
            [200],
            MemberPaymentObligation::query()
                ->where('group_id', $group->id)
                ->where('round_number', 1)
                ->distinct()
                ->pluck('principal_amount')
                ->all(),
        );
        $this->assertSame(
            [300],
            MemberPaymentObligation::query()
                ->where('group_id', $group->id)
                ->where('round_number', 2)
                ->distinct()
                ->pluck('principal_amount')
                ->all(),
        );
        Carbon::setTestNow();
    }

    /** @return array{Group, User, User} */
    private function startedGroup(): array
    {
        $owner = User::factory()->create(['whatsapp_opted_in_at' => now()]);
        $coordinator = User::factory()->create([
            'name' => 'Second Coordinator',
            'phone' => '255700000002',
            'whatsapp_wa_id' => '255700000002',
            'whatsapp_opted_in_at' => now(),
        ]);
        $member = User::factory()->create([
            'name' => 'Group Member',
            'phone' => '255700000003',
            'whatsapp_wa_id' => '255700000003',
            'whatsapp_opted_in_at' => now(),
        ]);
        $group = Group::create([
            'name' => 'Editable started group',
            'group_code' => 'EDIT01',
            'status' => GroupStatus::Active,
            'created_by' => $owner->id,
            'contribution_amount' => 200,
            'currency' => 'TZS',
            'contribution_interval_days' => 1,
            'payout_interval_days' => 1,
            'member_slots' => 2,
            'cycle_starts_at' => now()->subDay()->startOfDay(),
            'kijumbe_fee' => 50,
            'penalty_amount' => 50,
            'penalty_timing_hours' => 1,
        ]);

        foreach ([[$coordinator, GroupRole::Coordinator], [$member, GroupRole::Member]] as $index => [$user, $role]) {
            $membership = GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $user->id,
                'member_name' => $user->name,
                'majina_count' => 1,
                'role' => $role,
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

        return [$group, $coordinator, $member];
    }
}
