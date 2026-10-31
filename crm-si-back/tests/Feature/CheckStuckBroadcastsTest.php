<?php

namespace Tests\Feature;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\BroadcastStatus;
use App\Enums\ChannelType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Models\BroadcastCampaign;
use App\Models\BroadcastRecipient;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RequiresPostgresTimestamptz;
use Tests\TestCase;

/**
 * broadcasts:check-stuck usa ::interval de Postgres para reconstruir el delay
 * esperado de cada recipient (índice dentro del batch * interval_seconds del
 * dispatcher). SQLite no soporta esa sintaxis, así que corre solo contra
 * Postgres real, igual que TimezoneAwareTimestampsTest.
 *
 * php artisan test -c phpunit.postgres.xml --group=requires-postgres
 */
#[Group('requires-postgres')]
class CheckStuckBroadcastsTest extends TestCase
{
    use RefreshDatabase;
    use RequiresPostgresTimestamptz;

    public function test_recipient_still_within_its_scheduled_delay_is_not_flagged_as_stuck(): void
    {
        [$campaign] = $this->createCampaign(intervalSeconds: 3600);

        // Posición 2 del batch (índice 1): su job legítimamente tiene 3600s de
        // delay sobre queued_at. Encolado hace 10 minutos, muy por debajo de
        // ese delay — no está atascado aunque supere el umbral de 15 min.
        $this->createRecipient($campaign, queuedMinutesAgo: 10, batchIndex: 1);

        $this->artisan('broadcasts:check-stuck')
            ->assertExitCode(0)
            ->doesntExpectOutputToContain('Difusiones atascadas');
    }

    public function test_recipient_past_its_scheduled_delay_is_flagged_as_stuck(): void
    {
        [$campaign] = $this->createCampaign(intervalSeconds: 60);

        // Índice 1 => delay esperado de 60s. Encolado hace 20 minutos: muy
        // por encima tanto del delay esperado como del umbral de 15 min.
        $this->createRecipient($campaign, queuedMinutesAgo: 20, batchIndex: 1);

        $this->artisan('broadcasts:check-stuck')
            ->assertExitCode(0)
            ->expectsOutputToContain('Difusiones atascadas');
    }

    /**
     * El bug que este test reproduce: si el ROW_NUMBER() se calculara solo
     * sobre recipients Queued, un recipient que nació en el índice 100 (delay
     * real de 100*interval_seconds) se vería renumerado hacia índices bajos a
     * medida que sus 99 predecesores del batch pasan a Sent, y el comando le
     * exigiría un delay muchísimo menor del que en verdad tiene — falso
     * positivo de "atascado" apenas pasan unos minutos.
     */
    public function test_recipient_position_is_not_renumbered_when_earlier_batch_members_complete(): void
    {
        [$campaign] = $this->createCampaign(intervalSeconds: 60);

        // Índice 100 real => delay esperado de 100*60s = 6000s (100 min).
        // Encolado hace 20 minutos: muy por debajo de ese delay real, así que
        // NO debería marcarse como atascado.
        $recipient = $this->createRecipient($campaign, queuedMinutesAgo: 20, batchIndex: 100);

        // Los 100 predecesores ya se procesaron: si el cálculo de posición
        // filtrara por status=queued antes de numerar, este recipient
        // pasaría a verse como índice 0 (delay esperado de 0s) y quedaría
        // marcado como atascado sin serlo.
        $campaign->recipients()->where('id', '!=', $recipient->id)->update(['status' => BroadcastRecipientStatus::Sent]);

        $this->artisan('broadcasts:check-stuck')
            ->assertExitCode(0)
            ->doesntExpectOutputToContain('Difusiones atascadas');

        $this->assertSame(BroadcastRecipientStatus::Queued, $recipient->fresh()->status);
    }

    /** @return array{0: BroadcastCampaign} */
    private function createCampaign(int $intervalSeconds): array
    {
        $tenant = Tenant::create(['name' => 'Tenant stuck '.uniqid()]);
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
            'name' => 'test_stuck',
            'language' => 'es_AR',
            'category' => TemplateCategory::Marketing,
            'status' => TemplateStatus::Approved,
            'components' => [],
        ]);

        $campaign = BroadcastCampaign::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'whatsapp_template_id' => $template->id,
            'created_by' => $user->id,
            'name' => 'Campaña stuck test',
            'audience_filters' => [],
            'components' => [],
            'audience_count' => 1,
            'estimated_cost_usd' => 0,
            'interval_seconds' => $intervalSeconds,
            'scheduled_at' => now(),
            'started_at' => now(),
            'status' => BroadcastStatus::Processing,
        ]);

        return [$campaign];
    }

    private function createRecipient(BroadcastCampaign $campaign, int $queuedMinutesAgo, int $batchIndex): BroadcastRecipient
    {
        $contact = Contact::create([
            'tenant_id' => $campaign->tenant_id,
            'name' => 'Contacto stuck',
            'phone' => '+549223555'.random_int(1000, 9999),
            'source' => 'test',
        ]);

        $queuedAt = now()->utc()->subMinutes($queuedMinutesAgo);

        // batch_index se reconstruye en el comando vía ROW_NUMBER() sobre
        // (campaign_id, queued_at) ordenado por id: para simular el índice N
        // alcanza con crear N registros "anteriores" con el mismo queued_at.
        for ($i = 0; $i < $batchIndex; $i++) {
            $filler = Contact::create([
                'tenant_id' => $campaign->tenant_id,
                'name' => 'Filler',
                'phone' => '+549223444'.random_int(1000, 9999),
                'source' => 'test',
            ]);

            $campaign->recipients()->create([
                'contact_id' => $filler->id,
                'status' => BroadcastRecipientStatus::Queued,
                'queued_at' => $queuedAt,
            ]);
        }

        return $campaign->recipients()->create([
            'contact_id' => $contact->id,
            'status' => BroadcastRecipientStatus::Queued,
            'queued_at' => $queuedAt,
        ]);
    }
}
