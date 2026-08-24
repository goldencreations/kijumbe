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

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Kijumbe Admin',
            'email' => 'admin@kijumbe.test',
            'phone' => '+255710000001',
            'system_role' => SystemRole::Admin,
            'password' => 'password',
        ]);

        $members = collect([
            ['Mama Asha', 'asha@kijumbe.test', '+255710000002'],
            ['Neema', 'neema@kijumbe.test', '+255710000003'],
            ['Rehema', 'rehema@kijumbe.test', '+255710000004'],
            ['Fatuma', 'fatuma@kijumbe.test', '+255710000005'],
            ['Zawadi', 'zawadi@kijumbe.test', '+255710000006'],
        ])->map(fn (array $member) => User::factory()->create([
            'name' => $member[0],
            'email' => $member[1],
            'phone' => $member[2],
            'system_role' => SystemRole::Member,
            'password' => 'password',
        ]));

        $group = Group::create([
            'name' => 'Kijumbe Office Pilot',
            'description' => 'Internal test group. No real customer funds.',
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

        foreach ($members as $member) {
            GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $member->id,
                'role' => GroupRole::Member,
                'is_active' => true,
                'joined_at' => now(),
            ]);
        }
    }
}
