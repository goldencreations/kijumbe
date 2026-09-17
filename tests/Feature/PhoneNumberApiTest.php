<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhoneNumberApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_phone_input_is_stored_for_providers_and_returned_for_local_display(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::Admin]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/office-users', [
            'name' => 'Asha Mussa',
            'email' => 'asha@example.test',
            'phone' => '0748281701',
            'password' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.phone', '255748281701')
            ->assertJsonPath('data.phone_local', '0748281701');

        $this->assertDatabaseHas('users', [
            'email' => 'asha@example.test',
            'phone' => '255748281701',
        ]);
    }

    public function test_duplicate_local_and_provider_phone_formats_are_rejected_as_the_same_number(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::Admin]);
        User::factory()->create(['phone' => '255748281701']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/office-users', [
            'name' => 'Asha Mussa',
            'email' => 'asha@example.test',
            'phone' => '0748281701',
            'password' => 'password123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }
}
