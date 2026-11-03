<?php

namespace App\Services\Dashboard;

use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Models\AiConfig;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\HumanHandoff;
use App\Models\Message;
use App\Support\DashboardScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bloque "IA y automatizaciones" del Panel: cuánto absorbe la IA y dónde falla,
 * ella o las automatizaciones.
 */
final class AiImpactReport
{
    private const LIST_LIMIT = 3;

    private const ACTIVE_HANDOFF = ['pending', 'acknowledged'];

    /**
     * @param  array{current: array{sum: int, count: int}}  $aiResponses  turnos que cerró la IA
     * @param  array{current: array{sum: int, count: int}}  $humanResponses  turnos que cerró un vendedor sin que la IA contestara antes
     * @return array<string, mixed>
     */
    public function build(
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $previousFrom,
        CarbonImmutable $previousTo,
        DashboardScope $scope,
        array $aiResponses,
        array $humanResponses,
    ): array {
        return [
            'ai_enabled' => AiConfig::query()->where('enabled', true)->exists(),
            'automations_active' => AutomationRule::query()->where('status', AutomationRuleStatus::Active)->exists(),
            'conversations' => $this->outcomes($from, $to, $scope),
            'previous_conversations' => $this->outcomes($previousFrom, $previousTo, $scope),
            'response_time' => [
                'ai_avg_seconds' => $this->averageSeconds($aiResponses['current']),
                'human_avg_seconds' => $this->averageSeconds($humanResponses['current']),
                'ai_samples' => $aiResponses['current']['count'],
            ],
            'handoffs' => $this->handoffs($from, $to, $scope),
            'automations' => $this->automations($from, $to, $scope),
        ];
    }

    /**
     * Conversaciones en las que respondió la IA, según cómo terminaron en el período.
     * "Sin vendedor" no prueba que se resolvió: sólo que ninguna persona intervino.
     * Una derivación pesa más que una respuesta humana: si hubo ambas, cuenta como derivada.
     *
     * @return array{total: int, ai_only: int, with_human: int, handed_off: int}
     */
    private function outcomes(CarbonImmutable $from, CarbonImmutable $to, DashboardScope $scope): array
    {
        $attended = $scope->conversations(Conversation::query())
            ->whereExists($this->replies($from, $to, SenderType::SYSTEM)->where('messages.message_type', MessageType::Text->value));

        $handoff = HumanHandoff::query()
            ->whereColumn('human_handoffs.conversation_id', 'conversations.id')
            ->whereBetween('human_handoffs.created_at', [$from, $to]);

        $total = (clone $attended)->count();
        $handedOff = (clone $attended)->whereExists($handoff)->count();
        $withHuman = (clone $attended)->whereNotExists($handoff)->whereExists($this->replies($from, $to, SenderType::USER))->count();

        return [
            'total' => $total,
            'ai_only' => $total - $handedOff - $withHuman,
            'with_human' => $withHuman,
            'handed_off' => $handedOff,
        ];
    }

    private function replies(CarbonImmutable $from, CarbonImmutable $to, SenderType $sender): Builder
    {
        return Message::query()
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->where('messages.sender_type', $sender->value)
            ->where('messages.direction', MessageDirection::OUTBOUND->value)
            ->whereBetween('messages.created_at', [$from, $to]);
    }

