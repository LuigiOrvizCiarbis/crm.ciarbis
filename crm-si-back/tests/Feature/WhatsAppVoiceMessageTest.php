<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\InstagramConfig;
use App\Models\MessengerConfig;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Services\VoiceTranscoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class WhatsAppVoiceMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.facebook.graph_version', 'v26.0');
        config()->set('services.facebook.public_media_base_url', 'https://public.example.com');
    }

    public function test_voice_true_requires_audio_type(): void
    {
        [$user, $conversation] = $this->conversation(ChannelType::WHATSAPP);
        Sanctum::actingAs($user);

        $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id, 'type' => 'text', 'content' => 'hola', 'voice' => true,
        ])->assertStatus(422);
    }

    public function test_voice_true_is_rejected_before_instagram_or_messenger_transport(): void
    {
        Http::fake();
        foreach ([ChannelType::INSTAGRAM, ChannelType::FACEBOOK] as $channelType) {
            [$user, $conversation] = $this->conversation($channelType);
            Sanctum::actingAs($user);
            $this->post('/api/messages', [
                'conversation_id' => $conversation->id,
                'type' => 'audio',
                'audio' => UploadedFile::fake()->create('voice.ogg', 10, 'audio/ogg'),
                'voice' => true,
            ])->assertStatus(422);
        }
        Http::assertNothingSent();
    }

    public function test_whatsapp_voice_transcodes_uploads_ogg_and_marks_meta_message_as_voice(): void
    {
        Storage::fake('public');
        $temporary = tempnam(sys_get_temp_dir(), 'test_voice_');
        $this->assertNotFalse($temporary);
        file_put_contents($temporary, 'valid ogg fixture');
        $transcoder = Mockery::mock(VoiceTranscoder::class);
        $transcoder->expects('transcode')->once()->andReturn($temporary);
        $this->app->instance(VoiceTranscoder::class, $transcoder);
        Http::fake([
            'https://graph.facebook.com/v26.0/*/media' => Http::response(['id' => 'media_voice'], 200),
            'https://graph.facebook.com/v26.0/*/messages' => Http::response(['messages' => [['id' => 'wamid_voice']]], 200),
        ]);
        [$user, $conversation] = $this->conversation(ChannelType::WHATSAPP);
        Sanctum::actingAs($user);

        $this->post('/api/messages', [
            'conversation_id' => $conversation->id, 'type' => 'audio',
            'audio' => UploadedFile::fake()->createWithContent(
                'voice.webm',
                "\x1A\x45\xDF\xA3\x93\x42\x82\x88webm\x42\x87\x81\x02\x42\x85\x81\x02\x18\x53\x80\x67"
            ),
            'voice' => true,
        ])->assertStatus(201);

        $files = Storage::disk('public')->allFiles('messages/'.$conversation->tenant_id);
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.ogg', $files[0]);
        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/media')
                && str_contains($request->body(), 'audio/ogg');
        });
        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/messages')
                && $request['audio']['id'] === 'media_voice' && $request['audio']['voice'] === true;
        });
        $this->assertFileDoesNotExist($temporary);
    }

    /**
     * El front manda multipart, donde todo valor es string. Laravel valida
     * `boolean` contra 1/0/"1"/"0" (no acepta "true"), así que este caso cubre
     * el valor tal cual viaja por HTTP.
     */
    public function test_voice_flag_accepts_multipart_string_value(): void
    {
        Storage::fake('public');
        $temporary = tempnam(sys_get_temp_dir(), 'test_voice_');
        $this->assertNotFalse($temporary);
        file_put_contents($temporary, 'valid ogg fixture');
        $transcoder = Mockery::mock(VoiceTranscoder::class);
        $transcoder->expects('transcode')->once()->andReturn($temporary);
        $this->app->instance(VoiceTranscoder::class, $transcoder);
        Http::fake([
            'https://graph.facebook.com/v26.0/*/media' => Http::response(['id' => 'media_voice'], 200),
            'https://graph.facebook.com/v26.0/*/messages' => Http::response(['messages' => [['id' => 'wamid_voice']]], 200),
        ]);
        [$user, $conversation] = $this->conversation(ChannelType::WHATSAPP);
        Sanctum::actingAs($user);

        $this->post('/api/messages', [
            'conversation_id' => $conversation->id, 'type' => 'audio',
            'audio' => UploadedFile::fake()->createWithContent(
                'voice.webm',
                "\x1A\x45\xDF\xA3\x93\x42\x82\x88webm\x42\x87\x81\x02\x42\x85\x81\x02\x18\x53\x80\x67"
            ),
            'voice' => '1',
        ])->assertStatus(201);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/messages')
                && $request['audio']['voice'] === true;
        });
    }

    public function test_normal_whatsapp_audio_skips_transcoder_and_omits_voice_key(): void
    {
        Storage::fake('public');
        $transcoder = Mockery::mock(VoiceTranscoder::class);
        $transcoder->shouldReceive('transcode')->never();
        $this->app->instance(VoiceTranscoder::class, $transcoder);
        Http::fake([
            'https://graph.facebook.com/v26.0/*/media' => Http::response(['id' => 'media_audio'], 200),
            'https://graph.facebook.com/v26.0/*/messages' => Http::response(['messages' => [['id' => 'wamid_audio']]], 200),
        ]);
        [$user, $conversation] = $this->conversation(ChannelType::WHATSAPP);
        Sanctum::actingAs($user);
        $this->post('/api/messages', [
            'conversation_id' => $conversation->id, 'type' => 'audio',
            'audio' => UploadedFile::fake()->createWithContent(
                'audio.mp3',
                "\xFF\xFB\x90\x64".str_repeat("\0", 1024)
            ),
        ])->assertStatus(201);
        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/messages') && ! isset($request['audio']['voice']);
        });
    }

    public function test_whatsapp_can_send_native_contact_cards(): void
    {
        Http::fake([
            'https://graph.facebook.com/v26.0/*/messages' => Http::response(['messages' => [['id' => 'wamid_contacts']]], 200),
        ]);
        [$user, $conversation] = $this->conversation(ChannelType::WHATSAPP);
        $shared = Contact::create([
            'tenant_id' => $conversation->tenant_id,
            'name' => 'Ana Pérez',
            'phone' => '+5491112345678',
            'email' => 'ana@example.com',
            'source' => 'manual',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'contacts',
            'contact_ids' => [$shared->id],
        ])->assertCreated();

        $message = \App\Models\Message::query()->where('external_id', 'wamid_contacts')->firstOrFail();
        $this->assertSame('contacts', $message->message_type->value);
        $this->assertSame('Ana Pérez', $message->contacts[0]['name']['formatted_name']);
        Http::assertSent(fn ($request) => $request['type'] === 'contacts'
            && $request['contacts'][0]['phones'][0]['phone'] === '+5491112345678');
    }

    private function conversation(ChannelType $type): array
    {
        $tenant = $this->createTenantWithRoles('Acme '.uniqid());
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');
        $attributes = ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'type' => $type, 'name' => $type->value, 'status' => 'active'];
        if ($type === ChannelType::WHATSAPP) {
            $attributes['whatsapp_config_id'] = WhatsAppConfig::create(['phone_number_id' => 'PHONE_'.uniqid(), 'waba_id' => 'WABA_'.uniqid(), 'bussines_token' => Crypt::encryptString('token')])->id;
        } elseif ($type === ChannelType::INSTAGRAM) {
            $attributes['instagram_config_id'] = InstagramConfig::create(['tenant_id' => $tenant->id, 'ig_user_id' => 'IG_'.uniqid(), 'page_id' => 'PAGE_'.uniqid(), 'webhook_object_id' => 'IG_'.uniqid(), 'username' => 'test', 'page_access_token' => Crypt::encryptString('token')])->id;
        } else {
            $attributes['messenger_config_id'] = MessengerConfig::create(['tenant_id' => $tenant->id, 'page_id' => 'PAGE_'.uniqid(), 'page_access_token' => Crypt::encryptString('token')])->id;
        }
        $channel = Channel::create($attributes);
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Contact',
            'phone' => '+5491112345678',
            'source' => strtolower($type->value),
            'external_id' => 'CONTACT_'.uniqid(),
        ]);
        return [$user, Conversation::create(['tenant_id' => $tenant->id, 'channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => 'open'])];
    }
}
