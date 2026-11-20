<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWooCommerceWebhookJob;
use App\Models\WooCommerceConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entregas de los webhooks que registró WooCommerceWebhookRegistrar. Se
 * valida la firma HMAC y el trabajo se hace en cola.
 */
class WooCommerceWebhookController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $config = WooCommerceConfig::withoutGlobalScopes()->where('webhook_token', $token)->first();
        if (! $config) {
            abort(404);
        }

        $topic = (string) $request->header('X-WC-Webhook-Topic', '');

        // Woo hace un ping sin tema al crear el webhook.
        if ($topic === '') {
            return response()->json(['ok' => true]);
        }

        $secret = (string) $config->getDecryptedWebhookSecret();
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if ($secret === '' || ! hash_equals($expected, (string) $request->header('X-WC-Webhook-Signature'))) {
            return response()->json(['message' => 'Firma inválida.'], 401);
        }

        $payload = json_decode($request->getContent(), true);

        ProcessWooCommerceWebhookJob::dispatch($config->id, $topic, is_array($payload) ? $payload : []);

        return response()->json(['ok' => true], 202);
    }
}
