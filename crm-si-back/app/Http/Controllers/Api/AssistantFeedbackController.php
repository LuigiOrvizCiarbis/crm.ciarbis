<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssistantFeedbackRequest;
use App\Models\AssistantMessageFeedback;
use App\Services\Ai\Assistant\AssistantConversations;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Response;

/**
 * Valoración 👍/👎 de una respuesta del asistente, solo sobre hilos propios.
 */
class AssistantFeedbackController extends Controller
{
    public function __invoke(
        AssistantFeedbackRequest $request,
        string $message,
        CurrentWorkspace $workspace,
        AssistantConversations $conversations,
    ): Response {
        abort_unless($conversations->ownsAssistantMessage($workspace->membership(), $message), 404);

        AssistantMessageFeedback::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'message_id' => $message],
            ['tenant_id' => $request->user()->tenant_id, 'rating' => (int) $request->validated('rating')],
        );

        return response()->noContent();
    }
}
