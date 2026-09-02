<?php

namespace App\Services\Channels;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\InstagramConfig;
use App\Models\MailConfig;
use App\Models\MessengerConfig;
use App\Models\User;
use App\Models\WhatsAppConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orquesta la desconexión "blanda" de un canal: revoca la suscripción de
 * webhooks en Meta (best-effort), purga las credenciales, resetea el sync de
 * contactos SMB de WhatsApp, y marca el canal como disconnected. Conversaciones,
 * mensajes, difusiones y grupos NO se tocan (todas las FK son cascade — borrar
 * el canal borraría el historial del cliente).
 *
 * Configs compartidas: WhatsAppConfig/InstagramConfig/MessengerConfig pueden
 * estar apuntadas por varios Channel (incluso de otros tenants, porque
 * whatsapp_configs no tiene tenant_id). Solo se revoca y se purga si no queda
 * ningún otro canal ACTIVO sobre la misma config.
 */
class ChannelDisconnector
{
    public function __construct(private readonly MetaWebhookUnsubscriber $unsubscriber) {}

    public function disconnect(Channel $channel, User $actor): DisconnectionResult
    {
        // Fase 1: bajo lock, decidir si esta config queda huérfana y tomar un
        // snapshot de las credenciales ANTES de purgarlas (después de este
        // bloque ya no vamos a poder leerlas). Marcar el canal desconectado
        // siempre, sin importar lo que pase con Meta.
        [$shouldPurge, $snapshot] = DB::transaction(function () use ($channel, $actor) {
            // lockForUpdate() sobre una sola fila NO alcanza: dos canales que
            // comparten config, desconectados en paralelo, tomarían cada uno
            // el lock de su propia fila y leerían al otro como "todavía
            // activo" (su UPDATE aún no confirmó) — ambos decidirían
            // shouldPurge=false y el token quedaría huérfano para siempre,
            // sin que nadie lo revoque. Hay que bloquear el conjunto completo
            // de canales que comparten el mismo recurso de Meta (incluyendo,
            // para IG/Messenger, el caso cross-tabla de página compartida)
            // ANTES de contar, para que la segunda transacción espere a la
            // primera y vea el estado ya actualizado.
            $lockedIds = $this->lockSharedChannelIds($channel);
            $fresh = Channel::withoutGlobalScopes()->findOrFail($channel->id);

            $shouldPurge = $this->otherActiveChannelsCount($fresh, $lockedIds) === 0;
            $snapshot = $shouldPurge ? $this->snapshotCredentials($fresh) : null;

            $fresh->disconnect($actor);

            return [$shouldPurge, $snapshot];
        });

        $channel->refresh();

        if (! $shouldPurge) {
            return new DisconnectionResult(
                credentialsPurged: false,
                unsubscribed: false,
                configShared: true,
                warnings: ['Las credenciales se conservan porque otro canal usa la misma configuración.'],
            );
        }

        // Fase 2: fuera de la transacción — la llamada HTTP no debe mantener
        // el lock de fila abierto.
        $unsubscribed = $this->revokeInMeta($channel, $snapshot);
        $this->purgeCredentials($channel, $snapshot);

        $warnings = [];
        if (! $unsubscribed && $snapshot !== null && $snapshot['token'] !== null) {
            $warnings[] = 'No se pudo confirmar la baja de webhooks en Meta. Las credenciales locales igual se eliminaron.';
        }

        return new DisconnectionResult(
            credentialsPurged: true,
            unsubscribed: $unsubscribed,
            configShared: false,
            warnings: $warnings,
        );
    }

    /**
     * Bloquea (SELECT ... FOR UPDATE) todos los canales que comparten el
     * mismo recurso de Meta que $channel, ANTES de que nadie cuente cuántos
     * quedan activos. Devuelve sus ids.
     *
     * Incluye, para Instagram/Messenger, el caso cross-tabla de página
     * compartida (ver otherActiveChannelsCount()). Ordenado por id: dos
     * desconexiones concurrentes sobre el mismo conjunto deben pedir los
     * locks en el mismo orden, o una espera al otro en vez de deadlockear.
     *
     * @return list<int>
     */
    private function lockSharedChannelIds(Channel $channel): array
    {
        $column = $this->configColumn($channel->type);

        if ($column === null || $channel->{$column} === null) {
            return [$channel->id];
        }

        $query = Channel::withoutGlobalScopes()->where(function ($q) use ($column, $channel) {
            $q->where($column, $channel->{$column})
                ->orWhere('id', $channel->id);
        });

        $pageColumns = $this->pageSharedColumns($channel);
        if ($pageColumns !== null) {
            [$otherConfigModel, $otherColumn, $pageId] = $pageColumns;

            if ($pageId !== null) {
                $otherConfigIds = $otherConfigModel::withoutGlobalScopes()
                    ->where('page_id', $pageId)
                    ->pluck('id');

                if ($otherConfigIds->isNotEmpty()) {
                    $query->orWhereIn($otherColumn, $otherConfigIds);
                }
            }
        }

        return $query->orderBy('id')->lockForUpdate()->pluck('id')->all();
    }

