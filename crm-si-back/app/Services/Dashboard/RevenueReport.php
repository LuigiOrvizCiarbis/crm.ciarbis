<?php

namespace App\Services\Dashboard;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceSetting;
use App\Models\Opportunity;
use App\Models\Tenant;
use App\Support\DashboardScope;
use Carbon\CarbonImmutable;

/**
 * Bloque "Ingresos" del Panel: lo ganado, lo que promete el pipeline abierto y
 * lo efectivamente cobrado.
 */
final class RevenueReport
{
    private const GOAL_MONTHS = ['este-mes' => 1, 'este-trimestre' => 3, 'este-ano' => 12];

    private const DEFAULT_TIMEZONE = 'America/Argentina/Buenos_Aires';

    /**
     * @param  float  $wonValue  ya calculado en los KPIs con el mismo rango y alcance
     * @return array<string, mixed>
     */
    public function build(
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $periodo,
        DashboardScope $scope,
        Tenant $tenant,
        float $wonValue,
        int $wonCount,
        bool $withCollections,
    ): array {
        $forecast = $this->forecast($scope);
        $months = self::GOAL_MONTHS[$periodo] ?? null;

        return [
            'won_value' => $wonValue,
            'won_count' => $wonCount,
            'open_weighted_value' => $forecast['weighted'],
            'projected_value' => $forecast['has_probability'] ? round($wonValue + $forecast['weighted'], 2) : null,
            'stages_without_probability' => $forecast['stages_without_probability'],
            'goal' => $months !== null && $tenant->revenue_goal_monthly !== null
                ? round((float) $tenant->revenue_goal_monthly * $months, 2)
                : null,
            'by_channel' => $this->byChannel($from, $to, $scope->withoutCanal()),
            'collections' => $withCollections ? $this->collections($from, $to, $scope, $tenant->id) : null,
        ];
    }

    /**
     * Las abiertas son una foto del momento: no se acotan al período.
     *
     * @return array{weighted: float, has_probability: bool, stages_without_probability: int}
     */
    private function forecast(DashboardScope $scope): array
    {
        $rows = $scope->opportunities(Opportunity::query()->where('opportunities.status', 'open'))
            ->join('pipeline_stages', 'pipeline_stages.id', '=', 'opportunities.pipeline_stage_id')
            ->toBase()
            ->select(['pipeline_stages.id', 'pipeline_stages.win_probability'])
            ->selectRaw('COALESCE(SUM(opportunities.value), 0) AS open_value')
            ->groupBy('pipeline_stages.id', 'pipeline_stages.win_probability')
            ->get();

        $weighted = 0.0;
        $withProbability = 0;

        foreach ($rows as $row) {
            if ($row->win_probability === null) {
                continue;
            }

            $weighted += (float) $row->open_value * (int) $row->win_probability / 100;
            $withProbability++;
        }

        return [
            'weighted' => round($weighted, 2),
            'has_probability' => $withProbability > 0,
            'stages_without_probability' => $rows->count() - $withProbability,
        ];
    }

