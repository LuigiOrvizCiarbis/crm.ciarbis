<?php

namespace App\Services\Ai;

/**
 * Conjunto de herramientas que se ofrece al modelo durante una generación.
 */
interface AiToolset
{
    /**
     * @return list<AiTool>
     */
    public function definitions(): array;

    /**
     * Ejecuta la herramienta y devuelve el texto que recibe el modelo como
     * resultado. Nunca lanza: los errores se devuelven como texto.
     *
     * @param  array<string, mixed>  $input
     */
    public function call(string $name, array $input): string;
}
