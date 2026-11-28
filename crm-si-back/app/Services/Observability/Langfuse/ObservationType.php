<?php

namespace App\Services\Observability\Langfuse;

/**
 * Tipos de observación que Langfuse reconoce en `langfuse.observation.type`.
 */
enum ObservationType: string
{
    case Span = 'span';
    case Generation = 'generation';
    case Event = 'event';
    case Agent = 'agent';
    case Tool = 'tool';
    case Chain = 'chain';
    case Retriever = 'retriever';
}