    /**
     * Cerradas en el período por el canal de su conversación; sin conversación
     * quedan en la fila de canal null.
     *
     * @return list<array<string, mixed>>
     */
    private function byChannel(CarbonImmutable $from, CarbonImmutable $to, DashboardScope $scope): array
    {
        $rows = $scope->opportunities(Opportunity::query()
            ->whereIn('opportunities.status', ['won', 'lost'])
            ->whereBetween('opportunities.closed_at', [$from, $to]))
            ->leftJoin('conversations', 'conversations.id', '=', 'opportunities.conversation_id')
            ->toBase()
            ->select('conversations.channel_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN opportunities.status = 'won' THEN COALESCE(opportunities.value, 0) ELSE 0 END), 0) AS won_value")
            ->selectRaw("COALESCE(SUM(CASE WHEN opportunities.status = 'won' THEN 1 ELSE 0 END), 0) AS won_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN opportunities.status = 'lost' THEN 1 ELSE 0 END), 0) AS lost_count")
            ->groupBy('conversations.channel_id')
            ->get();

        $channels = Channel::query()
            ->whereIn('id', $rows->pluck('channel_id')->filter()->all())
            ->get(['id', 'name', 'type'])
            ->keyBy('id');

        return $rows
            ->map(function (object $row) use ($channels) {
                $channel = $row->channel_id !== null ? $channels->get((int) $row->channel_id) : null;

                return [
                    'channel_id' => $row->channel_id !== null ? (int) $row->channel_id : null,
                    'channel_name' => $channel?->name,
                    'channel_type' => $channel?->type,
                    'won_value' => (float) $row->won_value,
                    'won_count' => (int) $row->won_count,
                    'lost_count' => (int) $row->lost_count,
                ];
            })
            ->sortBy([['won_value', 'desc'], ['won_count', 'desc']])
            ->values()
            ->all();
    }

    /**
     * Mismo criterio de saldo y vencimiento que el resumen de Facturación. Sólo
     * aplica la sucursal (la del contacto facturado): las facturas no tienen
     * canal, vendedor ni pipeline.
     *
     * @return array<string, mixed>|null
     */
    private function collections(CarbonImmutable $from, CarbonImmutable $to, DashboardScope $scope, int $tenantId): ?array
    {
        $hasActivity = Invoice::query()->where('invoices.tenant_id', $tenantId)->where('status', 'issued')->exists()
            || InvoicePayment::query()->where('invoice_payments.tenant_id', $tenantId)->exists();

        if (! $hasActivity) {
            return null;
        }

        $timezone = InvoiceSetting::where('tenant_id', $tenantId)->value('timezone') ?: self::DEFAULT_TIMEZONE;
        $today = now($timezone)->toDateString();
        $contacts = $scope->contacts(Contact::query()->select('contacts.id'));

        $paidTotals = InvoicePayment::query()
            ->select('invoice_id')
            ->selectRaw('SUM(amount_cents) AS paid_cents')
            ->where('invoice_payments.tenant_id', $tenantId)
            ->whereNull('reversed_at')
            ->groupBy('invoice_id');
        $paid = 'COALESCE(payment_totals.paid_cents, 0)';
        $overdue = "(invoices.collection_status_override = 'overdue' OR (invoices.collection_status_override IS NULL AND invoices.due_on < ?)) AND invoices.amount_cents > {$paid}";

        $balances = Invoice::query()
            ->leftJoinSub($paidTotals, 'payment_totals', 'payment_totals.invoice_id', '=', 'invoices.id')
            ->where('invoices.tenant_id', $tenantId)
            ->where('invoices.status', 'issued')
            ->whereIn('invoices.contact_id', $contacts)
            ->toBase()
            ->select('invoices.currency')
            ->selectRaw("COALESCE(SUM(CASE WHEN invoices.amount_cents > {$paid} THEN invoices.amount_cents - {$paid} ELSE 0 END), 0) AS outstanding_cents")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$overdue} THEN invoices.amount_cents - {$paid} ELSE 0 END), 0) AS overdue_cents", [$today])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$overdue} THEN 1 ELSE 0 END), 0) AS overdue_count", [$today])
            ->groupBy('invoices.currency')
            ->get()
            ->keyBy('currency');

        // paid_on es una fecha: se compara contra los días del rango, no contra instantes.
        $collected = InvoicePayment::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->where('invoice_payments.tenant_id', $tenantId)
            ->whereNull('invoice_payments.reversed_at')
            ->where('invoices.status', '!=', 'void')
            ->whereBetween('invoice_payments.paid_on', [$from->toDateString(), $to->toDateString()])
            ->whereIn('invoices.contact_id', $contacts)
            ->toBase()
            ->selectRaw('invoices.currency, SUM(invoice_payments.amount_cents) AS collected_cents')
            ->groupBy('invoices.currency')
            ->pluck('collected_cents', 'currency');

        // Los montos no se suman entre divisas: se muestra la principal y se avisa del resto.
        $totals = collect($balances->keys())->merge($collected->keys())->unique()
            ->mapWithKeys(fn (string $currency) => [
                $currency => (int) ($collected[$currency] ?? 0) + (int) ($balances[$currency]->outstanding_cents ?? 0),
            ])
            ->filter(fn (int $total) => $total > 0)
            ->sortKeys()
            ->sortDesc();

        $currency = $totals->keys()->first()
            ?? Invoice::query()->where('invoices.tenant_id', $tenantId)->latest('id')->value('currency')
            ?? 'ARS';
        $balance = $balances[$currency] ?? null;

        return [
            'currency' => $currency,
            'other_currencies' => $totals->keys()->contains(fn (string $other) => $other !== $currency),
            'collected_cents' => (int) ($collected[$currency] ?? 0),
            'outstanding_cents' => (int) ($balance->outstanding_cents ?? 0),
            'overdue_cents' => (int) ($balance->overdue_cents ?? 0),
            'overdue_count' => (int) ($balance->overdue_count ?? 0),
        ];
    }
}
