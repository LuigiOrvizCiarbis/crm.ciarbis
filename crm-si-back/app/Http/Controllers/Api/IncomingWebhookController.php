<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookBulkImportJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\WebhookContactUpsertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Endpoint público de recepción de webhooks entrantes. Vive FUERA de auth:sanctum
 * (se autentica con X-Api-Key por endpoint). Registra cada recepción en
 * webhook_deliveries y hace upsert idempotente de contactos, en dos variantes:
 *
 * - handle(): síncrono, hasta 500 contactos, resultado en la misma respuesta.
 * - bulk(): asíncrono, hasta webhooks.bulk_max_contacts, encola un job y
 *   devuelve 202; el resultado se consulta por showDelivery().
 */
class IncomingWebhookController extends Controller
{
    public function __construct(private readonly WebhookContactUpsertService $upserter) {}

    public function handle(Request $request, string $slug): JsonResponse
    {
        $endpoint = $this->authenticatedEndpoint($request, $slug);

        if ($endpoint instanceof JsonResponse) {
            return $endpoint;
        }

        $validator = Validator::make($request->all(), $this->contactRules(500));

        if ($validator->fails()) {
            $delivery = $this->recordDelivery($endpoint, $request, 'rejected', 422, [
                'errors' => $validator->errors()->all(),
            ]);

            return response()->json([
                'delivery_id' => $delivery->id,
                'message' => 'Invalid payload.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $items = $validator->validated()['contacts'];

        $delivery = $this->recordDelivery($endpoint, $request, 'received', null, null);

        try {
            $result = $this->upserter->process($endpoint, $items);
        } catch (\Throwable $e) {
            $delivery->update([
                'status' => 'failed',
                'http_status' => 500,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $delivery->update([
            'status' => WebhookDelivery::statusFromResult($result),
            'http_status' => 200,
            'result' => $result,
        ]);

        $endpoint->forceFill(['last_received_at' => now()])->save();

        return response()->json([
            'delivery_id' => $delivery->id,
            'created' => $result['created'],
            'updated' => $result['updated'],
            'failed' => $result['failed'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * Import bulk asíncrono: valida estructuralmente, persiste el payload
     * completo como delivery 'queued' y encola el job. Responde 202 al toque;
     * el cliente consulta el resultado por GET .../deliveries/{id}.
     */
    public function bulk(Request $request, string $slug): JsonResponse
    {
        $endpoint = $this->authenticatedEndpoint($request, $slug);

        if ($endpoint instanceof JsonResponse) {
            return $endpoint;
        }

        $maxBodyBytes = (int) config('webhooks.bulk_max_body_bytes', 2 * 1024 * 1024);

        if (strlen($request->getContent()) > $maxBodyBytes) {
            $delivery = $this->recordDelivery($endpoint, $request, 'rejected', 413, [
                'errors' => ['Payload too large.'],
            ]);

            return response()->json([
                'delivery_id' => $delivery->id,
                'message' => 'Payload too large.',
            ], 413);
        }

        $maxContacts = (int) config('webhooks.bulk_max_contacts', 5000);

        $validator = Validator::make($request->all(), $this->contactRules($maxContacts));

        if ($validator->fails()) {
            $delivery = $this->recordDelivery($endpoint, $request, 'rejected', 422, [
                'errors' => $validator->errors()->all(),
            ]);

            return response()->json([
                'delivery_id' => $delivery->id,
                'message' => 'Invalid payload.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $items = $validator->validated()['contacts'];

        // Payload completo SIN truncar: es la fuente que lee el job. Se achica
        // al tope de debug recién cuando el job termina de procesarlo.
        $delivery = WebhookDelivery::create([
            'tenant_id' => $endpoint->tenant_id,
            'webhook_endpoint_id' => $endpoint->id,
            'status' => 'queued',
            'http_status' => 202,
            'payload' => ['contacts' => $items],
            'ip' => $request->ip(),
        ]);

        $endpoint->forceFill(['last_received_at' => now()])->save();

        try {
            ProcessWebhookBulkImportJob::dispatch($delivery->id, $endpoint->tenant_id);
        } catch (\Throwable $e) {
            report($e);

            $delivery->update([
                'status' => 'failed',
                'http_status' => 503,
                'error' => 'Unable to queue delivery.',
            ]);

            return response()->json([
                'delivery_id' => $delivery->id,
                'status' => 'failed',
                'message' => 'Unable to queue delivery.',
            ], 503);
        }

        return response()->json([
            'delivery_id' => $delivery->id,
            'status' => 'queued',
            'contacts_received' => count($items),
            'status_url' => $endpoint->public_url.'/deliveries/'.$delivery->id,
        ], 202);
    }

    /**
     * Consulta del estado de un delivery (polling del flujo bulk). Misma
     * autenticación por X-Api-Key; la firma HMAC no aplica (GET sin body).
     */
    public function showDelivery(Request $request, string $slug, int $deliveryId): JsonResponse
    {
        $endpoint = $this->authenticatedEndpoint($request, $slug, verifySignature: false);

        if ($endpoint instanceof JsonResponse) {
            return $endpoint;
        }

        $delivery = WebhookDelivery::withoutGlobalScopes()
            ->where('tenant_id', $endpoint->tenant_id)
            ->where('webhook_endpoint_id', $endpoint->id)
            ->find($deliveryId);

        if (! $delivery) {
            return response()->json(['message' => 'Delivery not found.'], 404);
        }

        return response()->json([
            'delivery_id' => $delivery->id,
            'status' => $delivery->status,
            'result' => $delivery->result,
            'error' => $delivery->error,
            'created_at' => $delivery->created_at?->toIso8601String(),
            'updated_at' => $delivery->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Cadena de autenticación compartida por los tres endpoints: key válida →
     * endpoint habilitado → firma HMAC (si hay secret y el request tiene body).
     * Devuelve el endpoint o la JsonResponse de error lista para retornar.
     */
    private function authenticatedEndpoint(Request $request, string $slug, bool $verifySignature = true): WebhookEndpoint|JsonResponse
    {
        $endpoint = $this->resolveEndpoint($request, $slug);

        // 401 uniforme: no revelamos si el slug existe, si la key es de otro
        // endpoint, ni si el target no está soportado. Sin delivery (anti-flood).
        if (! $endpoint) {
            return $this->unauthorized();
        }

        if (! $endpoint->enabled) {
            return response()->json(['message' => 'Webhook endpoint disabled.'], 403);
        }

        // Firma HMAC obligatoria solo si el endpoint tiene signing secret. Inválida
        // o ausente → 401 sin delivery (mismo patrón anti-flood).
        if ($verifySignature && $endpoint->hasSigningSecret() && ! $this->isValidSignature($request, $endpoint)) {
            return $this->unauthorized();
        }

        return $endpoint;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function contactRules(int $maxContacts): array
    {
        return [
            'contacts' => ['required', 'array', 'min:1', 'max:'.$maxContacts],
            'contacts.*.external_id' => ['required', 'string', 'max:255'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.custom_data' => ['nullable', 'array', 'max:50'],
            // El tipo real (scalar, array o array de objetos para repeater)
            // depende de la definición de ContactField del tenant, que valida
            // WebhookContactUpsertService vía ValidContactCustomData.
            'contacts.*.custom_data.*' => ['nullable'],
        ];
    }

    private function resolveEndpoint(Request $request, string $slug): ?WebhookEndpoint
    {
        $apiKey = $request->header('X-Api-Key');

        if (! $apiKey) {
            return null;
        }

        $endpoint = WebhookEndpoint::query()
            ->where('api_key_hash', hash('sha256', $apiKey))
            ->first();

        if (! $endpoint || ! hash_equals($endpoint->slug, $slug) || $endpoint->target !== 'contacts') {
            return null;
        }

        return $endpoint;
    }

    /**
     * Valida X-Signature-256 (HMAC-SHA256 del raw body con el signing secret del
     * endpoint). Mismo patrón que InstagramController::isValidSignature pero con
     * secret por endpoint.
     */
    private function isValidSignature(Request $request, WebhookEndpoint $endpoint): bool
    {
        $signature = $request->header('X-Signature-256');
        $secret = $endpoint->getDecryptedSigningSecret();

        if (! $signature || ! $secret) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function recordDelivery(WebhookEndpoint $endpoint, Request $request, string $status, ?int $httpStatus, ?array $result): WebhookDelivery
    {
        return WebhookDelivery::create([
            'tenant_id' => $endpoint->tenant_id,
            'webhook_endpoint_id' => $endpoint->id,
            'status' => $status,
            'http_status' => $httpStatus,
            'payload' => WebhookDelivery::truncatePayload($request->all()),
            'result' => $result,
            'ip' => $request->ip(),
        ]);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['message' => 'Unauthorized.'], 401);
    }
}
