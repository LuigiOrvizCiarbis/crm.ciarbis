<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessengerConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class FacebookDataDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/facebook/data-deletion';

    private const APP_SECRET = 'test-app-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.facebook.app_secret', self::APP_SECRET);
    }

    public function test_missing_signed_request_returns_400(): void
    {
        $this->postJson(self::ENDPOINT, [])->assertStatus(400);
    }

    public function test_invalid_signature_returns_400(): void
    {
        $payload = $this->base64UrlEncode(json_encode(['user_id' => 'PSID_1']));

        $this->postJson(self::ENDPOINT, ['signed_request' => 'deadbeef.'.$payload])
            ->assertStatus(400);
    }

    public function test_deletes_contact_conversation_and_messages(): void
    {
        [$tenant, $contact, $conversation] = $this->createFacebookContact('PSID_1');

        $response = $this->postJson(self::ENDPOINT, [
            'signed_request' => $this->signedRequest(['user_id' => 'PSID_1']),
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('del_', $response->json('confirmation_code'));

        $this->assertSame(0, Contact::withoutGlobalScopes()->count());
        $this->assertSame(0, Conversation::withoutGlobalScopes()->count());

        // withTrashed: los mensajes usan SoftDeletes, el borrado debe ser real.
        $this->assertSame(0, Message::withoutGlobalScopes()->withTrashed()->count());
    }

    /**
     * Aislamiento entre tenants: el borrado de un contacto no toca los datos de
     * otro tenant cuyo external_id sea un superstring del pedido.
     *
     * Este es el escenario del bug anterior — `LIKE "%{$id}%"` sobre
     * messages.external_id (que guarda el mid de Meta, no el id del usuario)
     * matcheaba por substring mids de otros contactos y de otros tenants.
     *
     * OJO con la cobertura: los tests corren en SQLite y el LIKE viejo generaba
     * `"external_id"::text LIKE ?`, cast de sintaxis Postgres que en SQLite no
     * matchea nada. O sea que este test NO falla contra la implementación
     * anterior (lo verifiqué): el bug sólo se manifiesta en Postgres, que es lo
     * que corre en producción. El test fija el comportamiento correcto, no
     * demuestra la regresión.
     */
    public function test_does_not_touch_data_of_tenant_with_similar_external_id(): void
    {
        [$tenantA, $target] = $this->createFacebookContact('PSID_1');
        [$tenantB, $bystander] = $this->createFacebookContact('PSID_1234');

        $this->assertSame(2, Message::withoutGlobalScopes()->count());

        $this->postJson(self::ENDPOINT, [
            'signed_request' => $this->signedRequest(['user_id' => 'PSID_1']),
        ])->assertOk();

        // El objetivo se borró por completo.
        $this->assertNull(Contact::withoutGlobalScopes()->find($target->id));

        // El del otro tenant quedó intacto, con su conversación y su mensaje.
        $this->assertNotNull(Contact::withoutGlobalScopes()->find($bystander->id));
        $this->assertSame(
            1,
            Conversation::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count(),
        );
        $this->assertSame(
            1,
            Message::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count(),
        );
    }

    public function test_does_not_delete_contacts_with_different_external_id(): void
    {
        [$tenant, $target] = $this->createFacebookContact('PSID_1');
        [, $other] = $this->createFacebookContact('PSID_OTRO');

        $this->postJson(self::ENDPOINT, [
            'signed_request' => $this->signedRequest(['user_id' => 'PSID_1']),
        ])->assertOk();

        $this->assertNull(Contact::withoutGlobalScopes()->find($target->id));
        $this->assertNotNull(Contact::withoutGlobalScopes()->find($other->id));
        // Los mensajes del otro contacto quedan intactos.
        $this->assertSame(1, Message::withoutGlobalScopes()->withTrashed()->count());
    }

    /**
     * Un external_id igual pero de otra fuente (p. ej. whatsapp) no se toca: el
     * borrado está acotado a las fuentes de Meta.
     */
    public function test_does_not_delete_contacts_from_other_sources(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $waContact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fulano WA',
            'source' => 'whatsapp',
            'external_id' => 'PSID_1',
        ]);

        $this->postJson(self::ENDPOINT, [
            'signed_request' => $this->signedRequest(['user_id' => 'PSID_1']),
        ])->assertOk();

        $this->assertNotNull(Contact::withoutGlobalScopes()->find($waContact->id));
    }

    /**
     * Caso esperado por el desfasaje ASID/PSID: Meta manda un app-scoped id que
     * no matchea ningún PSID. Debe responder OK con código igual.
     */
    public function test_unknown_user_id_still_returns_confirmation(): void
    {
        $this->createFacebookContact('PSID_1');

        $response = $this->postJson(self::ENDPOINT, [
            'signed_request' => $this->signedRequest(['user_id' => 'ASID_DESCONOCIDO']),
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('del_', $response->json('confirmation_code'));

        // Nada se borró.
        $this->assertSame(1, Contact::withoutGlobalScopes()->count());
    }

    // ---------------------------------------------------------------------

    /**
     * @return array{0: Tenant, 1: Contact, 2: Conversation}
     */
    private function createFacebookContact(string $externalId): array
    {
        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $config = MessengerConfig::create([
            'tenant_id' => $tenant->id,
            'page_id' => 'PAGE_'.uniqid(),
            'page_access_token' => Crypt::encryptString('PAGE_TOKEN'),
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'messenger_config_id' => $config->id,
            'type' => ChannelType::FACEBOOK,
            'name' => 'Acme SRL',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fulano FB',
            'source' => 'facebook',
            'external_id' => $externalId,
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'status' => 'open',
        ]);

        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => 'hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'external_id' => 'mid_'.$externalId,
            'delivered_at' => now(),
        ]);

        return [$tenant, $contact, $conversation];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function signedRequest(array $data): string
    {
        $payload = $this->base64UrlEncode(json_encode($data));
        $sig = hash_hmac('sha256', $payload, self::APP_SECRET, true);

        return $this->base64UrlEncode($sig).'.'.$payload;
    }

    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}
