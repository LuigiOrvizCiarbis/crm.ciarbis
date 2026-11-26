<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MetaApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsAppCheckoutResource;
use App\Models\Conversation;
use App\Models\WhatsAppCheckout;
use App\Services\Commerce\CatalogItemsUnavailableException;
use App\Services\Commerce\CatalogUnavailableException;
use App\Services\Commerce\WhatsAppCheckoutService;
use App\Services\Commerce\WhatsAppProductMessageSender;
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

    /** Manda el catálogo nativo de WhatsApp para que el cliente arme el carrito. */
    public function catalog(Request $request, Conversation $conversation, WhatsAppProductMessageSender $products): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);
        abort_unless($request->user()->can('flows.send'), 403);

        $validated = $request->validate(['body' => ['nullable', 'string', 'max:1024']]);
        $body = filled($validated['body'] ?? null) ? $validated['body'] : 'Mirá nuestro catálogo y armá tu carrito acá mismo.';

        try {
            $message = $products->sendCatalog($conversation, $body, $request->user());
        } catch (InvalidArgumentException|MetaApiException|CatalogUnavailableException|CatalogItemsUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            Log::warning('No se pudo enviar el catálogo', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'No se pudo enviar el catálogo.'], 422);
        }

        return response()->json(['data' => $message], 201);
    }

    /** Último pedido de la conversación (30 días) para la tarjeta del chat. */
    public function show(Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $checkout = WhatsAppCheckout::query()
            ->where('conversation_id', $conversation->id)
            ->where('updated_at', '>=', now()->subDays(30))
            ->latest('id')
            ->first();

        return response()->json(['data' => $checkout ? (new WhatsAppCheckoutResource($checkout))->resolve() : null]);
    }
}
