<?php

namespace App\Services\Ai\Assistant;

/**
 * Proveedor del asistente listo para Ai::build(), con el modelo a usar.
 * metered indica que el costo lo paga la plataforma y aplica el cupo diario.
 */
final readonly class ResolvedAssistantProvider
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public array $config,
        public string $model,
        public bool $metered,
    ) {}
}
