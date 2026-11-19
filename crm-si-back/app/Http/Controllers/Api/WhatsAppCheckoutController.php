<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MetaApiException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Commerce\WhatsAppCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class WhatsAppCheckoutController extends Controller
{
    /** Manda el formulario de compra con los productos que eligió el cliente. */
    public function store(Request $request, Conversation $conversation, WhatsAppCheckoutService $checkouts): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);
        abort_unless($request->user()->can('flows.send'), 403);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        try {
            $message = $checkouts->open($conversation, $validated['items'], $request->user());
        } catch (InvalidArgumentException|MetaApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            Log::warning('No se pudo enviar el formulario de compra', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'No se pudo enviar el formulario de compra.'], 422);
        }

        return response()->json(['data' => $message], 201);
    }
}
