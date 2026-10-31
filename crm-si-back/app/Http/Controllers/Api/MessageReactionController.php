<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MetaApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageReactionRequest;
use App\Models\Message;
use App\Services\WhatsAppMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class MessageReactionController extends Controller
{
    public function __construct(private WhatsAppMessageService $messageService) {}

    /**
     * Pone, cambia o quita la reacción del usuario actual. emoji: "" (o
     * ausente) quita, que es exactamente el contrato de Meta — así no hace
     * falta un endpoint DELETE separado que reciba el emoji igual.
     */
    public function store(StoreMessageReactionRequest $request, Message $message): JsonResponse
    {
        $this->authorize('react', $message);

        try {
            $this->messageService->sendReactionFromCRM(
                $message,
                $request->validated('emoji'),
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (MetaApiException $e) {
            $errorMessage = match ($e->reason) {
                MetaApiException::REASON_REACTION_TARGET_INVALID =>
                    'No se puede reaccionar a este mensaje. WhatsApp sólo permite reaccionar a mensajes '.
                    'de los últimos 30 días que no hayan sido eliminados.',
                MetaApiException::REASON_TOKEN_INVALID =>
                    'La conexión de WhatsApp expiró. Reconectá el canal desde Configuración para volver a reaccionar.',
                MetaApiException::REASON_WINDOW_CLOSED =>
                    'La ventana de 24 horas de WhatsApp expiró: el contacto debe escribir primero para poder reaccionarle.',
                default => 'No se pudo enviar la reacción a WhatsApp.',
            };

            Log::warning('Error de Meta API al reaccionar a un mensaje', [
                'message_id' => $message->id,
                'tenant_id' => $request->user()->tenant_id,
                'reason' => $e->reason,
                'code' => $e->metaCode,
                'subcode' => $e->metaSubcode,
            ]);

            return response()->json(['message' => $errorMessage], 422);
        } catch (\RuntimeException $e) {
            Log::warning('Error enviando reacción por WhatsApp', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'No se pudo enviar la reacción a WhatsApp.'], 422);
        }

        return response()->json([
            'data' => $message->fresh()->load('reactions')->reaction_summary,
        ]);
    }
}
