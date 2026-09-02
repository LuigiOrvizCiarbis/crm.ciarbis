<?php

namespace App\Services;

use App\Enums\MarketingConsentStatus;
use App\Enums\TemplateCategory;
use App\Models\Contact;
use App\Models\User;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BroadcastAudienceService
{
    public function __construct(
        private readonly UnitedStatesPhoneDetector $usPhoneDetector = new UnitedStatesPhoneDetector,
    ) {}

    /**
     * Resuelve la audiencia de una difusión, separada en dos conjuntos:
     * `consented` (puede recibir marketing sin más) y `without_consent`
     * (requiere que el usuario reconozca el riesgo, ver StoreBroadcastRequest).
     *
     * `denied` nunca entra en ninguno de los dos: es un opt-out explícito
     * (incluye el error 131050 de Meta, "do not retry") y no hay casilla que
     * lo habilite.
     *
     * Para plantillas utility/authentication el consentimiento no aplica como
     * filtro —el cap por usuario y las restricciones de marketing son solo
     * para esa categoría— así que todo lo no-`denied` cae en `consented`.
     *
     * $includeWithoutConsent determina QUÉ cuenta contra el tope de
     * destinatarios. Si es false (el caso por defecto, y el único que conoce
     * estimate()), el cap se aplica solo sobre `consented`: un lote inicial
     * de contactos `unknown` no debe consumir el cupo ni cortar la paginación
     * antes de llegar a los contactos que sí importan para esta request. Si
     * es true, el cap se aplica sobre la suma de ambos, porque ambos van a
     * crearse como recipients.
     *
     * @param  array{pipeline_stage_id?: int|null, tag_ids?: list<int>, excluded_tag_ids?: list<int>, custom_filters?: list<array{field: string, operator: string, value: mixed}>}  $filters
     * @return array{
     *   consented: Collection<int, Contact>,
     *   without_consent: Collection<int, Contact>,
     *   total_contacts_with_phone: int,
     *   excluded_us_count: int,
     *   excluded_duplicate_count: int,
     * }
     */
    public function resolveForCategory(User $user, int $channelId, array $filters, ?TemplateCategory $category, bool $includeWithoutConsent = false): array
    {
        $max = (int) config('broadcasts.max_recipients');
        $isMarketing = $category === TemplateCategory::Marketing;
        $query = $this->buildQuery($user, $channelId, $filters);

        // Sin los filtros de audiencia (pipeline_stage_id, tag_ids,
        // excluded_tag_ids, custom_filters): es "cuántos contactos tiene el
        // tenant en total",
        // no "cuántos matchean los filtros" — eso último ya lo da
        // audience_count. Sirve para que el front explique un audience_count
        // chico cuando el filtro de etapa dejó fuera a los sin conversación.
        $totalContactsWithPhone = $this->buildQuery($user, $channelId, [])->count();

        // El tope de destinatarios se aplica sobre los que SÍ pueden recibir el
        // mensaje. Limitar antes de descartar duplicados/EE.UU. desperdiciaría
        // el cupo. La detección de EE.UU. depende del código de área (no se
        // puede resolver en SQL) y la deduplicación necesita ver todas las
        // filas para decidir quién gana, así que se pagina hasta llenar el cupo.
        $consented = new Collection;
        $withoutConsent = new Collection;
        $seenPhones = [];
        $excludedUs = 0;
        $excludedDuplicate = 0;
        $lastId = 0;

        do {
            $page = (clone $query)->where('id', '>', $lastId)->limit($max)->get();

            if ($page->isEmpty()) {
                break;
            }

            $lastId = (int) $page->last()->id;

            foreach ($page as $contact) {
                $key = PhoneNumberNormalizer::dedupeKey($contact->phone);

                if ($key === null) {
                    continue;
                }

                if ($isMarketing && $this->usPhoneDetector->isUnitedStates($contact->phone)) {
                    $excludedUs++;

                    continue;
                }

                // Gana el contact_id más bajo: es el registro más antiguo, el
                // que suele tener el historial. Determinístico porque la
                // query va ordenada por id.
                if (isset($seenPhones[$key])) {
                    $excludedDuplicate++;

                    continue;
                }
                $seenPhones[$key] = true;

                if (! $isMarketing || $contact->marketing_consent_status === MarketingConsentStatus::Granted) {
                    if ($consented->count() < $max) {
                        $consented->push($contact);
                    }
                } elseif ($withoutConsent->count() < $max) {
                    // Tope propio aunque no cuente para el corte: sin él, un
                    // tenant con pocos `granted` entre muchos miles de
                    // `unknown` haría que esta colección creciera sin límite
                    // escaneando toda la tabla, para datos que ni siquiera se
                    // usan si include_without_consent sigue en false.
                    $withoutConsent->push($contact);
                }

                // El cap se mide sobre la audiencia que esta request va a
                // usar, no sobre todo lo visto: si without_consent no se va a
                // incluir, no debe poder agotar el cupo ni cortar la
                // paginación antes de encontrar suficientes `consented`.
                $selectedCount = $includeWithoutConsent
                    ? $consented->count() + $withoutConsent->count()
                    : $consented->count();

                if ($selectedCount >= $max) {
                    break 2;
                }
            }
        } while ($page->count() === $max);

        return [
            'consented' => $consented->values(),
            'without_consent' => $withoutConsent->values(),
            'total_contacts_with_phone' => $totalContactsWithPhone,
            'excluded_us_count' => $excludedUs,
            'excluded_duplicate_count' => $excludedDuplicate,
        ];
    }

    /**
     * @param  array{pipeline_stage_id?: int|null, tag_ids?: list<int>, excluded_tag_ids?: list<int>, custom_filters?: list<array{field: string, operator: string, value: mixed}>}  $filters
     * @return Collection<int, Contact>
     */
    public function resolve(User $user, int $channelId, array $filters): Collection
    {
        return $this->resolveForCategory($user, $channelId, $filters, null)['consented'];
    }

    /**
     * Query base de la audiencia, sin tope: los llamadores deciden cuántas
     * filas traer según necesiten filtrar después en PHP.
     *
     * $channelId no filtra la audiencia (a diferencia del modelo anterior):
     * la difusión ahora alcanza a todos los contactos del tenant. Se recibe
     * igual porque queda reservado para el filtro de canal emisor en el
     * llamador (estimate/store) y para mantener la firma estable.
     *
     * @param  array{pipeline_stage_id?: int|null, tag_ids?: list<int>, excluded_tag_ids?: list<int>, custom_filters?: list<array{field: string, operator: string, value: mixed}>}  $filters
     * @return Builder<Contact>
     */
    private function buildQuery(User $user, int $channelId, array $filters): Builder
    {
        $query = Contact::query()
            ->select(['id', 'name', 'phone', 'custom_data', 'marketing_consent_status'])
            ->visibleForBroadcast($user)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('marketing_consent_status')
                ->orWhere('marketing_consent_status', '!=', 'denied'));

        // pipeline_stage_id vive en Conversation, no en Contact: pasa a
        // significar "contactos con al menos una conversación en esa etapa",
        // lo que excluye implícitamente a quienes no tienen conversación. Es
        // coherente —filtrar por etapa es pedir gente que ya está en el
        // pipeline— pero hay que anunciarlo (ver BroadcastCampaignController).
        if (! empty($filters['pipeline_stage_id'])) {
            $query->whereHas('conversations', fn (Builder $conversations): Builder => $conversations
                ->where('pipeline_stage_id', $filters['pipeline_stage_id']));
        }

        if (! empty($filters['tag_ids'])) {
            $tagIds = array_values(array_unique(array_map('intval', $filters['tag_ids'])));

            // Los tags están mayoritariamente en Conversation, no en Contact
            // (270 vs 86 en un tenant medido): mirar solo Contact dejaría
            // fuera casi todo el alcance del filtro.
            $query->where(fn (Builder $q) => $q
                ->whereHas('tags', fn (Builder $tags): Builder => $tags->whereIn('tags.id', $tagIds))
                ->orWhereHas('conversations.tags', fn (Builder $tags): Builder => $tags->whereIn('tags.id', $tagIds)));
        }

        if (! empty($filters['excluded_tag_ids'])) {
            $excludedTagIds = array_values(array_unique(array_map('intval', $filters['excluded_tag_ids'])));

            // La exclusión tiene prioridad sobre cualquier filtro inclusivo y
            // contempla las dos ubicaciones donde se asignan tags en el CRM.
            $query
                ->whereDoesntHave('tags', fn (Builder $tags): Builder => $tags->whereIn('tags.id', $excludedTagIds))
                ->whereDoesntHave('conversations.tags', fn (Builder $tags): Builder => $tags->whereIn('tags.id', $excludedTagIds));
        }

        foreach ($filters['custom_filters'] ?? [] as $filter) {
            $this->applyContactFilter($query, $filter);
        }

        return $query->orderBy('id');
    }

    /** @param array{field: string, operator: string, value: mixed} $filter */
    private function applyContactFilter(Builder $query, array $filter): void
    {
        $field = $filter['field'];
        $operator = $filter['operator'];
        $value = $filter['value'];
        $standardFields = ['name', 'phone', 'email', 'source'];

        $column = in_array($field, $standardFields, true)
            ? $field
            : 'custom_data->'.$field;

        match ($operator) {
            'contains' => $query->where($column, 'like', '%'.$value.'%'),
            'not_equals' => $query->where($column, '!=', $value),
            default => $query->where($column, $value),
        };
    }
}
