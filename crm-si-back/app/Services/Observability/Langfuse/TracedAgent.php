<?php

namespace App\Services\Observability\Langfuse;

/**
 * Agente de laravel/ai que AgentTracing traza en Langfuse sin código adicional.
 */
interface TracedAgent
{
    /** Nombre de la observación del agente; también es el nombre del trace cuando el agente abre uno propio. */
    public function traceName(): string;

    /** Nombre de cada generación (step) del agente. Estable: lo usan evaluadores y filtros de Langfuse. */
    public function generationName(): string;

    /** Contexto del trace (sesión, usuario, tenant, tags). Solo se usa cuando el agente abre su propio trace. */
    public function traceContext(): TraceContext;
}
