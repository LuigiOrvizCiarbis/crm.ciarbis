<?php

namespace App\Services\Ai;

/**
 * Contrato común de un proveedor de IA (Claude, OpenAI, etc.).
 *
 * Cada driver recibe la API key del tenant por constructor y expone un único
 * método de generación de texto. Nunca debe lanzar: ante cualquier error
 * loguea y devuelve null, para no romper el flujo del auto-responder.
 */
interface AiProvider
{
    /**
     * Máximo de rondas de herramientas por generación. Acota costo y tiempo
     * si el modelo encadena llamadas.
     */
    public const MAX_TOOL_ROUNDS = 3;

    /**
     * Cuándo derivar a una persona. Lo usan todos los drivers: el texto del
     * modelo no deriva nada, sólo la herramienta. Si el modelo le promete al
     * cliente que lo atiende el equipo sin llamarla (p. ej. porque el prompt
     * del tenant dice "si falta una tarifa, derivá"), la IA sigue contestando
     * y nadie se entera.
     */
    public const HANDOFF_TOOL_DESCRIPTION = 'Deriva la conversación a una persona del equipo: apaga tus respuestas automáticas y avisa al equipo. '
        .'Usala cuando el cliente pida hablar con una persona, y también siempre que le vayas a decir que alguien del equipo lo va a atender, '
        .'le va a responder o le va a pasar un dato (por ejemplo porque tus instrucciones indican derivar esa consulta o porque no podés resolverla). '
        .'Nunca le digas al cliente que lo va a atender una persona sin usar esta herramienta. '
        .'No la uses para preguntas que podés responder vos con tus instrucciones o tus otras herramientas. '
        .'En customer_message escribí el aviso que va a recibir el cliente.';

    /**
     * JSON Schema de los argumentos de la herramienta, común a los drivers.
     * customer_message es obligatorio: cuando derivan, los modelos casi nunca
     * escriben un texto aparte junto a la herramienta, y el cliente recibía
     * siempre el aviso fijo.
     */
    public const HANDOFF_TOOL_PARAMETERS = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'reason' => ['type' => 'string', 'enum' => ['customer_requested_human']],
            'summary' => ['type' => 'string', 'maxLength' => 300],
            'customer_locale' => ['type' => 'string', 'enum' => ['es', 'en']],
            'customer_message' => [
                'type' => 'string',
                'maxLength' => 500,
                'description' => 'Mensaje para el cliente, en su idioma y con el tono de tus instrucciones. '
                    .'Contale en una o dos oraciones por qué lo pasás con el equipo (por ejemplo, que ese dato no lo tenés confirmado '
                    .'o que lo pidió) y que alguien le va a responder por acá. No arranques con "Claro" si el cliente no pidió hablar con una persona.',
            ],
        ],
        'required' => ['reason', 'summary', 'customer_locale', 'customer_message'],
    ];

    /**
     * Genera una respuesta de texto a partir del historial de conversación.
     *
     * Con $toolset, el driver ofrece esas herramientas al modelo, ejecuta las
     * llamadas que pida y vuelve a consultarlo hasta obtener una respuesta sin
     * llamadas pendientes (máximo MAX_TOOL_ROUNDS rondas, dentro de
     * ToolLoopDeadline). Si el loop se corta con llamadas sin ejecutar devuelve
     * null: el texto de esa respuesta podría afirmar algo que no ocurrió.
     *
     * @param  array<int, array{role: string, content: string|array<int, array<string, mixed>>}>  $messages
     *                                                                                                       Historial en orden cronológico. role ∈ {user, assistant}. content es texto
     *                                                                                                       plano, o una lista de bloques neutrales {type: 'text'|'image', ...} que cada
     *                                                                                                       driver traduce al formato multimodal de su API.
     * @param  string  $systemPrompt  Instrucciones de sistema para el modelo.
     * @param  string  $model  Identificador del modelo del proveedor.
     * @param  AiToolset|null  $toolset  Herramientas disponibles, o null para generar solo texto.
     * @return string|null Texto de la respuesta, o null si no se pudo generar.
     */
    public function generate(array $messages, string $systemPrompt, string $model, ?AiToolset $toolset = null): ?string;

    /**
     * Traduce un texto con un request corto y determinista. Usa un modelo
     * económico separado del configurado para el auto-responder.
     */
    public function translate(string $content, string $systemPrompt, string $model): ?string;

    /**
     * Redacta un texto largo a partir de un contenido fuente, en un request
     * único sin herramientas (p. ej. resumir un sitio web). Nunca lanza: ante
     * error loguea y devuelve null.
     */
    public function summarize(string $content, string $systemPrompt, string $model, int $maxTokens): ?string;

    /**
     * Lista los modelos de chat disponibles para la API key del tenant.
     *
     * Consulta el endpoint de modelos del proveedor. Nunca lanza: ante cualquier
     * error (key inválida, timeout, etc.) loguea y devuelve un array vacío.
     *
     * @return list<string> IDs de modelo, ordenados alfabéticamente.
     */
    public function listModels(): array;

    /**
     * Verifica que la API key funcione y mide el system prompt.
     *
     * Hace un request real chico contra el proveedor para distinguir key
     * inválida, saldo insuficiente y rate limit (a diferencia de generate(),
     * que devuelve null ante cualquier error). Cuando el proveedor lo permite,
     * también reporta cuántos tokens ocupa el system prompt, para saber si
     * supera el mínimo de prompt caching. Nunca lanza.
     *
     * @param  string  $systemPrompt  System prompt a medir/validar.
     * @param  string  $model  Modelo contra el que probar.
     */
    public function verify(string $systemPrompt, string $model): AiVerificationResult;

    /**
     * Extrae datos estructurados de un documento según un JSON Schema.
     *
     * El PDF se procesa localmente antes de llegar acá. Cuando tiene capa de
     * texto se manda el texto: es ~10x más barato que visión y sirve con
     * cualquier modelo de texto. Cuando es un escaneo (el caso habitual de un
     * contrato firmado) se mandan las páginas rasterizadas, y ahí sí hace falta
     * un modelo con visión.
     *
     * A diferencia de generate(), nunca devuelve null silencioso: el resultado
     * distingue cada modo de falla para que el job lo persista y la UI pueda
     * explicarle al usuario qué pasó.
     *
     * @param  string  $text  Texto del documento, o '' si viene por imágenes.
     * @param  list<array{mime: string, data: string}>  $images  Páginas en base64
     *                                                           cuando el PDF era un escaneo. Vacío en el caso normal.
     * @param  array<string, mixed>  $schema  JSON Schema del objeto a extraer.
     */
    public function extract(
        string $text,
        array $images,
        array $schema,
        string $systemPrompt,
        string $model,
    ): AiExtractionResult;
}
