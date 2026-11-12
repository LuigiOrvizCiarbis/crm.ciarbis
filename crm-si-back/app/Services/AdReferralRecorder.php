<?php

namespace App\Services;

use App\Models\AdReferral;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Normaliza el `referral` que Meta adjunta a los mensajes que llegan desde un
 * anuncio (Click-to-WhatsApp / Messenger / Instagram Direct) y lo guarda como
 * historial de atribución del contacto.
 */
class AdReferralRecorder
{
    private const WHATSAPP_SOURCE_TYPES = ['ad', 'post'];

    /**
     * @param  array<string, mixed>  $referral  `messages[].referral` del webhook de WhatsApp Cloud API.
     * @return array<string, mixed>|null
     */
    public function fromWhatsApp(array $referral): ?array
    {
        if ($referral === []) {
            return null;
        }

        $sourceType = data_get($referral, 'source_type') ?? 'ad';
        if (! in_array($sourceType, self::WHATSAPP_SOURCE_TYPES, true)) {
            return null;
        }

        $adId = data_get($referral, 'source_id');

        return [
            'source_type' => $sourceType,
            'ad_id' => $adId !== null ? (string) $adId : null,
            'headline' => data_get($referral, 'headline'),
            'body' => data_get($referral, 'body'),
            'source_url' => data_get($referral, 'source_url'),
            'media_type' => data_get($referral, 'media_type'),
            'media_url' => data_get($referral, 'image_url')
                ?? data_get($referral, 'thumbnail_url')
                ?? data_get($referral, 'video_url'),
            'ctwa_clid' => data_get($referral, 'ctwa_clid'),
            'ref' => null,
            'post_id' => null,
            'raw' => $referral,
        ];
    }

    /**
     * Messenger e Instagram comparten el formato. Solo `source: ADS` es un
     * anuncio; SHORTLINK, IGME-SOURCE-LINK y los referral de Shops se ignoran.
     *
     * @param  array<string, mixed>  $referral
     * @return array<string, mixed>|null
     */
    public function fromMetaMessaging(array $referral): ?array
    {
        if (strtoupper((string) ($referral['source'] ?? '')) !== 'ADS') {
            return null;
        }

        $adId = data_get($referral, 'ad_id');
        $photoUrl = data_get($referral, 'ads_context_data.photo_url');
        $videoUrl = data_get($referral, 'ads_context_data.video_url');

        return [
            'source_type' => 'ad',
            'ad_id' => $adId !== null ? (string) $adId : null,
            'ref' => data_get($referral, 'ref'),
            'headline' => data_get($referral, 'ads_context_data.ad_title'),
            'body' => null,
            'source_url' => null,
            'media_type' => $photoUrl ? 'image' : ($videoUrl ? 'video' : null),
            'media_url' => $photoUrl ?? $videoUrl,
            'post_id' => data_get($referral, 'ads_context_data.post_id'),
            'ctwa_clid' => null,
            'raw' => $referral,
        ];
    }

    /**
     * Los `timestamp` de Messenger e Instagram vienen en milisegundos. Se pasa
     * a la zona de la app porque Eloquent guarda la hora sin convertirla.
     */
    public function referredAtFromMillis(mixed $millis): CarbonInterface
    {
        if (! is_numeric($millis)) {
            return now();
        }

        return Carbon::createFromTimestampMs((int) $millis)->setTimezone(config('app.timezone'));
    }

    /**
     * Nunca lanza: un fallo de atribución no debe reintentar ni perder el
     * mensaje (en WhatsApp el reintento del job choca con el wamid duplicado).
     *
     * @param  array<string, mixed>  $attributes  Resultado de fromWhatsApp()/fromMetaMessaging().
     */
    public function record(
        array $attributes,
        string $platform,
        string $dedupeKey,
        Contact $contact,
        Conversation $conversation,
        ?Message $message,
        CarbonInterface $referredAt,
    ): ?AdReferral {
        try {
            // tenant_id explícito: en el webhook no hay usuario autenticado.
            return AdReferral::firstOrCreate(['dedupe_key' => $dedupeKey], $attributes + [
                'tenant_id' => $conversation->tenant_id,
                'channel_id' => $conversation->channel_id,
                'contact_id' => $contact->id,
                'conversation_id' => $conversation->id,
                'message_id' => $message?->id,
                'platform' => $platform,
                'referred_at' => $referredAt,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Ad referral no registrado', [
                'dedupe_key' => $dedupeKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
