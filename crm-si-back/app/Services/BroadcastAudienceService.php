<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BroadcastAudienceService
{
    /**
     * @param  array{pipeline_stage_id?: int|null, tag_ids?: list<int>, custom_filters?: list<array{field: string, operator: string, value: mixed}>}  $filters
     * @return Collection<int, Conversation>
     */
    public function resolve(User $user, int $channelId, array $filters): Collection
    {
        $query = Conversation::query()
            ->select(['id', 'contact_id', 'channel_id', 'assigned_to', 'branch_id'])
            ->visibleTo($user)
            ->where('channel_id', $channelId)
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
            ->orderBy('id')
            ->limit((int) config('broadcasts.max_recipients'))
            ->get();
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
