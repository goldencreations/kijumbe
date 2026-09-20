<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Enums\SystemRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMemberSlot;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Models\User;
use App\Services\MemberPaymentLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_can_load_all_dashboard_feeds(): void
    {
        $this->seed();
        $admin = User::query()->where('system_role', SystemRole::Admin->value)->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.active_groups', 1)
            ->assertJsonCount(0, 'data.active_rounds');

        $this->getJson('/api/v1/dashboard/operations')
            ->assertOk()
            ->assertJsonStructure(['data' => ['payment_attempts', 'payouts']]);

        $this->getJson('/api/v1/dashboard/audit-logs')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'per_page']);
    }

    public function test_a_member_cannot_load_administrative_dashboard_feeds(): void
    {
        $member = User::factory()->create(['system_role' => SystemRole::Member]);
        Sanctum::actingAs($member);

        $this->getJson('/api/v1/dashboard')->assertForbidden();
        $this->getJson('/api/v1/dashboard/operations')->assertForbidden();
        $this->getJson('/api/v1/dashboard/audit-logs')->assertForbidden();
    }

    public function test_dashboard_uses_all_successful_payments_and_can_filter_every_total_by_group(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::Admin]);
        Sanctum::actingAs($admin);
        $this->app->instance(PaymentGateway::class, new class implements PaymentGateway
        {
            public function initiateCollection(PaymentAttempt $attempt): array
            {
                return ['id' => 'dashboard-payment-'.$attempt->id];
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

        $group = Group::create([
            'name' => 'Dashboard Totals Group',
            'status' => GroupStatus::Active,
            'created_by' => $admin->id,
            'contribution_amount' => 1000,
            'currency' => 'TZS',
            'contribution_interval_days' => 1,
            'payout_interval_days' => 1,
            'cycle_starts_at' => now()->subHours(3),
            'kijumbe_fee' => 100,
            'platform_fee_percent' => 30,
            'penalty_amount' => 100,
            'penalty_timing_hours' => 2,
        ]);
        $users = collect([$admin, User::factory()->create(['phone' => '255748281701'])]);
        foreach ($users as $index => $user) {
            $membership = GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $user->id,
                'member_name' => $user->name,
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
        foreach ($users as $index => $user) {
            $attempt = $ledger->initiate(
                $user,
                $group->fresh(),
                1100,
                $user->phone ?: '255700000001',
                'dashboard-total-payment-'.$index,
            );
            $ledger->confirmFromProvider($attempt, 1100, 'DASHBOARD-PAID-'.$index, [
                'data' => ['id' => 'dashboard-payment-'.$index, 'fee' => 0, 'net' => 1100],
            ]);
        }

        $round = $group->cycles()->firstOrFail()->rounds()->firstOrFail();
        Payout::create([
            'purpose' => Payout::PURPOSE_MEMBER,
            'round_id' => $round->id,
            'recipient_cycle_member_id' => $round->recipient_cycle_member_id,
            'amount' => 1800,
            'gross_amount' => 2000,
            'kijumbe_fee_amount' => 200,
            'platform_fee_amount' => 60,
            'coordinator_fee_amount' => 140,
            'status' => 'paid',
            'processed_at' => now(),
        ]);

        $emptyGroup = Group::create([
            'name' => 'Empty Dashboard Group',
            'status' => GroupStatus::Active,
            'created_by' => $admin->id,
        ]);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.total_amount', 2200)
            ->assertJsonPath('data.summary.collected_amount', 2200)
            ->assertJsonPath('data.summary.principal_collected', 2000)
            ->assertJsonPath('data.summary.fees_collected', 200)
            ->assertJsonPath('data.summary.platform_fees_collected', 60)
            ->assertJsonPath('data.summary.coordinator_fees_collected', 140)
            ->assertJsonPath('data.summary.penalties_collected', 200)
            ->assertJsonPath('data.summary.successful_payments', 2);

        $this->getJson('/api/v1/dashboard?group_id='.$group->id)
            ->assertOk()
            ->assertJsonPath('data.selected_group_id', $group->id)
            ->assertJsonPath('data.summary.total_amount', 2200)
            ->assertJsonCount(1, 'data.group_breakdown')
            ->assertJsonPath('data.group_breakdown.0.group_id', $group->id)
            ->assertJsonPath('data.group_breakdown.0.penalties_collected', 200);

        $this->getJson('/api/v1/dashboard?group_id='.$emptyGroup->id)
            ->assertOk()
            ->assertJsonPath('data.summary.total_amount', 0)
            ->assertJsonPath('data.summary.fees_collected', 0)
            ->assertJsonPath('data.summary.penalties_collected', 0);

        $this->getJson('/api/v1/dashboard/operations?group_id='.$group->id)
            ->assertOk()
            ->assertJsonCount(2, 'data.payment_attempts');
        $this->getJson('/api/v1/dashboard/operations?group_id='.$emptyGroup->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.payment_attempts');
    }
}
