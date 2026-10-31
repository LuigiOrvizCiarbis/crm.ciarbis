<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DashboardMetricsRequest;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Scopes\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\JsonResponse;

class DashboardController extends Controller
{
    private const KPI_STAGES_LIMIT = 6;

    private const DAILY_SERIES_DAYS = 30;

    public function metrics(DashboardMetricsRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('analytics.view'), 403);
        $canViewTeam = $user->can('analytics.view_team');

        [$rangeStart, $rangeEnd] = $this->resolveRange($request->periodo());
        [$previousStart, $previousEnd] = $this->resolvePreviousRange($rangeStart, $rangeEnd);

        $canalId = $request->canalId();
        $ownerId = $request->ownerId();

        $effectiveOwnerId = $canViewTeam ? $ownerId : $user->id;

        $stages = $this->stageBreakdown($canalId, $effectiveOwnerId);
        $kpis = $this->kpis($rangeStart, $rangeEnd, $canalId, $effectiveOwnerId);
        $previous = $this->kpis($previousStart, $previousEnd, $canalId, $effectiveOwnerId);

        return response()->json([
            'stages' => $stages,
            'kpis' => $kpis,
            'previous' => $previous,
            'trends' => $this->trends($kpis, $previous),
            'daily_series' => $this->dailySeries($canalId, $effectiveOwnerId),
            'omnichannel' => $this->omnichannel($rangeStart, $rangeEnd, $effectiveOwnerId),
            'range' => [
                'start' => $rangeStart->toIso8601String(),
                'end' => $rangeEnd->toIso8601String(),
                'periodo' => $request->periodo(),
            ],
        ]);
    }

    public function branches(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isTenantOwner() && ! $user->can('branches.view_all') && ! $user->can('branches.manage')) {
            abort(403);
        }

        $rango = (int) $request->query('rango', 30);
        if (! in_array($rango, [7, 30, 90], true)) {
            $rango = 30;
        }

        $tenantId = $user->tenant_id;
        $cacheKey = "branches.dashboard.{$tenantId}.{$rango}";

        $data = Cache::remember($cacheKey, 60, function () use ($tenantId, $rango) {
            $since = CarbonImmutable::now()->subDays($rango);

            return Branch::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get()
                ->map(fn (Branch $branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'is_active' => (bool) $branch->is_active,
                    'contacts_total' => Contact::withoutGlobalScope(BranchScope::class)
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branch->id)
                        ->count(),
                    'conversations_open' => Conversation::withoutGlobalScope(BranchScope::class)
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branch->id)
                        ->where('status', 'open')
                        ->count(),
                    'opportunities_count' => Opportunity::withoutGlobalScope(BranchScope::class)
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branch->id)
                        ->where('created_at', '>=', $since)
                        ->count(),
                    'opportunities_value' => (float) Opportunity::withoutGlobalScope(BranchScope::class)
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branch->id)
                        ->where('created_at', '>=', $since)
                        ->sum('value'),
                    'opportunities_won' => Opportunity::withoutGlobalScope(BranchScope::class)
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branch->id)
                        ->where('status', 'won')
                        ->where('created_at', '>=', $since)
                        ->count(),
                ])
                ->all();
        });

        return response()->json([
            'data' => $data,
            'meta' => ['rango' => $rango],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stageBreakdown(?int $canalId, ?int $ownerId): array
    {
        $stages = PipelineStage::query()
            ->whereHas('pipeline', fn ($query) => $query->where('is_default', true))
            ->orderBy('sort_order')
            ->limit(self::KPI_STAGES_LIMIT)
            ->get();

        return $stages->map(function (PipelineStage $stage) use ($canalId, $ownerId) {
            $opportunityQuery = Opportunity::query()
                ->where('pipeline_stage_id', $stage->id);

            $this->applyOwner($opportunityQuery, $ownerId);
            $this->applyCanalToOpportunities($opportunityQuery, $canalId);

            $conversationQuery = Conversation::query()
                ->where('pipeline_stage_id', $stage->id)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('opportunities')
                        ->whereColumn('opportunities.conversation_id', 'conversations.id');
                });

            $this->applyConversationOwner($conversationQuery, $ownerId);
            $this->applyConversationCanal($conversationQuery, $canalId);

            $count = (clone $opportunityQuery)->count() + (clone $conversationQuery)->count();
            $value = (clone $opportunityQuery)->sum('value');

            return [
                'id' => $stage->id,
                'name' => $stage->name,
                'sort_order' => $stage->sort_order,
                'count' => $count,
                'value' => (float) $value,
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function kpis(CarbonImmutable $from, CarbonImmutable $to, ?int $canalId, ?int $ownerId): array
    {
        $opportunityQuery = Opportunity::query();
        $this->applyOwner($opportunityQuery, $ownerId);
        $this->applyCanalToOpportunities($opportunityQuery, $canalId);
        $this->applyOpportunityRange($opportunityQuery, $from, $to);

        $totalOpportunities = (clone $opportunityQuery)->count();
        $pipelineValue = (float) (clone $opportunityQuery)->sum('value');
        $avgValue = $totalOpportunities > 0 ? $pipelineValue / $totalOpportunities : 0.0;

        $wonOpportunitiesQuery = (clone $opportunityQuery)->where('status', 'won');
        $wonCount = (clone $wonOpportunitiesQuery)->count();
        $wonValue = (float) (clone $wonOpportunitiesQuery)->sum('value');

        $weightedValue = $totalOpportunities > 0 ? $pipelineValue * ($wonCount / max($totalOpportunities, 1)) : 0.0;

        $conversationsQuery = Conversation::query()->whereBetween('created_at', [$from, $to]);
        $this->applyConversationOwner($conversationsQuery, $ownerId);
        $this->applyConversationCanal($conversationsQuery, $canalId);
        $totalConversations = (clone $conversationsQuery)->count();

        $contactsQuery = Contact::query()->whereBetween('created_at', [$from, $to]);
        $totalContacts = (clone $contactsQuery)->count();

        $conversionRate = $totalConversations > 0 ? ($wonCount / $totalConversations) * 100 : 0.0;
        $roi = $pipelineValue > 0 ? ($wonValue / $pipelineValue) * 100 : 0.0;

        return [
            'total_opportunities' => $totalOpportunities,
            'pipeline_value' => $pipelineValue,
            'weighted_value' => round($weightedValue, 2),
            'avg_value' => round($avgValue, 2),
            'total_conversations' => $totalConversations,
            'total_contacts' => $totalContacts,
            'won_count' => $wonCount,
            'won_value' => $wonValue,
            'global_conversion_rate' => round($conversionRate, 2),
            'avg_roi' => round($roi, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, float>
     */
    private function trends(array $current, array $previous): array
    {
        $keys = ['total_opportunities', 'pipeline_value', 'total_conversations', 'total_contacts', 'won_count', 'won_value', 'global_conversion_rate', 'avg_roi'];
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
     * @return array<int, array<string, mixed>>
     */
    private function dailySeries(?int $canalId, ?int $ownerId): array
    {
        $stages = PipelineStage::query()
            ->whereHas('pipeline', fn ($query) => $query->where('is_default', true))
            ->orderBy('sort_order')
            ->limit(self::KPI_STAGES_LIMIT)
            ->get(['id', 'name']);

        $start = CarbonImmutable::now()->subDays(self::DAILY_SERIES_DAYS - 1)->startOfDay();
        $end = CarbonImmutable::now()->endOfDay();

        $base = Opportunity::query()->whereBetween('created_at', [$start, $end]);
        $this->applyOwner($base, $ownerId);
        $this->applyCanalToOpportunities($base, $canalId);

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
            $stage = $stages->firstWhere('id', $row->pipeline_stage_id);
            if (! $stage) {
                continue;
            }
            $key = $this->stageKey($stage->name);
            if (isset($series[$row->day])) {
                $series[$row->day][$key] = (int) $row->total;
            }
        }

        return array_values($series);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function omnichannel(CarbonImmutable $from, CarbonImmutable $to, ?int $ownerId): array
    {
        $query = Conversation::query()
            ->selectRaw('channel_id, COUNT(*) as total')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('channel_id')
            ->with(['channel:id,name,type']);

        $this->applyConversationOwner($query, $ownerId);

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

    private function applyOwner(Builder $query, ?int $ownerId): void
    {
        if ($ownerId !== null) {
            $query->where('assigned_to', $ownerId);
        }
    }

    private function applyConversationOwner(Builder $query, ?int $ownerId): void
    {
        if ($ownerId !== null) {
            $query->where('assigned_to', $ownerId);
        }
    }

    private function applyCanalToOpportunities(Builder $query, ?int $canalId): void
    {
        if ($canalId === null) {
            return;
        }

        $query->whereHas('conversation', function (Builder $builder) use ($canalId): void {
            $builder->where('channel_id', $canalId);
        });
    }

    private function applyConversationCanal(Builder $query, ?int $canalId): void
    {
        if ($canalId !== null) {
            $query->where('channel_id', $canalId);
        }
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
