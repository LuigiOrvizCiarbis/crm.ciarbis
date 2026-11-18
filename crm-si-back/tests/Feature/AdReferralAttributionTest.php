<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Events\TenantMessageReceived;
use App\Models\AdReferral;
use App\Models\Branch;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\InstagramConfig;
use App\Models\Message;
use App\Models\MessengerConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Services\Channels\MessengerWebhookSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdReferralAttributionTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'test-app-secret';

    private const PAGE_ID = 'PAGE_1';

    private const IG_BUSINESS_ID = 'IG_BIZ_1';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.facebook.app_secret', self::APP_SECRET);
        config()->set('services.facebook.graph_version', 'v21.0');
    }

    public function test_whatsapp_ad_referral_is_recorded_and_exposed_in_conversation_and_contact(): void
    {
        $channel = $this->makeWhatsAppChannel();

        $this->postJson('/api/whatsapp-webhook', $this->whatsAppPayload($channel, 'wamid.ad1', [
            'source_id' => 'AD_123',
            'source_type' => 'ad',
            'headline' => 'Promo verano',
            'body' => 'Texto',
            'source_url' => 'https://fb.me/x',
            'media_type' => 'image',
            'image_url' => 'https://cdn/x.jpg',
            'ctwa_clid' => 'CLID1',
        ]));

        $referral = AdReferral::withoutGlobalScopes()->sole();
        $message = Message::withoutGlobalScopes()->where('external_id', 'wamid.ad1')->sole();
        $this->assertSame('whatsapp', $referral->platform);
        $this->assertSame('AD_123', $referral->ad_id);
        $this->assertSame('CLID1', $referral->ctwa_clid);
        $this->assertSame('https://cdn/x.jpg', $referral->media_url);
        $this->assertSame($message->id, $referral->message_id);
        $this->assertSame($channel->tenant_id, $referral->tenant_id);

        Sanctum::actingAs($this->makeOwner($channel->tenant_id));

        $this->getJson("/api/conversations/{$referral->conversation_id}")
            ->assertOk()
            ->assertJsonPath('data.ad_referral.ad_id', 'AD_123')
            ->assertJsonPath('data.ad_referral.headline', 'Promo verano')
            ->assertJsonMissingPath('data.ad_referral.ctwa_clid');

        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.ad_referral.ad_id', 'AD_123')
            ->assertJsonPath('data.0.ad_referral.source_type', 'ad');

        $this->getJson("/api/contacts/{$referral->contact_id}")
            ->assertOk()
            ->assertJsonPath('data.ad_referrals.0.ad_id', 'AD_123');
    }

    public function test_whatsapp_retry_of_same_message_does_not_duplicate_referral(): void
    {
        $channel = $this->makeWhatsAppChannel();
        $payload = $this->whatsAppPayload($channel, 'wamid.dup', ['source_id' => 'AD_123', 'source_type' => 'ad']);

        $this->postJson('/api/whatsapp-webhook', $payload);
        $this->postJson('/api/whatsapp-webhook', $payload);

        $this->assertSame(1, AdReferral::withoutGlobalScopes()->count());
    }

    public function test_whatsapp_message_without_referral_records_nothing(): void
    {
        $channel = $this->makeWhatsAppChannel();

        $this->postJson('/api/whatsapp-webhook', $this->whatsAppPayload($channel, 'wamid.plain'));

        $this->assertSame(0, AdReferral::withoutGlobalScopes()->count());

        $conversation = Conversation::withoutGlobalScopes()->sole();
        Sanctum::actingAs($this->makeOwner($channel->tenant_id));

        $this->getJson("/api/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.ad_referral', null);

        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.ad_referral', null);
    }

    public function test_messenger_ad_referral_in_message_is_recorded(): void
    {
        $this->fakeMetaMessaging();
        $this->makeMessengerChannel();

        $this->postSignedWebhook('/api/messenger-webhook', $this->wrapMessengerEvent([
            'sender' => ['id' => 'PSID_1'],
            'recipient' => ['id' => self::PAGE_ID],
            'timestamp' => 1700000000000,
            'message' => [
                'mid' => 'mid_ad',
                'text' => 'Hola, vi el anuncio',
                'referral' => [
                    'source' => 'ADS',
                    'type' => 'OPEN_THREAD',
                    'ad_id' => 'AD_FB',
                    'ref' => 'promo',
                    'ads_context_data' => ['ad_title' => 'Oferta', 'photo_url' => 'https://cdn/p.jpg'],
                ],
            ],
        ]))->assertOk();

        $referral = AdReferral::sole();
        $this->assertSame('facebook', $referral->platform);
        $this->assertSame('AD_FB', $referral->ad_id);
        $this->assertSame('Oferta', $referral->headline);
        $this->assertSame('image', $referral->media_type);
        $this->assertSame('promo', $referral->ref);
        $this->assertSame(Message::sole()->id, $referral->message_id);
    }

    public function test_messenger_standalone_referral_attaches_only_to_existing_conversation(): void
    {
        $this->fakeMetaMessaging();
        $this->makeMessengerChannel();

        $this->postSignedWebhook('/api/messenger-webhook', $this->wrapMessengerEvent([
            'sender' => ['id' => 'PSID_1'],
            'recipient' => ['id' => self::PAGE_ID],
            'message' => ['mid' => 'mid_1', 'text' => 'hola'],
        ]))->assertOk();
        $contactsBefore = Contact::count();

        $this->postSignedWebhook('/api/messenger-webhook', $this->standaloneReferralEvent('PSID_1'))->assertOk();
        $this->postSignedWebhook('/api/messenger-webhook', $this->standaloneReferralEvent('PSID_NUEVO'))->assertOk();

        $referral = AdReferral::sole();
        $this->assertNull($referral->message_id);
        $this->assertSame('AD_2', $referral->ad_id);
        $this->assertSame(Conversation::sole()->id, $referral->conversation_id);
        $this->assertSame(1700000000, $referral->referred_at->getTimestamp());
        $this->assertSame($contactsBefore, Contact::count());
    }

    public function test_messenger_shortlink_referral_is_ignored(): void
    {
        $this->fakeMetaMessaging();
        $this->makeMessengerChannel();

        $this->postSignedWebhook('/api/messenger-webhook', $this->wrapMessengerEvent([
            'sender' => ['id' => 'PSID_1'],
            'recipient' => ['id' => self::PAGE_ID],
            'message' => [
                'mid' => 'mid_link',
                'text' => 'hola',
                'referral' => ['source' => 'SHORTLINK', 'type' => 'OPEN_THREAD', 'ref' => 'bio'],
            ],
        ]))->assertOk();

        $this->assertSame(1, Message::count());
        $this->assertSame(0, AdReferral::count());
    }

    public function test_instagram_ad_referral_in_message_is_recorded(): void
    {
        $this->fakeMetaMessaging();
        $this->makeInstagramChannel();

        $this->postSignedWebhook('/api/instagram-webhook', [
            'object' => 'instagram',
            'entry' => [[
                'id' => self::IG_BUSINESS_ID,
                'time' => now()->timestamp,
                'messaging' => [[
                    'sender' => ['id' => 'IGSID_1'],
                    'recipient' => ['id' => self::IG_BUSINESS_ID],
                    'timestamp' => 1700000000000,
                    'message' => [
                        'mid' => 'ig_mid_ad',
                        'text' => 'Info',
                        'referral' => ['source' => 'ADS', 'type' => 'OPEN_THREAD', 'ad_id' => 'AD_IG'],
                    ],
                ]],
            ]],
        ])->assertOk();

        $referral = AdReferral::sole();
        $this->assertSame('instagram', $referral->platform);
        $this->assertSame('AD_IG', $referral->ad_id);
    }

    public function test_resubscribe_command_sends_referral_fields_for_active_pages(): void
    {
        Http::fake(['graph.facebook.com/*/subscribed_apps' => Http::response(['success' => true])]);
        $this->makeMessengerChannel();

        $this->artisan('messenger:resubscribe-webhooks')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains((string) $request->url(), '/'.self::PAGE_ID.'/subscribed_apps')
            && $request['subscribed_fields'] === MessengerWebhookSubscriber::FIELDS);
    }

    public function test_contact_detail_hides_referrals_of_conversations_outside_user_branch(): void
    {
        $channel = $this->makeWhatsAppChannel();
        $tenantId = $channel->tenant_id;
        $north = Branch::create(['tenant_id' => $tenantId, 'name' => 'North']);
        $south = Branch::create(['tenant_id' => $tenantId, 'name' => 'South']);
        $member = User::factory()->create(['tenant_id' => $tenantId, 'branch_id' => $north->id]);
        $member->syncRoles(['Member']);

        $contact = Contact::create(['tenant_id' => $tenantId, 'name' => 'Lead', 'source' => 'whatsapp']);
        $visible = $this->makeBranchConversation($channel, $contact, $north, $member->id);
        $hidden = $this->makeBranchConversation($channel, $contact, $south, null);

        $this->makeReferral($channel, $contact, $visible->id, 'AD_NORTH');
        $this->makeReferral($channel, $contact, $hidden->id, 'AD_SOUTH');
        $this->makeReferral($channel, $contact, null, 'AD_ORPHAN');

        Sanctum::actingAs($member);

        $adIds = collect($this->getJson("/api/contacts/{$contact->id}")->assertOk()->json('data.ad_referrals'))
            ->pluck('ad_id')->sort()->values()->all();

        $this->assertSame(['AD_NORTH', 'AD_ORPHAN'], $adIds);
    }

    public function test_conversation_list_exposes_every_ad_of_the_chat_history(): void
    {
        $channel = $this->makeWhatsAppChannel();
        $contact = Contact::create(['tenant_id' => $channel->tenant_id, 'name' => 'Lead', 'source' => 'whatsapp']);
        $conversation = Conversation::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $this->makeReferral($channel, $contact, $conversation->id, 'AD_OLD', referredAt: now()->subDay());
        $this->makeReferral($channel, $contact, $conversation->id, 'AD_NEW');
        $this->makeReferral($channel, $contact, $conversation->id, 'AD_NEW');

        Sanctum::actingAs($this->makeOwner($channel->tenant_id));

        $list = $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.ad_referral.ad_id', 'AD_NEW');
        $this->assertEqualsCanonicalizing(['AD_OLD', 'AD_NEW'], $list->json('data.0.ad_ids'));

        $detail = $this->getJson("/api/conversations/{$conversation->id}")->assertOk();
        $this->assertEqualsCanonicalizing(['AD_OLD', 'AD_NEW'], $detail->json('data.ad_ids'));
    }

    public function test_ads_list_groups_visible_referrals_by_ad_newest_first(): void
    {
        $channel = $this->makeWhatsAppChannel();
        $tenantId = $channel->tenant_id;
        $north = Branch::create(['tenant_id' => $tenantId, 'name' => 'North']);
        $south = Branch::create(['tenant_id' => $tenantId, 'name' => 'South']);
        $member = User::factory()->create(['tenant_id' => $tenantId, 'branch_id' => $north->id]);
        $member->syncRoles(['Member']);

        $contact = Contact::create(['tenant_id' => $tenantId, 'name' => 'Lead', 'source' => 'whatsapp']);
        $visible = $this->makeBranchConversation($channel, $contact, $north, $member->id);
        $other = $this->makeBranchConversation($channel, $contact, $north, $member->id);
        $hidden = $this->makeBranchConversation($channel, $contact, $south, null);

        $this->makeReferral($channel, $contact, $visible->id, 'AD_SUMMER', 'Promo verano', now()->subDays(3));
        $this->makeReferral($channel, $contact, $other->id, 'AD_SUMMER', 'Promo verano', now()->subDay());
        $this->makeReferral($channel, $contact, $visible->id, 'AD_WINTER', referredAt: now()->subDays(2));
        $this->makeReferral($channel, $contact, $hidden->id, 'AD_SOUTH');
        $this->makeReferral($channel, $contact, null, 'AD_ORPHAN');

        Sanctum::actingAs($member);

        $this->getJson('/api/conversations/ads')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['ad_id' => 'AD_SUMMER', 'headline' => 'Promo verano', 'source_type' => 'ad'],
                ['ad_id' => 'AD_WINTER', 'headline' => null, 'source_type' => 'ad'],
            ]]);
    }

    private function makeBranchConversation(Channel $channel, Contact $contact, Branch $branch, ?int $assignedTo): Conversation
    {
        return Conversation::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'branch_id' => $branch->id,
            'assigned_to' => $assignedTo,
            'status' => 'open',
        ]);
    }

    private function makeReferral(
        Channel $channel,
        Contact $contact,
        ?int $conversationId,
        string $adId,
        ?string $headline = null,
        ?Carbon $referredAt = null,
    ): void {
        AdReferral::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'conversation_id' => $conversationId,
            'platform' => 'whatsapp',
            'source_type' => 'ad',
            'ad_id' => $adId,
            'headline' => $headline,
            'dedupe_key' => 'test:'.$adId.':'.uniqid(),
            'referred_at' => $referredAt ?? now(),
        ]);
    }

    private function fakeMetaMessaging(): void
    {
        Event::fake([MessageSent::class, TenantMessageReceived::class]);
        Queue::fake();
        Http::fake();
    }

    private function makeAdmin(int $tenantId): User
    {
        return User::factory()->create(['tenant_id' => $tenantId, 'role' => UserRole::ADMIN]);
    }

    private function makeOwner(int $tenantId): User
    {
        $user = $this->makeAdmin($tenantId);
        $user->assignRole('Owner');

        return $user;
    }

    private function makeWhatsAppChannel(): Channel
    {
        $tenant = $this->createTenantWithRoles('Acme '.uniqid());
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $config = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 11 0000-0000',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);

        return Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);
    }

    private function makeMessengerChannel(): Channel
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $user = $this->makeAdmin($tenant->id);
        $config = MessengerConfig::create([
            'tenant_id' => $tenant->id,
            'page_id' => self::PAGE_ID,
            'page_name' => 'Acme SRL',
            'page_access_token' => Crypt::encryptString('PAGE_TOKEN'),
            'ai_autoreply_default' => false,
        ]);

        return Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'messenger_config_id' => $config->id,
            'type' => ChannelType::FACEBOOK,
            'external_id' => self::PAGE_ID,
            'name' => 'Acme SRL',
            'status' => 'active',
        ]);
    }

    private function makeInstagramChannel(): Channel
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $user = $this->makeAdmin($tenant->id);
        $config = InstagramConfig::create([
            'tenant_id' => $tenant->id,
            'ig_user_id' => self::IG_BUSINESS_ID,
            'page_id' => self::PAGE_ID,
            'webhook_object_id' => self::IG_BUSINESS_ID,
            'username' => 'acme',
            'page_access_token' => Crypt::encryptString('PAGE_TOKEN'),
            'ai_autoreply_default' => false,
        ]);

        return Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'instagram_config_id' => $config->id,
            'type' => ChannelType::INSTAGRAM,
            'external_id' => self::IG_BUSINESS_ID,
            'name' => '@acme',
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $referral
     * @return array<string, mixed>
     */
    private function whatsAppPayload(Channel $channel, string $wamid, ?array $referral = null): array
    {
        $message = [
            'from' => '5491100000000',
            'id' => $wamid,
            'timestamp' => (string) now()->timestamp,
            'text' => ['body' => 'Hola, vi el anuncio'],
            'type' => 'text',
        ];
        if ($referral !== null) {
            $message['referral'] = $referral;
        }

        return [
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => $channel->whatsappConfig->phone_number_id],
                        'contacts' => [['profile' => ['name' => 'Ana']]],
                        'messages' => [$message],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function standaloneReferralEvent(string $psid): array
    {
        return $this->wrapMessengerEvent([
            'sender' => ['id' => $psid],
            'recipient' => ['id' => self::PAGE_ID],
            'timestamp' => 1700000000000,
            'referral' => [
                'source' => 'ADS',
                'type' => 'OPEN_THREAD',
                'ad_id' => 'AD_2',
                'ads_context_data' => ['ad_title' => 'Re-engage'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function wrapMessengerEvent(array $event): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => self::PAGE_ID,
                'time' => now()->timestamp,
                'messaging' => [$event],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSignedWebhook(string $endpoint, array $payload)
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            $endpoint,
            [],
            [],
            [],
            [
                'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET),
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );
    }
}
