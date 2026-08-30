<?php

namespace App\Services;

use App\Enums\TemplateCategory;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BroadcastAudienceService
{
    public function __construct(
        private readonly UnitedStatesPhoneDetector $usPhoneDetector = new UnitedStatesPhoneDetector,
    ) {}

    /**
     * Resuelve la audiencia y descarta los destinos a los que Meta no va a
     * entregar la plantilla, para no consumir cupo del límite de 24h en
     * mensajes que nacen muertos.
     *
     * @param  array{pipeline_stage_id?: int|null, tag_ids?: list<int>, custom_filters?: list<array{field: string, operator: string, value: mixed}>}  $filters
     * @return array{audience: Collection<int, Conversation>, excluded_us_count: int}
     */
    public function resolveForCategory(User $user, int $channelId, array $filters, ?TemplateCategory $category): array
    {
        // Solo marketing está bloqueado hacia EE.UU.; utility y authentication
        // se entregan con normalidad, así que no se toca la audiencia.
        if ($category !== TemplateCategory::Marketing) {
            return ['audience' => $this->resolve($user, $channelId, $filters), 'excluded_us_count' => 0];
        }

        $max = (int) config('broadcasts.max_recipients');
        $query = $this->buildQuery($user, $channelId, $filters);

        // El tope de destinatarios se aplica sobre los que SÍ pueden recibir el
        // mensaje. Limitar antes de descartar los de EE.UU. desperdiciaría el
        // cupo — con suficientes números estadounidenses en las primeras filas
        // la campaña saldría corta, o vacía, aun habiendo elegibles después.
        // La detección depende del código de área, que no se puede resolver en
        // SQL, así que se pagina hasta llenar el cupo.
        $audience = new Collection;
        $excluded = 0;
        $lastId = 0;

        do {
            $page = (clone $query)->where('id', '>', $lastId)->limit($max)->get();

            if ($page->isEmpty()) {
                break;
            }

            $lastId = (int) $page->last()->id;

            foreach ($page as $conversation) {
                if ($this->usPhoneDetector->isUnitedStates($conversation->contact?->phone)) {
                    $excluded++;

                    continue;
                }

                $audience->push($conversation);

                if ($audience->count() >= $max) {
                    break 2;
                }
            }
        } while ($page->count() === $max);

        return [
            'audience' => $audience->values(),
            'excluded_us_count' => $excluded,
        ];
    }

    /**
     * @param  array{pipeline_stage_id?: int|null, tag_ids?: list<int>, custom_filters?: list<array{field: string, operator: string, value: mixed}>}  $filters
     * @return Collection<int, Conversation>
     */
    public function resolve(User $user, int $channelId, array $filters): Collection
    {
        return $this->buildQuery($user, $channelId, $filters)
            ->limit((int) config('broadcasts.max_recipients'))
            ->get();
    }

    /**
     * Query base de la audiencia, sin tope: los llamadores deciden cuántas
     * filas traer según necesiten filtrar después en PHP.
     *
     * @param  array{pipeline_stage_id?: int|null, tag_ids?: list<int>, custom_filters?: list<array{field: string, operator: string, value: mixed}>}  $filters
     * @return Builder<Conversation>
     */
    private function buildQuery(User $user, int $channelId, array $filters): Builder
    {
        $query = Conversation::query()
            ->select(['id', 'contact_id', 'channel_id', 'assigned_to', 'branch_id'])
            ->visibleTo($user)
            ->where('channel_id', $channelId)
            // Los grupos ya quedan afuera por whereHas('contact'), pero el
            // filtro explícito no depende de ese efecto lateral: una difusión
            // masiva no tiene sentido sobre un grupo de 8 personas.
            ->where('kind', 'direct')
            ->whereHas('contact', fn (Builder $contact): Builder => $contact
                ->whereNotNull('phone')
                ->where('phone', '!=', ''));

        if (! empty($filters['pipeline_stage_id'])) {
            $query->where('pipeline_stage_id', $filters['pipeline_stage_id']);
        }

        if (! empty($filters['tag_ids'])) {
            $tagIds = array_values(array_unique(array_map('intval', $filters['tag_ids'])));
            $query->whereHas('contact.tags', fn (Builder $tags): Builder => $tags->whereIn('tags.id', $tagIds));
        }

        foreach ($filters['custom_filters'] ?? [] as $filter) {
            $this->applyContactFilter($query, $filter);
        }

        return $query
            ->with('contact:id,name,phone')
            ->orderBy('id');
    }

    /** @param array{field: string, operator: string, value: mixed} $filter */
    private function applyContactFilter(Builder $query, array $filter): void
    {
        $field = $filter['field'];
        $operator = $filter['operator'];
        $value = $filter['value'];
        $standardFields = ['name', 'phone', 'email', 'source'];

        $query->whereHas('contact', function (Builder $contact) use ($field, $operator, $value, $standardFields): void {
            $column = in_array($field, $standardFields, true)
                ? $field
                : 'custom_data->'.$field;

            match ($operator) {
                'contains' => $contact->where($column, 'like', '%'.$value.'%'),
                'not_equals' => $contact->where($column, '!=', $value),
                default => $contact->where($column, $value),
            };
        });
    }
}
