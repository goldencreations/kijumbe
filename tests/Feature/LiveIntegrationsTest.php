<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Contracts\PayoutGateway;
use App\Enums\ContributionStatus;
use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PayoutStatus;
use App\Enums\SystemRole;
use App\Models\Contribution;
use App\Models\Cycle;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Models\Round;
use App\Models\User;
use App\Models\WhatsappFlowSession;
use App\Models\WhatsappMessage;
use App\Models\WhatsappWebhookEvent;
use App\Services\Whatsapp\MetaWhatsAppClient;
use App\Services\Whatsapp\WhatsappConversationService;
use App\Services\Whatsapp\WhatsappFlowSessionService;
use App\Services\Whatsapp\WhatsappWebhookEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class LiveIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-24 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_meta_webhook_verification_and_signed_delivery_are_enforced(): void
    {
        config([
            'services.whatsapp.verify_token' => 'office-verify-token',
            'services.whatsapp.app_secret' => 'meta-app-secret',
            'services.whatsapp.phone_number_id' => '123456789',
        ]);

        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=office-verify-token&hub.challenge=98765')
            ->assertOk()
            ->assertSeeText('98765');

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '123456789'],
                        'messages' => [[
                            'from' => '255700000001',
                            'id' => 'wamid.office.1',
                            'timestamp' => '1787565600',
                            'type' => 'text',
                            'text' => ['body' => 'STATUS'],
                        ]],
                    ],
                ]],
            ]],
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=wrong',
        ], $json)
            ->assertUnauthorized();

        $signature = 'sha256='.hash_hmac('sha256', $json, 'meta-app-secret');
        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $json)
            ->assertOk()
            ->assertJsonPath('events_stored', 1);

        $this->assertDatabaseHas('whatsapp_messages', [
            'meta_message_id' => 'wamid.office.1',
            'direction' => 'incoming',
        ]);
    }

    public function test_meta_status_timestamps_are_stored_in_eat_and_can_be_repaired(): void
    {
        config(['app.timezone' => 'Africa/Dar_es_Salaam']);

        $message = WhatsappMessage::query()->create([
            'direction' => 'outgoing',
            'meta_message_id' => 'wamid.eat.1',
            'wa_id' => '255700000001',
            'message_type' => 'text',
            'status' => 'sent',
        ]);
        $event = WhatsappWebhookEvent::query()->create([
            'event_key' => hash('sha256', 'wamid.eat.1:delivered'),
            'event_type' => 'status',
            'meta_message_id' => 'wamid.eat.1',
            'payload' => [
                'status' => [
                    'id' => 'wamid.eat.1',
                    'status' => 'delivered',
                    'timestamp' => '1789668920', // 2026-09-17 18:15:20 UTC / 21:15:20 EAT
                ],
            ],
        ]);

        app(WhatsappWebhookEventProcessor::class)->process($event);

        $this->assertSame('2026-09-17 21:15:20', $message->fresh()->getRawOriginal('delivered_at'));

        $message->update(['delivered_at' => '2026-09-17 18:15:20']);
        $this->artisan('kijumbe:repair-whatsapp-status-times', ['--message' => 'wamid.eat.1'])
            ->expectsOutput('Repaired 1 WhatsApp status timestamp(s); 0 event(s) had no matching message.')
            ->assertSuccessful();

        $this->assertSame('2026-09-17 21:15:20', $message->fresh()->getRawOriginal('delivered_at'));
    }

    public function test_whatsapp_flow_reply_creates_and_stores_a_group(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::Admin, 'whatsapp_wa_id' => '255700000001', 'is_active' => true, 'whatsapp_opted_in_at' => now()]);

        $whatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $whatsapp->shouldReceive('sendText')->once()->withArgs(function (User $user, string $text) use ($admin) {
            return $user->is($admin) && str_contains($text, 'kimehifadhiwa vizuri');
        })->andReturnUsing(fn () => new WhatsappMessage);
        $this->app->instance(MetaWhatsAppClient::class, $whatsapp);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '123456789'],
                        'messages' => [[
                            'from' => '255700000001',
                            'id' => 'wamid.flow.1',
                            'timestamp' => '1787565600',
                            'type' => 'interactive',
                            'interactive' => [
                                'type' => 'nfm_reply',
                                'nfm_reply' => [
                                    'response_json' => json_encode([
                                        'group_name' => 'Mwananchi Savings',
                                        'group_description' => 'Weekly savings group',
                                        'language' => 'sw',
                                        'contribution_amount' => 1000,
                                        'currency' => 'TZS',
                                        'period_days' => 7,
                                        'member_slots' => 10,
                                        'cycle_starts_at' => '2026-08-25 10:00:00',
                                        'payout_order' => 'fifo',
                                        'grace_period_hours' => 24,
                                        'platform_fee_amount' => 50,
                                        'resolution_threshold_percent' => 75,
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ],
                        ]],
                    ],
                ]],
            ]],
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $json, config('services.whatsapp.app_secret') ?: 'testing-secret');

        config([
            'services.whatsapp.app_secret' => 'testing-secret',
            'services.whatsapp.phone_number_id' => '123456789',
        ]);

        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $json)->assertOk();

        $group = Group::query()->where('name', 'Mwananchi Savings')->firstOrFail();
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $group->id);
        $this->assertSame('draft', $group->status->value);
        $this->assertSame('255700000001', $group->created_by_phone);
        $this->assertSame('255700000001', $group->created_by_wa_id);
        $this->assertSame('sw', $group->language);
        $this->assertSame(1000, $group->contribution_amount);
        $this->assertSame('TZS', $group->currency);
        $this->assertSame(7, $group->period_days);
        $this->assertSame(10, $group->member_slots);
        $this->assertSame(24, $group->grace_period_hours);
        $this->assertSame(50, $group->platform_fee_amount);
        $this->assertSame(75, $group->resolution_threshold_percent);
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->id,
            'user_id' => $admin->id,
            'role' => GroupRole::Coordinator->value,
        ]);
    }

    public function test_whatsapp_chat_group_creation_persists_confirmed_fields_and_generated_code(): void
    {
        $leader = User::factory()->create([
            'name' => 'Asha Kijumbe',
            'phone' => '255700000030',
            'whatsapp_wa_id' => '255700000030',
            'is_active' => true,
            'whatsapp_opted_in_at' => now(),
        ]);
        $whatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $whatsapp->shouldReceive('sendText')->atLeast()->once()->andReturnUsing(fn () => new WhatsappMessage);
        $this->app->instance(MetaWhatsAppClient::class, $whatsapp);

        $conversation = $this->app->make(WhatsappConversationService::class);
        $conversation->handle($leader, ['id' => 'wamid.chat.create', 'text' => ['body' => 'UNDA KIKUNDI']]);

        foreach (['Mshikamano', '10', '2', 'Asha Kijumbe', '2000', '1', '3', '2026-09-15', '500', '100', '2'] as $index => $answer) {
            $conversation->handle($leader, [
                'id' => 'wamid.chat.'.($index + 1),
                'text' => ['body' => $answer],
            ]);
        }

        $session = WhatsappFlowSession::query()
            ->where('user_id', $leader->id)
            ->where('flow_key', WhatsappFlowSessionService::GROUP_CREATION)
            ->firstOrFail();
        $this->assertSame('confirmation', $session->step_key);

        $conversation->handle($leader, ['id' => 'wamid.chat.confirm', 'text' => ['body' => 'NDIO']]);

        $group = Group::query()->where('name', 'Mshikamano')->firstOrFail();
        $this->assertSame('money', $group->group_type);
        $this->assertSame('Asha Kijumbe', $group->leader_name);
        $this->assertSame('255700000030', $group->created_by_phone);
        $this->assertSame('255700000030', $group->leader_phone);
        $this->assertSame(2000, $group->amount_per_day);
        $this->assertSame(1, $group->contribution_interval_days);
        $this->assertSame(3, $group->payout_interval_days);
        $this->assertSame('daily', $group->payment_frequency);
        $this->assertSame(500, $group->kijumbe_fee);
        $this->assertSame(100, $group->penalty_amount);
        $this->assertSame(2, $group->penalty_timing_hours);
        $this->assertMatchesRegularExpression('/^MSHIKAMANO-\d{4}$/', $group->group_code);
        $this->assertNotNull($session->fresh()->completed_at);
    }

    public function test_group_code_flow_allows_join_and_details_with_session_persistence(): void
    {
        $creator = User::factory()->create([
            'whatsapp_wa_id' => '255700000010',
            'phone' => '255700000010',
            'is_active' => true,
            'whatsapp_opted_in_at' => now(),
        ]);
        $group = Group::create([
            'id' => (string) str()->uuid(),
            'name' => 'Jamii Savings',
            'description' => 'Weekly group',
            'status' => GroupStatus::Draft,
            'created_by' => $creator->id,
            'created_by_phone' => '255700000010',
            'created_by_wa_id' => '255700000010',
            'language' => 'sw',
            'contribution_amount' => 2000,
            'currency' => 'TZS',
            'period_days' => 7,
            'member_slots' => 10,
            'cycle_starts_at' => now()->addDay(),
            'payout_order' => 'fixed',
            'grace_period_hours' => 24,
            'platform_fee_amount' => 500,
            'resolution_threshold_percent' => 70,
        ]);
        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $creator->id,
            'role' => GroupRole::Coordinator,
            'is_active' => true,
            'joined_at' => now(),
        ]);

        $member = User::factory()->create([
            'whatsapp_wa_id' => '255700000011',
            'phone' => '255700000011',
            'is_active' => true,
            'whatsapp_opted_in_at' => now(),
        ]);

        $whatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $whatsapp->shouldReceive('sendText')->times(5)->andReturnUsing(fn () => new WhatsappMessage);
        $this->app->instance(MetaWhatsAppClient::class, $whatsapp);

        $conversation = $this->app->make(WhatsappConversationService::class);
        $conversation->handle($member, ['id' => 'wamid.group.1', 'text' => ['body' => $group->id]]);

        $session = WhatsappFlowSession::query()
            ->where('user_id', $member->id)
            ->where('flow_key', WhatsappFlowSessionService::GROUP_ACTION)
            ->firstOrFail();
        $this->assertSame('choose_action', $session->step_key);

        $conversation->handle($member, ['id' => 'wamid.group.2', 'text' => ['body' => '1']]);
        $conversation->handle($member, ['id' => 'wamid.group.2a', 'text' => ['body' => 'NDIO']]);
        $conversation->handle($member, ['id' => 'wamid.group.2b', 'text' => ['body' => 'Amina Member']]);
        $conversation->handle($member, ['id' => 'wamid.group.2c', 'text' => ['body' => '1']]);

        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->id,
            'user_id' => $member->id,
            'role' => GroupRole::Member->value,
            'is_active' => true,
        ]);

        $creatorWhatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $creatorWhatsapp->shouldReceive('sendText')->twice()->andReturnUsing(fn () => new WhatsappMessage);
        $this->app->instance(MetaWhatsAppClient::class, $creatorWhatsapp);

        $conversation = $this->app->make(WhatsappConversationService::class);
        $conversation->handle($creator, ['id' => 'wamid.group.3', 'text' => ['body' => $group->id]]);
        $conversation->handle($creator, ['id' => 'wamid.group.4', 'text' => ['body' => '3']]);
    }

    public function test_group_join_rejects_full_or_started_groups(): void
    {
        $user = User::factory()->create([
            'whatsapp_wa_id' => '255700000020',
            'phone' => '255700000020',
            'is_active' => true,
            'whatsapp_opted_in_at' => now(),
        ]);
        $whatsapp = Mockery::mock(MetaWhatsAppClient::class);
        $whatsapp->shouldReceive('sendText')->times(4)->andReturnUsing(fn () => new WhatsappMessage);
        $this->app->instance(MetaWhatsAppClient::class, $whatsapp);

        $fullGroup = Group::create([
            'id' => (string) str()->uuid(),
            'name' => 'Full Group',
            'status' => GroupStatus::Draft,
            'created_by' => $user->id,
            'created_by_phone' => '255700000020',
            'created_by_wa_id' => '255700000020',
            'language' => 'sw',
            'contribution_amount' => 1000,
            'currency' => 'TZS',
            'period_days' => 7,
            'member_slots' => 1,
            'cycle_starts_at' => now()->addDay(),
            'payout_order' => 'fixed',
        ]);
        GroupMember::create([
            'group_id' => $fullGroup->id,
            'user_id' => $user->id,
            'role' => GroupRole::Coordinator,
            'is_active' => true,
            'joined_at' => now(),
        ]);

        $member = User::factory()->create([
            'whatsapp_wa_id' => '255700000021',
            'phone' => '255700000021',
            'is_active' => true,
            'whatsapp_opted_in_at' => now(),
        ]);

        $conversation = $this->app->make(WhatsappConversationService::class);
        $conversation->handle($member, ['id' => 'wamid.join.1', 'text' => ['body' => $fullGroup->id]]);
        $conversation->handle($member, ['id' => 'wamid.join.2', 'text' => ['body' => '1']]);

        WhatsappFlowSession::query()->delete();

        $startedGroup = Group::create([
            'id' => (string) str()->uuid(),
            'name' => 'Started Group',
            'status' => GroupStatus::Draft,
            'created_by' => $user->id,
            'created_by_phone' => '255700000020',
            'created_by_wa_id' => '255700000020',
            'language' => 'sw',
            'contribution_amount' => 1000,
            'currency' => 'TZS',
            'period_days' => 7,
            'member_slots' => 10,
            'cycle_starts_at' => now()->subHour(),
            'payout_order' => 'fixed',
        ]);

        $conversation->handle($member, ['id' => 'wamid.join.3', 'text' => ['body' => $startedGroup->id]]);
        $conversation->handle($member, ['id' => 'wamid.join.4', 'text' => ['body' => '1']]);

        $this->assertDatabaseMissing('group_members', [
            'group_id' => $fullGroup->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseMissing('group_members', [
            'group_id' => $startedGroup->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_authenticated_selcom_payment_is_posted_exactly_once(): void
    {
        config([
            'services.selcom.c2b_token' => 'selcom-office-c2b-token',
        ]);

        [$admin, $contribution] = $this->readyContribution();
        PaymentAttempt::create([
            'contribution_id' => $contribution->id,
            'initiated_by' => $admin->id,
            'provider' => 'selcom',
            'order_reference' => 'KJC-OFFICE-001',
            'idempotency_key' => 'office-attempt-001',
            'phone_e164' => '255700000001',
            'amount' => $contribution->amount,
            'currency' => 'TZS',
            'status' => PaymentAttemptStatus::Processing,
            'initiated_at' => now(),
        ]);

        $payload = [
            'operator' => 'MPESA-TZ',
            'transid' => 'MPESA-OFFICE-001',
            'reference' => 'SELCOM-RECEIPT-001',
            'utilityref' => 'KJC-OFFICE-001',
            'amount' => (string) $contribution->amount,
            'msisdn' => '0700000001',
        ];

        $this->postJson('/api/v1/webhooks/payments/selcom/notification', $payload)
            ->assertUnauthorized();

        $headers = ['Authorization' => 'Bearer selcom-office-c2b-token'];
        $this->postJson('/api/v1/webhooks/payments/selcom/notification', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('resultcode', '000');
        $this->postJson('/api/v1/webhooks/payments/selcom/notification', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('resultcode', '000');

        $this->assertSame(ContributionStatus::Paid, $contribution->fresh()->status);
        $this->assertDatabaseHas('contributions', [
            'id' => $contribution->id,
            'payment_reference' => 'SELCOM-RECEIPT-001',
        ]);
        $this->assertDatabaseCount('payment_provider_events', 1);
    }

    public function test_signed_ecofy_payment_received_webhook_confirms_the_contribution_idempotently(): void
    {
        config(['services.ecofy.webhook_secret' => 'ecofy-webhook-secret']);
        [$admin, $contribution] = $this->readyContribution();

        $attempt = PaymentAttempt::create([
            'contribution_id' => $contribution->id,
            'initiated_by' => $admin->id,
            'provider' => 'ecofy',
            'method' => 'mobile_money',
            'order_reference' => 'KJC-ECOFY-001',
            'idempotency_key' => 'ecofy-attempt-001',
            'phone_e164' => '255700000001',
            'amount' => $contribution->amount,
            'currency' => 'TZS',
            'status' => PaymentAttemptStatus::Processing,
        ]);

        $payload = [
            'event' => 'PAYMENT RECEIVED',
            'data' => [
                'id' => 'ecofy-payment-001',
                'reference' => $attempt->order_reference,
                'paymentReference' => 'ECOFY-RECEIPT-001',
                'collectedAmount' => $attempt->amount,
                'collectedCurrency' => 'TZS',
            ],
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$json, 'ecofy-webhook-secret');

        $this->call('POST', '/api/v1/webhooks/payments/ecofy', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ECOFY_SIGNATURE' => $signature,
        ], $json)->assertOk();

        $this->assertSame(ContributionStatus::Paid, $contribution->fresh()->status);
        $this->assertSame(PaymentAttemptStatus::Successful, $attempt->fresh()->status);
        $this->assertDatabaseCount('payment_provider_events', 1);
        $this->assertDatabaseHas('payment_provider_events', [
            'event_name' => 'payment received',
            'processed_at' => now(),
        ]);

        $this->call('POST', '/api/v1/webhooks/payments/ecofy', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ECOFY_SIGNATURE' => $signature,
        ], $json)->assertOk();

        $this->assertDatabaseCount('payment_provider_events', 1);
    }

    public function test_live_payout_requires_a_different_approver(): void
    {
        $gateway = new class implements PaymentGateway
        {
            public function initiateCollection(PaymentAttempt $attempt): array
            {
                return ['id' => 'collection'];
            }

            public function queryCollection(PaymentAttempt $attempt): array
            {
                return [];
            }

            public function initiatePayout(Payout $payout): array
            {
                return ['id' => 'CP-PAYOUT-001', 'status' => 'AUTHORIZED'];
            }

            public function queryPayout(Payout $payout): array
            {
                return [];
            }
        };
        $this->app->instance(PaymentGateway::class, $gateway);

        [$maker, $contribution, $round] = $this->readyContribution(withReadyRound: true);
        $approver = User::factory()->create(['system_role' => SystemRole::Admin]);
        Sanctum::actingAs($maker);

        $response = $this->postJson("/api/v1/rounds/{$round->id}/live-payouts", [
            'idempotency_key' => 'payout-request-001',
        ])->assertCreated();
        $payoutId = $response->json('data.id');

        $this->postJson("/api/v1/payouts/{$payoutId}/approve")
            ->assertConflict()
            ->assertJsonPath('message', 'The payout requester cannot approve the same payout.');

        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/payouts/{$payoutId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.approved_by', $approver->id);
    }

    public function test_dashboard_cannot_approve_a_system_managed_whatsapp_payout(): void
    {
        [$admin, $contribution, $round] = $this->readyContribution(withReadyRound: true);
        $payout = Payout::query()->create([
            'round_id' => $round->id,
            'recipient_cycle_member_id' => $round->recipient_cycle_member_id,
            'amount' => $round->payout_amount,
            'status' => 'pending',
            'provider' => 'ecofy',
            'order_reference' => 'KJP-WD-SYSTEM-001',
            'idempotency_key' => 'KJP-WD-'.$round->id,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/payouts/{$payout->id}/approve")
            ->assertConflict()
            ->assertJsonPath('message', 'This system-managed payout must be completed by the recipient through the secure WhatsApp POKEA flow.');

        $this->assertSame(PayoutStatus::Pending, $payout->fresh()->status);
        $this->assertNull($payout->fresh()->approved_by);
    }

    public function test_ecofy_quote_explains_insufficient_provider_balance_safely(): void
    {
        config([
            'services.payments.live_enabled' => true,
            'services.ecofy.base_url' => 'https://ecofy.test',
            'services.ecofy.api_key' => 'test-key',
            'services.ecofy.merchant_id' => 'test-merchant',
        ]);
        Http::fake([
            'https://ecofy.test/v1/payouts/quote' => Http::response([
                'error' => [
                    'code' => 'insufficient_balance',
                    'message' => 'payout: insufficient available balance',
                ],
            ], 422),
        ]);

        $payout = new Payout([
            'amount' => 800,
            'destination_phone' => '255744330332',
            'order_reference' => 'KJP-WD-QUOTE-001',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Malipo yanasubiri salio la Kijumbe liongezwe kwa mtoa huduma. Tafadhali jaribu POKEA baadaye.');

        app(PayoutGateway::class)->quote($payout);
    }

    /** @return array{User, Contribution, Round|null} */
    private function readyContribution(bool $withReadyRound = false): array
    {
        $admin = User::factory()->create(['system_role' => SystemRole::Admin]);
        $group = Group::create([
            'name' => 'Office Live Pilot',
            'status' => GroupStatus::Active,
            'created_by' => $admin->id,
        ]);
        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $admin->id,
            'role' => GroupRole::Coordinator,
            'is_active' => true,
            'joined_at' => now(),
        ]);
        $memberships = User::factory()->count(2)->sequence(
            ['phone' => '255700000001', 'whatsapp_wa_id' => '255700000001'],
            ['phone' => '255700000002', 'whatsapp_wa_id' => '255700000002'],
        )->create()->map(fn (User $user) => GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'role' => GroupRole::Member,
            'is_active' => true,
            'joined_at' => now(),
        ]));

        Sanctum::actingAs($admin);
        $cycleId = $this->postJson("/api/v1/groups/{$group->id}/cycles", [
            'name' => 'Real-money office cycle',
            'contribution_amount' => 1000,
            'period_days' => 7,
            'grace_period_hours' => 24,
            'starts_at' => now()->addHour()->toIso8601String(),
            'members' => $memberships->values()->map(fn (GroupMember $membership, int $index) => [
                'group_member_id' => $membership->id,
                'payout_position' => $index + 1,
            ])->all(),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/cycles/{$cycleId}/activate")->assertOk();
        $round = Cycle::findOrFail($cycleId)->rounds()->with('contributions')->firstOrFail();

        if ($withReadyRound) {
            foreach ($round->contributions as $index => $item) {
                $this->postJson("/api/v1/contributions/{$item->id}/confirm", [
                    'payment_reference' => "OFFICE-{$index}",
                ])->assertOk();
            }
            $round->refresh();
        }

        return [$admin, $round->contributions->first(), $withReadyRound ? $round : null];
    }
}
