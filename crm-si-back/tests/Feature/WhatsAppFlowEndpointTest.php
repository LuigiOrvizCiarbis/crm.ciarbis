<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Enums\WhatsAppFlowStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use Tests\TestCase;

/**
 * El endpoint de datos tiene que hablar el mismo cifrado que Meta:
 * RSA-OAEP-SHA256 para la llave AES, AES-128-GCM para el payload y la
 * respuesta cifrada con el IV invertido.
 */
class WhatsAppFlowEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    private const ENDPOINT_TOKEN = 'endpoint-token';

    private string $publicPem;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.facebook.app_secret' => self::SECRET]);
    }

    public function test_ping_answers_active(): void
    {
        $this->context();

        [$response, $aesKey, $iv] = $this->send(['version' => '3.0', 'action' => 'ping']);

        $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame(['data' => ['status' => 'active']], $this->decrypt($response->getContent(), $aesKey, $iv));
    }

    public function test_invalid_signature_returns_432(): void
    {
        $this->context();

        [$response] = $this->send(['action' => 'ping'], signature: 'sha256=bogus');

        $response->assertStatus(432);
    }

    public function test_undecryptable_body_returns_421(): void
    {
        $this->context();
        $raw = json_encode(['encrypted_flow_data' => 'Zm9v', 'encrypted_aes_key' => 'YmFy', 'initial_vector' => 'YmF6']);

        $this->postRaw($raw, $this->sign($raw))->assertStatus(421);
    }

    public function test_unknown_flow_token_returns_427_with_plain_error(): void
    {
        $this->context();

        [$response] = $this->send(['version' => '3.0', 'action' => 'INIT', 'flow_token' => 'nope']);

        $response->assertStatus(427)->assertJsonStructure(['error_msg']);
    }

    public function test_init_prefills_only_declared_contact_data(): void
    {
        $session = $this->context();

        [$response, $aesKey, $iv] = $this->send(['version' => '3.0', 'action' => 'INIT', 'flow_token' => $session->flow_token]);

        $response->assertOk();
        $this->assertSame(
            ['screen' => 'DATOS', 'data' => ['contact_name' => 'Jane Doe', 'cf_dni' => '123']],
            $this->decrypt($response->getContent(), $aesKey, $iv),
        );
    }

    public function test_complete_closes_the_flow_with_the_answers(): void
    {
        $session = $this->context();

        [$response, $aesKey, $iv] = $this->send([
            'version' => '3.0',
            'action' => 'data_exchange',
            'screen' => 'DATOS',
            'flow_token' => $session->flow_token,
            'data' => ['crm_action' => 'complete', 'nombre' => 'Ana'],
        ]);

        $response->assertOk();
        $this->assertSame(
            ['screen' => 'SUCCESS', 'data' => ['extension_message_response' => ['params' => [
                'flow_token' => $session->flow_token,
                'nombre' => 'Ana',
            ]]]],
            $this->decrypt($response->getContent(), $aesKey, $iv),
        );
    }

    private function context(): WhatsAppFlowSession
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privatePem);
        $this->publicPem = openssl_pkey_get_details($key)['key'];

        $tenant = Tenant::create(['name' => 'Acme']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $config = WhatsAppConfig::create([
            'phone_number_id' => '123456789',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
            'flows_private_key' => Crypt::encryptString($privatePem),
            'flows_public_key' => $this->publicPem,
            'flows_public_key_uploaded_at' => now(),
            'flows_endpoint_token' => self::ENDPOINT_TOKEN,
        ]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
            'custom_data' => ['dni' => '123'],
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $flow = WhatsAppFlow::create([
            'tenant_id' => $tenant->id,
            'whatsapp_config_id' => $config->id,
            'meta_flow_id' => 'flow-1',
            'name' => 'Datos',
            'status' => WhatsAppFlowStatus::Published,
            'categories' => ['OTHER'],
            'data_api_version' => '3.0',
            'flow_json' => json_encode(['version' => '7.3', 'screens' => [[
                'id' => 'DATOS',
                'data' => [
                    'contact_name' => ['type' => 'string', '__example__' => ''],
                    'cf_dni' => ['type' => 'string', '__example__' => ''],
                ],
            ]]]),
        ]);

        return WhatsAppFlowSession::create([
            'tenant_id' => $tenant->id,
            'whatsapp_flow_id' => $flow->id,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'flow_token' => 'token-'.uniqid(),
            'status' => WhatsAppFlowSession::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);
    }

    /** @return array{0: TestResponse, 1: string, 2: string} */
    private function send(array $payload, ?string $signature = null): array
    {
        $aesKey = random_bytes(16);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt(json_encode($payload), 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
        /** @var RSA\PublicKey $rsa */
        $rsa = PublicKeyLoader::loadPublicKey($this->publicPem);
        $encryptedKey = $rsa->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256')->encrypt($aesKey);

        $raw = json_encode([
            'encrypted_flow_data' => base64_encode($cipher.$tag),
            'encrypted_aes_key' => base64_encode($encryptedKey),
            'initial_vector' => base64_encode($iv),
        ]);

        return [$this->postRaw($raw, $signature ?? $this->sign($raw)), $aesKey, $iv];
    }

    private function postRaw(string $raw, string $signature): TestResponse
    {
        return $this->call('POST', '/api/whatsapp-flow-endpoint/'.self::ENDPOINT_TOKEN, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $raw);
    }

    private function sign(string $raw): string
    {
        return 'sha256='.hash_hmac('sha256', $raw, self::SECRET);
    }

    private function decrypt(string $body, string $aesKey, string $iv): array
    {
        $decoded = base64_decode($body);
        $plain = openssl_decrypt(substr($decoded, 0, -16), 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, ~$iv, substr($decoded, -16));
        $this->assertNotFalse($plain, 'La respuesta no se pudo descifrar con el IV invertido.');

        return json_decode($plain, true);
    }
}
