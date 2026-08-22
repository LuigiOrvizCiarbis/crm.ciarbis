<?php

namespace App\Services;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\AiConfig;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Product;
use App\Services\Ai\AiProviderFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Genera respuestas automáticas de IA para conversaciones entrantes.
 *
 * Arma el historial multi-turno desde la tabla messages del CRM
 * (INBOUND => role user, OUTBOUND => role assistant) y delega la llamada al
 * driver del proveedor configurado por el tenant (Claude, OpenAI, etc.).
 */
class AiReplyService
{
    public const DEFAULT_SYSTEM_PROMPT = 'Sos un asistente de atención al cliente que responde mensajes de WhatsApp '
        .'en nombre de la empresa. Respondé en el mismo idioma del cliente, de forma breve, cordial y útil. '
        .'Si no sabés algo o el cliente pide hablar con una persona, indicá que un agente humano lo va a contactar. '
        .'Nunca inventes precios, promociones ni datos de la empresa.';

    /**
     * Regla anti-contaminación de historial. Cuando el tenant actualiza su
     * prompt con la conversación ya empezada, el historial queda lleno de
     * respuestas viejas del bot diciendo "no tengo esa información" y el
     * modelo imita ese patrón en vez de usar el prompt nuevo. Tiene que ir
     * al FINAL del system prompt (después del catálogo): probado en prod,
     * en el medio del prompt no alcanza para revertir el patrón.
     */
    public const HISTORY_OVERRIDE_RULE = 'IMPORTANTE — REGLA FINAL: La información de este system prompt es la '
        .'única fuente de verdad y SIEMPRE prevalece sobre lo dicho antes en la conversación. Si en mensajes '
        .'anteriores respondiste que no tenías o no sabías algún dato (horarios, direcciones, sucursales, '
        .'servicios, productos) que SÍ figura acá arriba, eso fue un error: ignorá esas respuestas y contestá '
        .'ahora con los datos de este prompt.';

    /**
     * Refuerzo de la regla anterior, anexado al último mensaje del cliente.
     * Con muchas negativas viejas acumuladas en el historial, la regla en el
     * system prompt sola no alcanza (probado en prod con ~8 negativas: el
     * modelo seguía imitando su propio patrón). La misma instrucción dentro
     * del turno user sí lo revierte. El cliente de WhatsApp nunca ve esto:
     * solo viaja en el payload a la API.
     */
    public const HISTORY_OVERRIDE_NOTE = '[Nota del sistema: respondé usando la información del system prompt '
        .'vigente, aunque en mensajes anteriores hayas dicho que no la tenías.]';

    /**
     * Genera el texto de respuesta para la conversación usando el proveedor
     * configurado por el tenant, o null si no se pudo. Nunca lanza: los drivers
     * loguean y devuelven null para no romper el flujo.
     */
    public function respond(Conversation $conversation, AiConfig $config): ?string
    {
        $model = $config->model ?: $config->provider->defaultModel();

        // Si el modelo no procesa imágenes, las fotos entrantes se mandan como
        // placeholder de texto en vez de bloque visual: un modelo text-only
        // rechaza contenido multimodal y generate() devolvería null.
        $allowImages = $config->provider->modelSupportsVision($model);

        $messages = $this->buildHistory($conversation, $allowImages);

        if (empty($messages)) {
            return null;
        }

        $provider = AiProviderFactory::make($config);

        if (! $provider) {
            return null;
        }

        return $provider->generate($messages, $this->systemPrompt($config, $conversation->tenant_id), $model);
    }

