<?php

namespace App\Services;

use App\Enums\ContactFieldType;
use App\Exceptions\MetaConversionRequestException;
use App\Models\AdReferral;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\MetaConversionEvent;
use App\Models\Opportunity;
use App\Support\MetaOAuth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Conversions API for Business Messaging: avisa a Meta los leads y ventas de
 * chats que vinieron de un anuncio, para que los atribuya y optimice la
 * campaña. Cada evento sale una sola vez por oportunidad (fila única en
 * meta_conversion_events): Meta no deduplica estos eventos.
 *
 * @see https://developers.facebook.com/docs/marketing-api/conversions-api/business-messaging
 */
class MetaConversionService
{
    /** ad_referrals.platform => messaging_channel de Meta. */
    private const CHANNELS = [
        'whatsapp' => 'whatsapp',
        'facebook' => 'messenger',
        'instagram' => 'instagram',
    ];

    /** ad_referrals.platform => relación de Channel con la config del canal. */
    private const CONFIG_RELATIONS = [
        'whatsapp' => 'whatsappConfig',
        'facebook' => 'facebookConfig',
        'instagram' => 'instagramConfig',
    ];

    public function send(int $opportunityId, string $eventName): void
    {
        $opportunity = Opportunity::withoutGlobalScopes()
            ->with(['contact' => fn ($query) => $query->withoutGlobalScopes()])
            ->find($opportunityId);
        if (! $opportunity) {
            return;
        }

        $referral = $this->eligibleReferral($opportunity, $eventName);
        if (! $referral) {
            return;
        }

        $target = $this->target($referral, $opportunity->contact);
        if ($target === null) {
            $this->recordMissingCredentials($opportunity, $eventName, $referral);

            return;
        }

        $event = MetaConversionEvent::withoutGlobalScopes()->createOrFirst(
            ['opportunity_id' => $opportunity->id, 'event_name' => $eventName],
            [
                'tenant_id' => $opportunity->tenant_id,
                'ad_referral_id' => $referral->id,
                'messaging_channel' => $target['channel'],
                'status' => 'pending',
            ],
        );

        // Reclamo atómico: si otro worker ya lo envía (o ya terminó), no se repite.
        $claimed = MetaConversionEvent::withoutGlobalScopes()
            ->whereKey($event->id)
            ->where('status', 'pending')
            ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1')]);
        if ($claimed === 0) {
            return;
        }
        $event->refresh();

        $payload = null;
        try {
            $datasetId = $this->datasetId($target);
            $payload = ['data' => [$this->eventData($opportunity, $eventName, $target)]];
            $response = $this->post($target['token'], "{$datasetId}/events", $payload);
        } catch (MetaConversionRequestException $e) {
            if ($e->retryable) {
                $event->forceFill(['status' => 'pending', 'error' => $e->getMessage()])->save();

                throw $e;
            }

            $this->markRejected($event, $e, $payload);

            return;
        }

        $event->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'payload' => $payload,
            'response' => $response->json(),
            'error' => null,
        ])->save();
    }

    /**
     * Último referral elegible del chat (o del contacto, para ventas sin chat)
     * en una plataforma habilitada. WhatsApp exige ctwa_clid; Messenger e
     * Instagram, el id del contacto en la plataforma.
     */
    private function eligibleReferral(Opportunity $opportunity, string $eventName): ?AdReferral
    {
        $query = AdReferral::withoutGlobalScopes();
        if ($opportunity->conversation_id) {
            $query->where('conversation_id', $opportunity->conversation_id);
        } elseif ($eventName === MetaConversionEvent::PURCHASE && $opportunity->contact_id) {
            $query->where('contact_id', $opportunity->contact_id);
        } else {
            return null;
        }

        $platforms = array_values(array_intersect(
            (array) config('services.meta.conversions_api_platforms', []),
            array_keys(self::CHANNELS),
        ));

        $referral = $query
            ->whereIn('platform', $platforms)
            ->where(fn ($q) => $q->where('platform', '!=', 'whatsapp')->orWhereNotNull('ctwa_clid'))
            ->orderByDesc('referred_at')
            ->orderByDesc('id')
            ->first();

        if ($referral && $referral->platform !== 'whatsapp' && blank($opportunity->contact?->external_id)) {
            return null;
        }

        return $referral;
    }

    /**
     * Cuenta de Meta, token y user_data del canal por donde entró el anuncio;
     * null si falta el canal, su config, la cuenta o el token.
     *
     * @return array{channel: string, config: Model, account_id: string, token: string, user_data: array<string, string>}|null
     */
    private function target(AdReferral $referral, ?Contact $contact): ?array
    {
        $relation = self::CONFIG_RELATIONS[$referral->platform];
        $channel = Channel::withoutGlobalScopes()
            ->with([$relation => fn ($query) => $query->withoutGlobalScopes()])
            ->find($referral->channel_id);
        $config = $channel?->{$relation};
        if (! $config) {
            return null;
        }

        $externalId = (string) $contact?->external_id;
        [$accountId, $userData] = match ($referral->platform) {
            'whatsapp' => [$config->waba_id, ['whatsapp_business_account_id' => $config->waba_id, 'ctwa_clid' => $referral->ctwa_clid]],
            'facebook' => [$config->page_id, ['page_id' => $config->page_id, 'page_scoped_user_id' => $externalId]],
            'instagram' => [$config->ig_user_id, ['instagram_business_account_id' => $config->ig_user_id, 'ig_sid' => $externalId]],
        };

        $token = $config->getDecryptedToken();
        if (! $accountId || ! $token) {
            return null;
        }

        return [
            'channel' => self::CHANNELS[$referral->platform],
            'config' => $config,
            'account_id' => (string) $accountId,
            'token' => $token,
            'user_data' => $userData,
        ];
    }

    /**
     * Dataset de la cuenta. Si no está guardado se pide a Meta, que devuelve
     * el existente o crea uno.
     *
     * @param  array{config: Model, account_id: string, token: string}  $target
     */
    private function datasetId(array $target): string
    {
        $config = $target['config'];
        if ($config->meta_dataset_id) {
            return $config->meta_dataset_id;
        }

        $response = $this->post($target['token'], "{$target['account_id']}/dataset");
        $datasetId = (string) $response->json('id');
        if ($datasetId === '') {
            throw new MetaConversionRequestException('Meta no devolvió el dataset.', false, $response->json());
        }

        $config->forceFill(['meta_dataset_id' => $datasetId])->save();

        return $datasetId;
    }

    /**
     * @param  array{channel: string, user_data: array<string, string>}  $target
     * @return array<string, mixed>
     */
    private function eventData(Opportunity $opportunity, string $eventName, array $target): array
    {
        $isPurchase = $eventName === MetaConversionEvent::PURCHASE;
        $data = [
            'event_name' => $eventName,
            'event_time' => $isPurchase ? ($opportunity->closed_at ?? now())->timestamp : $opportunity->created_at->timestamp,
            'action_source' => 'business_messaging',
            'messaging_channel' => $target['channel'],
            'user_data' => $target['user_data'],
        ];

        if ($isPurchase) {
            $data['custom_data'] = [
                'currency' => ContactFieldType::DEFAULT_CURRENCY,
                'value' => round((float) ($opportunity->value ?? 0), 2),
            ];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws MetaConversionRequestException reintentable si es de conexión, 429 o 5xx.
     */
    private function post(string $token, string $path, array $body = []): Response
    {
        try {
            // Un array vacío se codifica como "[]" y Graph exige que la raíz sea un
            // objeto: sin cuerpo (POST /dataset) se manda "{}".
            $response = Http::withToken($token)->timeout(15)->acceptJson()
                ->post($this->graph($path), $body === [] ? new \stdClass : $body);
        } catch (ConnectionException $e) {
            throw new MetaConversionRequestException(MetaOAuth::scrubMessage($e->getMessage()), true);
        }

        if ($response->successful()) {
            return $response;
        }

        $error = is_array($response->json()) ? $response->json() : null;

        throw new MetaConversionRequestException(
            MetaOAuth::formatMetaError($error),
            $response->status() === 429 || $response->serverError(),
            $error,
        );
    }

    /**
     * Sin credenciales del canal no hay envío posible: queda registrado como
     * fallido. Un evento ya enviado o en curso no se toca.
     */
    private function recordMissingCredentials(Opportunity $opportunity, string $eventName, AdReferral $referral): void
    {
        $attributes = ['status' => 'failed', 'error' => 'missing_channel_credentials'];
        $event = MetaConversionEvent::withoutGlobalScopes()->createOrFirst(
            ['opportunity_id' => $opportunity->id, 'event_name' => $eventName],
            [
                'tenant_id' => $opportunity->tenant_id,
                'ad_referral_id' => $referral->id,
                'messaging_channel' => self::CHANNELS[$referral->platform],
                ...$attributes,
            ],
        );

        if (! $event->wasRecentlyCreated && $event->status === 'pending') {
            $event->forceFill($attributes)->save();
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function markRejected(MetaConversionEvent $event, MetaConversionRequestException $e, ?array $payload): void
    {
        $event->forceFill([
            'status' => 'failed',
            'payload' => $payload,
            'response' => $e->response,
            'error' => $e->getMessage(),
        ])->save();

        Log::warning('Meta conversion event rechazado', [
            'opportunity_id' => $event->opportunity_id,
            'event' => $event->event_name,
            'error' => MetaOAuth::describeMetaError($e->response),
        ]);
    }

    private function graph(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.facebook.graph_version', 'v26.0').'/'.$path;
    }
}
