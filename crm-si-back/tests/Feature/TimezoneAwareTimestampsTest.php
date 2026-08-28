<?php

namespace Tests\Feature;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\BroadcastStatus;
use App\Enums\ChannelType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Jobs\SendBroadcastMessageJob;
use App\Models\BroadcastCampaign;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use App\Services\BroadcastDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresPostgresTimestamptz;
use Tests\TestCase;

/**
 * Regresión del incidente: una difusión programada para las 9:00 salió a las
 * 12:55. Causa: Eloquent serializa fechas sin offset ('Y-m-d H:i:s'), la
 * sesión de Postgres está en UTC, y con PHP en America/Argentina un now()
 * local se guardaba como si ya fuera UTC — 3 horas perdidas en cada
 * escritura o comparación contra una columna timestamptz.
 *
 * Estos tests DEBEN correr contra Postgres real (ver RequiresPostgresTimestamptz):
 * SQLite no distingue timestamp de timestamptz y no puede detectar nada de esto.
 *
 * php artisan test -c phpunit.postgres.xml --group=requires-postgres
 */
#[Group('requires-postgres')]
class TimezoneAwareTimestampsTest extends TestCase
{
    use RefreshDatabase;
    use RequiresPostgresTimestamptz;

    /**
     * Caso 1 del plan: un now() local escrito en una columna timestamptz debe
     * guardar el instante UTC real, no la hora de pared local re-etiquetada.
     * Es el mecanismo exacto que rompió el disparo de difusiones.
     */
    public function test_now_written_to_timestamptz_column_preserves_the_real_instant(): void
    {
        [, $channel, $template] = $this->createBroadcastSetup();

        $before = now()->utc()->subSeconds(2);

        $campaign = BroadcastCampaign::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'whatsapp_template_id' => $template->id,
            'created_by' => null,
            'name' => 'Test now()',
            'audience_filters' => [],
            'components' => [],
            'audience_count' => 0,
            'estimated_cost_usd' => 0,
            'interval_seconds' => 0,
            'scheduled_at' => now(),
            'started_at' => now(),
        ]);

        $after = now()->utc()->addSeconds(2);

        $storedUtc = $campaign->fresh()->started_at->utc();

        $this->assertTrue(
            $storedUtc->betweenIncluded($before, $after),
            "started_at guardado ({$storedUtc}) no está entre {$before} y {$after}. "
            .'Si el offset se perdió al escribir, este valor aparece ~3h desplazado.'
        );
    }

    /**
     * Caso 2: un Carbon UTC explícito (como el que arma DateAutomationScheduler
     * con ->utc(), o el que resulta de Carbon::parse() sobre un ISO con Z del
     * front) debe seguir siendo ese mismo instante después del round-trip.
     * Es el caso que el enfoque descartado (SET TimeZone en la conexión) rompía.
     */
    public function test_explicit_utc_carbon_round_trips_unchanged(): void
    {
        [, $channel, $template] = $this->createBroadcastSetup();

        $explicitUtc = Carbon::parse('2026-08-28T12:00:00.000Z');

        $campaign = BroadcastCampaign::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'whatsapp_template_id' => $template->id,
            'created_by' => null,
            'name' => 'Test Carbon UTC',
            'audience_filters' => [],
            'components' => [],
            'audience_count' => 0,
            'estimated_cost_usd' => 0,
            'interval_seconds' => 0,
            'scheduled_at' => $explicitUtc,
            'started_at' => now(),
        ]);

        $this->assertTrue(
            $campaign->fresh()->scheduled_at->utc()->eq($explicitUtc),
            'scheduled_at cambió de instante en el round-trip: debería seguir siendo '
            .$explicitUtc->toIso8601String()
        );
    }

    /**
     * End-to-end del bug reportado: una campaña programada para "ahora" (en
     * términos de instante real) debe ser detectada como vencida por
     * broadcasts:dispatch-due. Antes del fix, la comparación scheduled_at <=
     * now() (sin ->utc()) tardaba 3 horas en dar verdadera.
     */
    public function test_dispatch_due_broadcasts_fires_at_the_correct_instant(): void
    {
        // No importa si SendBroadcastMessageJob después falla contra Meta
        // (fixture sin token real): lo único que este test verifica es si
        // dispatch-due DETECTA la campaña como vencida a tiempo.
        Queue::fake();

        [$user, $channel, $template] = $this->createBroadcastSetup();
        $contact = Contact::create([
            'tenant_id' => $channel->tenant_id,
            'name' => 'Contacto test',
            'phone' => '+5491100000000',
            'source' => 'whatsapp',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $campaign = BroadcastCampaign::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'whatsapp_template_id' => $template->id,
            'created_by' => $user->id,
            'name' => 'Programada a 1 minuto',
            'status' => BroadcastStatus::Scheduled,
            'audience_filters' => [],
            'components' => [],
            'audience_count' => 1,
            'estimated_cost_usd' => 0,
            'interval_seconds' => 0,
            // Instante real: dentro de 1 minuto desde ahora.
            'scheduled_at' => now()->utc()->addMinute(),
        ]);
        $campaign->recipients()->create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
        ]);

        $this->travelTo(now()->addMinutes(2));

        $this->artisan('broadcasts:dispatch-due')->assertSuccessful();

        $this->assertNotSame(
            BroadcastStatus::Scheduled,
            $campaign->fresh()->status,
            'La campaña programada para dentro de 1 minuto sigue Scheduled 2 minutos '
            .'después: dispatch-due no la detectó como vencida (bug de comparación de timezone). '
            .'scheduled_at <= now()->utc() debería haber dado verdadero.'
        );
        Queue::assertPushed(SendBroadcastMessageJob::class);

        $this->travelBack();
    }

    /**
     * Regresión puntual: BroadcastDispatcher::dispatch() marca los destinatarios
     * como Queued vía un bulk update sobre la relación ($locked->recipients()->
     * whereKey(...)->update(...)). Ese camino NO pasa por el modelo, así que
     * HasTimezoneAwareDates (getDateFormat) no interviene — necesita ->utc()
     * explícito en el propio update. Sin él, queued_at queda 3h en el pasado y
     * cualquier alerta de "cola atascada" (broadcasts:check-stuck) o reclamo de
     * jobs huérfanos se dispara horas antes de tiempo.
     */
    public function test_dispatcher_writes_queued_at_via_bulk_update_in_the_correct_instant(): void
    {
        // Con QUEUE_CONNECTION=sync (bootstrap de test), sin fake el job de
        // envío corre inline y pisa el status a Failed contra un token de
        // fixture inválido. Irrelevante para lo que este test mide: si
        // queued_at (escrito por el bulk update) quedó en el instante correcto.
        Queue::fake();

        [$user, $channel, $template] = $this->createBroadcastSetup();
        $contact = Contact::create([
            'tenant_id' => $channel->tenant_id,
            'name' => 'Contacto bulk',
            'phone' => '+5491100000002',
            'source' => 'whatsapp',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $campaign = BroadcastCampaign::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'whatsapp_template_id' => $template->id,
            'created_by' => $user->id,
            'name' => 'Bulk update queued_at',
            'status' => BroadcastStatus::Scheduled,
            'audience_filters' => [],
            'components' => [],
            'audience_count' => 1,
            'estimated_cost_usd' => 0,
            'interval_seconds' => 0,
            'scheduled_at' => now()->utc()->subMinute(),
        ]);
        $campaign->recipients()->create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
        ]);

        $before = now()->utc()->subSeconds(2);
        app(BroadcastDispatcher::class)->dispatch($campaign);
        $after = now()->utc()->addSeconds(2);

        $recipient = $campaign->recipients()->firstOrFail();

        $this->assertSame(BroadcastRecipientStatus::Queued, $recipient->status);
        $this->assertTrue(
            $recipient->queued_at->utc()->betweenIncluded($before, $after),
            "queued_at guardado ({$recipient->queued_at->utc()}) no está entre {$before} y {$after}. "
            .'El bulk update de BroadcastDispatcher perdió el offset (~3h de desfase).'
        );
    }

    /**
     * Control negativo: una columna timestamp SIN zona (la convención del
     * resto del sistema, ej. messages.created_at) debe seguir guardando hora
     * local sin verse afectada por nada de lo anterior. Prueba que el fix es
     * quirúrgico y no invadió la otra convención.
     */
    public function test_timestamp_without_timezone_column_is_unaffected(): void
    {
        [, $channel] = $this->createBroadcastSetup();
        $contact = Contact::create([
            'tenant_id' => $channel->tenant_id,
            'name' => 'Contacto control',
            'phone' => '+5491100000001',
            'source' => 'whatsapp',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $before = now()->subSeconds(2);

        $message = Message::create([
            'tenant_id' => $channel->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'system',
            'content' => 'control',
            'message_type' => 'text',
            'direction' => 'outbound',
        ]);

        $after = now()->addSeconds(2);

        $storedLocal = $message->fresh()->created_at;

        $this->assertTrue(
            $storedLocal->betweenIncluded($before, $after),
            "messages.created_at ({$storedLocal}) se movió de la hora local esperada "
            .'entre '.$before.' y '.$after.'. Este control no debería fallar nunca.'
        );
    }

    /** @return array{0: User, 1: Channel, 2: WhatsAppTemplate} */
    private function createBroadcastSetup(): array
    {
        $tenant = Tenant::create(['name' => 'Tenant tz '.uniqid()]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $config = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 223 555-0101',
            'waba_id' => 'waba-'.uniqid(),
            'bussines_token' => Crypt::encryptString('token'),
        ]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp test',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);
        $template = WhatsAppTemplate::create([
            'tenant_id' => $tenant->id,
            'whatsapp_config_id' => $config->id,
            'external_id' => 'template-'.uniqid(),
            'name' => 'test_tz',
            'language' => 'es_AR',
            'category' => TemplateCategory::Marketing,
            'status' => TemplateStatus::Approved,
            'components' => [['type' => 'BODY', 'text' => 'Hola']],
            'synced_at' => now(),
        ]);

        return [$user, $channel, $template];
    }
}
