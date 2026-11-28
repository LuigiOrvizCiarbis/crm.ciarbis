<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Langfuse (observabilidad de IA)
    |--------------------------------------------------------------------------
    |
    | Las llamadas a modelos (respuesta automática, asistente interno y
    | extracción de documentos) se registran como traces OpenTelemetry y se
    | envían a Langfuse. Apagado o sin keys, el tracer no hace nada y no sale
    | ningún request.
    |
    */

    'enabled' => (bool) env('LANGFUSE_ENABLED', false),

    // Cloud EU por defecto. Sin barra final: se le agregan los paths de la API.
    'host' => rtrim((string) env('LANGFUSE_HOST', 'https://cloud.langfuse.com'), '/'),

    'public_key' => env('LANGFUSE_PUBLIC_KEY'),

    'secret_key' => env('LANGFUSE_SECRET_KEY'),

    // Entorno de Langfuse: minúsculas, dígitos, "-" y "_" (p. ej. production, local).
    'environment' => env('LANGFUSE_ENVIRONMENT', env('APP_ENV', 'production')),

    // Versión desplegada (p. ej. el git sha): permite comparar calidad antes
    // y después de un cambio de prompt o de modelo.
    'release' => env('LANGFUSE_RELEASE'),

    // Timeout (s) de cada envío a Langfuse. Corre después de responder al
    // cliente, pero demora al worker en tomar el siguiente job. Vacío o 0
    // usa el default: para Guzzle 0 es "esperar para siempre".
    'timeout' => max(1.0, (float) (env('LANGFUSE_TIMEOUT') ?: 3)),

    // Tope de caracteres por string exportado (system prompts, documentos).
    'max_string_chars' => (int) env('LANGFUSE_MAX_STRING_CHARS', 50000),

];
