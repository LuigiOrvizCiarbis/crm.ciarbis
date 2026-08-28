<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Aviso único de que terminó de importarse el historial de coexistencia.
 *
 * A diferencia de MessageSent/TenantMessageReceived, que se emiten por cada
 * mensaje, este evento se emite UNA sola vez al final de la importación y no
 * lleva los mensajes en el payload: sólo le dice al front que recargue las
 * conversaciones.
 *
 * El motivo es un incidente real: importar un historial completo emitía un
 * broadcast por mensaje (cientos de golpe) y Reverb los rechazaba con
 * "Pusher error: Payload too large", excepción que además abortaba el
 * onboarding a mitad de camino.
 */
class HistorySyncCompleted implements ShouldBroadcastNow
{
    public function __construct(
        public int $tenantId,
        public int $importedCount,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenant.'.$this->tenantId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'history.sync.completed';
    }

    /**
     * Payload deliberadamente mínimo: sin mensajes ni conversaciones serializadas.
     *
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return ['imported' => $this->importedCount];
    }
}
