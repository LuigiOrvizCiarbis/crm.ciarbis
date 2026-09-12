<?php

namespace Tests\Feature;

use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Models\Tenant;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * El webhook message_template_status_update ya está suscrito en la app de Meta
 * (campo `message_template_status_update` del topic whatsapp_business_account),
 * pero el controller lo descartaba: el CRM sólo se enteraba de una aprobación
 * si alguien sincronizaba a mano desde /configuracion.
 *
 * Los payloads replican los ejemplos de la referencia oficial:
 * developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/message_template_status_update
 */
class WhatsAppTemplateStatusWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_event_marks_template_as_approved(): void
    {
        $template = $this->makeTemplate(TemplateStatus::Pending, externalId: '1689556908129832');

        $this->postJson('/api/whatsapp-webhook', $this->payload([
            'event' => 'APPROVED',
            'message_template_id' => 1689556908129832,
            'message_template_name' => $template->name,
            'message_template_language' => 'es_AR',
            'reason' => 'NONE',
            'message_template_category' => 'UTILITY',
        ]))->assertOk();

        $template->refresh();
        $this->assertSame(TemplateStatus::Approved, $template->status);
        $this->assertTrue($template->isApproved());
        $this->assertNull($template->rejected_reason, 'reason="NONE" no es un rechazo real.');
        $this->assertNotNull($template->synced_at);
    }

    public function test_rejected_event_stores_the_human_readable_reason(): void
    {
        $template = $this->makeTemplate(TemplateStatus::Pending, externalId: '1689556908129835');

        $this->postJson('/api/whatsapp-webhook', $this->payload([
            'event' => 'REJECTED',
            'message_template_id' => 1689556908129835,
            'message_template_name' => $template->name,
            'message_template_language' => 'es_AR',
            'reason' => 'INVALID_FORMAT',
            'message_template_category' => 'UTILITY',
            'rejection_info' => [
                'reason' => 'Your template has parameters placed next to each other.',
                'recommendation' => 'Separate parameters with descriptive text.',
            ],
        ]))->assertOk();

        $template->refresh();
        $this->assertSame(TemplateStatus::Rejected, $template->status);
        // Se prefiere el texto explicativo al código seco: "INVALID_FORMAT" no
        // le dice nada a quien tiene que corregir la plantilla.
        $this->assertSame('Your template has parameters placed next to each other.', $template->rejected_reason);
    }

    public function test_rejection_falls_back_to_the_reason_code_without_rejection_info(): void
    {
        $template = $this->makeTemplate(TemplateStatus::Pending, externalId: '111222333');

        $this->postJson('/api/whatsapp-webhook', $this->payload([
            'event' => 'REJECTED',
            'message_template_id' => 111222333,
            'reason' => 'ABUSIVE_CONTENT',
        ]))->assertOk();

        $this->assertSame('ABUSIVE_CONTENT', $template->refresh()->rejected_reason);
    }

    public function test_paused_event_is_reflected(): void
    {
        $template = $this->makeTemplate(TemplateStatus::Approved, externalId: '444555666');

        $this->postJson('/api/whatsapp-webhook', $this->payload([
            'event' => 'PAUSED',
            'message_template_id' => 444555666,
            'reason' => 'NONE',
            'other_info' => ['title' => 'FIRST_PAUSE', 'description' => 'Tu plantilla fue pausada.'],
        ]))->assertOk();

        $this->assertSame(TemplateStatus::Paused, $template->refresh()->status);
    }

    public function test_unmapped_event_leaves_the_status_untouched(): void
    {
        $template = $this->makeTemplate(TemplateStatus::Approved, externalId: '777888999');

        // FLAGGED/LOCKED/ARCHIVED/REINSTATED no están en el enum: degradar a
        // Unknown bloquearía una plantilla que en Meta sigue siendo enviable.
        $this->postJson('/api/whatsapp-webhook', $this->payload([
            'event' => 'FLAGGED',
            'message_template_id' => 777888999,
            'reason' => 'NONE',
        ]))->assertOk();

        $this->assertSame(TemplateStatus::Approved, $template->refresh()->status);
    }

    public function test_unknown_template_is_ignored_without_error(): void
    {
        $this->postJson('/api/whatsapp-webhook', $this->payload([
            'event' => 'APPROVED',
            'message_template_id' => 999000111,
            'reason' => 'NONE',
        ]))->assertOk();

        $this->assertDatabaseCount('whatsapp_templates', 0);
    }

    public function test_incomplete_payload_is_ignored_without_error(): void
    {
        $template = $this->makeTemplate(TemplateStatus::Pending, externalId: '123123123');

        $this->postJson('/api/whatsapp-webhook', $this->payload(['event' => 'APPROVED']))->assertOk();

        $this->assertSame(TemplateStatus::Pending, $template->refresh()->status);
    }

    /**
     * El external_id de Meta es único global, así que la plantilla se resuelve
     * sin depender de que el waba_id del entry matchee una config local.
     */
    public function test_resolves_the_template_even_when_the_waba_id_is_unknown(): void
    {
        $template = $this->makeTemplate(TemplateStatus::Pending, externalId: '555666777');

        $payload = $this->payload([
            'event' => 'APPROVED',
            'message_template_id' => 555666777,
            'reason' => 'NONE',
        ]);
        $payload['entry'][0]['id'] = 'WABA_QUE_NO_EXISTE';

        $this->postJson('/api/whatsapp-webhook', $payload)->assertOk();

        $this->assertSame(TemplateStatus::Approved, $template->refresh()->status);
    }

    /** @param array<string, mixed> $value */
    private function payload(array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '102290129340398',
                'time' => 1751247548,
                'changes' => [[
                    'field' => 'message_template_status_update',
                    'value' => $value,
                ]],
            ]],
        ];
    }

    private function makeTemplate(TemplateStatus $status, string $externalId): WhatsAppTemplate
    {
        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);

        $config = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 223 555-0101',
            'waba_id' => '102290129340398',
            'bussines_token' => Crypt::encryptString('token'),
        ]);

        return WhatsAppTemplate::create([
            'tenant_id' => $tenant->id,
            'whatsapp_config_id' => $config->id,
            'external_id' => $externalId,
            'name' => 'cobranza_aviso_'.uniqid(),
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => $status,
            'components' => [['type' => 'BODY', 'text' => 'Hola {{nombre}}']],
            'synced_at' => now()->subDay(),
        ]);
    }
}
