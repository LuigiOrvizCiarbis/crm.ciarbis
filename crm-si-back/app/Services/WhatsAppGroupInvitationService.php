<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppGroupParticipant;
use App\Models\WhatsAppTemplate;

/**
 * Invita gente a un grupo. Meta no tiene "agregar participante": la única
 * vía es un invite link, enviado 1:1 (no al grupo) con una plantilla que
 * lleva el group_id como parámetro — Meta lo traduce al link al entregar.
 */
class WhatsAppGroupInvitationService
{
    public function __construct(
        private readonly WhatsAppMessageService $messageService,
        private readonly WhatsAppTemplateService $templateService,
    ) {}

    /**
     * @param  list<array{contact_id?: int, phone?: string, name?: string}>  $invitees
     * @return list<WhatsAppGroupParticipant>
     */
    public function invite(WhatsAppGroup $group, WhatsAppTemplate $template, array $invitees, User $sender): array
    {
        if (! $group->group_id || ! $group->isActive()) {
            throw new \InvalidArgumentException('El grupo todavía se está creando en WhatsApp o ya no está activo.');
        }

        $maxParticipants = (int) config('whatsapp_groups.max_participants');
        $currentCount = $group->activeParticipants()->count();
        $pendingCount = $group->participants()->where('status', 'invited')->count();

        if ($currentCount + $pendingCount + count($invitees) > $maxParticipants) {
            $available = max(0, $maxParticipants - $currentCount - $pendingCount);
            throw new \InvalidArgumentException("Este grupo admite hasta {$maxParticipants} participantes (incluido el número del negocio). Quedan {$available} lugares disponibles.");
        }

        $channel = $group->channel;

        $participants = [];
        foreach ($invitees as $invitee) {
            $contact = $this->resolveContact($invitee, $channel->tenant_id, $channel->branch_id);

            $conversation = $this->messageService->findOrCreateConversation($contact, $channel);

            $message = $this->templateService->sendTemplateMessage(
                $conversation,
                $template,
                [[
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'group_id', 'group_id' => $group->group_id],
                    ],
                ]],
                $sender,
            );

            $participants[] = WhatsAppGroupParticipant::updateOrCreate(
                ['whatsapp_group_id' => $group->id, 'wa_id' => $contact->phone],
                [
                    'contact_id' => $contact->id,
                    'display_name' => $contact->name,
                    'status' => 'invited',
                    'invited_message_id' => $message->id,
                ]
            );
        }

        return $participants;
    }

    /**
     * @param  array{contact_id?: int, phone?: string, name?: string}  $invitee
     */
    private function resolveContact(array $invitee, int $tenantId, ?int $branchId): Contact
    {
        if (isset($invitee['contact_id'])) {
            // Explícito por tenant_id, sin depender de que el TenantScope
            // global esté activo en este contexto: un contact_id de otro
            // tenant no debe ser invitable a este grupo.
            return Contact::where('tenant_id', $tenantId)->findOrFail($invitee['contact_id']);
        }

        if (! isset($invitee['phone'])) {
            throw new \InvalidArgumentException('Cada invitado necesita un contact_id o un teléfono.');
        }

        return Contact::firstOrCreate(
            ['tenant_id' => $tenantId, 'phone' => $invitee['phone']],
            ['name' => $invitee['name'] ?? 'Sin nombre', 'source' => 'whatsapp', 'branch_id' => $branchId]
        );
    }
}
