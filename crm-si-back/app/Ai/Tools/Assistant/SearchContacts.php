<?php

namespace App\Ai\Tools\Assistant;

use App\Ai\Tools\Assistant\Concerns\RespondsSafely;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Búsqueda de contactos visibles para el usuario, con su contexto comercial.
 */
class SearchContacts implements Tool
{
    use RespondsSafely;

    private const LIMIT = 5;

    public function __construct(private User $user) {}

    public function name(): string
    {
        return 'search_contacts';
    }

    public function description(): string
    {
        return 'Busca contactos por nombre, teléfono o email y devuelve sus etiquetas, oportunidades abiertas y último mensaje.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('Texto a buscar (mínimo 2 caracteres).'),
        ];
    }

    public function handle(Request $request): string
    {
        return $this->respond(function () use ($request): array|string {
            $search = trim((string) ($request['query'] ?? ''));

            if (mb_strlen($search) < 2) {
                return 'Pasá al menos 2 caracteres para buscar.';
            }

            return Contact::query()->visibleTo($this->user)
                ->where(fn ($q) => $q->whereLike('name', "%{$search}%", caseSensitive: false)
                    ->orWhereLike('phone', "%{$search}%", caseSensitive: false)
                    ->orWhereLike('email', "%{$search}%", caseSensitive: false))
                ->with('tags:id,name')
                ->withCount(['opportunities as open_opportunities' => fn ($q) => $q->where('status', 'open')])
                ->withMax('conversations', 'last_message_at')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Contact $contact) => [
                    'name' => $contact->name,
                    'phone' => $contact->phone,
                    'email' => $contact->email,
                    'tags' => $contact->tags->pluck('name')->all(),
                    'open_opportunities' => (int) $contact->open_opportunities,
                    'last_message_at' => $contact->conversations_max_last_message_at,
                    'created_at' => $contact->created_at?->toDateTimeString(),
                ])->all();
        });
    }
}
