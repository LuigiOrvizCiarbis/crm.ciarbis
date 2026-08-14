<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Importación del historial de mensajes de coexistencia (paso 2, sync_type=history).
 *
 * El bug que motiva estos tests: la primera implementación leía value.threads,
 * pero el shape real (confirmado contra la doc oficial de Meta y payloads de
 * producción) es value.history[].threads[]. Con eso, el parser importaba cero
 * mensajes en todos los onboardings reales, aunque el webhook sí llegaba.
 *
 * @see https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users
 */
class WhatsAppHistorySyncTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = '/api/whatsapp-webhook';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.facebook.app_id', 'test-app-id');
        config()->set('services.facebook.app_secret', 'test-app-secret');
        config()->set('services.facebook.graph_version', 'v21.0');
    }

    /**
     * Fixture con el ejemplo literal de la doc oficial de Meta: número de EEUU,
     * formato E.164 sin separadores. Cubre el caso real de números de EEUU que
     * también se onboardean en este CRM, y sirve como referencia canónica del
     * shape del payload si Meta lo cambia en el futuro.
     */
    public function test_imports_the_official_meta_doc_example_payload(): void
    {
        [, $config] = $this->createChannelWithConfig([
            'phone_number_id' => '106540352242922',
            'display_phone_number' => '15550783881',
        ]);

        $payload = $this->historyPayload($config, [[
            'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 100],
            'threads' => [
                [
                    'id' => '16505551234',
                    'messages' => [
                        [
                            'from' => '15550783881',
                            'id' => 'wamid.OUTBOUND_TEXT',
                            'timestamp' => '1739230955',
                            'type' => 'text',
                            'text' => ['body' => "Here's the info you requested!"],
                            'history_context' => ['status' => 'READ'],
                        ],
                        [
                            'from' => '15550783881',
                            'id' => 'wamid.MEDIA_PLACEHOLDER',
                            'timestamp' => '1739230970',
                            'type' => 'media_placeholder',
                            'history_context' => ['status' => 'PLAYED'],
                        ],
                        [
                            'from' => '16505551234',
                            'id' => 'wamid.INBOUND_TEXT',
                            'timestamp' => '1739230980',
                            'type' => 'text',
                            'text' => ['body' => 'Thanks!'],
                            'history_context' => ['status' => 'READ'],
                        ],
                    ],
                ],
            ],
        ]]);

        $this->postJson(self::WEBHOOK, $payload)->assertOk();

        $contact = Contact::where('phone', '16505551234')->first();
        $this->assertNotNull($contact, 'el contacto se crea a partir de threads[].id, no de messages[].to');

        $conversation = Conversation::where('contact_id', $contact->id)->first();
        $this->assertNotNull($conversation);
        $this->assertSame(3, Message::where('conversation_id', $conversation->id)->count());

        $outbound = Message::where('external_id', 'wamid.OUTBOUND_TEXT')->firstOrFail();
        $this->assertSame(MessageDirection::OUTBOUND, $outbound->direction);
        $this->assertSame(SenderType::USER, $outbound->sender_type);
        $this->assertNotNull($outbound->read_at, 'history_context.status=READ debe marcar read_at');

        $placeholder = Message::where('external_id', 'wamid.MEDIA_PLACEHOLDER')->firstOrFail();
        $this->assertSame(MessageDirection::OUTBOUND, $placeholder->direction);

        $inbound = Message::where('external_id', 'wamid.INBOUND_TEXT')->firstOrFail();
        $this->assertSame(MessageDirection::INBOUND, $inbound->direction);
        $this->assertSame(SenderType::CONTACT, $inbound->sender_type);

        $this->assertSame(WhatsAppConfig::SYNC_COMPLETED, $config->fresh()->contact_history_sync_status);
    }

    /**
     * Caso real de producción que rompió la primera versión del parser:
     * display_phone_number en DB viene formateado con +, espacios y guión
     * ("+54 9 223 436-3047"), mientras que `from` en el webhook siempre llega
     * en dígitos puros y sin el 9 argentino ("542234363047"). Sin normalizar
     * ambos lados a lo mismo, la comparación de dirección nunca matchea y todo
     * se importa como INBOUND, invirtiendo conversaciones enteras.
     */
    public function test_imports_argentine_format_with_correct_direction(): void
    {
        [, $config] = $this->createChannelWithConfig([
            'phone_number_id' => '1039791795888430',
            'display_phone_number' => '+54 9 223 436-3047',
        ]);

        $payload = $this->historyPayload($config, [[
            'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 100],
            'threads' => [
                [
                    'id' => '5491150113262',
                    'messages' => [
                        [
                            // El negocio manda sin el 9 (normalizePhoneForWhatsApp lo quita).
                            'from' => '542234363047',
                            'id' => 'wamid.AR_OUTBOUND',
                            'timestamp' => '1786716789',
                            'type' => 'text',
                            'text' => ['body' => 'Hola, en que te puedo ayudar?'],
                            'history_context' => ['status' => 'DELIVERED'],
                        ],
                        [
                            'from' => '5491150113262',
                            'id' => 'wamid.AR_INBOUND',
                            'timestamp' => '1786716800',
                            'type' => 'text',
                            'text' => ['body' => 'Quiero hacer una consulta'],
                            'history_context' => ['status' => 'READ'],
                        ],
                    ],
                ],
            ],
        ]]);

        $this->postJson(self::WEBHOOK, $payload)->assertOk();

        $outbound = Message::where('external_id', 'wamid.AR_OUTBOUND')->firstOrFail();
        $this->assertSame(
            MessageDirection::OUTBOUND,
            $outbound->direction,
            'from=542234363047 debe matchear al negocio (+54 9 223 436-3047) pese al formato distinto'
        );

        $inbound = Message::where('external_id', 'wamid.AR_INBOUND')->firstOrFail();
        $this->assertSame(MessageDirection::INBOUND, $inbound->direction);

        $contact = Contact::where('phone', '5491150113262')->first();
        $this->assertNotNull($contact);
    }

    /**
     * "Messaging history not shared": Meta entrega un webhook `history` con
     * error 2593109 en vez de threads cuando el negocio no aceptó compartir su
     * historial en el Embedded Signup. No es un fallo nuestro: no hay que
     * marcarlo failed ni dejarlo reintentable.
     */
    public function test_history_not_shared_marks_not_applicable(): void
    {
        [, $config] = $this->createChannelWithConfig();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $config->waba_id,
                'changes' => [[
                    'field' => 'history',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => $config->display_phone_number,
                            'phone_number_id' => $config->phone_number_id,
                        ],
                        'history' => [[
                            'error' => ['code' => 2593109, 'message' => 'Messaging history not shared'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson(self::WEBHOOK, $payload)->assertOk();

        $this->assertSame(WhatsAppConfig::SYNC_NOT_APPLICABLE, $config->fresh()->contact_history_sync_status);
        $this->assertSame(0, Message::count());
    }

    /**
     * progress llega por chunk (0-100). Un chunk parcial no debe marcar el sync
     * como completado: recién con progress=100 hay prueba de que terminó.
     */
    public function test_partial_chunk_does_not_mark_completed(): void
    {
        [, $config] = $this->createChannelWithConfig();

        $payload = $this->historyPayload($config, [[
            'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 55],
            'threads' => [[
                'id' => '16505551234',
                'messages' => [[
                    'from' => '16505551234',
                    'id' => 'wamid.PARTIAL',
                    'timestamp' => (string) now()->timestamp,
                    'type' => 'text',
                    'text' => ['body' => 'mensaje de un chunk parcial'],
                    'history_context' => ['status' => 'READ'],
                ]],
            ]],
        ]]);

        $this->postJson(self::WEBHOOK, $payload)->assertOk();

        $this->assertNotSame(WhatsAppConfig::SYNC_COMPLETED, $config->fresh()->contact_history_sync_status);
        $this->assertSame(1, Message::count(), 'el mensaje del chunk parcial igual se importa');
    }

    /**
     * media_placeholder no se descarta: Meta promete un webhook posterior con
     * el mismo wamid y el contenido real. Ese segundo webhook debe actualizar
     * el mensaje existente, nunca crear uno nuevo (external_id es unique global
     * en la tabla messages).
     */
    public function test_media_placeholder_is_resolved_by_a_later_webhook_with_same_wamid(): void
    {
        [, $config] = $this->createChannelWithConfig();

        $placeholderPayload = $this->historyPayload($config, [[
            'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 60],
            'threads' => [[
                'id' => '16505551234',
                'messages' => [[
                    'from' => '16505551234',
                    'id' => 'wamid.MEDIA_LATER',
                    'timestamp' => (string) now()->timestamp,
                    'type' => 'media_placeholder',
                    'history_context' => ['status' => 'READ'],
                ]],
            ]],
        ]]);

        $this->postJson(self::WEBHOOK, $placeholderPayload)->assertOk();

        $this->assertSame(1, Message::count());
        $placeholder = Message::where('external_id', 'wamid.MEDIA_LATER')->firstOrFail();
        $this->assertSame('Multimedia no disponible', $placeholder->content);

        $contentPayload = $this->historyPayload($config, [[
            'metadata' => ['phase' => 0, 'chunk_order' => 2, 'progress' => 100],
            'threads' => [[
                'id' => '16505551234',
                'messages' => [[
                    'from' => '16505551234',
                    'id' => 'wamid.MEDIA_LATER',
                    'timestamp' => (string) now()->timestamp,
                    'type' => 'image',
                    'image' => ['id' => 'MEDIA_ID_123', 'caption' => 'una foto'],
                    'history_context' => ['status' => 'READ'],
                ]],
            ]],
        ]]);

        $this->postJson(self::WEBHOOK, $contentPayload)->assertOk();

        // Sigue siendo un solo mensaje: se actualizó, no se duplicó.
        $this->assertSame(1, Message::count());
        $resolved = Message::where('external_id', 'wamid.MEDIA_LATER')->firstOrFail();
        $this->assertSame('image', $resolved->message_type->value);
        $this->assertSame('una foto', $resolved->content);
    }

    /**
     * Meta puede reentregar el mismo chunk (redelivery). El dedupe por
     * external_id ya cubre esto para mensajes de texto normales: no debe
     * duplicar mensajes si el mismo webhook llega dos veces.
     */
    public function test_redelivered_chunk_does_not_duplicate_messages(): void
    {
        [, $config] = $this->createChannelWithConfig();

        $payload = $this->historyPayload($config, [[
            'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 100],
            'threads' => [[
                'id' => '16505551234',
                'messages' => [[
                    'from' => '16505551234',
                    'id' => 'wamid.REDELIVERED',
                    'timestamp' => (string) now()->timestamp,
                    'type' => 'text',
                    'text' => ['body' => 'mensaje reentregado'],
                    'history_context' => ['status' => 'READ'],
                ]],
            ]],
        ]]);

        $this->postJson(self::WEBHOOK, $payload)->assertOk();
        $this->postJson(self::WEBHOOK, $payload)->assertOk();

        $this->assertSame(1, Message::count());
    }

    /**
     * @return array{0: Tenant, 1: WhatsAppConfig}
     */
    private function createChannelWithConfig(array $overrides = []): array
    {
        $tenant = Tenant::create(['name' => 'Acme']);

        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);

        $config = new WhatsAppConfig(array_merge([
            'phone_number_id' => 'PHONE_777',
            'waba_id' => 'WABA_777',
            'display_phone_number' => '+54 11 7777-7777',
        ], $overrides));
        $config->setEncryptedToken('TOKEN_777');
        $config->save();

        Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'name' => 'WhatsApp',
            'type' => ChannelType::WHATSAPP,
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);

        return [$tenant, $config->fresh()];
    }

    private function historyPayload(WhatsAppConfig $config, array $historyChunks): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $config->waba_id,
                'changes' => [[
                    'field' => 'history',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => $config->display_phone_number,
                            'phone_number_id' => $config->phone_number_id,
                        ],
                        'history' => $historyChunks,
                    ],
                ]],
            ]],
        ];
    }
}
