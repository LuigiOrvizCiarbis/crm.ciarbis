<?php

namespace App\Services\Ai;

/**
 * Resultado de extraer datos estructurados de un documento.
 *
 * A diferencia de generate(), que ante cualquier error loguea y devuelve null,
 * acá cada modo de falla tiene su código: una extracción corre en un job y su
 * estado se persiste para que el usuario sepa qué pasó. "Key inválida", "sin
 * saldo" y "el modelo devolvió algo que no valida" necesitan mensajes distintos.
 *
 * También expone el usage real. generate() lo descarta; una extracción cuesta
 * bastante más por request, así que conviene poder medirla.
 */
class AiExtractionResult
{
    /**
     * @param  array<string, mixed>  $data  Campos extraídos, ya validados contra el schema.
     * @param  string|null  $errorCode  invalid_key | no_credit | rate_limit |
     *                                  invalid_output | unsupported | unknown
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $data = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(array $data, ?int $inputTokens = null, ?int $outputTokens = null): self
    {
        return new self(true, $data, inputTokens: $inputTokens, outputTokens: $outputTokens);
    }

    public static function failure(
        string $errorCode,
        ?string $errorMessage = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
    ): self {
        return new self(
            false,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        );
    }
}
