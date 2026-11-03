<?php

namespace App\Http\Controllers\Api;

use App\Enums\AutomationRuleStatus;
use App\Enums\BroadcastStatus;
use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\BroadcastCampaign;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;

class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Tag::query()->orderBy('name');

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->whereLike('name', "%{$search}%", caseSensitive: false);
        }

        if ($request->filled('type')) {
            $query->where('type', (string) $request->query('type'));
        }

        if ($request->boolean('with_counts')) {
            $query->withCount(['contacts', 'conversations', 'opportunities']);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Tag::class);

        $validated = $request->validate($this->rules($request));
        $slug = $this->slugFor($validated['name']);
        $this->ensureSlugIsAvailable($request, $slug);

        $tag = Tag::create([
            ...$validated,
            'tenant_id' => $request->user()->tenant_id,
            'created_by' => $request->user()->id,
            'slug' => $slug,
            'color' => $validated['color'] ?? '#64748b',
            'is_system' => false,
        ]);

        return response()->json(['data' => $tag], 201);
    }

    public function show(Request $request, Tag $tag): JsonResponse
    {
        $this->authorizeTenant($request, $tag->tenant_id);

        return response()->json(['data' => $tag]);
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        $this->authorizeTenant($request, $tag->tenant_id);
        $this->authorize('update', $tag);

        $validated = $request->validate($this->rules($request, $tag));

        if (array_key_exists('name', $validated)) {
            $validated['slug'] = $this->slugFor($validated['name']);
            $this->ensureSlugIsAvailable($request, $validated['slug'], $tag);
        }

        $tag->update($validated);

        return response()->json(['data' => $tag->refresh()]);
    }

    public function usage(Request $request, Tag $tag): JsonResponse
    {
        $this->authorizeTenant($request, $tag->tenant_id);
        $this->authorize('delete', $tag);

        $tag->loadCount(['contacts', 'conversations', 'opportunities']);

        return response()->json(['data' => [
            'contacts_count' => $tag->contacts_count,
            'conversations_count' => $tag->conversations_count,
            'opportunities_count' => $tag->opportunities_count,
            'blocking' => $this->blockingUsage($tag),
        ]]);
    }

    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        $this->authorizeTenant($request, $tag->tenant_id);
        $this->authorize('delete', $tag);

        $blocking = $this->blockingUsage($tag);
        if ($blocking['automations'] !== [] || $blocking['broadcasts'] !== []) {
            return response()->json([
                'message' => 'La etiqueta está en uso en automatizaciones o difusiones programadas.',
                'blocking' => $blocking,
            ], 409);
        }

        DB::transaction(function () use ($tag): void {
            // El FK de taggables ya hace cascade; se borra explícito para no
            // depender de que el driver tenga las foreign keys activas.
            DB::table('taggables')->where('tag_id', $tag->id)->delete();
            $tag->delete();
        });

        return response()->json(['message' => 'Etiqueta eliminada']);
    }

    public function attachToContact(Request $request, Contact $contact): JsonResponse
    {
        $this->authorizeTenant($request, $contact->tenant_id);

        return $this->attachTags($request, $contact);
    }

    public function detachFromContact(Request $request, Contact $contact, Tag $tag): JsonResponse
    {
        $this->authorizeTenant($request, $contact->tenant_id);
        $this->authorizeTenant($request, $tag->tenant_id);

        $contact->tags()->detach($tag->id);
        $contact->load('tags');

        return response()->json(['data' => $contact]);
    }

    public function attachToConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeTenant($request, $conversation->tenant_id);

        $response = $this->attachTags($request, $conversation);

        $contact = $conversation->contact;
        if ($contact && $contact->tenant_id === $request->user()->tenant_id) {
            $tagIds = (array) $request->input('tag_ids', []);
            $pivotData = collect($tagIds)
                ->mapWithKeys(fn (int $tagId) => [
                    $tagId => [
                        'tenant_id' => $request->user()->tenant_id,
                        'assigned_by' => $request->user()->id,
                    ],
                ])
                ->all();
            $contact->tags()->syncWithoutDetaching($pivotData);
        }

        return $response;
    }

    public function detachFromConversation(Request $request, Conversation $conversation, Tag $tag): JsonResponse
    {
        $this->authorizeTenant($request, $conversation->tenant_id);
        $this->authorizeTenant($request, $tag->tenant_id);

        $conversation->tags()->detach($tag->id);
        $conversation->load('tags');

        return response()->json(['data' => $conversation]);
    }

    private function attachTags(Request $request, Model $taggable): JsonResponse
    {
        $validated = $request->validate([
            'tag_ids' => ['required', 'array', 'min:1'],
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn ($query) => $query->where('tenant_id', $request->user()->tenant_id)),
            ],
        ]);

        $pivotData = collect($validated['tag_ids'])
            ->mapWithKeys(fn (int $tagId) => [
                $tagId => [
                    'tenant_id' => $request->user()->tenant_id,
                    'assigned_by' => $request->user()->id,
                ],
            ])
            ->all();

        $taggable->tags()->syncWithoutDetaching($pivotData);
        $taggable->load('tags');

        return response()->json(['data' => $taggable]);
    }

    /**
     * Automatizaciones activas y difusiones sin terminar que dependen de la
     * etiqueta: borrarla las rompería en silencio.
     *
     * @return array{automations: list<array{id: int, name: string}>, broadcasts: list<array{id: int, name: string, status: string}>}
     */
    private function blockingUsage(Tag $tag): array
    {
        $automations = AutomationRule::query()
            ->where('tenant_id', $tag->tenant_id)
            ->where('status', AutomationRuleStatus::Active)
            ->orderBy('id')
            ->get(['id', 'name', 'conditions'])
            ->filter(fn (AutomationRule $rule): bool => $this->conditionsReferenceTag($rule->conditions, $tag))
            ->map(fn (AutomationRule $rule): array => ['id' => $rule->id, 'name' => $rule->name])
            ->values()
            ->all();

        $broadcasts = BroadcastCampaign::query()
            ->where('tenant_id', $tag->tenant_id)
            ->whereIn('status', [BroadcastStatus::Scheduled, BroadcastStatus::Processing])
            ->orderBy('id')
            ->get(['id', 'name', 'status', 'audience_filters'])
            ->filter(function (BroadcastCampaign $campaign) use ($tag): bool {
                $filters = $campaign->audience_filters ?? [];
                $tagIds = array_merge((array) ($filters['tag_ids'] ?? []), (array) ($filters['excluded_tag_ids'] ?? []));

                return in_array($tag->id, array_map('intval', $tagIds), true);
            })
            ->map(fn (BroadcastCampaign $campaign): array => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status->value,
            ])
            ->values()
            ->all();

        return ['automations' => $automations, 'broadcasts' => $broadcasts];
    }

    /**
     * Recorre el árbol de condiciones (grupos AND/OR anidados) igual que
     * ConditionEvaluator: has_tag compara contra el id o el slug.
     */
    private function conditionsReferenceTag(?array $node, Tag $tag): bool
    {
        if ($node === null || $node === []) {
            return false;
        }

        if (isset($node['conditions']) && is_array($node['conditions'])) {
            foreach ($node['conditions'] as $child) {
                if (is_array($child) && $this->conditionsReferenceTag($child, $tag)) {
                    return true;
                }
            }

            return false;
        }

        if (($node['operator'] ?? null) !== 'has_tag') {
            return false;
        }

        foreach ((array) ($node['value'] ?? null) as $value) {
            if (is_scalar($value) && in_array((string) $value, [(string) $tag->id, $tag->slug], true)) {
                return true;
            }
        }

        return false;
    }

    private function rules(Request $request, ?Tag $tag = null): array
    {
        $nameRules = [$tag ? 'sometimes' : 'required', 'string', 'max:80'];

        return [
            'name' => $nameRules,
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'type' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function slugFor(string $name): string
    {
        return Str::slug($name) ?: Str::lower(Str::random(8));
    }

    private function ensureSlugIsAvailable(Request $request, string $slug, ?Tag $tag = null): void
    {
        $exists = Tag::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('slug', $slug)
            ->when($tag, fn ($query) => $query->where('id', '!=', $tag->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Ya existe una etiqueta con ese nombre.',
            ]);
        }
    }

    private function authorizeTenant(Request $request, int $tenantId): void
    {
        if ($tenantId !== $request->user()->tenant_id) {
            abort(403);
        }
    }
}
