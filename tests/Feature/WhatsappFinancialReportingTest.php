<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMemberSlot;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderEvent;
use App\Models\Payout;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\MemberPaymentLedgerService;
use App\Services\Payments\PaymentProviderEventProcessor;
use App\Services\Whatsapp\MetaWhatsAppClient;
use App\Services\Whatsapp\WhatsappConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class WhatsappFinancialReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 09:00:00');
        $this->app->instance(PaymentGateway::class, new class implements PaymentGateway
        {
            public function initiateCollection(PaymentAttempt $attempt): array
            {
                return ['id' => 'report-payment'];
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_coordinator_can_request_a_complete_payment_report_with_taarifa(): void
    {
        [$group, $coordinator, $member] = $this->groupWithCoordinatorAndMember();
        $ledger = app(MemberPaymentLedgerService::class);
        $attempt = $ledger->initiate($member, $group, 250, '0748281701', 'report-payment-001');
        $ledger->confirmFromProvider($attempt, 250, 'REPORT-PAID-001', [
            'data' => ['id' => 'report-payment', 'fee' => 0, 'net' => 250],
        ]);

        $messages = [];
        $whatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $whatsapp->shouldReceive('sendText')->once()->andReturnUsing(function (User $user, string $text) use (&$messages): WhatsappMessage {
            $messages[] = $text;

            return new WhatsappMessage;
        });
        $this->app->instance(MetaWhatsAppClient::class, $whatsapp);

        app(WhatsappConversationService::class)->handle($coordinator, [
            'id' => 'wamid.taarifa.1',
            'text' => ['body' => 'TAARIFA'],
        ]);

        $report = implode("\n", $messages);
        $this->assertStringContainsString('TAARIFA YA Office Report Group', $report);
        $this->assertStringContainsString('Office Member (Jina 1): AMELIPA TZS 250/250', $report);
        $this->assertStringContainsString('Ada ya platform iliyokusanywa: TZS 15', $report);
        $this->assertStringContainsString('Ada ya Kijumbe iliyokusanywa: TZS 35', $report);
        $this->assertStringContainsString('MAREJESHO:', $report);
    }

    public function test_coordinator_is_notified_when_a_member_payment_is_confirmed(): void
    {
        [$group, $coordinator, $member] = $this->groupWithCoordinatorAndMember();
        $attempt = app(MemberPaymentLedgerService::class)
            ->initiate($member, $group, 250, '0748281701', 'report-payment-002');

        $messages = [];
        $whatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $whatsapp->shouldReceive('sendText')->twice()->andReturnUsing(function (User $user, string $text) use (&$messages): WhatsappMessage {
            $messages[$user->id][] = $text;

            return new WhatsappMessage;
        });
        $this->app->instance(MetaWhatsAppClient::class, $whatsapp);

        $event = PaymentProviderEvent::create([
            'provider' => 'ecofy',
            'event_key' => 'report-event-001',
            'event_name' => 'PAYMENT RECEIVED',
            'order_reference' => $attempt->order_reference,
            'verified' => true,
            'payload' => [
                'data' => [
                    'id' => 'report-payment-002',
                    'paymentReference' => 'REPORT-PAID-002',
                    'collectedAmount' => 250,
                    'collectedCurrency' => 'TZS',
                ],
            ],
        ]);
        app(PaymentProviderEventProcessor::class)->process($event);

        $coordinatorMessage = implode("\n", $messages[$coordinator->id] ?? []);
        $this->assertStringContainsString('MALIPO YAMEPOKELEWA', $coordinatorMessage);
        $this->assertStringContainsString('Mwanachama: Office Member', $coordinatorMessage);
        $this->assertStringContainsString('Kiasi: TZS 250', $coordinatorMessage);
        $this->assertStringContainsString('Sehemu ya ada ya Kijumbe: TZS 35', $coordinatorMessage);
        $this->assertStringContainsString('Tuma TAARIFA', $coordinatorMessage);
        $this->assertNotEmpty($messages[$member->id] ?? []);
    }

    /** @return array{Group, User, User} */
    private function groupWithCoordinatorAndMember(): array
    {
        $coordinator = User::factory()->create([
            'name' => 'Office Coordinator',
            'phone' => '255700000001',
            'whatsapp_wa_id' => '255700000001',
            'is_active' => true,
            'whatsapp_opted_in_at' => now(),
        ]);
        $member = User::factory()->create([
            'name' => 'Office Member',
            'phone' => '255748281701',
            'whatsapp_wa_id' => '255748281701',
            'is_active' => true,
            'whatsapp_opted_in_at' => now(),
        ]);
        $group = Group::create([
            'name' => 'Office Report Group',
            'status' => GroupStatus::Active,
            'created_by' => $coordinator->id,
            'contribution_amount' => 200,
            'currency' => 'TZS',
            'contribution_interval_days' => 1,
            'payout_interval_days' => 1,
            'cycle_starts_at' => now(),
            'kijumbe_fee' => 50,
            'platform_fee_percent' => 30,
            'penalty_amount' => 50,
            'penalty_timing_hours' => 2,
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
