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

class WhatsappPaymentMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_menu_excludes_a_completed_mchezo_with_no_outstanding_contributions(): void
    {
        Carbon::setTestNow('2026-09-20 09:00:00');
        $member = User::factory()->create([
            'phone' => '255700000001',
            'whatsapp_wa_id' => '255700000001',
            'whatsapp_opted_in_at' => now(),
        ]);
        $completed = $this->groupFor($member, 'Kijumbe trial 001', now()->subDay());
        $payable = $this->groupFor($member, 'Utwa grp', now()->startOfDay());

        $ledger = app(MemberPaymentLedgerService::class);
        $ledger->materialize($completed);
        $ledger->materialize($payable);
        MemberPaymentObligation::query()
            ->where('group_id', $completed->id)
            ->update(['paid_amount' => 200, 'status' => 'paid', 'paid_at' => now()]);

        $messages = [];
        $whatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $whatsapp->shouldReceive('sendInteractive')->once()->andReturnUsing(
            function (User $recipient, string $text, array $interaction) use (&$messages): WhatsappMessage {
                $messages[] = $text;
                $this->assertSame('buttons', $interaction['type']);
                $this->assertSame(['1', '2'], array_column($interaction['options'], 'id'));

                return new WhatsappMessage;
            },
        );
        $whatsapp->shouldReceive('sendText')->once()->andReturnUsing(
            function (User $recipient, string $text) use (&$messages): WhatsappMessage {
                $messages[] = $text;

                return new WhatsappMessage;
            },
        );
        $this->app->instance(MetaWhatsAppClient::class, $whatsapp);

        $conversation = app(WhatsappConversationService::class);
        $conversation->handle($member, ['id' => 'payment-menu-1', 'text' => ['body' => 'LIPA']]);
        $conversation->handle($member, ['id' => 'payment-menu-2', 'text' => ['body' => '1']]);

        $this->assertStringContainsString('Utwa grp', $messages[1]);
        $this->assertStringNotContainsString('Kijumbe trial 001', $messages[1]);
        Carbon::setTestNow();
    }

    private function groupFor(User $member, string $name, Carbon $startsAt): Group
    {
        $group = Group::create([
            'name' => $name,
            'status' => GroupStatus::Active,
            'created_by' => $member->id,
            'contribution_amount' => 200,
            'currency' => 'TZS',
            'contribution_interval_days' => 1,
            'member_slots' => 1,
            'cycle_starts_at' => $startsAt,
        ]);
        $membership = GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $member->id,
            'member_name' => $member->name,
            'majina_count' => 1,
            'role' => GroupRole::Member,
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

        return $group;
    }
}
