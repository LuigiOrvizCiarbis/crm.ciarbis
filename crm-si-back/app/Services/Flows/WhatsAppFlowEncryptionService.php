<?php

namespace App\Services\Flows;

use App\Models\WhatsAppConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Llaves RSA por número de WhatsApp para el endpoint de datos de Flows.
 */
class WhatsAppFlowEncryptionService
{
    /**
     * Genera las llaves si faltan y siempre vuelve a subir la pública a Meta,
     * así también cubre el re-registro del número. Idempotente.
     */
    public function setup(WhatsAppConfig $config): void
    {
        if ($config->flows_private_key === null) {
            [$privatePem, $publicPem] = $this->generateKeyPair();
            $config->flows_private_key = Crypt::encryptString($privatePem);
            $config->flows_public_key = $publicPem;
        }

        if ($config->flows_endpoint_token === null) {
            $config->flows_endpoint_token = Str::random(48);
        }

        $config->save();

        $response = Http::withToken((string) $config->getDecryptedToken())
            ->asForm()
            ->post($this->encryptionUrl($config), ['business_public_key' => $config->flows_public_key]);

        if ($response->failed()) {
            throw new RuntimeException('Meta rechazó la clave pública: '.($response->json('error.message') ?? $response->body()));
        }

        $config->forceFill(['flows_public_key_uploaded_at' => now()])->save();
    }

    /**
     * @return array{configured: bool, uploaded_at: ?string, endpoint_url: ?string, signature_status: ?string}
     */
    public function status(WhatsAppConfig $config): array
    {
        return [
            'configured' => $this->isConfigured($config),
            'uploaded_at' => $config->flows_public_key_uploaded_at?->toIso8601String(),
            'endpoint_url' => $config->flowsEndpointUrl(),
            'signature_status' => $this->signatureStatus($config),
        ];
    }

    public function isConfigured(WhatsAppConfig $config): bool
    {
        return $config->flows_private_key !== null && $config->flows_public_key_uploaded_at !== null;
    }

    /** @return array{0: string, 1: string} */
    private function generateKeyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false || ! openssl_pkey_export($key, $privatePem)) {
            throw new RuntimeException('No se pudo generar la llave de cifrado.');
        }

        return [$privatePem, openssl_pkey_get_details($key)['key']];
    }

    private function signatureStatus(WhatsAppConfig $config): ?string
    {
        if ($config->flows_public_key === null) {
            return null;
        }

        try {
            $response = Http::withToken((string) $config->getDecryptedToken())->get($this->encryptionUrl($config));
        } catch (ConnectionException $e) {
            Log::warning('Flows: no se pudo consultar la firma de la clave pública', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Flows: no se pudo consultar la firma de la clave pública', [
                'config_id' => $config->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json('data.0.business_public_key_signature_status')
            ?? $response->json('business_public_key_signature_status');
    }

    private function encryptionUrl(WhatsAppConfig $config): string
    {
        $version = (string) config('services.facebook.graph_version', 'v26.0');

        return "https://graph.facebook.com/{$version}/{$config->phone_number_id}/whatsapp_business_encryption";
    }
}
