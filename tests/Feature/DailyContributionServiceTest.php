<?php

namespace Tests\Feature;

use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\DailyContributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyContributionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_one_configured_daily_contribution_and_reuses_it_on_retry(): void
    {
        Carbon::setTestNow('2026-09-06 10:00:00');
        $user = User::factory()->create();
        $group = Group::create([
            'name' => 'Kikundi cha kila siku',
            'status' => GroupStatus::Draft,
            'created_by' => $user->id,
            'contribution_amount' => 2000,
            'currency' => 'TZS',
            'cycle_starts_at' => '2026-09-06 08:00:00',
            'grace_period_hours' => 24,
        ]);
        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'role' => GroupRole::Coordinator,
            'is_active' => true,
            'joined_at' => now(),
        ]);

        $service = app(DailyContributionService::class);
        $first = $service->forUser($group, $user);
        $retry = $service->forUser($group, $user);

        $this->assertNotNull($first);
        $this->assertSame(2000, $first->amount);
        $this->assertSame($first->id, $retry?->id);
        $this->assertDatabaseCount('cycles', 1);
        $this->assertDatabaseCount('rounds', 1);
        $this->assertDatabaseCount('contributions', 1);

        Carbon::setTestNow('2026-09-07 10:00:00');
        $next = $service->forUser($group->fresh(), $user);

        $this->assertNotNull($next);
        $this->assertNotSame($first->id, $next->id);
        $this->assertSame(2000, $next->amount);
        $this->assertDatabaseCount('rounds', 2);
        $this->assertDatabaseCount('contributions', 2);
        Carbon::setTestNow();
    }
}
