<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\User;
use App\Services\Whatsapp\WhatsappMessageTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappMessageTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_can_customize_and_restore_an_automatic_message(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::Admin]);
        $this->actingAs($admin);

        $this->get('/admin/whatsapp/message-templates')
            ->assertOk()
            ->assertSee('Contribution reminder')
            ->assertSee('{group_name}');

        $body = 'Habari, deni la {group_name} ni TZS {contribution_due}.';
        $this->putJson('/admin/actions/whatsapp/message-templates/contribution_reminder', ['body' => $body])
            ->assertOk()
            ->assertJsonPath('data.key', 'contribution_reminder');

        $this->assertDatabaseHas('whatsapp_message_templates', [
            'key' => 'contribution_reminder',
            'body' => $body,
            'updated_by' => $admin->id,
        ]);
        $this->assertSame(
            'Habari, deni la Upendo ni TZS 12,000.',
            app(WhatsappMessageTemplateService::class)->render('contribution_reminder', [
                'group_name' => 'Upendo',
                'contribution_due' => '12,000',
            ]),
        );

        $this->deleteJson('/admin/actions/whatsapp/message-templates/contribution_reminder')->assertOk();
        $this->assertDatabaseMissing('whatsapp_message_templates', ['key' => 'contribution_reminder']);
        $this->assertStringContainsString('Ukumbusho wa mchango wa Upendo', app(WhatsappMessageTemplateService::class)->render('contribution_reminder', [
            'group_name' => 'Upendo',
            'oldest_round' => 2,
            'contribution_due' => '12,000',
            'penalty_notice' => '.',
            'deadline_notice' => '',
        ]));
    }

    public function test_unknown_template_variables_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['system_role' => SystemRole::Admin]));

        $this->putJson('/admin/actions/whatsapp/message-templates/payout_completed', [
            'body' => 'Paid to {recipient_name} using {secret_value}.',
        ])->assertUnprocessable()->assertJsonValidationErrors('body');

        $this->assertDatabaseCount('whatsapp_message_templates', 0);
    }

    public function test_non_admin_cannot_manage_message_templates(): void
    {
        $this->actingAs(User::factory()->create(['system_role' => SystemRole::Member]));

        $this->get('/admin/whatsapp/message-templates')->assertForbidden();
        $this->putJson('/admin/actions/whatsapp/message-templates/contribution_reminder', ['body' => 'Changed'])->assertForbidden();
    }
}
