<?php

namespace App\Services\Scheduling;

use App\Models\AiSchedulingConfig;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Decide si el bot puede agendar en una conversación y sobre qué calendario:
 * el del vendedor asignado si tiene Google conectado; si no, el usuario por
 * defecto de la config. Corre en la cola, sin usuario autenticado.
 */
class AppointmentToolsetFactory
{
    public function __construct(
        private AppointmentAvailabilityService $availability,
        private AppointmentBookingService $booking,
    ) {}

    public function forConversation(Conversation $conversation): ?AppointmentToolset
    {
        $config = AiSchedulingConfig::withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('enabled', true)
            ->first();

        if (! $config) {
            return null;
        }

        $owner = $this->resolveOwner($conversation, $config);

        if (! $owner) {
            Log::info('AppointmentToolsetFactory: sin calendario conectado', [
                'conversation_id' => $conversation->id,
                'assigned_to' => $conversation->assigned_to,
                'default_user_id' => $config->default_user_id,
            ]);

            return null;
        }

        return new AppointmentToolset($conversation, $config, $owner, $this->availability, $this->booking);
    }

    private function resolveOwner(Conversation $conversation, AiSchedulingConfig $config): ?User
    {
        $candidates = array_values(array_unique(array_filter([
            $conversation->assigned_to,
            $config->default_user_id,
        ])));

        foreach ($candidates as $userId) {
            $user = User::withoutGlobalScopes()
                ->where('tenant_id', $conversation->tenant_id)
                ->whereKey($userId)
                ->whereHas('googleCalendarConnection', fn ($query) => $query->withoutGlobalScopes()->where('status', 'connected'))
                ->first();

            if ($user) {
                return $user;
            }
        }

        return null;
    }
}
