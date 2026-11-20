<?php

namespace App\Services\Ai;

/**
 * Varios conjuntos de herramientas ofrecidos juntos (agenda, ventas): las
 * llamadas se derivan al conjunto dueño de cada herramienta.
 */
class AiToolsetGroup implements AiToolset
{
    /** @var list<AiToolset> */
    private array $toolsets;

    public function __construct(AiToolset ...$toolsets)
    {
        $this->toolsets = array_values($toolsets);
    }

    public function definitions(): array
    {
        return array_merge(...array_map(fn (AiToolset $toolset) => $toolset->definitions(), $this->toolsets));
    }

    public function call(string $name, array $input): string
    {
        foreach ($this->toolsets as $toolset) {
            foreach ($toolset->definitions() as $tool) {
                if ($tool->name === $name) {
                    return $toolset->call($name, $input);
                }
            }
        }

        return 'Herramienta desconocida.';
    }

    public function promptSection(): string
    {
        return implode("\n\n", array_filter(
            array_map(fn (AiToolset $toolset) => $toolset->promptSection(), $this->toolsets),
            fn (string $section) => $section !== '',
        ));
    }

    public function fallbackMessage(): ?string
    {
        foreach ($this->toolsets as $toolset) {
            $message = $toolset->fallbackMessage();
            if ($message !== null) {
                return $message;
            }
        }

        return null;
    }
}
