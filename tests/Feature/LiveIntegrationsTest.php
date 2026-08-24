<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Enums\ContributionStatus;
use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\SystemRole;
use App\Models\Contribution;
use App\Models\Cycle;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Models\Round;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
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
