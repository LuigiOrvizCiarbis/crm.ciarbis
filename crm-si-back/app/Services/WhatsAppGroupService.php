<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\WhatsAppGroup;
use App\Services\Concerns\ResolvesWhatsAppCredentials;
use App\Support\MetaOAuth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente saliente de la Groups API de Meta. No hay endpoint para agregar
 * participantes directamente: sólo se puede crear el grupo (asíncrono, el
 * group_id llega por webhook), compartir el invite link, y remover
 * participantes. Límite duro de Meta: 8 participantes por grupo (el número
 * del negocio ocupa un slot).
 *
 * @see https://developers.facebook.com/documentation/business-messaging/whatsapp/groups
 */
class WhatsAppGroupService
{
    use ResolvesWhatsAppCredentials;

    public function __construct(
        private readonly WhatsAppGroupEligibilityService $eligibilityService,
    ) {}

    private function graphVersion(): string
    {
        return (string) config('services.facebook.graph_version', 'v26.0');
    }

    /**
     * Crea el grupo en Meta. La respuesta NO trae el group_id: llega después
     * por el webhook group_lifecycle_update, correlacionado por request_id.
     * Acá sólo se persiste el registro local en estado 'pending'.
     */
    public function createGroup(
        Channel $channel,
        string $subject,
        ?string $description,
        string $joinApprovalMode,
        ?int $createdBy = null,
        ?int $opportunityId = null,
    ): WhatsAppGroup {
        if (mb_strlen($subject) > 128) {
            throw new \InvalidArgumentException('El nombre del grupo no puede superar los 128 caracteres.');
        }

        $credentials = $this->resolveWhatsAppCredentials($channel);

        $waConfig = $channel->whatsappConfig;
        $eligibility = $this->eligibilityService->statusFor($waConfig);
        if ($eligibility['status'] !== \App\Models\WhatsAppConfig::GROUPS_ELIGIBLE) {
            throw new \InvalidArgumentException($eligibility['reason_message']);
        }

        $payload = array_filter([
            'messaging_product' => 'whatsapp',
            'subject' => $subject,
            'description' => $description,
            'join_approval_mode' => $joinApprovalMode,
        ], fn ($value) => $value !== null);

        $response = Http::withToken($credentials['business_token'])
            ->timeout(15)
            ->post("https://graph.facebook.com/{$this->graphVersion()}/{$credentials['business_phone_id']}/groups", $payload);

        if (! $response->successful()) {
            Log::warning('WhatsAppGroupService::createGroup falló', [
                'channel_id' => $channel->id,
                'status' => $response->status(),
                'error' => MetaOAuth::describeMetaError($response->json()),
            ]);

            throw new \RuntimeException('Meta rechazó la creación del grupo: '.$response->json('error.message', $response->body()));
        }

        // Algunas respuestas de Meta pueden traer el request_id directo; si no,
        // se genera uno propio para poder correlacionar igual con el webhook.
        $requestId = $response->json('request_id') ?? (string) \Illuminate\Support\Str::uuid();

        return WhatsAppGroup::create([
            'tenant_id' => $channel->tenant_id,
            'branch_id' => $channel->branch_id,
            'channel_id' => $channel->id,
            'created_by' => $createdBy,
            'opportunity_id' => $opportunityId,
            'request_id' => $requestId,
            'subject' => $subject,
            'description' => $description,
            'join_approval_mode' => $joinApprovalMode,
            'status' => 'pending',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchGroup(WhatsAppGroup $group): array
    {
        $credentials = $this->resolveWhatsAppCredentials($group->channel);
        $this->assertHasGroupId($group);

        $response = Http::withToken($credentials['business_token'])
            ->timeout(15)
            ->get("https://graph.facebook.com/{$this->graphVersion()}/{$group->group_id}", [
                'fields' => 'subject,description,participants,join_approval_mode,suspended,creation_timestamp,total_participant_count',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('No se pudo obtener el estado del grupo desde Meta: '.$response->body());
        }

        return $response->json();
    }

    public function updateGroupSettings(WhatsAppGroup $group, array $attributes): void
    {
        $credentials = $this->resolveWhatsAppCredentials($group->channel);
        $this->assertHasGroupId($group);

        // array_filter($value !== null) descartaría description => null, que
        // es justamente cómo se pide "borrar la descripción" (distinto de no
        // haber mandado la clave). Meta no documenta null para vaciar un
        // string; se envía '' explícito cuando la clave está presente y es null.
        $payload = ['messaging_product' => 'whatsapp'];
        if (array_key_exists('subject', $attributes) && $attributes['subject'] !== null) {
            $payload['subject'] = $attributes['subject'];
        }
        if (array_key_exists('description', $attributes)) {
            $payload['description'] = $attributes['description'] ?? '';
        }
        if (array_key_exists('profile_picture_file', $attributes) && $attributes['profile_picture_file'] !== null) {
            $payload['profile_picture_file'] = $attributes['profile_picture_file'];
        }

        $response = Http::withToken($credentials['business_token'])
            ->timeout(15)
            ->post("https://graph.facebook.com/{$this->graphVersion()}/{$group->group_id}", $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('Meta rechazó la actualización del grupo: '.$response->json('error.message', $response->body()));
        }

        $localUpdates = [];
        if (array_key_exists('subject', $attributes) && $attributes['subject'] !== null) {
            $localUpdates['subject'] = $attributes['subject'];
        }
        if (array_key_exists('description', $attributes)) {
            $localUpdates['description'] = $attributes['description'];
        }
        if ($localUpdates !== []) {
            $group->update($localUpdates);
        }
    }

    public function deleteGroup(WhatsAppGroup $group): void
    {
        $credentials = $this->resolveWhatsAppCredentials($group->channel);
        $this->assertHasGroupId($group);

        $response = Http::withToken($credentials['business_token'])
            ->timeout(15)
            ->delete("https://graph.facebook.com/{$this->graphVersion()}/{$group->group_id}");

        if (! $response->successful()) {
            throw new \RuntimeException('Meta no pudo eliminar el grupo: '.$response->json('error.message', $response->body()));
        }

        $group->update(['status' => 'deleted']);
    }

    public function fetchInviteLink(WhatsAppGroup $group): string
    {
        $credentials = $this->resolveWhatsAppCredentials($group->channel);
        $this->assertHasGroupId($group);

        $response = Http::withToken($credentials['business_token'])
            ->timeout(15)
            ->get("https://graph.facebook.com/{$this->graphVersion()}/{$group->group_id}/invite_link");

        if (! $response->successful()) {
            throw new \RuntimeException('No se pudo obtener el link de invitación: '.$response->body());
        }

        $link = (string) $response->json('invite_link');
        $group->update(['invite_link' => $link]);

        return $link;
    }

    public function resetInviteLink(WhatsAppGroup $group): string
    {
        $credentials = $this->resolveWhatsAppCredentials($group->channel);
        $this->assertHasGroupId($group);

        $response = Http::withToken($credentials['business_token'])
            ->timeout(15)
            ->post("https://graph.facebook.com/{$this->graphVersion()}/{$group->group_id}/invite_link", [
                'messaging_product' => 'whatsapp',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('No se pudo resetear el link de invitación: '.$response->body());
        }

        $link = (string) $response->json('invite_link');
        $group->update(['invite_link' => $link]);

        return $link;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listJoinRequests(WhatsAppGroup $group): array
    {
        $credentials = $this->resolveWhatsAppCredentials($group->channel);
        $this->assertHasGroupId($group);

        $response = Http::withToken($credentials['business_token'])
            ->timeout(15)
            ->get("https://graph.facebook.com/{$this->graphVersion()}/{$group->group_id}/join_requests");

        if (! $response->successful()) {
            throw new \RuntimeException('No se pudieron obtener las solicitudes de ingreso: '.$response->body());
        }

        return $response->json('data', []);
    }

    /**
     * @param  list<string>  $waIds
     */
    public function approveJoinRequests(WhatsAppGroup $group, array $waIds): void
    {
        $this->respondToJoinRequests($group, $waIds, approve: true);
    }

    /**
     * @param  list<string>  $waIds
     */
    public function rejectJoinRequests(WhatsAppGroup $group, array $waIds): void
    {
        $this->respondToJoinRequests($group, $waIds, approve: false);
    }

    /**
     * @param  list<string>  $waIds
     */
    private function respondToJoinRequests(WhatsAppGroup $group, array $waIds, bool $approve): void
    {
        $credentials = $this->resolveWhatsAppCredentials($group->channel);
        $this->assertHasGroupId($group);

        $payload = [
            'messaging_product' => 'whatsapp',
            'join_requests' => array_map(fn (string $waId) => ['wa_id' => $waId], $waIds),
        ];

        $request = Http::withToken($credentials['business_token'])->timeout(15);
        $url = "https://graph.facebook.com/{$this->graphVersion()}/{$group->group_id}/join_requests";
        $response = $approve
            ? $request->post($url, $payload)
            : $request->delete($url, $payload);

        if (! $response->successful()) {
            $action = $approve ? 'aprobar' : 'rechazar';

            throw new \RuntimeException("Meta no pudo {$action} las solicitudes de ingreso: ".$response->body());
        }
    }

    /**
     * Quita hasta 8 participantes de una vez (límite de Meta). No hay
     * endpoint equivalente para agregar: sólo invite link.
     *
     * @param  list<string>  $waIds
     */
    public function removeParticipants(WhatsAppGroup $group, array $waIds): void
    {
        if (count($waIds) > (int) config('whatsapp_groups.max_participants')) {
            throw new \InvalidArgumentException('No se pueden quitar más de '.config('whatsapp_groups.max_participants').' participantes por request.');
        }

        $credentials = $this->resolveWhatsAppCredentials($group->channel);
        $this->assertHasGroupId($group);

        $response = Http::withToken($credentials['business_token'])
            ->timeout(15)
            ->delete("https://graph.facebook.com/{$this->graphVersion()}/{$group->group_id}/participants", [
                'messaging_product' => 'whatsapp',
                'participants' => array_map(fn (string $waId) => ['user' => $waId], $waIds),
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Meta no pudo quitar los participantes: '.$response->body());
        }
    }

    private function assertHasGroupId(WhatsAppGroup $group): void
    {
        if (! $group->group_id) {
            throw new \InvalidArgumentException('El grupo todavía se está creando en WhatsApp: esperá a que Meta confirme la creación.');
        }
    }
}
