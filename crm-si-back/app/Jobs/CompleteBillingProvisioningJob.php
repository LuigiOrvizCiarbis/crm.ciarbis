<?php

namespace App\Jobs;

use App\Enums\TemplateStatus;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\WhatsAppTemplate;
use App\Services\BillingProvisioner;
use App\Support\BillingTemplateDrafts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Completa el provisioning de cobranzas cuando Meta aprueba las plantillas que
 * se pidieron desde la UI.
 *
 * Lo dispara el webhook message_template_status_update. Corre como job y no en
 * línea para que un fallo del provisioning (canal borrado, Owner sin resolver)
 * no haga fallar la recepción del webhook: Meta reintenta los eventos que no
 * responden 200, y no queremos que reintente por algo que no es su problema.
 *
 * Las reglas quedan en Draft: activarlas solas, sobre vencimientos que el
 * tenant ya tenía cargados, podría disparar una ráfaga de mensajes que nadie
 * revisó. El último click lo da el usuario.
 */
class CompleteBillingProvisioningJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $tenantId) {}

    public function handle(BillingProvisioner $provisioner): void
    {
        $tenant = Tenant::withoutGlobalScopes()->find($this->tenantId);

        if (! $tenant) {
            return;
        }

        $drafts = collect(BillingTemplateDrafts::all($tenant))->keyBy('key');

        $templates = WhatsAppTemplate::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->whereIn('name', $drafts->pluck('name')->all())
            ->get()
            ->keyBy('name');

        $resolved = [];
        foreach ($drafts as $key => $draft) {
            $template = $templates->get($draft['name']);
            if ($template?->status === TemplateStatus::Approved) {
                $resolved[$key] = $template;
            }
        }

        // El aviso de trial es opcional; las otras dos son el mínimo para que
        // el módulo sirva. Si alguna sigue pendiente o fue rechazada, se espera
        // al siguiente webhook.
        if (! isset($resolved[BillingTemplateDrafts::REMINDER], $resolved[BillingTemplateDrafts::OVERDUE])) {
            return;
        }

        // Qué falta se decide por regla, no por el flag `enabled` de la
        // config: `enabled` se setea antes de crear las reglas, así que usarlo
        // como marcador de "ya terminé" rompía tres casos reales —
        //  1. la plantilla de trial aprobada DESPUÉS de las obligatorias
        //     nunca generaba su regla;
        //  2. un fallo al crear una regla dejaba el resto sin reintento, aun
        //     con los tries del job;
        //  3. un tenant con config hecha a mano (enabled sin reglas) quedaba
        //     excluido para siempre.
        $missing = $provisioner->missingRuleKeys($tenant, array_keys($resolved));

        if ($missing === []) {
            return;
        }

        try {
            $result = $provisioner->provision($tenant, $resolved, ['activate_rules' => false]);

            Log::info('Cobranzas provisionadas tras la aprobación de las plantillas', [
                'tenant_id' => $tenant->id,
                'rules' => $result['rules'],
            ]);
        } catch (\Throwable $e) {
            Log::error('No se pudo completar el provisioning de cobranzas', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
