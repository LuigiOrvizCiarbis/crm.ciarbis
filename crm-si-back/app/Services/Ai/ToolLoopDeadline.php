<?php

namespace App\Services\Ai;

/**
 * Tiempo total disponible para una generación con herramientas.
 *
 * GenerateAiReplyJob muere a los 120 s con tries=1: si el loop se pasa, el
 * turno puede quedar creado sin que el cliente reciba respuesta. Por eso el
 * presupuesto se verifica ANTES de cada operación bloqueante y cada request
 * al modelo recibe como timeout lo que queda, no el timeout fijo del driver.
 */
final class ToolLoopDeadline
{
    /** Mínimo restante para iniciar un request al modelo. */
    public const MIN_REQUEST_SECONDS = 5.0;

    /**
     * Mínimo restante para ejecutar herramientas: alcanza para consultar
     * Google (timeout propio) y hacer el request final con la respuesta.
     */
    public const TOOL_RESERVE_SECONDS = 25.0;

    private float $deadline;

    private \Closure $clock;

    public function __construct(?float $budgetSeconds = null, ?callable $clock = null)
    {
        $budget = $budgetSeconds ?? (float) config('services.ai.tool_loop_budget_seconds', 80);
        $this->clock = $clock === null ? static fn (): float => microtime(true) : \Closure::fromCallable($clock);
        $this->deadline = ($this->clock)() + $budget;
    }

    public function remaining(): float
    {
        return max(0.0, $this->deadline - ($this->clock)());
    }

    public function canRequest(): bool
    {
        return $this->remaining() >= self::MIN_REQUEST_SECONDS;
    }

    public function canRunTools(): bool
    {
        return $this->remaining() >= self::TOOL_RESERVE_SECONDS;
    }

    /** Timeout del próximo request: el del driver, acotado a lo que queda. */
    public function requestTimeout(float $driverTimeout): float
    {
        return min($driverTimeout, $this->remaining());
    }
}
