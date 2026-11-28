<?php

namespace App\Http\Controllers\Api;

use App\Ai\Agents\WorkspaceAssistant;
use App\Exceptions\AssistantException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssistantChatRequest;
use App\Models\AssistantMessageTrace;
use App\Services\Ai\Assistant\AssistantConversations;
use App\Services\Ai\Assistant\AssistantProviderResolver;
use App\Services\Ai\Assistant\AssistantUsageMeter;
use App\Services\Observability\Langfuse\AgentTracing;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Vercel\Vercel;

/**
 * Turno del asistente interno, en streaming con el protocolo de useChat.
 *
 * Los errores previsibles (sin key, cupo agotado) se responden como JSON antes
 * de abrir el stream, para que el front pueda mostrarlos con su código.
 */
class AssistantChatController extends Controller
{
    public function __invoke(
        AssistantChatRequest $request,
        CurrentWorkspace $workspace,
        AssistantConversations $conversations,
        AssistantProviderResolver $resolver,
        AssistantUsageMeter $meter,
    ): StreamableAgentResponse|JsonResponse {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $membership = $workspace->membership();
        $conversation = $conversations->findOwned($membership, $request->validated('conversation_id'));

        try {
            $provider = $resolver->resolve($tenantId);
            if ($provider->metered) {
                $meter->ensureWithinDailyLimit($tenantId);
            }
        } catch (AssistantException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        // Si el usuario corta (Detener, cierra la pestaña), PHP no muere a mitad
        // de un paso: lo termina, lo registra (StepCompleted) y el agente se
        // detiene antes del siguiente (ver WorkspaceAssistant::middleware).
        ignore_user_abort(true);

        $chat = Vercel::chat($request);

        return (new WorkspaceAssistant($user, $provider->config, $provider->model))
            ->continue($conversation->id, as: $membership)
            ->stream($chat)
            // Corre después de que el SDK guardó el mensaje: vincula su id con el trace.
            ->then(function (StreamedAgentResponse $response) use ($tenantId): void {
                $reference = app(AgentTracing::class)->referenceFor($response->invocationId);
                if ($reference && $response->assistantMessageId) {
                    AssistantMessageTrace::query()->updateOrCreate(
                        ['message_id' => $response->assistantMessageId],
                        ['tenant_id' => $tenantId, 'trace_id' => $reference->traceId, 'observation_id' => $reference->observationId],
                    );
                }
            })
            ->usingProtocol($chat->protocol());
    }
}
