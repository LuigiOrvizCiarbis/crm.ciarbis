<?php

namespace App\Services;

use App\Models\WhatsAppConfig;
use App\Support\MetaOAuth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve si un número puede usar la Groups API de Meta. Dos exclusiones
 * independientes según la doc oficial: requiere Official Business Account
 * (OBA) Y no puede estar en coexistencia con la WhatsApp Business App
 * (is_on_biz_app=true). Se guardan por separado porque el mensaje al
 * usuario cambia según cuál falle.
 *
 * @see https://developers.facebook.com/documentation/business-messaging/whatsapp/groups
 */
class WhatsAppGroupEligibilityService
{
    private function graphVersion(): string
    {
        return (string) config('services.facebook.graph_version', 'v26.0');
    }

    /**
     * @return array{
     *     status: string,
     *     is_oba: bool|null,
     *     platform_type: string|null,
     *     checked_at: \Illuminate\Support\Carbon|null,
     *     reason_message: string
     * }
     */
    public function statusFor(WhatsAppConfig $config, bool $force = false): array
    {
        if (! $force && $this->isFresh($config)) {
            return $this->present($config);
        }

        $token = $config->getDecryptedToken();
        if (! $token || ! $config->phone_number_id) {
            $this->persist($config, WhatsAppConfig::GROUPS_TOKEN_INVALID, null, null, 'No se pudo verificar el token del canal.');

            return $this->present($config->refresh());
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get("https://graph.facebook.com/{$this->graphVersion()}/{$config->phone_number_id}", [
                    'fields' => 'is_official_business_account,is_on_biz_app,platform_type',
                ]);

            if (! $response->successful()) {
                Log::warning('WhatsAppGroupEligibilityService: Meta no respondió', [
                    'phone_number_id' => $config->phone_number_id,
                    'status' => $response->status(),
                    'error' => MetaOAuth::describeMetaError($response->json()),
                ]);

                $this->persist($config, WhatsAppConfig::GROUPS_UNKNOWN, null, null, 'No se pudo consultar el estado con Meta.');

                return $this->present($config->refresh());
            }

            $isOnBizApp = (bool) $response->json('is_on_biz_app', false);
            $isOba = (bool) $response->json('is_official_business_account', false);
            $platformType = $response->json('platform_type');

            $status = match (true) {
                $isOnBizApp => WhatsAppConfig::GROUPS_ON_BIZ_APP,
                ! $isOba => WhatsAppConfig::GROUPS_NOT_OBA,
                default => WhatsAppConfig::GROUPS_ELIGIBLE,
            };

            $this->persist($config, $status, $isOba, $platformType, null);

            return $this->present($config->refresh());
        } catch (\Throwable $e) {
            Log::warning('WhatsAppGroupEligibilityService exception', MetaOAuth::describeException($e));

            $this->persist($config, WhatsAppConfig::GROUPS_UNKNOWN, null, null, 'Ocurrió un error al verificar el estado con Meta.');

            return $this->present($config->refresh());
        }
    }

    private function isFresh(WhatsAppConfig $config): bool
    {
        return $config->groups_eligibility_checked_at !== null
            && $config->groups_eligibility_checked_at->gt(now()->subHours(WhatsAppConfig::GROUPS_ELIGIBILITY_TTL_HOURS));
    }

    private function persist(WhatsAppConfig $config, string $status, ?bool $isOba, ?string $platformType, ?string $error): void
    {
        $config->update([
            'groups_eligibility_status' => $status,
            'groups_is_oba' => $isOba,
            'groups_platform_type' => $platformType,
            'groups_eligibility_checked_at' => now(),
            'groups_eligibility_error' => $error,
        ]);
    }

    private function present(WhatsAppConfig $config): array
    {
        return [
            'status' => $config->groups_eligibility_status ?? WhatsAppConfig::GROUPS_UNKNOWN,
            'is_oba' => $config->groups_is_oba,
            'platform_type' => $config->groups_platform_type,
            'checked_at' => $config->groups_eligibility_checked_at,
            'reason_message' => $this->reasonMessage($config->groups_eligibility_status, $config->groups_eligibility_error),
        ];
    }

    private function reasonMessage(?string $status, ?string $error): string
    {
        return match ($status) {
            WhatsAppConfig::GROUPS_ELIGIBLE => 'Este número puede crear grupos de WhatsApp.',
            WhatsAppConfig::GROUPS_NOT_OBA => 'Este número no es una Official Business Account (OBA). Meta exige ese estado para usar grupos; solicitá la verificación desde el Business Manager.',
            WhatsAppConfig::GROUPS_ON_BIZ_APP => 'Este número está en coexistencia con la app WhatsApp Business. Meta no habilita grupos para números en ese modo.',
            WhatsAppConfig::GROUPS_TOKEN_INVALID => 'No se pudo verificar el canal: revisá la conexión de WhatsApp.',
            default => $error ?? 'No se pudo determinar si este número puede usar grupos.',
        };
    }
}
