<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Jobs\SyncMailChannelJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MailConfig;
use App\Models\MailIntake;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MailMessageService;
use App\Services\MailTransportFactory;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MailChannelTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/admin/channels/mail-auth';

    /** Estado del último doble de carpeta IMAP creado (ver fakeInboxWithUids). */
    private ?object $inboxState = null;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email_address' => 'soporte@acme.com',
            'password' => 'app-password',
            'from_name' => 'Acme Soporte',
            'imap_host' => 'imap.acme.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.acme.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
        ], $overrides);
    }

    public function test_connects_mailbox_and_creates_channel(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        // La cola corre en modo sync durante los tests: sin el fake, la primera
        // sincronización se ejecutaría en línea e intentaría abrir un socket.
        Queue::fake();

        // Credenciales válidas: el servicio no debe abrir sockets en el test.
        $this->mockService(function ($mock) {
            $mock->shouldReceive('assertImapCredentials')->once();
            $mock->shouldReceive('assertSmtpCredentials')->once();
        });

        $response = $this->postJson(self::ENDPOINT, $this->payload());

        $response->assertOk()->assertJsonPath('success', true);

        // El onboarding encola la primera sincronización de la casilla.
        Queue::assertPushed(SyncMailChannelJob::class);

        $config = MailConfig::first();
        $this->assertNotNull($config);
        $this->assertSame('soporte@acme.com', $config->email_address);
        $this->assertSame('Acme Soporte', $config->from_name);
        // La contraseña se guarda encriptada, nunca en claro.
        $this->assertNotSame('app-password', $config->password);
        $this->assertSame('app-password', Crypt::decryptString($config->password));

        $channel = Channel::where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($channel);
        $this->assertSame(ChannelType::MAIL, $channel->type);
        $this->assertSame('soporte@acme.com', $channel->external_id);
        $this->assertSame($config->id, $channel->mail_config_id);
    }

    public function test_email_address_is_normalized_to_lowercase(): void
    {
        [, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        Queue::fake();
        $this->mockService(function ($mock) {
            $mock->shouldReceive('assertImapCredentials')->once();
            $mock->shouldReceive('assertSmtpCredentials')->once();
        });

        $this->postJson(self::ENDPOINT, $this->payload(['email_address' => 'Soporte@ACME.com']))->assertOk();

        $this->assertSame('soporte@acme.com', MailConfig::first()->email_address);
    }

    public function test_invalid_credentials_do_not_persist_anything(): void
    {
        [, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->mockService(fn ($mock) => $mock->shouldReceive('assertImapCredentials')
            ->once()
            ->andThrow(new \InvalidArgumentException('Credenciales inválidas.')));

        $response = $this->postJson(self::ENDPOINT, $this->payload());

        $response->assertStatus(422)->assertJsonPath('success', false);

        // Nada persistido: ni config ni canal huérfano.
        $this->assertSame(0, MailConfig::count());
        $this->assertSame(0, Channel::count());
    }

    public function test_invalid_smtp_credentials_do_not_persist_anything(): void
    {
        [, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        // IMAP válido pero SMTP no: sin esta validación quedaría un canal que
        // sincroniza pero nunca puede responder.
        $this->mockService(function ($mock) {
            $mock->shouldReceive('assertImapCredentials')->once();
            $mock->shouldReceive('assertSmtpCredentials')
                ->once()
                ->andThrow(new \InvalidArgumentException('No pudimos conectarnos al servidor de correo.'));
        });

        $response = $this->postJson(self::ENDPOINT, $this->payload());

        $response->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame(0, MailConfig::count());
        $this->assertSame(0, Channel::count());
    }

    public function test_duplicate_mailbox_in_same_tenant_returns_409(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        MailConfig::create([
            'tenant_id' => $tenant->id,
            'email_address' => 'soporte@acme.com',
            'imap_host' => 'imap.acme.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.acme.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'password' => Crypt::encryptString('x'),
        ]);

        $response = $this->postJson(self::ENDPOINT, $this->payload());

        $response->assertStatus(409)->assertJsonPath('success', false);
        $this->assertSame(1, MailConfig::count());
    }

    public function test_validation_rejects_bad_encryption_and_port(): void
    {
        [, $user] = $this->createTenantAndUser();
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, $this->payload([
            'imap_encryption' => 'quantum',
            'smtp_port' => 99999,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['imap_encryption', 'smtp_port']);
    }

    public function test_member_cannot_connect_mailbox(): void
    {
        $tenant = $this->seedTenantWithRoles();
        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        $member->assignRole('Member');
        Sanctum::actingAs($member);

        $this->postJson(self::ENDPOINT, $this->payload())->assertForbidden();

        $this->assertSame(0, MailConfig::count());
    }

    public function test_manual_sync_enqueues_job(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        [, $channel] = $this->createMailChannel($tenant, $user);

        Queue::fake();
        Sanctum::actingAs($user);

        $this->postJson("/api/admin/channels/{$channel->id}/mail-sync")
            ->assertOk()
            ->assertJsonPath('success', true);

        Queue::assertPushed(SyncMailChannelJob::class);
    }

    public function test_manual_sync_rejects_non_mail_channel(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'external_id' => '123',
            'name' => 'WhatsApp',
            'status' => 'active',
        ]);

        Queue::fake();
        Sanctum::actingAs($user);

        $this->postJson("/api/admin/channels/{$channel->id}/mail-sync")->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_manual_sync_is_denied_without_update_permission(): void
    {
        $tenant = $this->seedTenantWithRoles();
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $owner->assignRole('Owner');
        [, $channel] = $this->createMailChannel($tenant, $owner);

        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        $member->assignRole('Member');

        Queue::fake();
        Sanctum::actingAs($member);

        $this->postJson("/api/admin/channels/{$channel->id}/mail-sync")->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_manual_sync_cannot_reach_another_tenants_channel(): void
    {
        [$tenantA, $userA] = $this->createTenantAndUser();
        [, $channelA] = $this->createMailChannel($tenantA, $userA);

        [, $userB] = $this->createTenantAndUser();

        Queue::fake();
        Sanctum::actingAs($userB);

        // El scope de tenant debe ocultar el canal ajeno: 404, nunca 200.
        $this->postJson("/api/admin/channels/{$channelA->id}/mail-sync")->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_reply_carries_threading_headers(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        [$config, $channel] = $this->createMailChannel($tenant, $user);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente',
            'email' => 'cliente@example.com',
            'source' => 'mail',
            'external_id' => 'cliente@example.com',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        // Hilo previo: consulta del cliente, nuestra respuesta, y su re-respuesta.
        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => "Consulta por el pedido\n\n¿Cuándo llega?",
            'direction' => MessageDirection::INBOUND,
            'external_id' => 'mail-'.$config->id.'-<primero@example.com>',
            'mail_message_id' => '<primero@example.com>',
        ]);
        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $user->id,
            'content' => 'Ya sale',
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => '<nuestro@acme.com>',
            'mail_message_id' => '<nuestro@acme.com>',
        ]);
        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => "Re: Consulta por el pedido\n\nGracias",
            'direction' => MessageDirection::INBOUND,
            'external_id' => 'mail-'.$config->id.'-<ultimo@example.com>',
            'mail_message_id' => '<ultimo@example.com>',
        ]);

        $sent = $this->captureSentEmail($config);

        app(MailMessageService::class)->sendTextMessageFromCRM($conversation, 'De nada', $user);

        $email = $sent();
        $this->assertNotNull($email);

        // In-Reply-To apunta al último entrante, no a cualquier mensaje.
        $this->assertSame('<ultimo@example.com>', $email->getHeaders()->get('In-Reply-To')->getBodyAsString());

        // References encadena el hilo en orden y termina en el mensaje respondido.
        $references = $email->getHeaders()->get('References')->getBodyAsString();
        $this->assertSame('<primero@example.com> <nuestro@acme.com> <ultimo@example.com>', $references);

        // El asunto ya venía con Re:, no debe encadenarse otro.
        $this->assertSame('Re: Consulta por el pedido', $email->getSubject());
    }

    public function test_first_outbound_without_inbound_has_no_threading_headers(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        [$config, $channel] = $this->createMailChannel($tenant, $user);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente',
            'email' => 'cliente@example.com',
            'source' => 'mail',
            'external_id' => 'cliente@example.com',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $sent = $this->captureSentEmail($config);

        app(MailMessageService::class)->sendTextMessageFromCRM($conversation, 'Hola', $user);

        $email = $sent();
        $this->assertNotNull($email);

        // Sin hilo previo no hay a qué referenciar: omitir es correcto.
        $this->assertNull($email->getHeaders()->get('In-Reply-To'));
        $this->assertNull($email->getHeaders()->get('References'));
    }

    public function test_rich_outbound_email_persists_structured_details_and_sanitizes_html(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        [$config, $channel] = $this->createMailChannel($tenant, $user);
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente',
            'email' => 'cliente@example.com',
            'source' => 'mail',
            'external_id' => 'cliente@example.com',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $sent = $this->captureSentEmail($config);
        $message = app(MailMessageService::class)->sendRichTextMessageFromCRM(
            $conversation,
            'Hola equipo',
            $user,
            '<p>Hola <strong>equipo</strong></p><script>alert(1)</script>',
            ['copia@example.com'],
        );

        $email = $sent();
        $this->assertNotNull($email);
        $this->assertStringContainsString('<strong>equipo</strong>', $email->getHtmlBody());
        $this->assertStringNotContainsString('<script', $email->getHtmlBody());
        $this->assertSame('copia@example.com', $email->getCc()[0]->getAddress());

        $message->refresh()->load('mailDetails');
        $this->assertStringStartsWith('Mensaje de ', (string) $message->mailDetails?->subject);
        $this->assertSame('Hola equipo', $message->mailDetails?->body_text);
        $this->assertSame('soporte@acme.com', $message->mailDetails?->from['email']);
        $this->assertSame('copia@example.com', $message->mailDetails?->cc[0]['email']);
    }

    public function test_inbound_without_message_id_produces_no_threading_headers(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        [$config, $channel] = $this->createMailChannel($tenant, $user);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente',
            'email' => 'cliente@example.com',
            'source' => 'mail',
            'external_id' => 'cliente@example.com',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        // Entrante que llegó sin Message-ID: el external_id cae al UID y
        // mail_message_id queda null, así que no hay nada que referenciar.
        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => 'Sin message id',
            'direction' => MessageDirection::INBOUND,
            'external_id' => 'mail-'.$config->id.'-uid-42',
            'mail_message_id' => null,
        ]);

        $sent = $this->captureSentEmail($config);

        app(MailMessageService::class)->sendTextMessageFromCRM($conversation, 'Respuesta', $user);

        $email = $sent();
        $this->assertNotNull($email);
        $this->assertNull($email->getHeaders()->get('In-Reply-To'));
        $this->assertNull($email->getHeaders()->get('References'));
    }

    /**
     * Intercepta el envío SMTP y devuelve un closure con el Email construido,
     * para poder inspeccionar sus headers sin abrir un socket.
     *
     * @return \Closure(): ?Email
     */
    private function captureSentEmail(MailConfig $config): \Closure
    {
        $captured = new \stdClass;
        $captured->email = null;

        $transport = Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')
            ->andReturnUsing(function ($message) use ($captured) {
                $captured->email = $message;

                return null;
            });

        $factory = Mockery::mock(MailTransportFactory::class);
        $factory->shouldReceive('smtp')->andReturn(new Mailer($transport));

        $this->app->instance(MailTransportFactory::class, $factory);

        return fn () => $captured->email;
    }

    public function test_outbound_message_requires_contact_with_valid_email(): void
    {
        $tenant = $this->seedTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');

        $config = MailConfig::create([
            'tenant_id' => $tenant->id,
            'email_address' => 'soporte@acme.com',
            'imap_host' => 'imap.acme.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.acme.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'password' => Crypt::encryptString('x'),
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'mail_config_id' => $config->id,
            'type' => ChannelType::MAIL,
            'external_id' => 'soporte@acme.com',
            'name' => 'Soporte',
            'status' => 'active',
        ]);

        // Contacto sin email: el envío debe fallar con un 422 accionable en vez
        // de reventar en el transporte SMTP.
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sin Email',
            'source' => 'manual',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/messages', [
            'conversation_id' => $conversation->id,
            'type' => 'text',
            'content' => 'Hola',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->count());
    }

    public function test_unknown_inbound_email_is_held_for_review_before_creating_crm_records(): void
    {
        $tenant = $this->seedTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $config = MailConfig::create([
            'tenant_id' => $tenant->id,
            'email_address' => 'soporte@acme.com',
            'imap_host' => 'imap.acme.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.acme.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'password' => Crypt::encryptString('x'),
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'mail_config_id' => $config->id,
            'type' => ChannelType::MAIL,
            'external_id' => 'soporte@acme.com',
            'name' => 'Soporte',
            'status' => 'active',
        ]);

        $service = app(MailMessageService::class);

        // storeIncomingMessage es privado: lo ejercitamos con un doble del
        // mensaje IMAP, que es la frontera real con la librería.
        $mail = $this->fakeInboundMail(
            uid: 42,
            from: 'cliente@example.com',
            fromName: 'Cliente Uno',
            subject: 'Consulta por el pedido',
            text: "Hola, ¿me confirman el envío?\n\nOn 2026-08-01 soporte@acme.com wrote:\n> mensaje anterior",
        );

        $this->assertFalse($this->invokeStore($service, $channel, $config, $mail));
        $this->assertSame(0, Contact::count());
        $this->assertSame(0, Message::count());
        $this->assertDatabaseHas('mail_intakes', ['channel_id' => $channel->id, 'from_email' => 'cliente@example.com', 'status' => 'pending', 'classification_reason' => 'unknown_sender']);
    }

    public function test_inbound_email_is_deduplicated_by_message_id(): void
    {
        $tenant = $this->seedTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $config = MailConfig::create([
            'tenant_id' => $tenant->id,
            'email_address' => 'soporte@acme.com',
            'imap_host' => 'imap.acme.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.acme.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'password' => Crypt::encryptString('x'),
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'mail_config_id' => $config->id,
            'type' => ChannelType::MAIL,
            'external_id' => 'soporte@acme.com',
            'name' => 'Soporte',
            'status' => 'active',
        ]);

        $service = app(MailMessageService::class);

        $mail = $this->fakeInboundMail(42, 'cliente@example.com', 'Cliente', 'Hola', 'Cuerpo');

        $this->assertFalse($this->invokeStore($service, $channel, $config, $mail));
        // Segunda pasada con el mismo Message-ID: no debe duplicar.
        $this->assertFalse($this->invokeStore($service, $channel, $config, $mail));

        $this->assertSame(0, Message::count());
        $this->assertSame(1, MailIntake::count());
    }

    public function test_same_message_id_lands_in_two_connected_mailboxes(): void
    {
        $tenant = $this->seedTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // Dos casillas del mismo equipo, ambas conectadas al CRM.
        [$soporteConfig, $soporteChannel] = $this->createMailboxFor($tenant, $user, 'soporte@acme.com');
        [$ventasConfig, $ventasChannel] = $this->createMailboxFor($tenant, $user, 'ventas@acme.com');

        $service = app(MailMessageService::class);

        // Un cliente escribe a las dos a la vez: el servidor entrega la misma
        // pieza en cada casilla, con el mismo Message-ID y distinto UID.
        $messageId = '<compartido@example.com>';
        $paraSoporte = $this->fakeInboundMail(10, 'cliente@example.com', 'Cliente', 'Consulta', 'Cuerpo', $messageId);
        $paraVentas = $this->fakeInboundMail(77, 'cliente@example.com', 'Cliente', 'Consulta', 'Cuerpo', $messageId);

        $this->assertFalse($this->invokeStore($service, $soporteChannel, $soporteConfig, $paraSoporte));
        // La segunda casilla tiene que poder guardar su propia copia.
        $this->assertFalse($this->invokeStore($service, $ventasChannel, $ventasConfig, $paraVentas));

        $this->assertSame(0, Message::count());
        $this->assertSame(2, MailIntake::count());

        // Y la deduplicación por casilla se mantiene.
        $this->assertFalse($this->invokeStore($service, $ventasChannel, $ventasConfig, $paraVentas));
        $this->assertSame(2, MailIntake::count());
    }

    public function test_inbound_without_message_id_is_not_lost_when_uidvalidity_changes(): void
    {
        $tenant = $this->seedTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        [$config, $channel] = $this->createMailChannel($tenant, $user);

        $service = app(MailMessageService::class);

        // Un mensaje sin Message-ID cae al UID. Si luego el servidor invalida
        // la casilla (p. ej. reindexación) y reutiliza el mismo UID bajo una
        // UIDVALIDITY nueva, sin la UIDVALIDITY en la clave sintética el
        // segundo mensaje (distinto) se descartaría como si fuera duplicado.
        $primero = $this->fakeInboundMail(42, 'cliente@example.com', 'Cliente', 'Primero', 'Cuerpo', '');
        $segundo = $this->fakeInboundMail(42, 'otro@example.com', 'Otro Cliente', 'Segundo', 'Cuerpo distinto', '');

        $this->assertFalse($this->invokeStore($service, $channel, $config, $primero, '1000'));
        $this->assertFalse($this->invokeStore($service, $channel, $config, $segundo, '2000'));

        $this->assertSame(2, MailIntake::count());
    }

    public function test_own_sent_copy_is_ignored(): void
    {
        $tenant = $this->seedTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $config = MailConfig::create([
            'tenant_id' => $tenant->id,
            'email_address' => 'soporte@acme.com',
            'imap_host' => 'imap.acme.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.acme.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'password' => Crypt::encryptString('x'),
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'mail_config_id' => $config->id,
            'type' => ChannelType::MAIL,
            'external_id' => 'soporte@acme.com',
            'name' => 'Soporte',
            'status' => 'active',
        ]);

        $service = app(MailMessageService::class);

        // Mismo remitente que la casilla: es una copia de lo que enviamos.
        $mail = $this->fakeInboundMail(7, 'Soporte@acme.com', 'Acme', 'Re: hola', 'Cuerpo');

        $this->assertFalse($this->invokeStore($service, $channel, $config, $mail));
        $this->assertSame(0, Message::count());
    }

    public function test_channel_user_can_approve_reviewed_email_without_creating_an_opportunity(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        [$config, $channel] = $this->createMailChannel($tenant, $user);
        $intake = MailIntake::create([
            'tenant_id' => $tenant->id, 'channel_id' => $channel->id, 'mail_config_id' => $config->id,
            'external_id' => 'mail-'.$config->id.'-reviewed@example.com', 'status' => 'pending',
            'classification_reason' => 'unknown_sender', 'from_email' => 'lead@example.com',
            'from_name' => 'Lead', 'mail_message_id' => '<reviewed@example.com>', 'subject' => 'Quiero cotizar',
            'body_text' => 'Necesito una cotización.', 'to' => [], 'cc' => [], 'bcc' => [], 'in_reply_to' => [],
            'references' => [], 'attachments' => [], 'received_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/mail-intakes/'.$intake->id.'/approve')->assertOk()
            ->assertJsonPath('data.intake.status', 'accepted');

        $this->assertDatabaseHas('contacts', ['tenant_id' => $tenant->id, 'email' => 'lead@example.com']);
        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, Message::count());
        $this->assertDatabaseCount('opportunities', 0);
    }

    public function test_automatic_email_is_rejected_and_does_not_create_crm_records(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        [$config, $channel] = $this->createMailChannel($tenant, $user);
        $service = app(MailMessageService::class);
        $mail = $this->fakeInboundMail(22, 'no-reply@example.com', 'Robot', 'Aviso', 'Contenido');

        $this->assertFalse($this->invokeStore($service, $channel, $config, $mail));
        $this->assertDatabaseHas('mail_intakes', ['status' => 'rejected', 'classification_reason' => 'automatic_email']);
        $this->assertSame(0, Contact::count());
        $this->assertSame(0, Conversation::count());
    }

    public function test_first_sync_takes_the_newest_messages_in_ascending_order(): void
    {
        $service = app(MailMessageService::class);

        // Casilla con más historial que el tope por corrida: 60 UID (1..60).
        $inbox = $this->fakeInboxWithUids(range(1, 60));

        $fetched = $this->invokeFetch($service, $inbox, null);
        $uids = array_map(fn (object $mail) => $mail->uid(), iterator_to_array($fetched));

        // Sin cursor arrancamos por lo más reciente: los 50 UID más altos, no
        // los 50 más viejos del historial.
        $this->assertCount(50, $uids);
        $this->assertSame(11, $uids[0]);
        $this->assertSame(60, $uids[49]);

        // Y en orden ascendente, para que el último procesado sea el más nuevo
        // y quede como último mensaje de la conversación.
        $sorted = $uids;
        sort($sorted);
        $this->assertSame($sorted, $uids);
    }

    public function test_sync_with_cursor_requests_the_range_above_it(): void
    {
        $service = app(MailMessageService::class);

        $inbox = $this->fakeInboxWithUids(range(1, 60));

        $fetched = $this->invokeFetch($service, $inbox, 58);
        $uids = array_map(fn (object $mail) => $mail->uid(), iterator_to_array($fetched));

        // Con cursor se pide el rango `58:*`; el propio cursor viene incluido
        // porque el rango UID de IMAP es inclusivo (lo saltea syncMailbox).
        $this->assertSame([58, 59, 60], $uids);
    }

    public function test_sync_with_cursor_uses_an_open_ended_upper_bound(): void
    {
        $service = app(MailMessageService::class);

        $inbox = $this->fakeInboxWithUids(range(1, 60));

        $this->invokeFetch($service, $inbox, 58);

        // El "hasta el final" del rango `58:*` se expresa con INF, el valor que
        // ImapQueryBuilder traduce al UID máximo. Un '*' literal tira TypeError
        // y deja la casilla sin sincronizar (ningún email entra al CRM).
        $state = $this->inboxState;
        $this->assertSame(58, $state->from);
        $this->assertSame(INF, $state->to);
    }

    /**
     * Invoca el método privado que trae los mensajes nuevos.
     */
    private function invokeFetch(MailMessageService $service, object $inbox, ?int $lastUid): iterable
    {
        $method = new \ReflectionMethod($service, 'fetchNewMessages');
        $method->setAccessible(true);

        return $method->invoke($service, $inbox, $lastUid);
    }

    /**
     * Doble de la carpeta IMAP que reproduce la semántica de la librería: el
     * recorte por `limit()` se aplica DESPUÉS de ordenar según oldest()/newest(),
     * que es justamente lo que decide si la primera sync trae lo más nuevo o
     * arranca por el historial más viejo.
     *
     * @param  list<int>  $uids
     */
    private function fakeInboxWithUids(array $uids): object
    {
        $state = (object) ['order' => 'asc', 'limit' => null, 'from' => null, 'to' => null];

        // Expuesto para poder afirmar sobre los argumentos con que el servicio
        // arma la query, no sólo sobre los UID que devuelve.
        $this->inboxState = $state;

        $query = Mockery::mock(MessageQueryInterface::class);

        foreach (['withHeaders', 'withBody', 'leaveUnread'] as $passthrough) {
            $query->shouldReceive($passthrough)->andReturnSelf();
        }

        $query->shouldReceive('oldest')->andReturnUsing(function () use ($query, $state) {
            $state->order = 'asc';

            return $query;
        });

        $query->shouldReceive('newest')->andReturnUsing(function () use ($query, $state) {
            $state->order = 'desc';

            return $query;
        });

        $query->shouldReceive('limit')->andReturnUsing(function (int $limit) use ($query, $state) {
            $state->limit = $limit;

            return $query;
        });

        // El doble replica la firma real de ImapQueryBuilder::uid(): $to es
        // int|float|null. Sin esta validación el mock acepta cualquier cosa
        // (p. ej. '*') y un TypeError que rompe la sync en producción pasa
        // desapercibido en los tests.
        $query->shouldReceive('uid')->andReturnUsing(function ($from, $to = null) use ($query, $state) {
            if ($to !== null && ! is_int($to) && ! is_float($to)) {
                throw new \TypeError(sprintf(
                    'ImapQueryBuilder::uid(): Argument #2 ($to) must be of type int|float|null, %s given',
                    get_debug_type($to)
                ));
            }

            $state->from = (int) $from;
            $state->to = $to;

            return $query;
        });

        $query->shouldReceive('get')->andReturnUsing(function () use ($uids, $state) {
            $selected = $state->from !== null
                ? array_values(array_filter($uids, fn (int $uid) => $uid >= $state->from))
                : $uids;

            // La librería ordena todo el conjunto y recién ahí recorta.
            $state->order === 'desc' ? rsort($selected) : sort($selected);

            if ($state->limit !== null) {
                $selected = array_slice($selected, 0, $state->limit);
            }

            return new MessageCollection(array_map(
                fn (int $uid) => $this->fakeInboundMail($uid, "c{$uid}@example.com", "Cliente {$uid}", "Asunto {$uid}", 'Cuerpo'),
                $selected
            ));
        });

        $inbox = Mockery::mock(FolderInterface::class);
        $inbox->shouldReceive('messages')->andReturn($query);

        return $inbox;
    }

    /**
     * Invoca el método privado que persiste un entrante.
     */
    private function invokeStore(MailMessageService $service, Channel $channel, MailConfig $config, object $mail, ?string $uidValidity = '1'): bool
    {
        $method = new \ReflectionMethod($service, 'storeIncomingMessage');
        $method->setAccessible(true);

        return (bool) $method->invoke($service, $channel, $config, $mail, $uidValidity);
    }

    /**
     * Crea un canal MAIL activo con su config, listo para usar.
     *
     * @return array{0: MailConfig, 1: Channel}
     */
    private function createMailChannel(Tenant $tenant, User $user): array
    {
        $config = MailConfig::create([
            'tenant_id' => $tenant->id,
            'email_address' => 'soporte@acme.com',
            'imap_host' => 'imap.acme.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.acme.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'password' => Crypt::encryptString('x'),
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'mail_config_id' => $config->id,
            'type' => ChannelType::MAIL,
            'external_id' => 'soporte@acme.com',
            'name' => 'Soporte',
            'status' => 'active',
        ]);

        return [$config, $channel];
    }

    /**
     * Igual que createMailChannel() pero con la dirección como parámetro, para
     * poder tener varias casillas conectadas en el mismo tenant.
     *
     * @return array{0: MailConfig, 1: Channel}
     */
    private function createMailboxFor(Tenant $tenant, User $user, string $address): array
    {
        $config = MailConfig::create([
            'tenant_id' => $tenant->id,
            'email_address' => $address,
            'imap_host' => 'imap.acme.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.acme.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'password' => Crypt::encryptString('x'),
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'mail_config_id' => $config->id,
            'type' => ChannelType::MAIL,
            'external_id' => $address,
            'name' => $address,
            'status' => 'active',
        ]);

        return [$config, $channel];
    }

    /**
     * Doble del mensaje que devuelve ImapEngine, con sólo los accessors que usa
     * el servicio.
     */
    private function fakeInboundMail(
        int $uid,
        string $from,
        string $fromName,
        string $subject,
        string $text,
        ?string $messageId = null,
    ): object {
        $address = Mockery::mock(Address::class);
        $address->shouldReceive('email')->andReturn($from);
        $address->shouldReceive('name')->andReturn($fromName);

        $mail = Mockery::mock(MessageInterface::class);
        $mail->shouldReceive('uid')->andReturn($uid);
        $mail->shouldReceive('from')->andReturn($address);
        $mail->shouldReceive('messageId')->andReturn($messageId ?? "<msg-{$uid}@example.com>");
        $mail->shouldReceive('subject')->andReturn($subject);
        $mail->shouldReceive('text')->andReturn($text);
        $mail->shouldReceive('html')->andReturn(null);
        $mail->shouldReceive('date')->andReturn(now());
        $mail->shouldReceive('attachments')->andReturn([]);
        $mail->shouldReceive('to')->andReturn([]);
        $mail->shouldReceive('cc')->andReturn([]);
        $mail->shouldReceive('bcc')->andReturn([]);
        $mail->shouldReceive('replyTo')->andReturn(null);
        $mail->shouldReceive('inReplyTo')->andReturn([]);
        $mail->shouldReceive('header')->andReturn(null);

        return $mail;
    }

    /**
     * Reemplaza MailMessageService en el contenedor para no tocar la red.
     */
    private function mockService(callable $expectations): void
    {
        $mock = Mockery::mock(MailMessageService::class);
        $expectations($mock);
        $this->app->instance(MailMessageService::class, $mock);
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function createTenantAndUser(): array
    {
        $tenant = $this->seedTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');

        return [$tenant, $user];
    }

    private function seedTenantWithRoles(): Tenant
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        return $tenant;
    }
}
