<?php

namespace App\Services\Flows\Builder;

/**
 * Caminos entre las pantallas del constructor. Un destino es `following`
 * (la pantalla siguiente en la lista; en la última, el fin), `end` (fin del
 * formulario, o la reserva de turno si está activa) o `screen:<id>`.
 * Cada pantalla tiene un destino (`next`) y, opcionalmente, una pregunta de
 * opciones cuyas respuestas eligen otro (`branches`). Un índice null es el fin.
 *
 * Asume una definición validada: los saltos apuntan a pantallas posteriores.
 */
final class FlowDefinitionRoutes
{
    public const FOLLOWING = 'following';

    public const END = 'end';

    public const SCREEN_PREFIX = 'screen:';

    public const BRANCHING_TYPES = ['dropdown', 'single_choice'];

    /** @param  list<array<string, mixed>>  $screens */
    public function __construct(private readonly array $screens) {}

    public static function fromDefinition(array $definition): self
    {
        return new self(array_values($definition['screens'] ?? []));
    }

    public function count(): int
    {
        return count($this->screens);
    }

    /** Pregunta que elige el camino de la pantalla, si la hay. */
    public function branchingBlock(int $index): ?array
    {
        foreach ($this->screens[$index]['blocks'] ?? [] as $block) {
            if (in_array($block['type'] ?? null, self::BRANCHING_TYPES, true) && ! empty($block['branches'])) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Destinos posibles de la pantalla, sin repetir. Con una pregunta que
     * elige el camino, cada opción lleva a su destino; si la pregunta no es
     * obligatoria, sin respuesta se sigue el destino de la pantalla.
     *
     * @return list<?int>
     */
    public function destinations(int $index): array
    {
        $screenTarget = $this->screenTarget($index);
        $block = $this->branchingBlock($index);

        if ($block === null) {
            return [$this->resolve($index, $screenTarget)];
        }

        $destinations = [];
        foreach ($block['options'] ?? [] as $option) {
            $destinations[] = $this->resolve($index, (string) ($block['branches'][(string) $option['id']] ?? $screenTarget));
        }

        if (($block['required'] ?? false) !== true) {
            $destinations[] = $this->resolve($index, $screenTarget);
        }

        $unique = [];
        foreach ($destinations as $destination) {
            if (! in_array($destination, $unique, true)) {
                $unique[] = $destination;
            }
        }

        return $unique;
    }

    /** Pantalla a la que lleva lo que respondió el cliente; null es el fin. */
    public function next(int $index, array $answers): ?int
    {
        $block = $this->branchingBlock($index);
        $answer = $block !== null ? ($answers[$block['key']] ?? null) : null;
        $target = is_string($answer) && isset($block['branches'][$answer])
            ? (string) $block['branches'][$answer]
            : $this->screenTarget($index);

        return $this->resolve($index, $target);
    }

    /** @return array<int, true> índices a los que se llega desde la primera pantalla */
    public function reachable(): array
    {
        $seen = [];
        $pending = $this->screens === [] ? [] : [0];

        while ($pending !== []) {
            $index = array_pop($pending);

            if (isset($seen[$index])) {
                continue;
            }

            $seen[$index] = true;

            foreach ($this->destinations($index) as $destination) {
                if ($destination !== null) {
                    $pending[] = $destination;
                }
            }
        }

        return $seen;
    }

    public function indexOf(string $screenId): ?int
    {
        foreach ($this->screens as $index => $screen) {
            if (($screen['id'] ?? null) === $screenId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Índice al que apunta un destino; null es el fin. Un `screen:` que no
     * existe se trata como el fin (la validación ya lo rechaza al guardar).
     */
    public function resolve(int $index, string $target): ?int
    {
        if ($target === self::END) {
            return null;
        }

        if (str_starts_with($target, self::SCREEN_PREFIX)) {
            return $this->indexOf(substr($target, strlen(self::SCREEN_PREFIX)));
        }

        return $index + 1 < count($this->screens) ? $index + 1 : null;
    }

    private function screenTarget(int $index): string
    {
        return (string) ($this->screens[$index]['next'] ?? self::FOLLOWING);
    }
}
