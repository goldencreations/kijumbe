<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\User;
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
}
