<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppConfig;
use App\Services\Flows\FlowCrypto;
use App\Services\Flows\FlowDecryptionException;
use App\Services\Flows\FlowEndpointHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Endpoint de datos de WhatsApp Flows (uno por número, identificado por
 * `flows_endpoint_token`). Meta exige códigos propios: 421 si no se pudo
 * descifrar, 432 si la firma es inválida y 427 si el flow_token no sirve.
 *
 * Cada request deja una línea de log con su duración y resultado: Meta corta
 * a los 10 s y el formulario no avanza, así que se avisa desde los 5 s.
 */
class WhatsAppFlowEndpointController extends Controller
{
    private const SLOW_MS = 5000;

    public function __invoke(Request $request, string $token): Response|JsonResponse
    {
        $startedAt = microtime(true);
        $context = ['outcome' => 'unknown'];
        $response = $this->respond($request, $token, $context);

        $this->logRequest($context, $response->getStatusCode(), (int) ((microtime(true) - $startedAt) * 1000));

        return $response;
    }

    /** @param  array<string, mixed>  $context  datos para el log, completados en el camino */
    private function respond(Request $request, string $token, array &$context): Response|JsonResponse
    {
        $config = WhatsAppConfig::where('flows_endpoint_token', $token)->first();
        $privateKey = $config?->getDecryptedFlowsPrivateKey();
        $context['config_id'] = $config?->id;

        if ($privateKey === null) {
            $context['outcome'] = 'not_found';

            return response('', 404);
        }

        if (! $this->hasValidSignature($request)) {
            $context['outcome'] = 'bad_signature';

            return response('', 432);
        }

        $crypto = app(FlowCrypto::class);

        try {
            $decrypted = $crypto->decryptRequest($request->json()->all(), $privateKey);
        } catch (FlowDecryptionException) {
            $context['outcome'] = 'undecryptable';

            return response('', 421);
        }

        $context['action'] = $decrypted->payload['action'] ?? null;
        $context['crm_action'] = $decrypted->payload['data']['crm_action'] ?? null;
        $context['screen'] = $decrypted->payload['screen'] ?? null;

        try {
            $result = app(FlowEndpointHandler::class)->handle($config, $decrypted->payload);

            if ($result->invalidToken) {
                $context['outcome'] = 'invalid_token';

                return response()->json(['error_msg' => $result->errorMessage], 427);
            }

            // Una pantalla sin datos declarados responde `data` como stdClass.
            $data = $result->payload['data'] ?? null;
            $context['outcome'] = is_array($data) && isset($data['error_message']) ? 'screen_error' : 'ok';
            $context['response_screen'] = $result->payload['screen'] ?? null;

            return response($crypto->encryptResponse($result->payload, $decrypted->aesKey, $decrypted->iv), 200)
                ->header('Content-Type', 'text/plain');
        } catch (Throwable $e) {
            report($e);
            $context['outcome'] = 'exception';

            return response('', 500);
        }
    }

    /** @param  array<string, mixed>  $context */
    private function logRequest(array $context, int $status, int $processingMs): void
    {
        $line = [...$context, 'status' => $status, 'processing_ms' => $processingMs];

        if ($processingMs >= self::SLOW_MS || in_array($context['outcome'], ['exception', 'bad_signature', 'undecryptable'], true)) {
            Log::warning('Flow endpoint', $line);

            return;
        }

        Log::info('Flow endpoint', $line);
    }

    private function hasValidSignature(Request $request): bool
    {
        $appSecret = (string) config('services.facebook.app_secret');

        if ($appSecret === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, (string) $request->header('X-Hub-Signature-256'));
    }
}
