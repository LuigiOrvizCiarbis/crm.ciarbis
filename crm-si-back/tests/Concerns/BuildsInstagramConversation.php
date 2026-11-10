<?php

namespace Tests\Concerns;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\InstagramConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

/**
 * Conversación de Instagram (PAGE_1/IGSID_1) con un mensaje entrante recién
 * recibido: ventana de mensajería abierta.
 */
trait BuildsInstagramConversation
{
    use ReceivesInboundMessages;

    abstract private function seedTenantWithRoles(): Tenant;

    /**
     * @return array{0: User, 1: Conversation}
     */
    private function createInstagramConversation(): array
    {
        $tenant = $this->seedTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');

        $config = InstagramConfig::create([
            'tenant_id' => $tenant->id,
            'ig_user_id' => 'IG_BIZ_1',
            'page_id' => 'PAGE_1',
            'webhook_object_id' => 'IG_BIZ_1',
            'username' => 'acme',
            'page_access_token' => Crypt::encryptString('PAGE_TOKEN'),
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'instagram_config_id' => $config->id,
            'type' => ChannelType::INSTAGRAM,
            'external_id' => 'IG_BIZ_1',
            'name' => '@acme',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fulano IG',
            'source' => 'instagram',
            'external_id' => 'IGSID_1',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $this->receiveInbound($conversation);

        return [$user, $conversation];
    }
}
