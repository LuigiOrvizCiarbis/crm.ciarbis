<?php

namespace App\Console\Commands;

use App\Enums\ContactFieldType;
use App\Models\AutomationRule;
use App\Models\BillingConfig;
use App\Models\Contact;
use App\Models\ContactField;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Avanza el ciclo de cobranza de cada contacto vencido, un día por corrida
 * por tenant. Ver Fase 5 del plan SI-27 para el diseño completo.
 *
 * Reemplaza "la fecha se congela" (inviable: DateAutomationScheduler.php:44
 * descarta fechas pasadas, así que un moroso congelado queda invisible para
 * siempre) por "la fecha siempre avanza; el estado de pago es dato
 * explícito". Un save() por contacto que cambia fecha+estado+contador juntos
 * dispara ContactAutomationObserver -> EvaluateAutomationEventJob, que
 * reagenda las automation_rules de date.reached contra la fecha nueva.
 */
class BillingRollCycleCommand extends Command
{
    protected $signature = 'billing:roll-cycle
                            {--tenant= : Limitar a un tenant específico (debug/backfill)}';

    protected $description = 'Avanza el ciclo de vencimiento de los contactos vencidos hace más de grace_days, tenant por tenant';

    private const CHUNK_SIZE = 250;

    public function handle(): int
    {
        $configsQuery = BillingConfig::where('enabled', true);
        if ($this->option('tenant')) {
            $configsQuery->where('tenant_id', (int) $this->option('tenant'));
        }

        $configs = $configsQuery->get();
        if ($configs->isEmpty()) {
            $this->info('Sin tenants con cobranzas habilitadas.');

            return self::SUCCESS;
        }

        $hadFailure = false;
        foreach ($configs as $config) {
            try {
                $this->rollTenant($config);
            } catch (\Throwable $exception) {
                $hadFailure = true;
                Log::error('billing:roll-cycle falló para un tenant', [
                    'tenant_id' => $config->tenant_id,
                    'error' => $exception->getMessage(),
                ]);
                $this->error("Tenant #{$config->tenant_id}: {$exception->getMessage()}");
            }
        }

        return $hadFailure ? self::FAILURE : self::SUCCESS;
    }

    private function rollTenant(BillingConfig $config): void
    {
        $now = CarbonImmutable::now($config->timezone);
        $today = $now->format('Y-m-d');

        // Idempotencia: dos corridas el mismo día (timezone del tenant) no
        // avanzan dos ciclos. No es un lock contra concurrencia real —
        // withoutOverlapping() en el scheduler cubre eso — es la barrera
        // contra un reintento manual o un segundo disparo del mismo día.
        if ($config->last_rolled_at?->timezone($config->timezone)->format('Y-m-d') === $today) {
            $this->line("Tenant #{$config->tenant_id}: ya corrió hoy ({$today}), sin cambios.");

            return;
        }

        $dueDateField = ContactField::forTenant($config->tenant_id)->firstWhere('key', $config->due_date_field_key);
        $statusField = ContactField::forTenant($config->tenant_id)->firstWhere('key', $config->status_field_key);
        $overdueCyclesField = ContactField::forTenant($config->tenant_id)->firstWhere('key', $config->overdue_cycles_field_key);

        // Guard defensivo: si el tenant borró (soft delete) alguno de los
        // campos referenciados, la config queda colgada. Se saltea el
        // tenant con warning en vez de romper la corrida completa.
        if (! $dueDateField || $dueDateField->type !== ContactFieldType::Date
            || ! $statusField || $statusField->type !== ContactFieldType::Select
            || ! $overdueCyclesField || $overdueCyclesField->type !== ContactFieldType::Number) {
            Log::warning('billing:roll-cycle: campo referenciado por billing_configs no existe o cambió de tipo', [
                'tenant_id' => $config->tenant_id,
            ]);
            $this->warn("Tenant #{$config->tenant_id}: campos de cobranza inválidos, saltando.");

            return;
        }

        $this->assertTimezoneAligned($config);

        $cutoff = $now->subDays($config->grace_days)->format('Y-m-d');
        $rolledCount = 0;

        $query = Contact::withoutGlobalScopes()
            ->where('tenant_id', $config->tenant_id)
            ->whereCustomFieldRange($config->due_date_field_key, ContactFieldType::Date, null, $cutoff)
            ->when($config->externally_managed_field_key, function ($q) use ($config) {
                // Un booleano JSON se extrae distinto según el motor: Postgres
                // da el string "true"/"false"; SQLite da el entero 1/0 con
                // afinidad de tipo, así que "1" != 1 sin castear (SQLite no
                // iguala INTEGER y TEXT del mismo valor bajo ciertas reglas de
                // comparación). CAST(... AS TEXT) fuerza ambos lados a texto
                // en cualquier motor, evitando el problema en la raíz.
                $truthy = $q->getConnection()->getDriverName() === 'pgsql' ? 'true' : '1';

                return $q->where(function ($w) use ($config, $truthy) {
                    $w->whereRaw('custom_data ->> ? IS NULL', [$config->externally_managed_field_key])
                        ->orWhereRaw('CAST(custom_data ->> ? AS TEXT) != ?', [$config->externally_managed_field_key, $truthy]);
                });
            })
            ->orderBy('id');

        $query->chunkById(self::CHUNK_SIZE, function ($contacts) use ($config, &$rolledCount): void {
            foreach ($contacts as $contact) {
                if ($this->rollContact($contact, $config)) {
                    $rolledCount++;
                }
            }
        });

        $config->update(['last_rolled_at' => $now]);
        $this->info("Tenant #{$config->tenant_id}: {$rolledCount} contacto(s) avanzado(s) de ciclo.");
    }

