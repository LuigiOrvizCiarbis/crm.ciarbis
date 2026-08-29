<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhatsAppGroupInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_sends_template_with_group_id_parameter_to_each_invitee(): void
    {
        [$user, $channel, $group, $template] = $this->context();
        Http::fake(['https://graph.facebook.com/*/*/messages' => Http::response(['messages' => [['id' => 'wamid.invite1']]], 200)]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/whatsapp-groups/{$group->id}/invitations", [
            'template_id' => $template->id,
            'invitees' => [
                ['phone' => '5511122233344', 'name' => 'Nuevo invitado'],
            ],
        ]);

        $response->assertCreated();

        Http::assertSent(function ($request) use ($group) {
            $body = $request->data();

            return ($body['template']['components'][0]['parameters'][0]['type'] ?? null) === 'group_id'
                && ($body['template']['components'][0]['parameters'][0]['group_id'] ?? null) === $group->group_id;
        });

        $this->assertDatabaseHas('whatsapp_group_participants', [
            'whatsapp_group_id' => $group->id,
            'wa_id' => '5511122233344',
            'status' => 'invited',
        ]);
    }

    public function test_invitation_fails_when_group_still_pending(): void
    {
        [$user, $channel, , $template] = $this->context();
        $pendingConversation = Conversation::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => null,
            'kind' => 'group',
            'status' => 'open',
        ]);
        $pendingGroup = WhatsAppGroup::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'conversation_id' => $pendingConversation->id,
            'subject' => 'Todavía pendiente',
            'status' => 'pending',
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/whatsapp-groups/{$pendingGroup->id}/invitations", [
            'template_id' => $template->id,
            'invitees' => [['phone' => '5511122233344']],
        ]);

        $response->assertStatus(422);
    }

    public function test_invitation_rejects_more_than_seven_invitees(): void
    {
        [$user, $channel, $group, $template] = $this->context();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/whatsapp-groups/{$group->id}/invitations", [
            'template_id' => $template->id,
            'invitees' => array_map(fn ($i) => ['phone' => "+549111111111{$i}"], range(0, 7)),
        ]);

        $response->assertStatus(422);
    }

    public function test_templates_endpoint_returns_only_templates_with_group_id_parameter(): void
    {
        [$user, $channel, $group, $template] = $this->context();

        WhatsAppTemplate::create([
            'tenant_id' => $channel->tenant_id,
            'whatsapp_config_id' => $channel->whatsapp_config_id,
            'external_id' => 'meta-other',
            'name' => 'other_template',
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}']],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/whatsapp-groups/{$group->id}/invite-templates");

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains($template->name));
        $this->assertFalse($names->contains('other_template'));
    }

    /** @return array{0: User, 1: Channel, 2: WhatsAppGroup, 3: WhatsAppTemplate} */
    private function context(): array
    {
        $tenant = $this->createTenantWithRoles('Acme '.uniqid());
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');

        $config = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 11 0000-0000',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => null,
            'kind' => 'group',
            'status' => 'open',
        ]);

        $group = WhatsAppGroup::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'conversation_id' => $conversation->id,
            'group_id' => 'group-'.uniqid().'@g.us',
            'subject' => 'Grupo activo',
            'status' => 'active',
        ]);

        $template = WhatsAppTemplate::create([
            'tenant_id' => $tenant->id,
            'whatsapp_config_id' => $config->id,
            'external_id' => 'meta-group-invite',
            'name' => 'group_invite_link',
            'language' => 'en_US',
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'components' => [[
                'type' => 'BODY',
                'text' => 'Te invitamos a sumarte al grupo.',
                'example' => ['body_text_named_params' => [['param_name' => 'group_id', 'example' => 'group_id']]],
            ]],
        ]);

        return [$user, $channel, $group, $template];
    }
}
