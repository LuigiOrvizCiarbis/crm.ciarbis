<?php

namespace App\Services;

use App\Models\WhatsAppConfig;
use App\Support\MetaOAuth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lee el estado de verificación de negocio (Meta Business Verification) de un
 * canal de WhatsApp.
 *
 * Meta NO permite iniciar la verificación por API: los documentos y la validación
 * se cargan en el Business Manager UI. Este servicio sólo LEE el verification_status
 * del Business Portfolio dueño del WABA y arma un deep-link para completar el
 * proceso en Meta.
 */
class WhatsAppBusinessVerificationService
{
    /** Estados normalizados que expone el servicio. */
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_PENDING = 'pending';

    public const STATUS_NOT_VERIFIED = 'not_verified';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BUSINESS_ID_MISSING = 'business_id_missing';

    public const STATUS_PERMISSION_MISSING = 'permission_missing';

    public const STATUS_TOKEN_INVALID = 'token_invalid';

    public const STATUS_UNKNOWN = 'unknown';

    /**
     * Resuelve el estado de verificación para una WhatsAppConfig.
     *
     * @return array{
     *     business_id: string|null,
     *     business_name: string|null,
     *     status: string,
     *     raw_verification_status: string|null,
     *     verify_url: string|null
     * }
     */
    public function statusFor(WhatsAppConfig $config): array
    {
        $token = $config->getDecryptedToken();

        if (! $token) {
            return $this->result(null, null, self::STATUS_TOKEN_INVALID);
        }

        // Paso 1: asegurar business_id. Canales viejos lo tienen NULL; se deriva
        // del WABA y se persiste (self-heal) para no repetir la llamada.
        $businessId = $config->business_id;
        if (! $businessId) {
            $businessId = $this->deriveBusinessIdFromWaba($config, $token);

            if ($businessId) {
                $config->update(['business_id' => $businessId]);
            }
        }

        if (! $businessId) {
            return $this->result(null, null, self::STATUS_BUSINESS_ID_MISSING);
        }

        // Paso 2: leer verification_status del Business Portfolio.
        return $this->fetchBusinessStatus($businessId, $token);
    }

    /**
     * Deriva el business_id (Business Portfolio) desde el WABA vía Graph API.
     * Verificado en prod: owner_business_info.id / on_behalf_of_business_info.id.
     */
    private function deriveBusinessIdFromWaba(WhatsAppConfig $config, string $token): ?string
    {
        $version = config('services.facebook.graph_version', 'v26.0');

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get("https://graph.facebook.com/{$version}/{$config->waba_id}", [
                    'fields' => 'owner_business_info,on_behalf_of_business_info',
                ]);

            if ($response->successful()) {
                $body = $response->json();

                return $body['owner_business_info']['id']
                    ?? $body['on_behalf_of_business_info']['id']
                    ?? null;
            }

            Log::warning('deriveBusinessIdFromWaba: Graph devolvió error', [
                'waba_id' => $config->waba_id,
                'status' => $response->status(),
                'error' => MetaOAuth::describeMetaError($response->json()),
            ]);
        } catch (\Throwable $e) {
            Log::warning('deriveBusinessIdFromWaba exception', MetaOAuth::describeException($e));
        }

        return null;
    }

    /**
     * Consulta el nodo business y normaliza su verification_status.
     *
     * @return array{business_id: string|null, business_name: string|null, status: string, raw_verification_status: string|null, verify_url: string|null}
     */
    private function fetchBusinessStatus(string $businessId, string $token): array
    {
        $version = config('services.facebook.graph_version', 'v26.0');

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get("https://graph.facebook.com/{$version}/{$businessId}", [
                    'fields' => 'id,name,verification_status',
                ]);

            if ($response->successful()) {
                $body = $response->json();
                $raw = $body['verification_status'] ?? null;

                return $this->result(
                    $businessId,
                    $body['name'] ?? null,
                    $this->normalizeStatus($raw),
                    $raw,
                );
            }

            // Mapear errores de Graph a estados claros.
            $error = MetaOAuth::describeMetaError($response->json());
            $status = $this->mapGraphError($error);

            Log::warning('fetchBusinessStatus: Graph devolvió error', [
                'business_id' => $businessId,
                'http_status' => $response->status(),
                'error' => $error,
                'mapped_status' => $status,
            ]);

            return $this->result($businessId, null, $status);
        } catch (\Throwable $e) {
            Log::warning('fetchBusinessStatus exception', MetaOAuth::describeException($e));

            return $this->result($businessId, null, self::STATUS_UNKNOWN);
        }
    }

    /**
     * Normaliza el verification_status crudo de Meta.
     * Valores reales observados en prod: not_verified, pending_submission.
     */
    private function normalizeStatus(?string $raw): string
    {
        return match ($raw) {
            'verified' => self::STATUS_VERIFIED,
            'pending', 'pending_submission', 'pending_need_more_info' => self::STATUS_PENDING,
            'rejected', 'failed' => self::STATUS_FAILED,
            'not_verified', null => self::STATUS_NOT_VERIFIED,
            default => self::STATUS_NOT_VERIFIED,
        };
    }

    /**
     * Mapea un error de Graph API a un estado normalizado.
     *
     * @param  array{code: int|null, type: string|null, subcode: int|null, message: string|null}  $error
     */
    private function mapGraphError(array $error): string
    {
        $code = $error['code'] ?? null;

        // 190: token inválido/revocado.
        if ($code === 190) {
            return self::STATUS_TOKEN_INVALID;
        }

        // 10 / 200 / 3: falta permiso (business_management) o el rol no alcanza.
        if (in_array($code, [10, 200, 3], true)) {
            return self::STATUS_PERMISSION_MISSING;
        }

        // 100 (p. ej. subcode 33): objeto inaccesible / sin permisos sobre él.
        if ($code === 100) {
            return self::STATUS_UNKNOWN;
        }

        return self::STATUS_UNKNOWN;
    }

    /**
     * Deep-link al centro de seguridad/verificación del Business Manager.
     */
    private function verifyUrl(?string $businessId): ?string
    {
        if (! $businessId) {
            return null;
        }

        return 'https://business.facebook.com/settings/security?business_id='.$businessId;
    }

    /**
     * @return array{business_id: string|null, business_name: string|null, status: string, raw_verification_status: string|null, verify_url: string|null}
     */
    private function result(?string $businessId, ?string $name, string $status, ?string $raw = null): array
    {
        return [
            'business_id' => $businessId,
            'business_name' => $name,
            'status' => $status,
            'raw_verification_status' => $raw,
            // Sólo ofrecemos el link si hay un business y todavía no está verificado.
            'verify_url' => $status === self::STATUS_VERIFIED ? null : $this->verifyUrl($businessId),
        ];
    }
}
