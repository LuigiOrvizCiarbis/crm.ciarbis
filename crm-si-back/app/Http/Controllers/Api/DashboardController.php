<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DashboardGoalsRequest;
use App\Http\Requests\Api\DashboardMetricsRequest;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Scopes\BranchScope;
use App\Models\Task;
use App\Models\Tenant;
use App\Services\Dashboard\AiImpactReport;
use App\Services\Dashboard\RevenueReport;
use App\Support\DashboardScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\JsonResponse;

class DashboardController extends Controller
{
    private const DAILY_SERIES_DAYS = 30;

    public const STALE_AFTER_DAYS = 14;

    public function metrics(DashboardMetricsRequest $request, AiImpactReport $aiImpact, RevenueReport $revenue): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('analytics.view'), 403);

        [$rangeStart, $rangeEnd] = $this->resolveRange($request->periodo());
        [$previousStart, $previousEnd] = $this->resolvePreviousRange($rangeStart, $rangeEnd);
        $ranges = ['current' => [$rangeStart, $rangeEnd], 'previous' => [$previousStart, $previousEnd]];

        $scope = $request->dashboardScope();
        $pipeline = $request->selectedPipeline();
        $stages = $this->selectedStages($scope->pipelineId);

        $kpis = $this->kpis($rangeStart, $rangeEnd, $scope);
        $previous = $this->kpis($previousStart, $previousEnd, $scope);
        $responses = $this->responseTimes($ranges, $scope);

        return response()->json([
            'pipeline' => $pipeline ? [
                'id' => $pipeline->id,
                'name' => $pipeline->name,
                'is_default' => (bool) $pipeline->is_default,
            ] : null,
            'stages' => $this->stageBreakdown($stages, $scope),
            'stale_after_days' => self::STALE_AFTER_DAYS,
            'kpis' => $kpis,
            'previous' => $previous,
            'trends' => $this->trends($kpis, $previous),
            'daily_series' => $this->dailySeries($stages, $scope),
            'omnichannel' => $this->omnichannel($rangeStart, $rangeEnd, $scope->withoutCanal()),
            'efficiency' => $this->efficiency($user->tenant, $rangeStart, $rangeEnd, $previousStart, $previousEnd, $scope, $responses['user']),
            // La IA atiende sin vendedor: el bloque mira todo el equipo, así que sólo lo ve quien puede verlo.
            'ai' => $user->can('analytics.view_team')
                ? $this->aiImpact($aiImpact, $ranges, $scope, $responses)
                : null,
            'revenue' => $revenue->build(
                $rangeStart,
                $rangeEnd,
                $request->periodo(),
                $scope,
                $user->tenant,
                $kpis['won_value'],
                $kpis['won_count'],
                $user->can('invoices.view'),
            ),
            'range' => [
                'start' => $rangeStart->toIso8601String(),
                'end' => $rangeEnd->toIso8601String(),
                'periodo' => $request->periodo(),
            ],
        ]);
    }

    /**
     * @param  array<string, array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     * @param  array{user: array, ai: array}  $responses  ya calculados con $scope
     * @return array<string, mixed>
     */
    private function aiImpact(AiImpactReport $report, array $ranges, DashboardScope $scope, array $responses): array
    {
        $aiScope = $scope->withoutOwner();
        // Sin vendedor elegido el alcance es el mismo: se reutiliza la pasada por los mensajes.
        $aiResponses = $scope->ownerId === null ? $responses : $this->responseTimes($ranges, $aiScope);

        return $report->build(
            $ranges['current'][0],
            $ranges['current'][1],
            $ranges['previous'][0],
            $ranges['previous'][1],
            $aiScope,
            $aiResponses['ai'],
            $aiResponses['user_without_ai'],
        );
    }

    public function goals(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('analytics.view'), 403);

        return response()->json(['data' => $this->goalsPayload($request->user()->tenant)]);
    }

    public function updateGoals(DashboardGoalsRequest $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $validated = $request->validated();

        $columns = [
            'response_time_minutes' => 'response_time_goal_minutes',
            'cycle_time_days' => 'cycle_time_goal_days',
            'revenue_monthly' => 'revenue_goal_monthly',
        ];

        foreach ($columns as $field => $column) {
            if (array_key_exists($field, $validated)) {
                $tenant->{$column} = $validated[$field];
            }
        }

        $tenant->save();

        return response()->json(['data' => $this->goalsPayload($tenant)]);
    }

    public function branches(DashboardMetricsRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isTenantOwner() && ! $user->can('branches.view_all') && ! $user->can('branches.manage')) {
            abort(403);
        }

        [$from, $to] = $this->resolveRange($request->periodo());
        // Cada fila ya es una sucursal: el filtro de sucursal no aplica acá.
        $scope = $request->dashboardScope()->withoutBranch();
        $tenantId = $user->tenant_id;

        $cacheKey = "branches.dashboard.{$tenantId}.".md5((string) json_encode([$request->periodo(), get_object_vars($scope)]));
        $data = Cache::remember($cacheKey, 60, fn () => $this->branchComparison($tenantId, $from, $to, $scope));

        return response()->json([
            'data' => $data,
            'meta' => ['periodo' => $request->periodo(), 'pipeline_id' => $scope->pipelineId],
        ]);
    }

    /**
     * Mismos criterios que los KPIs del Panel, abiertos por sucursal: así la tabla
     * explica los totales de arriba (salvo lo que no tiene sucursal).
     *
     * @return list<array<string, mixed>>
     */
    private function branchComparison(int $tenantId, CarbonImmutable $from, CarbonImmutable $to, DashboardScope $scope): array
    {
        // La comparación cruza sucursales: se saltea el BranchScope y se acota a mano por tenant.
        $contacts = $scope->contacts(Contact::withoutGlobalScope(BranchScope::class)->where('tenant_id', $tenantId))
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('branch_id, COUNT(*) as total')
            ->groupBy('branch_id')
            ->pluck('total', 'branch_id');

        $conversations = $scope->conversations(Conversation::withoutGlobalScope(BranchScope::class)->where('tenant_id', $tenantId))
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('branch_id, COUNT(*) as total')
            ->groupBy('branch_id')
            ->pluck('total', 'branch_id');

        $opportunitiesQuery = $scope->opportunities(Opportunity::withoutGlobalScope(BranchScope::class)->where('tenant_id', $tenantId));
        $this->applyOpportunityRange($opportunitiesQuery, $from, $to);
        $opportunities = $opportunitiesQuery
            ->selectRaw('branch_id, COUNT(*) as total, COALESCE(SUM(value), 0) as value_sum')
            ->groupBy('branch_id')
            ->toBase()
            ->get()
            ->keyBy('branch_id');

        // Ganadas por fecha de cierre, como won_count en los KPIs.
        $won = $scope->opportunities(Opportunity::withoutGlobalScope(BranchScope::class)->where('tenant_id', $tenantId))
            ->where('status', 'won')
            ->whereBetween('closed_at', [$from, $to])
            ->selectRaw('branch_id, COUNT(*) as total')
            ->groupBy('branch_id')
            ->pluck('total', 'branch_id');

        return Branch::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'is_active' => (bool) $branch->is_active,
                'contacts_new' => (int) ($contacts[$branch->id] ?? 0),
                'conversations' => (int) ($conversations[$branch->id] ?? 0),
                'opportunities_count' => (int) ($opportunities[$branch->id]->total ?? 0),
                'opportunities_value' => (float) ($opportunities[$branch->id]->value_sum ?? 0),
                'opportunities_won' => (int) ($won[$branch->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * Etapas del pipeline elegido o, con todos, de cada pipeline: primero el
     * predeterminado y luego por nombre, para que el embudo quede agrupado.
     *
     * @return Collection<int, PipelineStage>
     */
    private function selectedStages(?int $pipelineId): Collection
    {
        return PipelineStage::query()
            ->join('pipelines', 'pipelines.id', '=', 'pipeline_stages.pipeline_id')
            ->select('pipeline_stages.*', 'pipelines.name as pipeline_name')
            ->when($pipelineId !== null, fn (Builder $query) => $query->where('pipeline_stages.pipeline_id', $pipelineId))
            ->orderByDesc('pipelines.is_default')
            ->orderBy('pipelines.name')
            ->orderBy('pipelines.id')
            ->orderBy('pipeline_stages.sort_order')
            ->orderBy('pipeline_stages.id')
            ->get();
    }

    /**
     * @param  Collection<int, PipelineStage>  $stages
     * @return array<int, array<string, mixed>>
     */
    private function stageBreakdown(Collection $stages, DashboardScope $scope): array
    {
        // Sin actividad registrada, la última edición es la mejor señal de vida.
        $staleBefore = CarbonImmutable::now()->subDays(self::STALE_AFTER_DAYS)->toDateTimeString();

        return $stages->map(function (PipelineStage $stage) use ($scope, $staleBefore) {
            $opportunityQuery = $scope->opportunities(Opportunity::query()->where('pipeline_stage_id', $stage->id));

            $conversationQuery = $scope->conversations(Conversation::query()
                ->where('pipeline_stage_id', $stage->id)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('opportunities')
                        ->whereColumn('opportunities.conversation_id', 'conversations.id');
                }));

            $count = (clone $opportunityQuery)->count() + (clone $conversationQuery)->count();
            $value = (clone $opportunityQuery)->sum('value');

            $stale = "status = 'open' AND COALESCE(last_activity_at, updated_at) < ?";
            $open = (clone $opportunityQuery)
                ->toBase()
                ->selectRaw("COALESCE(SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END), 0) AS open_count")
                ->selectRaw("COALESCE(SUM(CASE WHEN status = 'open' THEN COALESCE(value, 0) ELSE 0 END), 0) AS open_value")
                ->selectRaw("COALESCE(SUM(CASE WHEN {$stale} THEN 1 ELSE 0 END), 0) AS stale_count", [$staleBefore])
                ->selectRaw("COALESCE(SUM(CASE WHEN {$stale} THEN COALESCE(value, 0) ELSE 0 END), 0) AS stale_value", [$staleBefore])
                ->first();

            return [
                'id' => $stage->id,
                'name' => $stage->name,
                'sort_order' => $stage->sort_order,
                'pipeline_id' => (int) $stage->pipeline_id,
                'pipeline_name' => (string) $stage->getAttribute('pipeline_name'),
                'count' => $count,
                'value' => (float) $value,
                'open_count' => (int) $open->open_count,
                'open_value' => (float) $open->open_value,
                'stale_count' => (int) $open->stale_count,
                'stale_value' => (float) $open->stale_value,
            ];
        })->all();
    }

    /**
     * Las ganadas cuentan por fecha de cierre: una oportunidad vieja ganada en el
     * período suma, una con actividad reciente pero cerrada antes no.
     *
     * @return array<string, mixed>
     */
    private function kpis(CarbonImmutable $from, CarbonImmutable $to, DashboardScope $scope): array
    {
        $opportunityQuery = $scope->opportunities(Opportunity::query());
        $this->applyOpportunityRange($opportunityQuery, $from, $to);

        $totalOpportunities = (clone $opportunityQuery)->count();
        $pipelineValue = (float) (clone $opportunityQuery)->sum('value');
        $avgValue = $totalOpportunities > 0 ? $pipelineValue / $totalOpportunities : 0.0;

        $wonOpportunitiesQuery = $scope->opportunities(Opportunity::query()
            ->where('status', 'won')
            ->whereBetween('closed_at', [$from, $to]));
        $wonCount = (clone $wonOpportunitiesQuery)->count();
        $wonValue = (float) (clone $wonOpportunitiesQuery)->sum('value');

        // weighted_value conserva su definición: la tasa sale de las ganadas con actividad en el período.
        $activeWonCount = (clone $opportunityQuery)->where('status', 'won')->count();
        $weightedValue = $totalOpportunities > 0 ? $pipelineValue * ($activeWonCount / $totalOpportunities) : 0.0;

        $conversationsQuery = $scope->conversations(Conversation::query()->whereBetween('created_at', [$from, $to]));
        $totalConversations = (clone $conversationsQuery)->count();

        $totalContacts = $scope->contacts(Contact::query()->whereBetween('created_at', [$from, $to]))->count();

        $conversionRate = $totalConversations > 0 ? ($wonCount / $totalConversations) * 100 : 0.0;

        return [
            'total_opportunities' => $totalOpportunities,
            'pipeline_value' => $pipelineValue,
            'weighted_value' => round($weightedValue, 2),
            'avg_value' => round($avgValue, 2),
            'total_conversations' => $totalConversations,
            'total_contacts' => $totalContacts,
            'won_count' => $wonCount,
            'won_value' => $wonValue,
            'avg_ticket' => $wonCount > 0 ? round($wonValue / $wonCount, 2) : 0.0,
            'global_conversion_rate' => round($conversionRate, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, float>
     */
    private function trends(array $current, array $previous): array
    {
        $keys = ['total_opportunities', 'pipeline_value', 'total_conversations', 'total_contacts', 'won_count', 'won_value', 'avg_ticket', 'global_conversion_rate'];
        $trends = [];

        foreach ($keys as $key) {
            $now = (float) ($current[$key] ?? 0);
            $prev = (float) ($previous[$key] ?? 0);

            if ($prev <= 0) {
                $trends[$key] = $now > 0 ? 100.0 : 0.0;

                continue;
            }

            $trends[$key] = round((($now - $prev) / $prev) * 100, 1);
        }

        return $trends;
    }

    /**
     * @param  Collection<int, PipelineStage>  $stages
     * @return array<int, array<string, mixed>>
     */
    private function dailySeries(Collection $stages, DashboardScope $scope): array
    {
        $stagesById = $stages->keyBy('id');

        $start = CarbonImmutable::now()->subDays(self::DAILY_SERIES_DAYS - 1)->startOfDay();
        $end = CarbonImmutable::now()->endOfDay();

        $base = $scope->opportunities(Opportunity::query()->whereBetween('created_at', [$start, $end]));

        $rows = (clone $base)
            ->selectRaw('DATE(created_at) as day, pipeline_stage_id, COUNT(*) as total')
            ->groupBy('day', 'pipeline_stage_id')
            ->get();

        $series = [];
        $cursor = $start;
        while ($cursor->lessThanOrEqualTo($end)) {
            $day = $cursor->toDateString();
            $entry = ['date' => $day];
            foreach ($stages as $stage) {
                $entry[$this->stageKey($stage->name)] = 0;
            }
            $series[$day] = $entry;
            $cursor = $cursor->addDay();
        }

        foreach ($rows as $row) {
            $stage = $stagesById->get($row->pipeline_stage_id);
            if (! $stage) {
                continue;
            }
            $key = $this->stageKey($stage->name);
            // Con todos los pipelines, etapas homónimas comparten clave: se suman.
            if (isset($series[$row->day])) {
                $series[$row->day][$key] += (int) $row->total;
            }
        }

        return array_values($series);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function omnichannel(CarbonImmutable $from, CarbonImmutable $to, DashboardScope $scope): array
    {
        $query = $scope->conversations(Conversation::query())
            ->selectRaw('channel_id, COUNT(*) as total')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('channel_id')
            ->with(['channel:id,name,type']);

        return $query->get()->map(function (Conversation $row) {
            return [
                'channel_id' => $row->channel_id,
                'channel_name' => $row->channel?->name,
                'channel_type' => $row->channel?->type,
                'conversations_count' => (int) $row->total,
            ];
        })->all();
    }

    /**
     * @param  array{current: array{sum: int, count: int}, previous: array{sum: int, count: int}}  $response  turnos de vendedores
     * @return array<string, array<string, mixed>>
     */
    private function efficiency(
        Tenant $tenant,
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $previousFrom,
        CarbonImmutable $previousTo,
        DashboardScope $scope,
        array $response,
    ): array {
        $cycle = $this->cycleTime($from, $to, $scope);
        $previousCycle = $this->cycleTime($previousFrom, $previousTo, $scope);

        $tasks = $this->taskCompletion($from, $to, $scope);
        $previousTasks = $this->taskCompletion($previousFrom, $previousTo, $scope);

        return [
            'response_time' => [
                'avg_minutes' => $this->average($response['current']['sum'] / 60, $response['current']['count']),
                'previous_avg_minutes' => $this->average($response['previous']['sum'] / 60, $response['previous']['count']),
                'samples' => $response['current']['count'],
                'goal_minutes' => $tenant->response_time_goal_minutes,
            ],
            'cycle_time' => [
                'avg_days' => $this->average($cycle['sum'], $cycle['count']),
                'previous_avg_days' => $this->average($previousCycle['sum'], $previousCycle['count']),
                'samples' => $cycle['count'],
                'goal_days' => $tenant->cycle_time_goal_days,
            ],
            'tasks' => [
                'completed' => $tasks['completed'],
                'total' => $tasks['total'],
                'previous_completed' => $previousTasks['completed'],
                'previous_total' => $previousTasks['total'],
            ],
        ];
    }

    /**
     * Tiempo de respuesta por turno: arranca con el primer mensaje del contacto sin
     * respuesta y cierra con la siguiente respuesta. Se miden tres turnos en paralelo:
     * - `user`: lo cierra un vendedor; los mensajes de sistema no lo cierran ni reinician.
     * - `ai`: lo cierra una respuesta de texto de la IA. Si contesta antes un vendedor,
     *   el turno se descarta (las plantillas de automatizaciones no cuentan).
     * - `user_without_ai`: como `user`, pero se descarta si la IA contestó antes. Es la
     *   comparación justa con la IA: el vendedor no carga el tiempo que la IA ya atendió.
     * No considera horario laboral: cuenta tiempo corrido.
     *
     * @param  array<string, array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     * @return array<'user'|'ai'|'user_without_ai', array<string, array{sum: int, count: int}>> segundos y turnos por rango
     */
    private function responseTimes(array $ranges, DashboardScope $scope): array
    {
        $bounds = array_map(fn (array $range) => [$range[0]->getTimestamp(), $range[1]->getTimestamp()], $ranges);
        $empty = array_map(fn () => ['sum' => 0, 'count' => 0], $ranges);
        $totals = ['user' => $empty, 'ai' => $empty, 'user_without_ai' => $empty];

        $conversations = $scope->conversations(Conversation::query()->select('conversations.id'));

        // Sin tope superior: la respuesta puede llegar después del fin del rango.
        $rows = Message::query()
            ->toBase()
            ->select(['id', 'conversation_id', 'sender_type', 'direction', 'message_type', 'created_at'])
            ->whereIn('conversation_id', $conversations->toBase())
            ->where('created_at', '>=', min(array_column($ranges, 0)))
            ->orderBy('conversation_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor();

        $conversationId = null;
        $userTurn = null;
        $aiTurn = null;
        $unassistedTurn = null;

        foreach ($rows as $row) {
            if ((int) $row->conversation_id !== $conversationId) {
                $conversationId = (int) $row->conversation_id;
                $userTurn = $aiTurn = $unassistedTurn = null;
            }

            $at = strtotime($row->created_at);

            if ($row->sender_type === SenderType::CONTACT->value && $row->direction === MessageDirection::INBOUND->value) {
                $userTurn ??= $at;
                $aiTurn ??= $at;
                $unassistedTurn ??= $at;

                continue;
            }

            if ($row->direction !== MessageDirection::OUTBOUND->value) {
                continue;
            }

            if ($row->sender_type === SenderType::USER->value) {
                $this->closeTurn($totals['user'], $bounds, $userTurn, $at);
                $this->closeTurn($totals['user_without_ai'], $bounds, $unassistedTurn, $at);
                $userTurn = $aiTurn = $unassistedTurn = null;
            } elseif ($row->sender_type === SenderType::SYSTEM->value && $row->message_type === MessageType::Text->value) {
                $this->closeTurn($totals['ai'], $bounds, $aiTurn, $at);
                $aiTurn = $unassistedTurn = null;
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, array{sum: int, count: int}>  $totals
     * @param  array<string, array{0: int, 1: int}>  $bounds
     */
    private function closeTurn(array &$totals, array $bounds, ?int $turnStart, int $repliedAt): void
    {
        if ($turnStart === null) {
            return;
        }

        foreach ($bounds as $key => [$start, $end]) {
            if ($turnStart >= $start && $turnStart <= $end) {
                $totals[$key]['sum'] += $repliedAt - $turnStart;
                $totals[$key]['count']++;

                return;
            }
        }
    }

    /**
     * @return array{sum: float, count: int} días acumulados y oportunidades ganadas
     */
    private function cycleTime(CarbonImmutable $from, CarbonImmutable $to, DashboardScope $scope): array
    {
        $query = $scope->opportunities(Opportunity::query()
            ->where('status', 'won')
            ->whereBetween('closed_at', [$from, $to]));

        $sum = 0.0;
        $count = 0;

        foreach ($query->toBase()->select(['opportunities.created_at', 'opportunities.closed_at'])->cursor() as $row) {
            $sum += (strtotime($row->closed_at) - strtotime($row->created_at)) / 86400;
            $count++;
        }

        return ['sum' => $sum, 'count' => $count];
    }

    /**
     * @return array{completed: int, total: int}
     */
    private function taskCompletion(CarbonImmutable $from, CarbonImmutable $to, DashboardScope $scope): array
    {
        $query = $scope->tasks(Task::query()
            ->whereBetween('deadline', [$from, $to])
            ->where('status', '!=', TaskStatus::CANCELED->value));

        $row = $query->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS completed', [TaskStatus::DONE->value])
            ->first();

        return ['completed' => (int) $row->completed, 'total' => (int) $row->total];
    }

    private function average(float|int $sum, int $count): ?float
    {
        return $count > 0 ? round($sum / $count, 1) : null;
    }

    /**
     * @return array{response_time_minutes: int|null, cycle_time_days: int|null, revenue_monthly: float|null}
     */
    private function goalsPayload(Tenant $tenant): array
    {
        return [
            'response_time_minutes' => $tenant->response_time_goal_minutes,
            'cycle_time_days' => $tenant->cycle_time_goal_days,
            'revenue_monthly' => $tenant->revenue_goal_monthly !== null ? (float) $tenant->revenue_goal_monthly : null,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveRange(string $periodo): array
    {
        $now = CarbonImmutable::now();

        return match ($periodo) {
            'hoy' => [$now->startOfDay(), $now->endOfDay()],
            'esta-semana' => [$now->startOfWeek(), $now->endOfWeek()],
            'este-trimestre' => [$now->firstOfQuarter()->startOfDay(), $now->lastOfQuarter()->endOfDay()],
            'este-ano' => [$now->startOfYear(), $now->endOfYear()],
            default => [$now->startOfMonth(), $now->endOfMonth()],
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolvePreviousRange(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $diffDays = $start->diffInDays($end) + 1;
        $previousEnd = $start->subSecond();
        $previousStart = $previousEnd->subDays((int) $diffDays - 1)->startOfDay();

        return [$previousStart, $previousEnd];
    }

    private function applyOpportunityRange(Builder $query, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $query->where(function (Builder $builder) use ($from, $to): void {
            $builder->whereBetween('last_activity_at', [$from, $to])
                ->orWhereBetween('created_at', [$from, $to]);
        });
    }

    private function stageKey(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?: 'stage';

        return trim($slug, '_');
    }
}
