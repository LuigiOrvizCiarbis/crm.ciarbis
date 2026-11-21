<?php

namespace App\Ai\Tools\Assistant;

use App\Ai\Tools\Assistant\Concerns\RespondsSafely;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Actividad del workspace en un período: altas, conversaciones por canal,
 * oportunidades ganadas/perdidas y tareas completadas.
 */
class ActivityStats implements Tool
{
    use RespondsSafely;

    private const PERIODS = ['hoy', 'esta-semana', 'este-mes', 'este-trimestre', 'este-ano'];

    public function __construct(private User $user) {}

    public function name(): string
    {
        return 'activity_stats';
    }

    public function description(): string
    {
        return 'Actividad del workspace en un período: contactos y conversaciones nuevas, conversaciones por canal, oportunidades creadas, ganadas y perdidas, y tareas completadas.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->enum(self::PERIODS)
                ->description('Período a analizar. Por defecto este-mes.'),
        ];
    }

    public function handle(Request $request): string
    {
        return $this->respond(function () use ($request): array {
            $period = in_array($request['period'] ?? null, self::PERIODS, true) ? $request['period'] : 'este-mes';
            [$from, $to] = $this->range($period);
            $closedInRange = fn (string $status) => Opportunity::query()->visibleTo($this->user)
                ->where('status', $status)
                ->whereBetween('closed_at', [$from, $to]);

            return [
                'period' => $period,
                'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
                'new_contacts' => Contact::query()->visibleTo($this->user)->whereBetween('contacts.created_at', [$from, $to])->count(),
                'new_conversations' => Conversation::query()->visibleTo($this->user)->whereBetween('conversations.created_at', [$from, $to])->count(),
                'conversations_by_channel' => $this->conversationsByChannel($from, $to),
                'opportunities_created' => Opportunity::query()->visibleTo($this->user)->whereBetween('opportunities.created_at', [$from, $to])->count(),
                'opportunities_won' => [
                    'count' => $closedInRange('won')->count(),
                    'value' => (float) $closedInRange('won')->sum('value'),
                ],
                'opportunities_lost' => $closedInRange('lost')->count(),
                'tasks_completed' => Task::query()->visibleTo($this->user)->whereBetween('completed_at', [$from, $to])->count(),
            ];
        });
    }

    /**
     * Mismo criterio que DashboardController::resolveRange.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(string $period): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'hoy' => [$now->startOfDay(), $now->endOfDay()],
            'esta-semana' => [$now->startOfWeek(), $now->endOfWeek()],
            'este-trimestre' => [$now->firstOfQuarter()->startOfDay(), $now->lastOfQuarter()->endOfDay()],
            'este-ano' => [$now->startOfYear(), $now->endOfYear()],
            default => [$now->startOfMonth(), $now->endOfMonth()],
        };
    }

    /**
     * Se agrupa por channel_id sin join: los scopes de visibilidad usan
     * columnas que también existen en channels (branch_id, user_id) y un join
     * las volvería ambiguas.
     *
     * @return list<array{channel: string, type: string, count: int}>
     */
    private function conversationsByChannel(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = Conversation::query()->visibleTo($this->user)
            ->whereBetween('conversations.created_at', [$from, $to])
            ->selectRaw('channel_id, count(*) as total')
            ->groupBy('channel_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $channels = Channel::query()->whereIn('id', $counts->pluck('channel_id'))->get(['id', 'name', 'type'])->keyBy('id');

        return $counts->map(fn ($row) => [
            'channel' => $channels->get($row->channel_id)?->name ?? 'Sin canal',
            'type' => $channels->has($row->channel_id) ? strtolower($channels->get($row->channel_id)->type->name) : 'unknown',
            'count' => (int) $row->total,
        ])->values()->all();
    }
}
