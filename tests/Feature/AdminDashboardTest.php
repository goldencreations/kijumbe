<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\Contribution;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Payout;
use App\Models\User;
use App\Models\WhatsappMessage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.payments.live_enabled' => false, 'services.whatsapp.live_enabled' => false]);
    }

    public function test_login_only_accepts_active_administrators_and_logout_ends_access(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/admin/login');
        foreach ([['system_role' => SystemRole::Member], ['system_role' => SystemRole::Admin, 'is_active' => false]] as $attributes) {
            $user = User::factory()->create($attributes);
            $this->post('/admin/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
            $this->assertGuest();
        }
        $admin = User::factory()->create(['system_role' => SystemRole::Admin]);
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/admin/dashboard');
        $this->assertAuthenticatedAs($admin);
        $this->post('/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_members_and_disabled_admins_cannot_read_or_mutate_web_dashboard(): void
    {
        foreach ([['system_role' => SystemRole::Member], ['system_role' => SystemRole::Admin, 'is_active' => false]] as $attributes) {
            $this->actingAs(User::factory()->create($attributes));
            $this->get('/admin/dashboard')->assertForbidden();
            $this->get('/admin/members')->assertForbidden();
            $this->postJson('/admin/actions/members', ['name' => 'Blocked'])->assertForbidden();
        }
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_all_empty_dashboard_pages_render_without_fictional_data(): void
    {
        $this->actingAs(User::factory()->create(['system_role' => SystemRole::Admin]));
        $this->get('/admin/dashboard')->assertOk()->assertSee('Waiting for data')->assertDontSee('128,450,000')->assertViewHas('series', []);
        foreach (['groups', 'members', 'payments', 'payouts', 'whatsapp', 'audit', 'exceptions'] as $page) {
            $this->get('/admin/'.$page)->assertOk();
        }

        $this->get('/admin/dashboard')->assertOk()->assertSee('Message templates');
        $this->get('/admin/login')->assertOk()->assertDontSee('Google')->assertDontSee('GitHub')->assertDontSee('Create an account');
    }

    public function test_greeting_uses_east_africa_time_and_admin_group_creation_is_unavailable(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Asha Admin', 'system_role' => SystemRole::Admin]));
        foreach (['05:00' => 'Good morning', '09:00' => 'Good afternoon', '15:00' => 'Good evening'] as $utc => $greeting) {
            $this->travelTo(Carbon::parse('2026-09-16 '.$utc, 'UTC'));
            $this->get('/admin/dashboard')->assertOk()->assertSee($greeting.', Asha.')->assertSee('images/dashboard-welcome.png')->assertDontSee('Create group');
        }
        $this->travelBack();
        $this->get('/admin/groups?create=1')->assertOk()->assertDontSee('Create group')->assertDontSee('create-group');
        $this->postJson('/admin/actions/groups', ['name' => 'Not allowed'])->assertNotFound();
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_existing_group_activation_and_cycle_workflow_through_web_actions(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::Admin]);
        $this->actingAs($admin);
        $group = Group::create(['name' => 'Actual group', 'contribution_amount' => 2000, 'member_slots' => 3, 'created_by' => $admin->id, 'status' => 'draft']);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $admin->id, 'role' => 'coordinator', 'is_active' => true, 'joined_at' => now()]);
        $id = $group->id;
        $this->get('/admin/groups/'.$id)
            ->assertOk()
            ->assertSee('Actual group')
            ->assertViewHas('memberPayments', fn (array $payments): bool => collect($payments)->every(
                fn (array $payment): bool => collect([
                    'current_paid_amount',
                    'current_expected_amount',
                    'current_coordinator_fee_amount',
                    'current_coordinator_fee_paid_amount',
                    'current_round_number',
                    'total_paid_amount',
                    'paid_amount',
                    'expected_amount',
                    'circle_amount',
                    'circle_paid_amount',
                    'coordinator_fee_amount',
                    'coordinator_fee_paid_amount',
                    'kijumbe_fee_amount',
                    'slots_count',
                ])->every(fn (string $key): bool => array_key_exists($key, $payment)),
            ))
            ->assertViewHas('financialSummary', fn (array $summary): bool => array_key_exists('total_fees_earned', $summary));
        $this->postJson('/admin/actions/groups/'.$id.'/activate')->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson('/admin/actions/groups/'.$id.'/activate')->assertConflict();
        $member = User::factory()->create();
        $this->postJson('/admin/actions/groups/'.$id.'/members', ['user_id' => $member->id])->assertCreated();
        $members = GroupMember::where('group_id', $id)->get()->values()->map(fn ($m, $i) => ['group_member_id' => $m->id, 'payout_position' => $i + 1])->all();
        $cycleId = $this->postJson('/admin/actions/groups/'.$id.'/cycles', ['name' => 'First cycle', 'contribution_amount' => 2000, 'period_days' => 7, 'grace_period_hours' => 24, 'starts_at' => now()->addDay()->toIso8601String(), 'members' => $members])->assertCreated()->json('data.id');
        $this->get('/admin/cycles/'.$cycleId)->assertOk()->assertSee('First cycle');
        $this->postJson('/admin/actions/cycles/'.$cycleId.'/activate')->assertOk();
        $this->get('/admin/cycles/'.$cycleId)->assertOk()->assertSee('Round 1');
        $this->assertDatabaseCount('contributions', 4);
        $this->assertDatabaseHas('audit_logs', ['action' => 'group.activated', 'auditable_id' => $id]);

        $contribution = Contribution::first();
        $contribution->update(['status' => 'paid', 'paid_at' => now()]);
        $this->get('/admin/dashboard?days=7')->assertOk()->assertViewHas('stats', fn ($stats) => $stats['collected'] === 2000)->assertViewHas('series', fn ($series) => count($series) === 7 && array_sum(array_column($series, 'collected')) === 2000);
        $contribution->update(['paid_at' => now()->subDays(10)]);
        $this->get('/admin/dashboard?days=7')->assertViewHas('series', []);
        $this->get('/admin/dashboard?days=30')->assertViewHas('series', fn ($series) => array_sum(array_column($series, 'collected')) === 2000);
        $this->get('/admin/groups?q=missing')->assertOk()->assertSee('No matching records')->assertDontSee('Actual group');
        $this->get('/admin/payments?q='.$admin->name)->assertOk();
        $this->get('/admin/audit')->assertOk()->assertSee('Group activated');

        $round = $contribution->round;
        $round->update(['status' => 'blocked']);
        $this->get('/admin/exceptions?q=Actual')->assertOk()->assertSee('Actual group');
        $this->get('/admin/exceptions?q=missing')->assertOk()->assertSee('No matching records');
        $contribution->update(['status' => 'pending', 'due_at' => now()->subDay()]);
        $this->get('/admin/payments?overdue=1')->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1);

        Payout::create(['round_id' => $round->id, 'recipient_cycle_member_id' => $round->recipient_cycle_member_id, 'amount' => 2000, 'status' => 'pending', 'requested_by' => $member->id, 'destination_phone' => '255700000001', 'order_reference' => 'UI-TEST-PAYOUT']);
        $this->get('/admin/payouts?q=Actual')->assertOk()->assertSee('Review')->assertSee('Approve and send');
        $this->get('/admin/cycles/'.$cycleId)->assertOk()->assertSee('Record decision');

        WhatsappMessage::create(['user_id' => $member->id, 'direction' => 'incoming', 'message_type' => 'text', 'status' => 'received', 'wa_id' => '255700000001', 'payload' => ['text' => ['body' => '<script>alert(1)</script>']]]);
        $this->get('/admin/whatsapp')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }
}
