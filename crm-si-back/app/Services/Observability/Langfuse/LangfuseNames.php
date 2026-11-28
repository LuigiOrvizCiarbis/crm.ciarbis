<?php

namespace App\Services\Observability\Langfuse;

/**
 * Nombres estables de traces, observaciones y scores. Los evaluadores,
 * filtros y dashboards de Langfuse se configuran por nombre: cambiar uno
 * de estos valores rompe esa configuración. Un agente de laravel/ai que
 * reemplace a un provider tiene que emitir los mismos nombres.
 */
final class LangfuseNames
{
    public const AUTO_REPLY = 'ai-auto-reply';

    public const DECIDE = 'decide';

    public const RESPOND = 'respond';

    public const CATALOG_SEARCH = 'catalog-search';

    public const DOCUMENT_EXTRACTION = 'document-extraction';

    public const EXTRACT = 'extract';

    public const WORKSPACE_ASSISTANT = 'workspace-assistant';

    public const ASSISTANT_STEP = 'assistant-step';

    public const USER_FEEDBACK = 'user_feedback';
}
