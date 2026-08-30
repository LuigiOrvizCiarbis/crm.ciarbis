<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppGroup;
use App\Services\WhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppGroupMessagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_text_to_group_uses_recipient_type_group_and_the_raw_group_id(): void
    {
        [$user, $channel, $group] = $this->context();
        Http::fake(['https://graph.facebook.com/*/*/messages' => Http::response(['messages' => [['id' => 'wamid.group1']]], 200)]);

        app(WhatsAppMessageService::class)->sendTextMessageFromCRM($group->conversation, 'Hola a todos', $user);

        Http::assertSent(function ($request) use ($group) {
            $body = $request->data();

            return $body['recipient_type'] === 'group'
                && $body['to'] === $group->group_id;
        });
    }

    public function test_sending_text_to_direct_conversation_still_uses_individual(): void
    {
        [$user, $channel] = $this->context();
        $contact = \App\Models\Contact::create([
            'tenant_id' => $channel->tenant_id,
            'name' => 'Ana',
            'phone' => '5511000000000',
            'source' => 'manual',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'kind' => 'direct',
            'status' => 'open',
        ]);
        Http::fake(['https://graph.facebook.com/*/*/messages' => Http::response(['messages' => [['id' => 'wamid.direct1']]], 200)]);

        app(WhatsAppMessageService::class)->sendTextMessageFromCRM($conversation, 'Hola', $user);

        Http::assertSent(fn ($request) => $request->data()['recipient_type'] === 'individual'
            && $request->data()['to'] === '5511000000000');
    }

    public function test_sending_to_pending_group_throws(): void
    {
        [$user, $channel] = $this->context();
        $conversation = Conversation::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => null,
            'kind' => 'group',
            'status' => 'open',
        ]);
        WhatsAppGroup::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'conversation_id' => $conversation->id,
            'subject' => 'Pendiente',
            'status' => 'pending',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(WhatsAppMessageService::class)->sendTextMessageFromCRM($conversation, 'Hola', $user);
    }

    public function test_group_conversation_rejects_voice_messages_via_api(): void
    {
        [$user, $channel, $group] = $this->context();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $response = $this->postJson('/api/messages', [
            'conversation_id' => $group->conversation_id,
            'type' => 'audio',
            'voice' => true,
            'audio' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                'audio.mp3',
                "\xFF\xFB\x90\x64".str_repeat("\0", 1024)
            ),
        ]);

        $response->assertStatus(422)->assertJsonPath('message', 'Los mensajes de voz no están disponibles en grupos.');
    }

    /** @return array{0: User, 1: Channel, 2: WhatsAppGroup} */
    private function context(): array
    {
        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $this->createTenantRolesFor($tenant);
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

        return [$user, $channel, $group];
    }

    private function createTenantRolesFor(Tenant $tenant): void
    {
        app(\App\Support\RoleProvisioner::class)->provisionDefaultRoles($tenant);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    }
}
