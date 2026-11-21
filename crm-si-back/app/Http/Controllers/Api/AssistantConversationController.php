<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssistantConversationRequest;
use App\Models\AssistantMessageFeedback;
use App\Services\Ai\Assistant\AssistantConversations;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Models\Conversation;

/**
 * Hilos del asistente interno del usuario en el workspace activo.
 */
class AssistantConversationController extends Controller
{
    public function __construct(
        private AssistantConversations $conversations,
        private CurrentWorkspace $workspace,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('assistant.use'), 403);

        return response()->json([
            'data' => $this->conversations->list($this->workspace->membership())
                ->map(fn (Conversation $conversation) => $this->transform($conversation))
                ->values(),
        ]);
    }

    public function store(StoreAssistantConversationRequest $request): JsonResponse
    {
        $conversation = $this->conversations->create(
            $this->workspace->membership(),
            $request->validated('first_message'),
        );

        return response()->json(['data' => $this->transform($conversation)], 201);
    }

    public function messages(Request $request, string $conversation): JsonResponse
    {
        abort_unless($request->user()->can('assistant.use'), 403);

        $messages = $this->conversations->uiMessages(
            $this->conversations->findOwned($this->workspace->membership(), $conversation),
        );

        $feedback = AssistantMessageFeedback::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('message_id', array_column($messages, 'id'))
            ->pluck('rating', 'message_id');

        return response()->json([
            'data' => $messages,
            // Objeto vacío (no lista) cuando no hay valoraciones.
            'feedback' => (object) $feedback->all(),
        ]);
    }

    /**
     * @return array{id: string, title: string, updated_at: ?string}
     */
    private function transform(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'updated_at' => $conversation->updated_at?->toIso8601String(),
        ];
    }
}
