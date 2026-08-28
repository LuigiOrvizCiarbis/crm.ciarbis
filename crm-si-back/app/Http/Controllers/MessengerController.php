<?php

namespace App\Http\Controllers;

use App\Enums\ChannelType;
use App\Exceptions\ChannelAlreadyConnectedException;
use App\Http\Requests\MessengerChannelStoreRequest;
use App\Models\Channel;
use App\Models\MessengerConfig;
use App\Models\Scopes\TenantScope;
use App\Services\MessengerMessageService;
use App\Support\MetaOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Canal Facebook Messenger.
 *
 * El webhook comparte app secret y verify token con WhatsApp e Instagram (los
 * tres cuelgan de la misma app de Meta), pero se sirve en su propia ruta y sólo
 * procesa eventos con object=page.
 */
class MessengerController extends Controller
{
    /**
     * Prefijo propio (no `ig_onboarding:`): dos onboardings simultáneos, uno de
     * Instagram y uno de Messenger, colisionarían en la misma clave.
     */
    private const ONBOARDING_CACHE_PREFIX = 'messenger_onboarding:';

    private const ONBOARDING_TTL_SECONDS = 600; // 10 minutos

    public function __construct(
        private MessengerMessageService $messageService
    ) {}

    public function handleAuth(MessengerChannelStoreRequest $request): JsonResponse
    {
        try {
            // Vuelta 2: el usuario ya eligió una página; el user token está en cache.
            if ($request->filled('onboarding_token')) {
                return $this->connectChosenPage($request);
            }

            // Vuelta 1: intercambiar el code por un user token long-lived. El
            // canje debe usar el MISMO redirect_uri con el que se abrió el
            // diálogo o Meta rechaza con el error 36008; el front nos lo envía.
            $redirectUri = $request->input('redirect_uri', '');
            $userToken = MetaOAuth::exchangeCodeForToken($request->code, $redirectUri);
            if (! $userToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo obtener el token de Meta. Intentá de nuevo.',
                ], 502);
            }

            $pages = $this->fetchPages($userToken);

