<?php

namespace Database\Seeders;

use App\Enums\GroupRole;
use App\Enums\GroupStatus;
use App\Enums\SystemRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@kijumbe.local'],
            [
            'name' => 'Kijumbe Admin',
                'phone' => '+255710000001',
                'system_role' => SystemRole::Admin,
                'password' => 'password',
                'is_active' => true,
            ]
        );

        $members = collect([
            ['Mama Asha', 'asha@kijumbe.test', '+255710000002'],
            ['Neema', 'neema@kijumbe.test', '+255710000003'],
            ['Rehema', 'rehema@kijumbe.test', '+255710000004'],
            ['Fatuma', 'fatuma@kijumbe.test', '+255710000005'],
            ['Zawadi', 'zawadi@kijumbe.test', '+255710000006'],
        ])->map(fn (array $member) => User::query()->firstOrCreate(
            ['email' => $member[1]],
            [
                'name' => $member[0],
                'phone' => $member[2],
                'system_role' => SystemRole::Member,
                'password' => 'password',
                'is_active' => true,
            ]
        ));

        $group = Group::query()->firstOrNew(['name' => 'Kijumbe Office Pilot']);
        $group->fill([
            'description' => 'Internal test group. No real customer funds.',
            'status' => GroupStatus::Active,
            'created_by' => $admin->id,
            'created_by_phone' => '+255710000001',
            'created_by_wa_id' => '255710000001',
            'language' => 'sw',
            'contribution_amount' => 2000,
            'currency' => 'TZS',
            'period_days' => 7,
            'member_slots' => 10,
            'cycle_starts_at' => now()->addDay(),
            'payout_order' => 'fixed',
            'grace_period_hours' => 24,
            'platform_fee_amount' => 0,
            'resolution_threshold_percent' => 75,
        ]);

        if (! $group->exists) {
            $group->id = (string) Str::uuid();
        }

        $group->save();

        GroupMember::query()->firstOrCreate([
            'group_id' => $group->id,
            'user_id' => $admin->id,
        ], [
            'role' => GroupRole::Coordinator,
            'is_active' => true,
            'joined_at' => now(),
        ]);

        foreach ($members as $member) {
            GroupMember::query()->firstOrCreate([
                'group_id' => $group->id,
                'user_id' => $member->id,
            ], [
                'role' => GroupRole::Member,
                'is_active' => true,
                'joined_at' => now(),
            ]);
        }
    }
}