    /**
     * Cuenta canales ACTIVOS distintos de $channel dentro del conjunto ya
     * bloqueado por lockSharedChannelIds(). No vuelve a consultar la config
     * sin lock: eso reabriría la ventana de carrera que este método existe
     * para cerrar.
     *
     * @param  list<int>  $lockedIds
     */
    private function otherActiveChannelsCount(Channel $channel, array $lockedIds): int
    {
        $otherIds = array_values(array_diff($lockedIds, [$channel->id]));

        if (empty($otherIds)) {
            return 0;
        }

        return Channel::withoutGlobalScopes()
            ->whereIn('id', $otherIds)
            ->where('status', 'active')
            ->count();
    }

    /**
     * Para Instagram/Messenger, `subscribed_apps` es un recurso de la PÁGINA
     * de Facebook, no de la config: si la misma página tiene ambos servicios
     * conectados (dos filas, InstagramConfig y MessengerConfig, con el mismo
     * page_id), hay que considerar también los canales del otro tipo.
     *
     * @return array{0: class-string<InstagramConfig|MessengerConfig>, 1: string, 2: ?string}|null
     */
    private function pageSharedColumns(Channel $channel): ?array
    {
        return match ($channel->type) {
            ChannelType::INSTAGRAM => [MessengerConfig::class, 'messenger_config_id', $channel->instagramConfig?->page_id],
            ChannelType::FACEBOOK => [InstagramConfig::class, 'instagram_config_id', $channel->facebookConfig?->page_id],
            default => null,
        };
    }

    private function configColumn(ChannelType $type): ?string
    {
        return match ($type) {
            ChannelType::WHATSAPP => 'whatsapp_config_id',
            ChannelType::INSTAGRAM => 'instagram_config_id',
            ChannelType::FACEBOOK => 'messenger_config_id',
            ChannelType::MAIL => 'mail_config_id',
            default => null,
        };
    }

    /**
     * @return array{token: ?string, remote_id: ?string}|null
     */
    private function snapshotCredentials(Channel $channel): ?array
    {
        return match ($channel->type) {
            ChannelType::WHATSAPP => $channel->whatsappConfig ? [
                'token' => $channel->whatsappConfig->getDecryptedToken(),
                'remote_id' => $channel->whatsappConfig->waba_id,
            ] : null,
            ChannelType::INSTAGRAM => $channel->instagramConfig ? [
                'token' => $channel->instagramConfig->getDecryptedToken(),
                'remote_id' => $channel->instagramConfig->page_id,
            ] : null,
            ChannelType::FACEBOOK => $channel->facebookConfig ? [
                'token' => $channel->facebookConfig->getDecryptedToken(),
                'remote_id' => $channel->facebookConfig->page_id,
            ] : null,
            default => null,
        };
    }

    private function revokeInMeta(Channel $channel, ?array $snapshot): bool
    {
        if ($snapshot === null || $snapshot['token'] === null || $snapshot['remote_id'] === null) {
            return true;
        }

        return match ($channel->type) {
            ChannelType::WHATSAPP => $this->unsubscriber->unsubscribeWaba($snapshot['remote_id'], $snapshot['token']),
            ChannelType::INSTAGRAM, ChannelType::FACEBOOK => $this->unsubscriber->unsubscribePage($snapshot['remote_id'], $snapshot['token']),
            default => true,
        };
    }

    private function purgeCredentials(Channel $channel, ?array $snapshot): void
    {
        match ($channel->type) {
            ChannelType::WHATSAPP => $this->purgeWhatsApp($channel),
            ChannelType::INSTAGRAM => $channel->instagramConfig?->forceFill(['page_access_token' => null])->save(),
            ChannelType::FACEBOOK => $channel->facebookConfig?->forceFill(['page_access_token' => null])->save(),
            ChannelType::MAIL => $this->purgeMail($channel),
            default => Log::info('ChannelDisconnector: sin credenciales que purgar', [
                'channel_id' => $channel->id,
                'type' => $channel->type->value,
            ]),
        };
    }

    /**
     * Purga el token de WhatsApp y resetea el estado del sync de contactos
     * SMB para que la reconexión pueda volver a dispararlo (ver plan §4b).
     *
     * NO se toca `registration_pin`: es el two-step verification real del
     * número en Meta. Si lo borráramos, la reconexión generaría un PIN nuevo
     * y Meta rechazaría /register con PIN mismatch, dejando el canal
     * inutilizable hasta que soporte de Meta intervenga.
     */
    private function purgeWhatsApp(Channel $channel): void
    {
        $config = $channel->whatsappConfig;
        if (! $config) {
            return;
        }

        $config->forceFill([
            'bussines_token' => null,
            'contact_sync_status' => WhatsAppConfig::SYNC_PENDING,
            'contact_sync_requested_at' => null,
            'contact_sync_first_webhook_at' => null,
            'contact_sync_last_webhook_at' => null,
            'contact_sync_contacts_count' => 0,
            'contact_sync_request_id' => null,
            'contact_sync_error' => null,
            'contact_sync_error_code' => null,
            'contact_sync_retryable' => null,
            'contact_history_sync_status' => null,
            'contact_history_sync_requested_at' => null,
            'contact_history_sync_request_id' => null,
            'contact_history_sync_error' => null,
            'contact_history_sync_messages_count' => 0,
        ])->save();
    }

    /**
     * Purga solo la contraseña. Se conservan last_uid/uidvalidity: son el
     * cursor IMAP, y perderlos haría que la reconexión reimporte la casilla
     * entera desde cero.
     */
    private function purgeMail(Channel $channel): void
    {
        $config = $channel->mailConfig;
        if (! $config instanceof MailConfig) {
            return;
        }

        $config->forceFill(['password' => null])->save();
    }
}