    /**
     * Un solo save() con fecha + estado + contador ya calculados: tres saves
     * serían tres eventos contact.field_changed y tres reprogramaciones
     * redundantes para el mismo contacto (ver EvaluateAutomationEventJob).
     */
    private function rollContact(Contact $contact, BillingConfig $config): bool
    {
        $customData = $contact->custom_data ?? [];
        $status = $customData[$config->status_field_key] ?? null;

        if (! in_array($status, BillingConfig::STATUSES, true)) {
            // Estado ausente o corrupto: no hay forma segura de decidir el
            // siguiente paso. Se deja intacto en vez de adivinar un ciclo.
            return false;
        }

        $currentDue = CarbonImmutable::parse((string) $customData[$config->due_date_field_key], $config->timezone);
        $nextDue = $this->addCycle($currentDue, $config->cycle_unit, $config->cycle_length);

        $customData[$config->due_date_field_key] = $nextDue->format('Y-m-d');

        if ($status === 'al_dia') {
            $customData[$config->status_field_key] = 'impago';
            $customData[$config->overdue_cycles_field_key] = 0;
        } elseif ($status === 'impago') {
            $current = (int) ($customData[$config->overdue_cycles_field_key] ?? 0);
            $customData[$config->overdue_cycles_field_key] = $current + 1;
        } else { // en_prueba
            $customData[$config->status_field_key] = 'impago';
            $customData[$config->overdue_cycles_field_key] = 1;
        }

        $contact->custom_data = $customData;
        $contact->save();

        return true;
    }

    private function addCycle(CarbonImmutable $date, string $unit, int $length): CarbonImmutable
    {
        return match ($unit) {
            'days' => $date->addDays($length),
            'weeks' => $date->addWeeks($length),
            default => $date->addMonthsNoOverflow($length),
        };
    }

    /**
     * grace_days > overdue-days ya se valida al provisionar (Fase 4), pero
     * una regla puede haberse editado después desde /automations con un
     * timezone distinto al de billing_configs — ahí el corte de "quién
     * venció" del roll-cycle dejaría de coincidir con el de la regla (ver
     * AutomationRuleService.php:29, timezone por default UTC). Se valida acá
     * y se aborta ese tenant en vez de avanzar fechas contra un cálculo
     * potencialmente desalineado.
     */
    private function assertTimezoneAligned(BillingConfig $config): void
    {
        $mismatched = AutomationRule::withoutGlobalScopes()
            ->where('tenant_id', $config->tenant_id)
            ->where('trigger_type', 'date.reached')
            ->where('trigger_config->subject', 'contact')
            ->where('trigger_config->field', 'contact.custom_data.'.$config->due_date_field_key)
            ->where('timezone', '!=', $config->timezone)
            ->exists();

        if ($mismatched) {
            throw new \RuntimeException(
                "El timezone de billing_configs ({$config->timezone}) no coincide con el de alguna regla de cobranza activa."
            );
        }
    }
}
