<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMercadoPagoPaymentJob;
use App\Models\MercadoPagoConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notificaciones de Mercado Pago. Sin validación de firma a propósito: el job
 * reconsulta el pago en la API de MP con el token del tenant y esa respuesta manda.
 */
class MercadoPagoWebhookController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $config = MercadoPagoConfig::withoutGlobalScopes()->where('webhook_token', $token)->first();
        if (! $config) {
            abort(404);
        }

        $id = $request->input('data.id') ?? $request->query('data.id') ?? $request->query('id');
        $type = $request->input('type') ?? $request->input('topic') ?? $request->query('type') ?? $request->query('topic');

        if ($type === 'payment' && is_scalar($id) && (string) $id !== '') {
            ProcessMercadoPagoPaymentJob::dispatch($config->id, (string) $id);
        }

        return response()->json(['ok' => true]);
    }
}