    /**
     * Una derivación queda atendida con el "Tomar" o con la primera respuesta de un
     * vendedor, lo que pase antes. No alcanza con el estado: las respuestas desde el
     * celular o anteriores al cierre automático no siempre lo actualizaron.
     * Las que esperan se listan sin importar el período: son trabajo pendiente.
     *
     * @return array<string, mixed>
     */
    private function handoffs(CarbonImmutable $from, CarbonImmutable $to, DashboardScope $scope): array
    {
        $inRange = $scope->handoffs(HumanHandoff::query())
            ->whereBetween('created_at', [$from, $to])
            ->where('status', '!=', 'cancelled');

        $waitSeconds = 0;
        $attended = 0;
        $rows = (clone $inRange)->toBase()
            ->select(['created_at', 'acknowledged_at', 'resolved_at'])
            ->selectSub($this->humanRepliesAfterHandoff()->selectRaw('MIN(messages.created_at)'), 'first_reply_at')
            ->cursor();
        foreach ($rows as $row) {
            $taken = array_filter([$row->acknowledged_at, $row->first_reply_at]);
            $takenAt = $taken ? min(array_map('strtotime', $taken)) : ($row->resolved_at ? strtotime($row->resolved_at) : null);
            if ($takenAt === null) {
                continue;
            }
            $waitSeconds += max(0, $takenAt - strtotime($row->created_at));
            $attended++;
        }

        $waiting = $scope->handoffs(HumanHandoff::query())
            ->whereIn('status', self::ACTIVE_HANDOFF)
            ->whereNotExists($this->humanRepliesAfterHandoff());

        return [
            'total' => (clone $inRange)->count(),
            'avg_minutes_to_attend' => $attended > 0 ? round($waitSeconds / $attended / 60, 1) : null,
            'waiting_count' => (clone $waiting)->count(),
            'waiting' => $waiting
                ->with('conversation.contact:id,name')
                ->oldest()
                ->limit(self::LIST_LIMIT)
                ->get()
                ->map(fn (HumanHandoff $handoff) => [
                    'id' => $handoff->id,
                    'conversation_id' => $handoff->conversation_id,
                    'contact_name' => $handoff->conversation?->contact?->name,
                    // El código por defecto no dice nada: el front muestra un texto propio.
                    'motive' => filled($handoff->summary) ? $handoff->summary
                        : ($handoff->reason === 'customer_requested_human' ? null : $handoff->reason),
                    'created_at' => $handoff->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /** Respuestas de un vendedor en la conversación posteriores a la derivación. */
    private function humanRepliesAfterHandoff(): Builder
    {
        return Message::query()
            ->whereColumn('messages.conversation_id', 'human_handoffs.conversation_id')
            ->whereColumn('messages.created_at', '>', 'human_handoffs.created_at')
            ->where('messages.sender_type', SenderType::USER->value)
            ->where('messages.direction', MessageDirection::OUTBOUND->value);
    }

    /**
     * Las ejecuciones se podan a los RETENTION_DAYS: un período más largo se acota y
     * se avisa, para no mostrar como completo lo que ya no está.
     *
     * @return array<string, mixed>
     */
    private function automations(CarbonImmutable $from, CarbonImmutable $to, DashboardScope $scope): array
    {
        $retained = CarbonImmutable::now()->subDays(AutomationRun::RETENTION_DAYS);
        $limited = $from->lessThan($retained);

        $finished = $scope->automationRuns(AutomationRun::query())->whereBetween('finished_at', [$limited ? $retained : $from, $to]);
        // "Para revisar" también necesita que alguien actúe: cuenta como fallo.
        $failed = (clone $finished)->whereIn('status', [AutomationRunStatus::Failed, AutomationRunStatus::NeedsReview]);
        $topRules = (clone $failed)
            ->whereNotNull('automation_rule_id')
            ->toBase()
            ->select('automation_rule_id')
            ->selectRaw('COUNT(*) AS failures')
            ->selectRaw('MAX(finished_at) AS last_failed_at')
            ->groupBy('automation_rule_id')
            ->orderByDesc('failures')
            ->orderByDesc('last_failed_at')
            ->limit(self::LIST_LIMIT)
            ->get();

        $names = AutomationRule::query()->whereIn('id', $topRules->pluck('automation_rule_id'))->pluck('name', 'id');

        return [
            'limited_to_days' => $limited ? AutomationRun::RETENTION_DAYS : null,
            'succeeded' => (clone $finished)->where('status', AutomationRunStatus::Succeeded)->count(),
            'failed' => (clone $failed)->count(),
            'failing_rules' => $topRules
                ->filter(fn ($row) => $names->has($row->automation_rule_id))
                ->map(fn ($row) => [
                    'id' => (int) $row->automation_rule_id,
                    'name' => $names[$row->automation_rule_id],
                    'failures' => (int) $row->failures,
                    'last_failed_at' => CarbonImmutable::parse($row->last_failed_at)->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @param  array{sum: int, count: int}  $totals */
    private function averageSeconds(array $totals): ?int
    {
        return $totals['count'] > 0 ? (int) round($totals['sum'] / $totals['count']) : null;
    }
}
