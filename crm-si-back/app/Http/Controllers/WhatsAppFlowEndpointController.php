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
 */
class WhatsAppFlowEndpointController extends Controller
{
    public function __invoke(Request $request, string $token): Response|JsonResponse
    {
        $config = WhatsAppConfig::where('flows_endpoint_token', $token)->first();
        $privateKey = $config?->getDecryptedFlowsPrivateKey();

        if ($privateKey === null) {
            return response('', 404);
        }

        if (! $this->hasValidSignature($request)) {
            Log::warning('Flow endpoint: firma inválida', ['config_id' => $config->id]);

            return response('', 432);
        }

        $crypto = app(FlowCrypto::class);

        try {
            $decrypted = $crypto->decryptRequest($request->json()->all(), $privateKey);
        } catch (FlowDecryptionException) {
            return response('', 421);
        }

        try {
            $result = app(FlowEndpointHandler::class)->handle($config, $decrypted->payload);

            if ($result->invalidToken) {
                return response()->json(['error_msg' => $result->errorMessage], 427);
            }

            return response($crypto->encryptResponse($result->payload, $decrypted->aesKey, $decrypted->iv), 200)
                ->header('Content-Type', 'text/plain');
        } catch (Throwable $e) {
            report($e);

            return response('', 500);
        }
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