            if (empty($pages)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró ninguna página de Facebook administrable con tu cuenta. '.
                        'Verificá que seas administrador de la página e intentá de nuevo.',
                ], 422);
            }

            // Varias páginas: no reintercambiamos el code (es single-use).
            // Guardamos el user token en cache y devolvemos la lista para elegir.
            if (count($pages) > 1) {
                $onboardingToken = Str::random(48);
                Cache::put(
                    self::ONBOARDING_CACHE_PREFIX.$onboardingToken,
                    Crypt::encryptString($userToken),
                    self::ONBOARDING_TTL_SECONDS,
                );

                return response()->json([
                    'success' => false,
                    'requires_page_selection' => true,
                    'onboarding_token' => $onboardingToken,
                    'pages' => array_map(fn (array $p) => [
                        'page_id' => $p['page_id'],
                        'name' => $p['name'],
                    ], $pages),
                ], 200);
            }

            return $this->connectPage($request, $pages[0]);

        } catch (ChannelAlreadyConnectedException $e) {
            Log::warning('Messenger handleAuth: página ya conectada por otro tenant/usuario', [
                'tenant_id' => $e->tenantId,
                'existing_user_id' => $e->existingUserId,
                'requesting_user' => $e->requestingUserId,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);

        } catch (\InvalidArgumentException $e) {
            Log::warning('Messenger handleAuth: error de negocio', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Messenger handleAuth: error interno', MetaOAuth::describeException($e));

            return response()->json([
                'success' => false,
                'message' => 'Error interno al procesar la solicitud.',
            ], 500);
        }
    }

    /**
     * Vuelta 2 del onboarding: recupera el user token de cache y conecta la
     * página elegida por el usuario.
     */
    private function connectChosenPage(MessengerChannelStoreRequest $request): JsonResponse
    {
        $cacheKey = self::ONBOARDING_CACHE_PREFIX.$request->onboarding_token;
        $stored = Cache::get($cacheKey);

        if (! $stored) {
            return response()->json([
                'success' => false,
                'message' => 'La sesión de conexión expiró. Volvé a iniciar la conexión con Facebook.',
            ], 410);
        }

        // Single-use: invalidar la entrada apenas se recupera.
        Cache::forget($cacheKey);

        $userToken = Crypt::decryptString($stored);

        $pages = $this->fetchPages($userToken);
        $chosen = collect($pages)->firstWhere('page_id', $request->page_id);

        if (! $chosen) {
            return response()->json([
                'success' => false,
                'message' => 'La página seleccionada ya no está disponible. Reintentá la conexión.',
            ], 422);
        }

        return $this->connectPage($request, $chosen);
    }

    /**
     * Obtiene las páginas de Facebook que el usuario administra.
     *
     * A diferencia del onboarding de Instagram NO se filtra por
     * instagram_business_account: Messenger sirve a cualquier página, incluidas
     * las que no tienen una cuenta de Instagram vinculada.
     *
     * @return list<array{page_id: string, name: string, page_access_token: string}>
     */
    private function fetchPages(string $userToken): array
    {
        $version = config('services.facebook.graph_version', 'v26.0');

        $response = Http::withToken($userToken)
            ->timeout(15)
            ->get("https://graph.facebook.com/{$version}/me/accounts", [
                'fields' => 'id,name,access_token',
            ]);

        if (! $response->successful()) {
            Log::error('Messenger fetchPages failed', [
                'status' => $response->status(),
                'error' => MetaOAuth::describeMetaError($response->json()),
            ]);

            throw new \InvalidArgumentException(
                'No se pudieron obtener tus páginas de Facebook. Verificá los permisos e intentá de nuevo.'
            );
        }

        $pages = [];
        foreach ($response->json('data', []) as $page) {
            if (empty($page['id']) || empty($page['access_token'])) {
                continue;
            }

            $pages[] = [
                'page_id' => (string) $page['id'],
                'name' => (string) ($page['name'] ?? ''),
                'page_access_token' => (string) $page['access_token'],
            ];
        }

        return $pages;
    }

    /**
     * Persiste la config + canal para la página dada y suscribe los webhooks. La
     * persistencia va en transacción; la suscripción queda fuera y sólo agrega
     * un warning si falla.
     *
     * @param  array{page_id: string, name: string, page_access_token: string}  $page
     */
    private function connectPage(MessengerChannelStoreRequest $request, array $page): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            throw new \InvalidArgumentException('Usuario no autenticado.');
        }

        // Ownership: si esta página ya tiene config con un canal cuyo dueño es
        // otro usuario (mismo u otro tenant), rechazar sin pisar el token.
        $existingConfig = MessengerConfig::where('page_id', $page['page_id'])->first();

        if ($existingConfig) {
            $existingChannel = Channel::withoutGlobalScope(TenantScope::class)
                ->where('type', ChannelType::FACEBOOK)
                ->where('messenger_config_id', $existingConfig->id)
                ->first();

            if ($existingChannel
                && ($existingChannel->tenant_id !== $user->tenant_id || $existingChannel->user_id !== $user->id)) {
                throw new ChannelAlreadyConnectedException(
                    tenantId: (int) $existingChannel->tenant_id,
                    existingUserId: (int) $existingChannel->user_id,
                    requestingUserId: $user->id,
                    phoneNumberId: null,
                    message: 'Esta página de Facebook ya está conectada por otro usuario. '.
                        'Pedile a un administrador que te la reasigne.',
                );
            }
        }

        DB::transaction(function () use ($request, $page, $user) {
            $config = MessengerConfig::updateOrCreate(
                ['page_id' => $page['page_id']],
                [
                    'tenant_id' => $user->tenant_id,
                    'page_name' => $page['name'] ?: null,
                    'page_access_token' => Crypt::encryptString($page['page_access_token']),
                ]
            );

            $existing = Channel::where('tenant_id', $user->tenant_id)
                ->where('type', ChannelType::FACEBOOK)
                ->where('messenger_config_id', $config->id)
                ->first();

            if ($existing) {
                $existing->fill(['status' => 'active'])->save();

                return $existing;
            }

            return Channel::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'messenger_config_id' => $config->id,
                'type' => ChannelType::FACEBOOK,
                'external_id' => $page['page_id'],
                'name' => $page['name'] ?: $request->input('name', 'Facebook'),
                'status' => 'active',
            ]);
        });

        $warnings = [];
        if (! $this->subscribeToWebhooks($page['page_id'], $page['page_access_token'])) {
            $warnings[] = 'No se pudo suscribir a los webhooks de Meta. Los mensajes entrantes pueden no llegar.';
        }

        return response()->json([
            'success' => true,
            'message' => 'Página de Facebook conectada exitosamente.',
            'warnings' => $warnings,
        ], 200);
    }

    /**
     * Suscribe la app a los webhooks de la página.
     *
     * `message_echoes` es obligatorio y es un field SEPARADO en Messenger (en
     * Instagram los echoes vienen dentro de `messages`). Sin él no llegan los
     * mensajes que un agente escribe desde la app de Messenger, y la IA seguiría
     * respondiendo por encima de una persona: el handoff se rompe en silencio.
     */
    private function subscribeToWebhooks(string $pageId, string $pageToken): bool
    {
        $version = config('services.facebook.graph_version', 'v26.0');

        try {
            $response = Http::withToken($pageToken)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$version}/{$pageId}/subscribed_apps", [
                    'subscribed_fields' => 'messages,message_echoes,messaging_postbacks',
                ]);

            if (! $response->successful()) {
                Log::error('Messenger subscribeToWebhooks failed', [
                    'status' => $response->status(),
                    'error' => MetaOAuth::describeMetaError($response->json()),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Messenger subscribeToWebhooks exception', MetaOAuth::describeException($e));

            return false;
        }
    }

    public function webhook(Request $request): Response|JsonResponse
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));
        $verifyToken = $request->query('hub_verify_token', $request->query('hub.verify_token'));

        if ($mode === 'subscribe') {
            $expected = config('services.facebook.verify_token', 'embbebedsecret');
            if ($verifyToken && hash_equals($expected, $verifyToken)) {
                return response($challenge, 200)->header('Content-Type', 'text/plain');
            }

            return response()->json(['error' => 'Verification token mismatch'], 403);
        }

        // Validar la firma del payload con el app secret. El endpoint es público:
        // sin esto, cualquiera podría inyectar mensajes falsos.
        if (! $this->isValidSignature($request)) {
            Log::warning('Messenger webhook: firma X-Hub-Signature-256 inválida');

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        // Los eventos de Messenger llegan con object=page. Una misma página
        // también emite eventos de Instagram (object=instagram) que pertenecen al
        // otro canal: procesarlos acá crearía el mensaje en el canal equivocado.
        $object = $request->input('object');
        if ($object !== 'page') {
            Log::info('Messenger webhook: object ajeno ignorado', ['object' => $object]);

            return response()->json(['status' => 'EVENT_RECEIVED'], 200);
        }

        try {
            $entries = $request->input('entry', []);

            // A diferencia del webhook de Instagram no logueamos el payload
            // completo: contiene el texto de los mensajes y los PSID. Con el
            // page id y el conteo alcanza para diagnosticar ruteo.
            Log::info('Messenger webhook recibido', [
                'entries' => count($entries),
                'page_ids' => array_values(array_filter(array_map(
                    fn ($entry) => $entry['id'] ?? null,
                    $entries
                ))),
            ]);

            foreach ($entries as $entry) {
                $entryId = $entry['id'] ?? null;

                foreach ($this->extractMessagingEvents($entry) as $event) {
                    $this->messageService->processIncomingMessage($entryId, $event);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Messenger webhook: error procesando evento', MetaOAuth::describeException($e));
        }

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }

    /**
     * Extrae los eventos de mensajería de un `entry`.
     *
     * Messenger usa `messaging[]`; mantenemos la rama `changes[]` por robustez.
     *
     * `standby[]` se ignora a propósito: son los eventos que llegan cuando otra
     * app tiene el control del thread (protocolo de handover). Procesarlos
     * duplicaría los mensajes que ya entran por `messaging[]`.
     *
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    private function extractMessagingEvents(array $entry): array
    {
        if (! empty($entry['standby']) && is_array($entry['standby'])) {
            Log::info('Messenger webhook: eventos standby ignorados', [
                'entry_id' => $entry['id'] ?? null,
                'count' => count($entry['standby']),
            ]);
        }

        if (! empty($entry['messaging']) && is_array($entry['messaging'])) {
            return array_values($entry['messaging']);
        }

        $events = [];
        foreach ($entry['changes'] ?? [] as $change) {
            if (($change['field'] ?? null) !== 'messages') {
                continue;
            }

            $value = $change['value'] ?? null;
            if (! is_array($value)) {
                continue;
            }

            if (! empty($value['messaging']) && is_array($value['messaging'])) {
                $events = array_merge($events, array_values($value['messaging']));
            } else {
                $events[] = $value;
            }
        }

        return $events;
    }

    /**
     * Verifica la cabecera X-Hub-Signature-256 (HMAC-SHA256 del raw body con el
     * app secret).
     */
    private function isValidSignature(Request $request): bool
    {
        $signature = $request->header('X-Hub-Signature-256');
        $appSecret = config('services.facebook.app_secret');

        if (! $signature || ! $appSecret) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $signature);
    }
}
