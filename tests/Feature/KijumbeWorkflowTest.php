<?php

namespace Tests\Feature;

use App\Enums\ContributionStatus;
use App\Enums\CycleStatus;
use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Enums\RoundStatus;
use App\Enums\SystemRole;
use App\Models\Cycle;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KijumbeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Group $group;

    private array $memberships;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-24 10:00:00');
        $this->admin = User::factory()->create(['system_role' => SystemRole::Admin]);
        $this->group = Group::create([
            'name' => 'Office Pilot',
            'status' => GroupStatus::Active,
            'created_by' => $this->admin->id,
        ]);

        GroupMember::create([
            'group_id' => $this->group->id,
            'user_id' => $this->admin->id,
            'role' => GroupRole::Coordinator,
            'is_active' => true,
            'joined_at' => now(),
        ]);

        $this->memberships = User::factory()->count(3)->create()
            ->map(fn (User $user) => GroupMember::create([
                'group_id' => $this->group->id,
                'user_id' => $user->id,
                'role' => GroupRole::Member,
                'is_active' => true,
                'joined_at' => now(),
            ]))
            ->all();

        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_activation_builds_the_full_schedule_and_blocks_an_early_payout(): void
    {
        $cycle = $this->activateCycle(now()->addHour());
        $firstRound = $cycle->rounds()->firstOrFail();

        $this->assertDatabaseCount('rounds', 3);
        $this->assertDatabaseCount('contributions', 9);
        $this->assertSame(RoundStatus::Scheduled, $firstRound->status);

        $this->postJson("/api/v1/rounds/{$firstRound->id}/payouts", [
            'payment_reference' => 'PAYOUT-EARLY',
        ])->assertConflict()
            ->assertJsonPath('message', 'Payout is blocked until the round is fully funded or a partial payout is formally accepted.');
    }

    public function test_office_user_can_log_in_and_receive_a_sanctum_token(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->admin->email,
            'password' => 'password',
            'device_name' => 'workflow-test',
        ])->assertOk()
            ->assertJsonPath('user.id', $this->admin->id)
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_payment_idempotency_key_is_safe_to_retry_but_cannot_move_to_another_contribution(): void
    {
        $cycle = $this->activateCycle(now()->addHour());
        $contributions = $cycle->rounds()->firstOrFail()->contributions()->get();
        $payload = [
            'payment_reference' => 'SAFE-RETRY-001',
            'idempotency_key' => 'provider-event-001',
        ];

        $this->postJson("/api/v1/contributions/{$contributions[0]->id}/confirm", $payload)->assertOk();
        $this->postJson("/api/v1/contributions/{$contributions[0]->id}/confirm", $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $contributions[0]->id);

        $this->postJson("/api/v1/contributions/{$contributions[1]->id}/confirm", $payload)
            ->assertConflict()
            ->assertJsonPath('message', 'This idempotency key belongs to a different contribution.');

        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_ordinary_member_cannot_confirm_a_payment_or_record_a_payout(): void
    {
        $cycle = $this->activateCycle(now()->addHour());
        $round = $cycle->rounds()->with('contributions')->firstOrFail();
        Sanctum::actingAs($this->memberships[0]->user);

        $this->postJson("/api/v1/contributions/{$round->contributions[0]->id}/confirm", [
            'payment_reference' => 'MEMBER-CANNOT-CONFIRM',
        ])->assertForbidden();

        $this->postJson("/api/v1/rounds/{$round->id}/payouts", [
            'payment_reference' => 'MEMBER-CANNOT-PAYOUT',
        ])->assertForbidden();
    }

    public function test_late_payments_are_marked_and_a_fully_funded_round_can_be_paid(): void
    {
        $cycle = $this->activateCycle(now()->subMinutes(30), graceHours: 2);
        $round = $cycle->rounds()->with('contributions')->firstOrFail();

        foreach ($round->contributions as $index => $contribution) {
            $this->postJson("/api/v1/contributions/{$contribution->id}/confirm", [
                'payment_reference' => "PAY-{$index}",
                'idempotency_key' => "office-payment-{$index}",
                'paid_at' => now()->toIso8601String(),
            ])->assertOk();
        }

        $round->refresh();
        $this->assertSame(RoundStatus::ReadyForPayout, $round->status);
        $this->assertSame(3000, $round->collected_amount);
        $this->assertSame(3, $round->contributions()->where('status', ContributionStatus::PaidLate->value)->count());

        $this->postJson("/api/v1/rounds/{$round->id}/payouts", [
            'payment_reference' => 'PAYOUT-001',
        ])->assertCreated()
            ->assertJsonPath('data.amount', 3000)
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(RoundStatus::Paid, $round->fresh()->status);
    }

    public function test_expired_grace_blocks_the_round_and_group_can_accept_a_partial_payout(): void
    {
        $cycle = $this->activateCycle(now()->subHours(2), graceHours: 1);
        $round = $cycle->rounds()->with('contributions')->firstOrFail();

        $this->artisan('kijumbe:refresh-round-statuses')->assertSuccessful();
        $this->assertSame(RoundStatus::Blocked, $round->fresh()->status);
        $this->assertSame(CycleStatus::Blocked, $cycle->fresh()->status);

        foreach ($round->contributions->take(2) as $index => $contribution) {
            $this->postJson("/api/v1/contributions/{$contribution->id}/confirm", [
                'payment_reference' => "LATE-{$index}",
                'paid_at' => now()->toIso8601String(),
            ])->assertOk();
        }

        $this->postJson("/api/v1/rounds/{$round->id}/decisions", [
            'type' => 'accept_partial',
            'reason' => 'Office pilot members voted to continue with the amount collected.',
        ])->assertCreated()
            ->assertJsonPath('data.payload.accepted_amount', 2000)
            ->assertJsonPath('data.payload.shortfall', 1000);

        $round->refresh();
        $this->assertSame(RoundStatus::ReadyForPayout, $round->status);
        $this->assertSame(1, $round->contributions()->where('status', ContributionStatus::Defaulted->value)->count());

        $this->postJson("/api/v1/rounds/{$round->id}/payouts", [
            'payment_reference' => 'PARTIAL-PAYOUT-001',
        ])->assertCreated()->assertJsonPath('data.amount', 2000);
    }

    public function test_group_can_extend_an_expired_grace_period(): void
    {
        $cycle = $this->activateCycle(now()->subHours(2), graceHours: 1);
        $round = $cycle->rounds()->firstOrFail();
        $this->artisan('kijumbe:refresh-round-statuses')->assertSuccessful();

        $newDeadline = now()->addHours(3);
        $this->postJson("/api/v1/rounds/{$round->id}/decisions", [
            'type' => 'extend_grace',
            'reason' => 'The member informed the group and requested three more hours.',
            'new_grace_ends_at' => $newDeadline->toIso8601String(),
        ])->assertCreated();

        $round->refresh();
        $this->assertSame(RoundStatus::InGrace, $round->status);
        $this->assertTrue($round->grace_ends_at->equalTo($newDeadline));
        $this->assertSame(CycleStatus::Active, $cycle->fresh()->status);
    }

    private function activateCycle(Carbon $startsAt, int $graceHours = 24): Cycle
    {
        $response = $this->postJson("/api/v1/groups/{$this->group->id}/cycles", [
            'name' => 'Pilot Cycle 1',
            'contribution_amount' => 1000,
            'period_days' => 7,
            'grace_period_hours' => $graceHours,
            'starts_at' => $startsAt->toIso8601String(),
            'members' => collect($this->memberships)->values()->map(fn (GroupMember $membership, int $index) => [
                'group_member_id' => $membership->id,
                'payout_position' => $index + 1,
            ])->all(),
        ])->assertCreated();

        $cycle = Cycle::findOrFail($response->json('data.id'));
        $this->postJson("/api/v1/cycles/{$cycle->id}/activate")->assertOk();

        return $cycle->fresh();
    }
}