    /**
     * Mime types de imagen que los modelos de visión aceptan. Un mime fuera de
     * esta lista degrada a placeholder de texto.
     */
    private const SUPPORTED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Últimos N mensajes en orden cronológico, mapeados a roles de la API.
     * La API exige que el primer mensaje sea "user" y no acepta contenidos vacíos.
     *
     * El texto viaja como string plano; las imágenes entrantes viajan como lista
     * de bloques neutrales {type: 'text'|'image', ...} que cada driver traduce al
     * formato multimodal de su API. Para acotar costo, solo las últimas
     * AI_MAX_IMAGES imágenes se envían como bloque visual; las más viejas (o las
     * que no se pueden leer/soportar) degradan a un placeholder de texto.
     *
     * $allowImages=false (modelo text-only) fuerza que TODAS las imágenes
     * degraden a placeholder: mandar bloques visuales a un modelo sin visión
     * haría que generate() devuelva null en vez de responder.
     *
     * @return array<int, array{role: string, content: string|array<int, array<string, mixed>>}>
     */
    private function buildHistory(Conversation $conversation, bool $allowImages = true): array
    {
        $maxHistory = (int) config('services.ai.max_history', 20);

        $recent = $conversation->messages()
            ->withoutTrashed()
            ->whereIn('message_type', [MessageType::Text, MessageType::Image])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($maxHistory)
            ->get()
            ->reverse()
            ->values();

        // Solo las últimas imágenes ENTRANTES van como bloque visual (contadas
        // de la más reciente hacia atrás). Marcamos sus IDs para decidir al
        // construir. Con un modelo text-only no se marca ninguna: todas degradan
        // a texto. Las imágenes salientes (mandadas por un humano/bot) nunca son
        // visuales: los bloques de imagen de OpenAI/Anthropic son user-content y
        // en un turno assistant harían fallar generate().
        $maxImages = $allowImages ? (int) config('services.ai.max_images', 3) : 0;
        $visualImageIds = $recent
            ->filter(fn (Message $m) => $m->message_type === MessageType::Image
                && $m->direction === MessageDirection::INBOUND)
            ->reverse()
            ->take($maxImages)
            ->pluck('id')
            ->all();

        $messages = [];
        foreach ($recent as $message) {
            $entry = $this->historyEntry($message, in_array($message->id, $visualImageIds, true));

            if ($entry !== null) {
                $messages[] = $entry;
            }
        }

        // El primer mensaje debe ser del usuario: recortar turnos assistant iniciales.
        while (! empty($messages) && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        // Si el último turno no es del contacto no hay nada nuevo que responder.
        if (empty($messages) || end($messages)['role'] !== 'user') {
            return [];
        }

        // Solo hay riesgo de contaminación si el bot ya habló en el historial.
        $hasAssistantTurns = collect($messages)->contains(fn ($m) => $m['role'] === 'assistant');

        if ($hasAssistantTurns) {
            $lastIndex = count($messages) - 1;
            $messages[$lastIndex]['content'] = $this->appendOverrideNote($messages[$lastIndex]['content']);
        }

        return $messages;
    }

    /**
     * Convierte un mensaje en una entrada de historial, o null si no aporta nada
     * (texto vacío). Las imágenes marcadas como "visuales" van como bloque de
     * imagen; el resto degrada a un placeholder de texto.
     *
     * @return array{role: string, content: string|array<int, array<string, mixed>>}|null
     */
    private function historyEntry(Message $message, bool $asVisual): ?array
    {
        $role = $message->direction === MessageDirection::INBOUND ? 'user' : 'assistant';
        $caption = trim((string) $message->content);

        if ($message->message_type !== MessageType::Image) {
            return $caption === '' ? null : ['role' => $role, 'content' => $caption];
        }

        $imageBlock = $asVisual ? $this->imageBlock($message) : null;

        if ($imageBlock === null) {
            // Imagen no enviable como bloque (saliente, vieja, ilegible o mime no
            // soportado): el modelo al menos sabe que hubo una imagen. El texto
            // refleja quién la mandó para no confundir al modelo.
            $placeholder = $message->direction === MessageDirection::INBOUND
                ? '[El cliente envió una imagen]'
                : '[Se envió una imagen al cliente]';
            $content = $caption === '' ? $placeholder : $placeholder."\n".$caption;

            return ['role' => $role, 'content' => $content];
        }

        $blocks = [$imageBlock];
        if ($caption !== '') {
            $blocks[] = ['type' => 'text', 'text' => $caption];
        }

        return ['role' => $role, 'content' => $blocks];
    }

    /**
     * Bloque neutral de imagen {type: 'image', mime, data} con el binario en
     * base64 leído del disco público, o null si no se puede leer, el mime no es
     * soportado o excede el tope de tamaño. Nunca lanza.
     *
     * @return array{type: string, mime: string, data: string}|null
     */
    private function imageBlock(Message $message): ?array
    {
        $mime = (string) $message->media_mime_type;

        if (! in_array($mime, self::SUPPORTED_IMAGE_MIMES, true)) {
            return null;
        }

        $path = $this->mediaStoragePath((string) $message->media_url);

        if ($path === null || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $maxBytes = (int) config('services.ai.max_image_bytes', 5242880);

        try {
            if (Storage::disk('public')->size($path) > $maxBytes) {
                return null;
            }

            $binary = Storage::disk('public')->get($path);
        } catch (\Throwable $e) {
            Log::warning('AiReplyService: no se pudo leer imagen para el historial', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($binary === null || $binary === '') {
            return null;
        }

        return [
            'type' => 'image',
            'mime' => $mime,
            'data' => base64_encode($binary),
        ];
    }

    /**
     * Deriva el path relativo dentro del disco 'public' desde media_url
     * (guardada como "/storage/messages/..."). Devuelve null si no es una URL
     * local del CRM (p.ej. media alojada en un host externo).
     */
    private function mediaStoragePath(string $mediaUrl): ?string
    {
        if ($mediaUrl === '' || ! str_starts_with($mediaUrl, '/storage/')) {
            return null;
        }

        return substr($mediaUrl, strlen('/storage/'));
    }

    /**
     * Anexa la nota anti-contaminación al último turno del cliente. Si el
     * contenido es multimodal, la nota va en un bloque de texto (nuevo o el
     * existente); si es string plano, se concatena.
     *
     * @param  string|array<int, array<string, mixed>>  $content
     * @return string|array<int, array<string, mixed>>
     */
    private function appendOverrideNote(string|array $content): string|array
    {
        if (is_string($content)) {
            return $content."\n\n".self::HISTORY_OVERRIDE_NOTE;
        }

        // Buscar el último bloque de texto para anexar ahí.
        for ($i = count($content) - 1; $i >= 0; $i--) {
            if (($content[$i]['type'] ?? null) === 'text') {
                $content[$i]['text'] .= "\n\n".self::HISTORY_OVERRIDE_NOTE;

                return $content;
            }
        }

        // No había bloque de texto (imagen sin caption): agregar uno.
        $content[] = ['type' => 'text', 'text' => self::HISTORY_OVERRIDE_NOTE];

        return $content;
    }

    private function systemPrompt(AiConfig $config, int $tenantId): string
    {
        $base = trim($config->system_prompt ?: self::DEFAULT_SYSTEM_PROMPT);

        $catalog = $this->catalogSection($tenantId);

        $prompt = $catalog === '' ? $base : $base."\n\n".$catalog;

        return $prompt."\n\n".self::HISTORY_OVERRIDE_RULE;
    }

    /**
     * Sección de catálogo con los productos activos del tenant, para anexar al
     * system prompt. Corre sin Auth (dentro del job de cola), por lo que hay que
     * saltear el TenantScope y filtrar el tenant a mano. Devuelve '' si no hay
     * productos, para no alterar el prompt actual.
     */
    private function catalogSection(int $tenantId): string
    {
        $maxProducts = (int) config('services.ai.catalog_max_products', 100);
        $maxDescription = (int) config('services.ai.catalog_max_description_chars', 300);
        $maxChars = (int) config('services.ai.catalog_max_chars', 12000);

        // Cargamos uno más que el tope para saber si hubo recorte, sin traer el
        // catálogo entero: un catálogo enorme reventaría el contexto del proveedor
        // y haría que generate() devuelva null para todo el tenant.
        $products = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit($maxProducts + 1)
            ->get();

        if ($products->isEmpty()) {
            return '';
        }

        $truncated = $products->count() > $maxProducts;
        $products = $products->take($maxProducts);

        $header = "## Catálogo de productos\n"
            .'Usá únicamente estos datos para responder sobre productos y precios. '
            ."No inventes productos ni precios que no estén en esta lista.\n";

        // Presupuesto de caracteres para las líneas (además del tope por producto).
        $budget = max(0, $maxChars - mb_strlen($header));
        $lines = [];

        foreach ($products as $product) {
            $line = $this->formatCatalogLine($product, $maxDescription);

            // Cortar antes de exceder el presupuesto total de caracteres.
            if (! empty($lines) && mb_strlen(implode("\n", $lines)) + mb_strlen("\n".$line) > $budget) {
                $truncated = true;
                break;
            }

            $lines[] = $line;
        }

        if (empty($lines)) {
            return '';
        }

        $body = implode("\n", $lines);

        if ($truncated) {
            $body .= "\n- (lista parcial: hay más productos disponibles; pedí al cliente que consulte por uno puntual)";
        }

        return $header.$body;
    }

    /**
     * Cada producto es una línea "- ..." del catálogo: saltos de línea en
     * nombre o descripción romperían el formato de lista, se colapsan a espacio.
     */
    private function formatCatalogLine(Product $product, int $maxDescription): string
    {
        $parts = [preg_replace('/\s+/u', ' ', trim($product->name))];

        if ($product->price !== null) {
            $parts[] = '$'.number_format((float) $product->price, 2);
        }

        if (filled($product->description)) {
            $description = preg_replace('/\s+/u', ' ', trim($product->description));

            if (mb_strlen($description) > $maxDescription) {
                $description = mb_substr($description, 0, $maxDescription).'…';
            }

            $parts[] = $description;
        }

        return '- '.implode(' — ', $parts);
    }
}
