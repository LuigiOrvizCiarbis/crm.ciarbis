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
     * Genera una respuesta de texto a partir del historial de conversación.
     *
     * @param  array<int, array{role: string, content: string|array<int, array<string, mixed>>}>  $messages
     *                                                                                                       Historial en orden cronológico. role ∈ {user, assistant}. content es texto
     *                                                                                                       plano, o una lista de bloques neutrales {type: 'text'|'image', ...} que cada
     *                                                                                                       driver traduce al formato multimodal de su API.
     * @param  string  $systemPrompt  Instrucciones de sistema para el modelo.
     * @param  string  $model  Identificador del modelo del proveedor.
     * @return string|null Texto de la respuesta, o null si no se pudo generar.
     */
    public function generate(array $messages, string $systemPrompt, string $model): ?string;

    /**
     * Traduce un texto con un request corto y determinista. Usa un modelo
     * económico separado del configurado para el auto-responder.
     */
    public function translate(string $content, string $systemPrompt, string $model): ?string;

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
     * Extrae datos estructurados de un texto según un JSON Schema.
     *
     * Recibe TEXTO, no el documento: el PDF se convierte localmente con
     * pdftotext antes de llegar acá. Eso abarata el request (~10x contra
     * visión) y hace que cualquier modelo de texto sirva, sin depender de que
     * el proveedor lea PDFs.
     *
     * A diferencia de generate(), nunca devuelve null silencioso: el resultado
     * distingue cada modo de falla para que el job lo persista y la UI pueda
     * explicarle al usuario qué pasó.
     *
     * @param  string  $text  Texto del documento (contenido NO confiable).
     * @param  array<string, mixed>  $schema  JSON Schema del objeto a extraer.
     */
    public function extract(string $text, array $schema, string $systemPrompt, string $model): AiExtractionResult;
}
