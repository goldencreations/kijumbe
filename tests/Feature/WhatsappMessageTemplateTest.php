<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\User;
use App\Models\WhatsappMessageTemplate;
use App\Services\Whatsapp\MetaWhatsAppClient;
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
            ->assertSee('Main menu')
            ->assertSee('Create group start')
            ->assertSee('BADILI TAARIFA - Badili mchezo unaoratibu')
            ->assertSee('data-template-preview', false)
            ->assertSee('data-template-format="bullet"', false)
            ->assertSee('{group_name}');

        $body = 'Habari, deni la {group_name} ni TZS {contribution_due}{penalty_notice} {deadline_notice}.';
        $this->putJson('/admin/actions/whatsapp/message-templates/contribution_reminder', ['body' => $body])
            ->assertOk()
            ->assertJsonPath('data.key', 'contribution_reminder');

        $this->assertDatabaseHas('whatsapp_message_templates', [
            'key' => 'contribution_reminder',
            'body' => $body,
            'updated_by' => $admin->id,
        ]);
        $this->assertSame(
            'Habari, deni la Upendo ni TZS 12,000 .',
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

    public function test_admin_can_configure_reply_buttons_and_meta_payload_uses_the_action_ids(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::Admin]);
        $this->actingAs($admin);
        $body = 'Chagua hatua ya {group_name}.';

        $this->putJson('/admin/actions/whatsapp/message-templates/status_due', [
            'body' => 'Mchango unaosubiri: TZS {amount}. Mwisho wa kulipa ni {deadline}.',
            'interaction_type' => 'buttons',
            'interaction_options' => [
                ['id' => 'LIPA', 'title' => 'Lipa sasa'],
                ['id' => 'STATUS', 'title' => 'Angalia tena'],
            ],
        ])->assertOk();

        $interaction = app(WhatsappMessageTemplateService::class)->interaction('status_due');
        $this->assertSame('buttons', $interaction['type']);
        $this->assertSame(['LIPA', 'STATUS'], array_column($interaction['options'], 'id'));
        $this->assertSame('buttons', WhatsappMessageTemplate::where('key', 'status_due')->first()->interaction['type']);

        $member = User::factory()->create([
            'is_active' => true,
            'whatsapp_wa_id' => '255700000011',
            'whatsapp_opted_in_at' => now(),
        ]);
        $message = app(MetaWhatsAppClient::class)->sendInteractive($member, $body, $interaction);

        $this->assertSame('interactive', $message->message_type);
        $this->assertSame('LIPA', $message->payload['interactive']['action']['buttons'][0]['reply']['id']);
        $this->assertSame('Lipa sasa', $message->payload['interactive']['action']['buttons'][0]['reply']['title']);
    }

    public function test_main_menu_has_a_native_whatsapp_list_by_default(): void
    {
        $interaction = app(WhatsappMessageTemplateService::class)->interaction('main_menu');

        $this->assertSame('list', $interaction['type']);
        $this->assertSame('Chagua huduma', $interaction['button_text']);
        $this->assertContains('LIPA', array_column($interaction['options'], 'id'));
    }

    public function test_required_template_variables_cannot_be_removed(): void
    {
        $this->actingAs(User::factory()->create(['system_role' => SystemRole::Admin]));

        $this->putJson('/admin/actions/whatsapp/message-templates/status_due', [
            'body' => 'Mchango unaosubiri.',
        ])->assertUnprocessable()->assertJsonValidationErrors('body');

        $this->assertDatabaseMissing('whatsapp_message_templates', ['key' => 'status_due']);
    }

    public function test_non_admin_cannot_manage_message_templates(): void
    {
        $this->actingAs(User::factory()->create(['system_role' => SystemRole::Member]));

        $this->get('/admin/whatsapp/message-templates')->assertForbidden();
        $this->putJson('/admin/actions/whatsapp/message-templates/contribution_reminder', ['body' => 'Changed'])->assertForbidden();
    }
}
