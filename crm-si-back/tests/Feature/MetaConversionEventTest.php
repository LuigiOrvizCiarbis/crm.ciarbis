<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Models\AdReferral;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MessengerConfig;
use App\Models\MetaConversionEvent;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetaConversionEventTest extends TestCase
{
    use RefreshDatabase;

    private const WON_STAGE = 'Cliente Convertido/Ganado';

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.meta.conversions_api_platforms', ['whatsapp', 'facebook', 'instagram']);
        config()->set('services.facebook.graph_version', 'v21.0');

        $this->tenant = $this->createTenantWithRoles();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('Owner');
        Sanctum::actingAs($this->user);
    }

    public function test_winning_whatsapp_ad_opportunity_sends_purchase(): void
    {
        $this->fakeMeta();
        $channel = $this->makeWhatsAppChannel();
        $opportunity = $this->opportunityFromAd($channel, 'whatsapp', 'CLID1', value: 1500);

        $this->moveTo($opportunity, self::WON_STAGE);

        // Graph rechaza (error 100) un cuerpo cuya raíz no sea un objeto, como "[]".
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v21.0/waba-test/dataset'
            && $request->body() === '{}');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/DS_1/events')
            && $request['data'][0]['event_name'] === 'Purchase'
            && $request['data'][0]['action_source'] === 'business_messaging'
            && $request['data'][0]['messaging_channel'] === 'whatsapp'
            && $request['data'][0]['user_data']['ctwa_clid'] === 'CLID1'
            && $request['data'][0]['user_data']['whatsapp_business_account_id'] === 'waba-test'
            && $request['data'][0]['custom_data']['value'] == 1500
            && $request['data'][0]['custom_data']['currency'] === 'ARS');

        $event = MetaConversionEvent::where('opportunity_id', $opportunity->id)->where('event_name', 'Purchase')->sole();
        $this->assertSame('sent', $event->status);
        $this->assertSame('DS_1', $channel->whatsappConfig->fresh()->meta_dataset_id);
    }

    public function test_winning_again_does_not_resend_purchase(): void
    {
        $this->fakeMeta();
        $opportunity = $this->opportunityFromAd($this->makeWhatsAppChannel(), 'whatsapp', 'CLID1');

        $this->moveTo($opportunity, self::WON_STAGE);
        $this->moveTo($opportunity, 'Negociación');
        $this->moveTo($opportunity, self::WON_STAGE);

        $this->assertSame(1, $this->sentEvents('Purchase')->count());
    }

    public function test_value_sent_with_the_move_to_won_reaches_the_purchase(): void
    {
        $this->fakeMeta();
        $opportunity = $this->opportunityFromAd($this->makeWhatsAppChannel(), 'whatsapp', 'CLID1');

        $this->patchJson("/api/opportunities/{$opportunity->id}/stage", [
            'pipeline_stage_id' => $this->stage(self::WON_STAGE)->id,
            'value' => 2500,
        ])->assertOk()->assertJsonPath('data.status', 'won');

        $this->assertEquals(2500, $this->sentEvents('Purchase')->first()['data'][0]['custom_data']['value']);
    }

    public function test_winning_a_chat_without_opportunity_takes_the_value_sent(): void
    {
        $this->fakeMeta();
        $channel = $this->makeWhatsAppChannel();
        $conversation = $this->makeConversation($channel, $this->makeContact());
        $this->makeReferral($channel, $conversation, 'whatsapp', 'CLID1');

        $this->patchJson("/api/conversations/{$conversation->id}/stage", [
            'pipeline_stage_id' => $this->stage(self::WON_STAGE)->id,
            'value' => 900,
        ])->assertOk();

        $this->assertEquals(900, $this->sentEvents('Purchase')->first()['data'][0]['custom_data']['value']);
    }

    public function test_messenger_chat_from_ad_sends_lead_submitted_when_opportunity_is_created(): void
    {
        $this->fakeMeta();
        $channel = $this->makeMessengerChannel();
        $contact = $this->makeContact('PSID_1');
        $conversation = $this->makeConversation($channel, $contact);
        $this->makeReferral($channel, $conversation, 'facebook');

        $this->patchJson("/api/conversations/{$conversation->id}/stage", ['pipeline_stage_id' => $this->stage('Interesado')->id])
            ->assertOk();

        $events = $this->sentEvents('LeadSubmitted');
        $this->assertCount(1, $events);
        $data = $events->first()['data'][0];
        $this->assertSame('messenger', $data['messaging_channel']);
        $this->assertSame(['page_id' => 'PAGE_1', 'page_scoped_user_id' => 'PSID_1'], $data['user_data']);
        $this->assertArrayNotHasKey('custom_data', $data);
    }

    public function test_opportunity_without_eligible_referral_sends_nothing(): void
    {
        $this->fakeMeta();
        $channel = $this->makeWhatsAppChannel();
        $withoutReferral = $this->opportunityFromAd($channel, null, null);
        $withoutClid = $this->opportunityFromAd($channel, 'whatsapp', null);

        $this->moveTo($withoutReferral, self::WON_STAGE);
        $this->moveTo($withoutClid, self::WON_STAGE);

        $this->assertCount(0, $this->sentEvents());
        $this->assertSame(0, MetaConversionEvent::count());
    }

    public function test_disabled_platforms_send_nothing(): void
    {
        $this->fakeMeta();
        config()->set('services.meta.conversions_api_platforms', []);
        $this->moveTo($this->opportunityFromAd($this->makeWhatsAppChannel(), 'whatsapp', 'CLID1'), self::WON_STAGE);

        config()->set('services.meta.conversions_api_platforms', ['whatsapp']);
        $contact = $this->makeContact('PSID_1');
        $channel = $this->makeMessengerChannel();
        $conversation = $this->makeConversation($channel, $contact);
        $this->makeReferral($channel, $conversation, 'facebook');
        $this->moveTo($this->makeOpportunity($contact, $conversation), self::WON_STAGE);

        Http::assertNothingSent();
        $this->assertSame(0, MetaConversionEvent::count());
    }

    public function test_rejected_event_is_marked_failed_without_breaking_the_request(): void
    {
        $this->fakeMeta(eventsStatus: 400);
        $opportunity = $this->opportunityFromAd($this->makeWhatsAppChannel(), 'whatsapp', 'CLID1');

        $this->moveTo($opportunity, self::WON_STAGE);

        $event = MetaConversionEvent::where('opportunity_id', $opportunity->id)->where('event_name', 'Purchase')->sole();
        $this->assertSame('failed', $event->status);
        $this->assertNotEmpty($event->error);
    }

    /**
     * Un solo fake con closure: Http::fake() apila stubs en vez de reemplazarlos.
     */
    private function fakeMeta(int $eventsStatus = 200): void
    {
        Http::fake(function (Request $request) use ($eventsStatus) {
            if (str_ends_with($request->url(), '/dataset')) {
                return Http::response(['id' => 'DS_1']);
            }
            if (str_ends_with($request->url(), '/DS_1/events')) {
                return $eventsStatus === 200
                    ? Http::response(['events_received' => 1])
                    : Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100]], $eventsStatus);
            }

            return Http::response([], 404);
        });
    }

    /**
     * @return Collection<int, Request>
     */
    private function sentEvents(?string $eventName = null): Collection
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/events')
            && ($eventName === null || $request['data'][0]['event_name'] === $eventName))
            ->map(fn (array $pair) => $pair[0]);
    }

    private function moveTo(Opportunity $opportunity, string $stageName): void
    {
        $this->patchJson("/api/opportunities/{$opportunity->id}/stage", ['pipeline_stage_id' => $this->stage($stageName)->id])
            ->assertOk();
    }

    private function opportunityFromAd(Channel $channel, ?string $platform, ?string $ctwaClid, float $value = 0): Opportunity
    {
        $contact = $this->makeContact();
        $conversation = $this->makeConversation($channel, $contact);
        if ($platform !== null) {
            $this->makeReferral($channel, $conversation, $platform, $ctwaClid);
        }

        return $this->makeOpportunity($contact, $conversation, $value);
    }

    private function makeOpportunity(Contact $contact, Conversation $conversation, float $value = 0): Opportunity
    {
        return Opportunity::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'conversation_id' => $conversation->id,
            'pipeline_stage_id' => $this->stage('Lead/Prospecto')->id,
            'title' => 'Venta',
            'status' => 'open',
            'source_type' => 'conversation',
            'value' => $value,
        ]);
    }

    private function makeReferral(Channel $channel, Conversation $conversation, string $platform, ?string $ctwaClid = null): void
    {
        AdReferral::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $conversation->contact_id,
            'conversation_id' => $conversation->id,
            'platform' => $platform,
            'source_type' => 'ad',
            'ad_id' => 'AD_1',
            'ctwa_clid' => $ctwaClid,
            'dedupe_key' => 'test:'.uniqid(),
            'referred_at' => now(),
        ]);
    }

    private function makeConversation(Channel $channel, Contact $contact): Conversation
    {
        return Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }

    private function makeContact(?string $externalId = null): Contact
    {
        return Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ana',
            'phone' => '+549'.fake()->numerify('##########'),
            'source' => 'whatsapp',
            'external_id' => $externalId,
        ]);
    }

    private function stage(string $name): PipelineStage
    {
        return PipelineStage::where('tenant_id', $this->tenant->id)->where('name', $name)->sole();
    }

    private function makeWhatsAppChannel(): Channel
    {
        $config = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 11 0000-0000',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);

        return Channel::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);
    }

    private function makeMessengerChannel(): Channel
    {
        $config = MessengerConfig::create([
            'tenant_id' => $this->tenant->id,
            'page_id' => 'PAGE_1',
            'page_name' => 'Acme SRL',
            'page_access_token' => Crypt::encryptString('PAGE_TOKEN'),
            'ai_autoreply_default' => false,
        ]);

        return Channel::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'messenger_config_id' => $config->id,
            'type' => ChannelType::FACEBOOK,
            'external_id' => 'PAGE_1',
            'name' => 'Acme SRL',
            'status' => 'active',
        ]);
    }
}
