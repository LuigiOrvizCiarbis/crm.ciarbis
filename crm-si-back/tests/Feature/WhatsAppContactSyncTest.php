<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Jobs\VerifyContactSyncJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Services\WhatsAppContactSyncService;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Importación de contactos de coexistencia (WhatsApp Business App → CRM).
 *
 * El bug que motiva estos tests: el onboarding daba por exitosa la sincronización
 * con solo ver un 2xx de `POST /smb_app_data`, pero los contactos llegan después
 * por webhook. Cuando el webhook no llegaba, el canal quedaba sin contactos y
 * nadie se enteraba.
 */
class WhatsAppContactSyncTest extends TestCase
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

    public function test_un_2xx_de_meta_no_marca_la_sync_como_completada(): void
    {
        Queue::fake();
        [, $config] = $this->createChannelWithConfig();

        Http::fake([
            '*/smb_app_data' => Http::response(['success' => true], 200),
        ]);

        app(WhatsAppContactSyncService::class)->retrySync($config);

        // Meta aceptó el pedido, pero todavía no llegó ningún contacto: el estado
        // no puede decir "completado" hasta que llegue el webhook.
        $this->assertNotSame(WhatsAppConfig::SYNC_COMPLETED, $config->fresh()->contact_sync_status);
        $this->assertSame(0, $config->fresh()->contact_sync_contacts_count);
    }

    public function test_un_retry_exitoso_desde_pending_pasa_a_syncing_y_ancla_la_ventana(): void
    {
        [, $config] = $this->createChannelWithConfig();

        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_PENDING,
            'contact_sync_requested_at' => null,
        ])->save();

        Http::fake([
            '*/smb_app_data' => Http::response([
                'messaging_product' => 'whatsapp',
                'request_id' => 'REQ_123',
            ], 200),
        ]);

        $this->assertTrue(app(WhatsAppContactSyncService::class)->retrySync($config));

        $fresh = $config->fresh();
        // Sigue sin haber contactos: el 2xx no completa nada, sólo arranca el sync.
        $this->assertSame(WhatsAppConfig::SYNC_SYNCING, $fresh->contact_sync_status);
        $this->assertNotNull($fresh->contact_sync_requested_at, 'requested_at ancla la ventana de 24h');
        $this->assertSame('REQ_123', $fresh->contact_sync_request_id);
        $this->assertSame(0, $fresh->contact_sync_contacts_count);
        $this->assertFalse($fresh->contact_sync_retryable);
    }

    /**
     * El reintento manual desde la UI también tiene que pedir el historial.
     *
     * Caso real: un onboarding falló con 422 (Meta devolvió datos incompletos) y
     * el usuario lo recuperó con el botón de reintentar. Los contactos llegaron,
     * pero el historial nunca se pidió, y como la ventana de 24h se consume
     * igual, se perdió para siempre.
     */
    public function test_un_retry_exitoso_tambien_pide_el_historial(): void
    {
        [, $config] = $this->createChannelWithConfig();

        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_PENDING,
            'contact_sync_requested_at' => null,
        ])->save();

        Http::fake([
            '*/smb_app_data' => Http::response([
                'messaging_product' => 'whatsapp',
                'request_id' => 'REQ_HISTORY',
            ], 200),
        ]);

        $this->assertTrue(app(WhatsAppContactSyncService::class)->retrySync($config));

        $syncTypes = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains((string) $pair[0]->url(), 'smb_app_data'))
            ->map(fn ($pair) => $pair[0]->data()['sync_type'] ?? null)
            ->sort()
            ->values();

        $this->assertSame(['history', 'smb_app_state_sync'], $syncTypes->all());
        $this->assertSame(WhatsAppConfig::SYNC_SYNCING, $config->fresh()->contact_history_sync_status);
    }

    /**
     * El guard de idempotencia también aplica al retry: si el historial ya se
     * pidió antes, un reintento del sync de contactos no debe volver a pedirlo.
     */
    public function test_un_retry_no_repite_el_historial_ya_pedido(): void
    {
        [, $config] = $this->createChannelWithConfig();

        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_PENDING,
            'contact_sync_requested_at' => null,
            'contact_history_sync_status' => WhatsAppConfig::SYNC_SYNCING,
            'contact_history_sync_requested_at' => now()->subHour(),
            'contact_history_sync_request_id' => 'REQ_PREVIO',
        ])->save();

        Http::fake([
            '*/smb_app_data' => Http::response(['request_id' => 'REQ_NUEVO'], 200),
        ]);

        app(WhatsAppContactSyncService::class)->retrySync($config);

        $syncTypes = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains((string) $pair[0]->url(), 'smb_app_data'))
            ->map(fn ($pair) => $pair[0]->data()['sync_type'] ?? null)
            ->values();

        $this->assertSame(['smb_app_state_sync'], $syncTypes->all());
        $this->assertSame('REQ_PREVIO', $config->fresh()->contact_history_sync_request_id);
    }

    public function test_un_retry_no_extiende_la_ventana_de_24h_original(): void
    {
        [, $config] = $this->createChannelWithConfig();

        $original = now()->subHours(3);
        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_SYNCING,
            'contact_sync_requested_at' => $original,
        ])->save();

        Http::fake(['*/smb_app_data' => Http::response(['request_id' => 'REQ_456'], 200)]);

        app(WhatsAppContactSyncService::class)->retrySync($config);

        // Reintentar no puede correr el vencimiento: la ventana la fija Meta desde
        // el onboarding, no desde nuestro último intento.
        $this->assertSame(
            $original->timestamp,
            $config->fresh()->contact_sync_requested_at->timestamp
        );
    }

    public function test_el_webhook_importa_los_contactos_y_marca_la_sync_completada(): void
    {
        [$tenant, $config] = $this->createChannelWithConfig();

        $this->postJson(self::WEBHOOK, $this->stateSyncPayload($config, [
            ['action' => 'add', 'phone_number' => '+5491133334444', 'full_name' => 'Ana Gómez'],
            ['action' => 'add', 'phone_number' => '+5491155556666', 'full_name' => 'Luis Pérez'],
        ]))->assertOk();

        $this->assertSame(2, Contact::where('tenant_id', $tenant->id)->count());

        $fresh = $config->fresh();
        $this->assertSame(WhatsAppConfig::SYNC_COMPLETED, $fresh->contact_sync_status);
        $this->assertSame(2, $fresh->contact_sync_contacts_count);
        $this->assertNotNull($fresh->contact_sync_first_webhook_at);
    }

    public function test_un_lote_vacio_no_completa_la_sync(): void
    {
        [, $config] = $this->createChannelWithConfig();
        $config->forceFill(['contact_sync_status' => WhatsAppConfig::SYNC_SYNCING])->save();

        $this->postJson(self::WEBHOOK, $this->stateSyncPayload($config, []))->assertOk();

        $fresh = $config->fresh();
        $this->assertSame(WhatsAppConfig::SYNC_SYNCING, $fresh->contact_sync_status);
        $this->assertSame(0, $fresh->contact_sync_contacts_count);
        $this->assertNull($fresh->contact_sync_first_webhook_at);
    }

    public function test_un_lote_solo_remove_no_completa_la_sync(): void
    {
        [, $config] = $this->createChannelWithConfig();
        $config->forceFill(['contact_sync_status' => WhatsAppConfig::SYNC_SYNCING])->save();

        $this->postJson(self::WEBHOOK, $this->stateSyncPayload($config, [
            ['action' => 'remove', 'phone_number' => '+5491133334444', 'full_name' => ''],
        ]))->assertOk();

        $fresh = $config->fresh();
        $this->assertSame(WhatsAppConfig::SYNC_SYNCING, $fresh->contact_sync_status);
        $this->assertSame(0, $fresh->contact_sync_contacts_count);
        $this->assertNull($fresh->contact_sync_first_webhook_at);

        // El lote igual queda registrado: sirve para saber que Meta sigue
        // hablándonos aunque no haya traído contactos.
        $this->assertNotNull($fresh->contact_sync_last_webhook_at);
    }

    public function test_un_lote_con_contacto_invalido_o_accion_desconocida_no_completa_la_sync(): void
    {
        [, $config] = $this->createChannelWithConfig();
        $config->forceFill(['contact_sync_status' => WhatsAppConfig::SYNC_SYNCING])->save();

        $this->postJson(self::WEBHOOK, $this->stateSyncPayload($config, [
            ['action' => 'add', 'phone_number' => '', 'full_name' => 'Sin teléfono'],
            ['action' => 'unknown', 'phone_number' => '+5491133334444', 'full_name' => 'Ignorado'],
        ]))->assertOk();

        $fresh = $config->fresh();
        $this->assertSame(WhatsAppConfig::SYNC_SYNCING, $fresh->contact_sync_status);
        $this->assertSame(0, $fresh->contact_sync_contacts_count);
        $this->assertNull($fresh->contact_sync_first_webhook_at);
    }

    public function test_los_contactos_importados_conservan_nombre_y_origen(): void
    {
        [$tenant, $config] = $this->createChannelWithConfig();

        $this->postJson(self::WEBHOOK, $this->stateSyncPayload($config, [
            ['action' => 'add', 'phone_number' => '+5491133334444', 'full_name' => 'Ana Gómez'],
        ]))->assertOk();

        $contact = Contact::where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertSame('Ana Gómez', $contact->name);
        $this->assertSame('+5491133334444', $contact->phone);
        $this->assertSame('whatsapp', $contact->source);
    }

    public function test_lotes_sucesivos_acumulan_sin_duplicar_contactos(): void
    {
        [$tenant, $config] = $this->createChannelWithConfig();

        // Meta manda la agenda en varios lotes; el mismo contacto puede repetirse.
        // Una edición también llega como `add`, así que debe actualizar, no duplicar.
        $this->postJson(self::WEBHOOK, $this->stateSyncPayload($config, [
            ['action' => 'add', 'phone_number' => '+5491133334444', 'full_name' => 'Ana'],
        ]))->assertOk();

        $this->postJson(self::WEBHOOK, $this->stateSyncPayload($config, [
            ['action' => 'add', 'phone_number' => '+5491133334444', 'full_name' => 'Ana Gómez'],
            ['action' => 'add', 'phone_number' => '+5491177778888', 'full_name' => 'Beto'],
        ]))->assertOk();

        $this->assertSame(2, Contact::where('tenant_id', $tenant->id)->count());
        $this->assertSame('Ana Gómez', Contact::where('phone', '+5491133334444')->first()->name);
    }

    public function test_un_remove_no_borra_el_contacto_del_crm(): void
    {
        [$tenant, $config] = $this->createChannelWithConfig();

        $this->postJson(self::WEBHOOK, $this->stateSyncPayload($config, [
            ['action' => 'add', 'phone_number' => '+5491133334444', 'full_name' => 'Ana Gómez'],
        ]))->assertOk();

        // Meta manda `remove` sin los campos de nombre.
        $this->postJson(self::WEBHOOK, $this->stateSyncPayload($config, [
            ['action' => 'remove', 'phone_number' => '+5491133334444', 'full_name' => ''],
        ]))->assertOk();

        // Se preserva para no perder el historial de conversaciones.
        $this->assertSame(1, Contact::where('tenant_id', $tenant->id)->count());
        $this->assertSame('Ana Gómez', Contact::where('phone', '+5491133334444')->first()->name);
    }

    public function test_un_remove_manual_no_completa_una_sync_que_nunca_importo(): void
    {
        [, $config] = $this->createChannelWithConfig();

        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_SYNCING,
            'contact_sync_requested_at' => now()->subMinutes(20),
        ])->save();

        // El cliente borra un contacto en el celular: llega webhook, pero la
        // importación inicial nunca ocurrió.
        $this->postJson(self::WEBHOOK, $this->stateSyncPayload($config, [
            ['action' => 'remove', 'phone_number' => '+5491133334444', 'full_name' => ''],
        ]))->assertOk();

        $this->assertTrue(
            $config->fresh()->hasStalledContactSync(),
            'la sync sigue colgada: ningún contacto fue importado'
        );
    }

    public function test_el_job_no_completa_si_no_se_importo_ningun_contacto(): void
    {
        Queue::fake();
        [, $config] = $this->createChannelWithConfig();

        // Llegó un webhook (cambio manual del cliente) pero sin importaciones.
        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_SYNCING,
            'contact_sync_requested_at' => now()->subHour(),
            'contact_sync_last_webhook_at' => now()->subMinutes(5),
            'contact_sync_contacts_count' => 0,
        ])->save();

        Http::fake(['*/smb_app_data' => Http::response(['request_id' => 'REQ_789'], 200)]);

        (new VerifyContactSyncJob($config->id))->handle(app(WhatsAppContactSyncService::class));

        // Debe reintentar, no darse por satisfecho.
        $this->assertNotSame(WhatsAppConfig::SYNC_COMPLETED, $config->fresh()->contact_sync_status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'smb_app_data'));
        Queue::assertPushed(VerifyContactSyncJob::class);
    }

    public function test_la_ventana_de_24h_vencida_marca_la_sync_como_fallida(): void
    {
        [, $config] = $this->createChannelWithConfig();

        // Sync pedida hace más de 24h y ningún webhook recibido: Meta ya no la acepta.
        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_SYNCING,
            'contact_sync_requested_at' => now()->subHours(25),
        ])->save();

        $this->assertFalse($config->isWithinContactSyncWindow());

        (new VerifyContactSyncJob($config->id))->handle(app(WhatsAppContactSyncService::class));

        $fresh = $config->fresh();
        $this->assertSame(WhatsAppConfig::SYNC_FAILED, $fresh->contact_sync_status);
        $this->assertStringContainsString('24h', $fresh->contact_sync_error);
    }

    public function test_dentro_de_la_ventana_el_job_reintenta_la_sync(): void
    {
        Queue::fake();
        [, $config] = $this->createChannelWithConfig();

        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_SYNCING,
            'contact_sync_requested_at' => now()->subHour(),
        ])->save();

        Http::fake(['*/smb_app_data' => Http::response(['success' => true], 200)]);

        (new VerifyContactSyncJob($config->id))->handle(app(WhatsAppContactSyncService::class));

        // Reintentó contra Meta y se reprogramó para volver a verificar.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'smb_app_data'));
        Queue::assertPushed(VerifyContactSyncJob::class);
    }

    public function test_el_job_espera_webhooks_sin_repetir_un_pedido_aceptado(): void
    {
        Queue::fake();
        [, $config] = $this->createChannelWithConfig();

        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_SYNCING,
            'contact_sync_requested_at' => now()->subHour(),
            'contact_sync_request_id' => 'REQ_ACCEPTED',
            'contact_sync_retryable' => true,
        ])->save();

        Http::fake();

        (new VerifyContactSyncJob($config->id))->handle(app(WhatsAppContactSyncService::class));

        Http::assertNothingSent();
        Queue::assertPushed(VerifyContactSyncJob::class);
        $this->assertSame(WhatsAppConfig::SYNC_SYNCING, $config->fresh()->contact_sync_status);
        $this->assertFalse($config->fresh()->contact_sync_retryable);
    }

    public function test_si_la_waba_ya_no_es_accesible_la_sync_falla_sin_reintentar(): void
    {
        Queue::fake();
        [, $config] = $this->createChannelWithConfig();

        // Caso real de producción: el token sigue vivo pero perdió el asset.
        Http::fake([
            '*/smb_app_data' => Http::response([
                'error' => [
                    'message' => "Unsupported post request. Object with ID '{$config->phone_number_id}' does not exist, cannot be loaded due to missing permissions, or does not support this operation.",
                    'code' => 100,
                    'error_subcode' => 33,
                ],
            ], 400),
        ]);

        $this->assertFalse(app(WhatsAppContactSyncService::class)->retrySync($config));

        $fresh = $config->fresh();
        $this->assertSame(WhatsAppConfig::SYNC_FAILED, $fresh->contact_sync_status);
        $this->assertStringContainsString('reconectar', $fresh->contact_sync_error);
        $this->assertFalse($fresh->contact_sync_retryable);
    }

    public function test_un_token_invalido_exige_reconectar_y_no_permite_reintento(): void
    {
        [, $config] = $this->createChannelWithConfig();

        Http::fake([
            '*/smb_app_data' => Http::response([
                'error' => [
                    'message' => 'Invalid OAuth access token - Cannot parse access token',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 401),
        ]);

        $this->assertFalse(app(WhatsAppContactSyncService::class)->retrySync($config));

        $fresh = $config->fresh();
        $this->assertSame(WhatsAppConfig::SYNC_FAILED, $fresh->contact_sync_status);
        $this->assertStringContainsString('Reconectá el canal', $fresh->contact_sync_error);
        $this->assertFalse($fresh->contact_sync_retryable);
    }

    public function test_un_rechazo_no_terminal_persiste_el_codigo_y_mensaje_de_meta(): void
    {
        [, $config] = $this->createChannelWithConfig();

        Http::fake([
            '*/smb_app_data' => Http::response([
                'error' => [
                    'message' => 'Temporary Graph API failure',
                    'type' => 'OAuthException',
                    'code' => 2,
                ],
            ], 500),
        ]);

        $this->assertFalse(app(WhatsAppContactSyncService::class)->retrySync($config));

        $fresh = $config->fresh();
        $this->assertSame(WhatsAppConfig::SYNC_FAILED, $fresh->contact_sync_status);
        $this->assertSame(
            'Meta rechazó la importación [2]: Temporary Graph API failure',
            $fresh->contact_sync_error
        );
        $this->assertTrue($fresh->contact_sync_retryable);
    }

    public function test_un_rate_limit_no_pisa_un_pedido_que_meta_ya_acepto(): void
    {
        [, $config] = $this->createChannelWithConfig();

        $requestedAt = now()->subMinute();
        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_SYNCING,
            'contact_sync_requested_at' => $requestedAt,
            'contact_sync_request_id' => 'REQ_ACCEPTED',
            'contact_sync_retryable' => true,
        ])->save();

        Http::fake([
            '*/smb_app_data' => Http::response([
                'error' => [
                    'message' => 'Application request limit reached',
                    'type' => 'OAuthException',
                    'code' => 4,
                ],
            ], 403),
        ]);

        $this->assertFalse(app(WhatsAppContactSyncService::class)->retrySync($config));

        $fresh = $config->fresh();
        $this->assertSame(WhatsAppConfig::SYNC_SYNCING, $fresh->contact_sync_status);
        $this->assertSame('REQ_ACCEPTED', $fresh->contact_sync_request_id);
        $this->assertSame($requestedAt->timestamp, $fresh->contact_sync_requested_at->timestamp);
        $this->assertNull($fresh->contact_sync_error);
        $this->assertFalse($fresh->contact_sync_retryable);
    }

    /**
     * El código 4 (rate limit) es el único caso donde vale la pena reintentar:
     * a diferencia de un token inválido o una WABA desvinculada, la cuota se
     * libera sola. Además debe quedar clasificado como error_code=rate_limit
     * para que el front traduzca el mensaje en vez de mostrar el texto de Meta.
     */
    public function test_un_rate_limit_sin_pedido_previo_queda_marcado_como_reintentable(): void
    {
        [, $config] = $this->createChannelWithConfig();

        Http::fake([
            '*/smb_app_data' => Http::response([
                'error' => [
                    'message' => 'Application request limit reached',
                    'type' => 'OAuthException',
                    'code' => 4,
                ],
            ], 403),
        ]);

        $this->assertFalse(app(WhatsAppContactSyncService::class)->retrySync($config));

        $fresh = $config->fresh();
        $this->assertSame(WhatsAppConfig::SYNC_FAILED, $fresh->contact_sync_status);
        $this->assertTrue($fresh->contact_sync_retryable);
        $this->assertSame('rate_limit', $fresh->contact_sync_error_code);
    }

    /**
     * El header X-App-Usage de Graph API es la única forma de ver la cuota del
     * business token del cliente (smb_app_data la usa, no el token de la app).
     * Se persiste en cada respuesta, incluso cuando Meta rechaza el pedido.
     */
    public function test_el_header_x_app_usage_se_persiste_tras_cada_llamada(): void
    {
        [, $config] = $this->createChannelWithConfig();

        Http::fake([
            '*/smb_app_data' => Http::response(
                ['request_id' => 'REQ_1'],
                200,
                ['X-App-Usage' => json_encode(['call_count' => 42, 'total_time' => 10, 'total_cputime' => 5])]
            ),
        ]);

        app(WhatsAppContactSyncService::class)->retrySync($config);

        $fresh = $config->fresh();
        $this->assertSame(42, $fresh->meta_app_usage_pct);
        $this->assertNotNull($fresh->meta_app_usage_at);
    }

    /**
     * Guard preventivo: si la última lectura de cuota es crítica (≥90) y
     * reciente, no se llama a Meta. Un rechazo por cuota consumiría igual el
     * único disparo permitido de smb_app_data sin traer nada.
     */
    public function test_con_cuota_critica_reciente_no_se_llama_a_meta(): void
    {
        [, $config] = $this->createChannelWithConfig();
        $config->forceFill([
            'meta_app_usage_pct' => 95,
            'meta_app_usage_at' => now()->subMinutes(5),
        ])->save();

        Http::fake(['*/smb_app_data' => Http::response(['request_id' => 'REQ_1'], 200)]);

        $this->assertFalse(app(WhatsAppContactSyncService::class)->retrySync($config));

        Http::assertNothingSent();

        $fresh = $config->fresh();
        $this->assertSame(WhatsAppConfig::SYNC_FAILED, $fresh->contact_sync_status);
        $this->assertTrue($fresh->contact_sync_retryable);
        $this->assertSame('rate_limit', $fresh->contact_sync_error_code);
    }

    /**
     * Una lectura de cuota vieja no debe bloquear indefinidamente: si pasó la
     * ventana de vigencia, se asume que puede haberse liberado y se reintenta.
     */
    public function test_con_cuota_critica_pero_vieja_si_se_llama_a_meta(): void
    {
        [, $config] = $this->createChannelWithConfig();
        $config->forceFill([
            'meta_app_usage_pct' => 95,
            'meta_app_usage_at' => now()->subMinutes(30),
        ])->save();

        Http::fake(['*/smb_app_data' => Http::response(['request_id' => 'REQ_1'], 200)]);

        app(WhatsAppContactSyncService::class)->retrySync($config);

        // 2, no 1: un contact sync exitoso encadena triggerHistorySync (ver
        // "un retry exitoso tambien pide el historial"). Lo que este test
        // verifica es que la llamada de contactos no se omitió por el guard.
        Http::assertSentCount(2);
    }

    public function test_el_endpoint_expone_el_estado_de_la_importacion(): void
    {
        // El endpoint es admin-only y la policy chequea `channels.connect_whatsapp`,
        // así que hacen falta los roles de Spatie provisionados para el tenant.
        $tenant = $this->seedTenantWithRoles();
        [, $config] = $this->createChannelWithConfig($tenant);
        $channel = Channel::where('tenant_id', $tenant->id)->firstOrFail();

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_COMPLETED,
            'contact_sync_contacts_count' => 42,
            'contact_sync_requested_at' => now()->subMinutes(30),
        ])->save();

        $this->getJson("/api/admin/channels/{$channel->id}/contact-sync")
            ->assertOk()
            ->assertJsonPath('data.status', WhatsAppConfig::SYNC_COMPLETED)
            ->assertJsonPath('data.contacts_imported', 42)
            ->assertJsonPath('data.can_retry', false);

        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_FAILED,
            'contact_sync_retryable' => false,
            'contact_sync_error' => 'Reconectá el canal.',
        ])->save();

        $this->getJson("/api/admin/channels/{$channel->id}/contact-sync")
            ->assertOk()
            ->assertJsonPath('data.status', WhatsAppConfig::SYNC_FAILED)
            ->assertJsonPath('data.can_retry', false)
            ->assertJsonPath('data.error', 'Reconectá el canal.');

        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_SYNCING,
            'contact_sync_requested_at' => now()->subMinute(),
            'contact_sync_request_id' => 'REQ_ACCEPTED',
            'contact_sync_retryable' => true,
            'contact_sync_error' => null,
        ])->save();

        $this->getJson("/api/admin/channels/{$channel->id}/contact-sync")
            ->assertOk()
            ->assertJsonPath('data.status', WhatsAppConfig::SYNC_SYNCING)
            ->assertJsonPath('data.can_retry', false);

        Http::fake();

        $this->postJson("/api/admin/channels/{$channel->id}/contact-sync/retry")
            ->assertConflict()
            ->assertJsonPath('message', 'La importación ya fue aceptada por Meta y está en curso.');

        Http::assertNothingSent();
    }

    /**
     * El sync de contactos (agenda) y el de historial (conversaciones) son dos
     * eventos separados de Meta: un canal puede traer mensajes de números que
     * nunca estuvieron guardados en la agenda del teléfono. El endpoint tiene
     * que exponer ambos para que la UI no muestre sólo la mitad del resultado.
     */
    /**
     * El bug que motiva este endpoint: un contact sync exitoso ya `completed`
     * hace que retryContactSync devuelva 409 sin tocar el historial. Si el
     * historial quedó `failed` por el guard preventivo de cuota (un rate limit
     * detectado antes del request, no un rechazo real de Meta), el usuario no
     * tenía ninguna forma de reintentarlo — pese a que el propio mensaje de
     * error le decía que esperara y reintentara.
     */
    public function test_retry_history_funciona_aunque_el_contact_sync_ya_este_completo(): void
    {
        $tenant = $this->seedTenantWithRoles();
        [, $config] = $this->createChannelWithConfig($tenant);
        $channel = Channel::where('tenant_id', $tenant->id)->firstOrFail();

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_COMPLETED,
            'contact_sync_requested_at' => now()->subMinutes(10),
            'contact_history_sync_status' => WhatsAppConfig::SYNC_FAILED,
            'contact_history_sync_error' => 'Meta está limitando las llamadas de la app. Esperá unos minutos y reintentá.',
        ])->save();

        // Confirma el bug reportado: el endpoint de contactos no sirve acá.
        $this->postJson("/api/admin/channels/{$channel->id}/contact-sync/retry")
            ->assertConflict()
            ->assertJsonPath('message', 'Los contactos ya fueron importados.');

        Http::fake(['*/smb_app_data' => Http::response(['request_id' => 'REQ_HISTORY_RETRY'], 200)]);

        $this->postJson("/api/admin/channels/{$channel->id}/contact-sync/retry-history")
            ->assertOk()
            ->assertJsonPath('data.status', WhatsAppConfig::SYNC_SYNCING);

        $fresh = $config->fresh();
        $this->assertSame(WhatsAppConfig::SYNC_SYNCING, $fresh->contact_history_sync_status);
        $this->assertSame('REQ_HISTORY_RETRY', $fresh->contact_history_sync_request_id);
        $this->assertNull($fresh->contact_history_sync_error);
        // El contact sync no se toca: este endpoint es exclusivo del historial.
        $this->assertSame(WhatsAppConfig::SYNC_COMPLETED, $fresh->contact_sync_status);
    }

    public function test_retry_history_es_rechazado_si_el_historial_ya_esta_completo(): void
    {
        $tenant = $this->seedTenantWithRoles();
        [, $config] = $this->createChannelWithConfig($tenant);
        $channel = Channel::where('tenant_id', $tenant->id)->firstOrFail();

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        $config->forceFill([
            'contact_history_sync_status' => WhatsAppConfig::SYNC_COMPLETED,
        ])->save();

        Http::fake();

        $this->postJson("/api/admin/channels/{$channel->id}/contact-sync/retry-history")
            ->assertConflict()
            ->assertJsonPath('message', 'El historial ya fue importado.');

        Http::assertNothingSent();
    }

    public function test_el_endpoint_expone_error_code_y_el_estado_del_historial(): void
    {
        $tenant = $this->seedTenantWithRoles();
        [, $config] = $this->createChannelWithConfig($tenant);
        $channel = Channel::where('tenant_id', $tenant->id)->firstOrFail();

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        $config->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_FAILED,
            'contact_sync_error' => 'Meta rechazó la importación [4]: Application request limit reached',
            'contact_sync_error_code' => 'rate_limit',
            'contact_history_sync_status' => WhatsAppConfig::SYNC_COMPLETED,
            'contact_history_sync_messages_count' => 97,
        ])->save();

        $this->getJson("/api/admin/channels/{$channel->id}/contact-sync")
            ->assertOk()
            ->assertJsonPath('data.error_code', 'rate_limit')
            ->assertJsonPath('data.history_status', WhatsAppConfig::SYNC_COMPLETED)
            ->assertJsonPath('data.history_messages_imported', 97)
            ->assertJsonPath('data.history_can_retry', false);

        $config->forceFill([
            'contact_history_sync_status' => WhatsAppConfig::SYNC_FAILED,
            'contact_history_sync_error' => 'Meta está limitando las llamadas de la app. Esperá unos minutos y reintentá.',
        ])->save();

        $this->getJson("/api/admin/channels/{$channel->id}/contact-sync")
            ->assertOk()
            ->assertJsonPath('data.history_can_retry', true);
    }

    /**
     * Tenant con el catálogo de permisos y los roles por defecto provisionados.
     * Sin esto las rutas admin devuelven 403.
     */
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

    /**
     * @param  Tenant|null  $tenant  Reutiliza un tenant ya provisionado con roles;
     *                               si no se pasa, crea uno simple (sin permisos).
     * @return array{0: Tenant, 1: WhatsAppConfig}
     */
    private function createChannelWithConfig(?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::create(['name' => 'Acme']);

        // `channels.user_id` es un FK obligatorio: el canal siempre pertenece al
        // usuario que lo conectó.
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);

        $config = new WhatsAppConfig([
            'phone_number_id' => 'PHONE_777',
            'waba_id' => 'WABA_777',
            'display_phone_number' => '+54 11 7777-7777',
        ]);
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

    /**
     * Payload de webhook `smb_app_state_sync` con la forma exacta que manda Meta.
     *
     * Verificado contra la doc oficial: el objeto `contact` tiene sólo full_name,
     * first_name y phone_number — NO hay user_id/BSUID. Y `action` sólo toma dos
     * valores: `add` (que cubre alta y edición) y `remove`, que además omite los
     * campos de nombre.
     *
     * @see https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users
     *
     * @param  array<int, array<string, string>>  $contacts
     */
    private function stateSyncPayload(WhatsAppConfig $config, array $contacts): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $config->waba_id,
                'changes' => [[
                    'field' => 'smb_app_state_sync',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => $config->display_phone_number,
                            'phone_number_id' => $config->phone_number_id,
                        ],
                        'state_sync' => array_map(function (array $c) {
                            $item = [
                                'type' => 'contact',
                                'contact' => ['phone_number' => $c['phone_number']],
                                'action' => $c['action'],
                                'metadata' => ['timestamp' => (string) now()->timestamp],
                            ];

                            // Meta omite los nombres cuando la acción es `remove`.
                            if ($c['action'] !== 'remove') {
                                $item['contact']['full_name'] = $c['full_name'];
                                $item['contact']['first_name'] = explode(' ', $c['full_name'])[0];
                            }

                            return $item;
                        }, $contacts),
                    ],
                ]],
            ]],
        ];
    }
}
