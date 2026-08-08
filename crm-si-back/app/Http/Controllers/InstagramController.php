<?php

namespace App\Http\Controllers;

use App\Enums\ChannelType;
use App\Exceptions\ChannelAlreadyConnectedException;
use App\Http\Requests\InstagramChannelStoreRequest;
use App\Models\Channel;
use App\Models\InstagramConfig;
use App\Models\Scopes\TenantScope;
use App\Services\InstagramMessageService;
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

class InstagramController extends Controller
{
    /**
     * Prefijo de las entradas de cache que guardan el user token long-lived
     * entre la primera y la segunda vuelta del onboarding (selector de página).
     */
    private const ONBOARDING_CACHE_PREFIX = 'ig_onboarding:';

    private const ONBOARDING_TTL_SECONDS = 600; // 10 minutos

    public function __construct(
        private InstagramMessageService $messageService
    ) {}

    public function handleAuth(InstagramChannelStoreRequest $request): JsonResponse
    {
        try {
            // Vuelta 2: el usuario ya eligió una página; el user token está en cache.
            if ($request->filled('onboarding_token')) {
                return $this->connectChosenPage($request);
            }

            // Vuelta 1: intercambiar el code por un user token long-lived.
            // FB.login (SDK JS) asocia el code a la URL de la página donde se
            // abrió el login. El canje debe usar ese MISMO redirect_uri o Meta
            // rechaza con el error 36008. El front nos lo envía.
            $redirectUri = $request->input('redirect_uri', '');
            Log::info('Instagram handleAuth: canjeando code', ['redirect_uri' => $redirectUri]);
            $userToken = MetaOAuth::exchangeCodeForToken($request->code, $redirectUri);
            if (! $userToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo obtener el token de Meta. Intentá de nuevo.',
                ], 502);
            }

            $pages = $this->fetchPagesWithInstagram($userToken);

            if (empty($pages)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró ninguna cuenta de Instagram profesional '.
                        'vinculada a una página de Facebook. Vinculá tu cuenta de Instagram '.
                        'a una página desde la configuración de Meta e intentá de nuevo.',
                ], 422);
            }

            // Varias páginas: no reintercambiamos el code (es single-use). Guardamos
            // el user token en cache y devolvemos la lista para que el front elija.
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
                        'username' => $p['username'],
                    ], $pages),
                ], 200);
            }

            return $this->connectPage($request, $pages[0]);

        } catch (ChannelAlreadyConnectedException $e) {
            Log::warning('Instagram handleAuth: cuenta ya conectada por otro tenant/usuario', [
                'tenant_id' => $e->tenantId,
                'existing_user_id' => $e->existingUserId,
                'requesting_user' => $e->requestingUserId,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);

        } catch (\InvalidArgumentException $e) {
            Log::warning('Instagram handleAuth: error de negocio', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Instagram handleAuth: error interno', MetaOAuth::describeException($e));

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
    private function connectChosenPage(InstagramChannelStoreRequest $request): JsonResponse
    {
        $cacheKey = self::ONBOARDING_CACHE_PREFIX.$request->onboarding_token;
        $stored = Cache::get($cacheKey);

        if (! $stored) {
            return response()->json([
                'success' => false,
                'message' => 'La sesión de conexión expiró. Volvé a iniciar la conexión con Instagram.',
            ], 410);
        }

        // Single-use: invalidar la entrada apenas se recupera.
        Cache::forget($cacheKey);

        $userToken = Crypt::decryptString($stored);

        $pages = $this->fetchPagesWithInstagram($userToken);
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
     * Obtiene las páginas de Facebook del usuario que tengan una cuenta de
     * Instagram profesional vinculada. El access_token de cada página, derivado
     * de un user token long-lived, ya es long-lived (no requiere refresh).
     *
     * @return list<array{page_id: string, name: string, ig_user_id: string, username: ?string, page_access_token: string}>
     */
    private function fetchPagesWithInstagram(string $userToken): array
    {
        $version = config('services.facebook.graph_version', 'v21.0');

        $response = Http::withToken($userToken)
            ->timeout(15)
            ->get("https://graph.facebook.com/{$version}/me/accounts", [
                'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            ]);

        if (! $response->successful()) {
            Log::error('Instagram fetchPagesWithInstagram failed', [
                'status' => $response->status(),
                'error' => MetaOAuth::describeMetaError($response->json()),
            ]);

            throw new \InvalidArgumentException(
                'No se pudieron obtener tus páginas de Facebook. Verificá los permisos e intentá de nuevo.'
            );
        }

        $pages = [];
        foreach ($response->json('data', []) as $page) {
            $igAccount = $page['instagram_business_account'] ?? null;
            if (! $igAccount || empty($igAccount['id']) || empty($page['access_token'])) {
                continue;
            }

            $pages[] = [
                'page_id' => (string) $page['id'],
                'name' => (string) ($page['name'] ?? ''),
                'ig_user_id' => (string) $igAccount['id'],
                'username' => $igAccount['username'] ?? null,
                'page_access_token' => (string) $page['access_token'],
            ];
        }

        return $pages;
    }

    /**
     * Persiste la config + canal de Instagram para la página dada y suscribe
     * los webhooks. La persistencia va en transacción; la suscripción queda
     * fuera y sólo agrega un warning si falla.
     *
     * @param  array{page_id: string, name: string, ig_user_id: string, username: ?string, page_access_token: string}  $page
     */
    private function connectPage(InstagramChannelStoreRequest $request, array $page): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            throw new \InvalidArgumentException('Usuario no autenticado.');
        }

        // Ownership: si esta cuenta IG ya tiene config con un canal cuyo dueño es
        // otro usuario (mismo u otro tenant), rechazar sin pisar el token.
        $existingConfig = InstagramConfig::where('ig_user_id', $page['ig_user_id'])->first();
        $existingChannel = null;

        if ($existingConfig) {
            $existingChannel = Channel::withoutGlobalScope(TenantScope::class)
                ->where('type', ChannelType::INSTAGRAM)
                ->where('instagram_config_id', $existingConfig->id)
                ->first();

            if ($existingChannel
                && ($existingChannel->tenant_id !== $user->tenant_id || $existingChannel->user_id !== $user->id)) {
                throw new ChannelAlreadyConnectedException(
                    tenantId: (int) $existingChannel->tenant_id,
                    existingUserId: (int) $existingChannel->user_id,
                    requestingUserId: $user->id,
                    phoneNumberId: null,
                    message: 'Esta cuenta de Instagram ya está conectada por otro usuario. '.
                        'Pedile a un administrador que te la reasigne.',
                );
            }
        }

        $channel = DB::transaction(function () use ($request, $page, $user) {
            $config = InstagramConfig::updateOrCreate(
                ['ig_user_id' => $page['ig_user_id']],
                [
                    'tenant_id' => $user->tenant_id,
                    'page_id' => $page['page_id'],
                    'webhook_object_id' => $page['ig_user_id'],
                    'username' => $page['username'],
                    'page_access_token' => Crypt::encryptString($page['page_access_token']),
                ]
            );

            $existing = Channel::where('tenant_id', $user->tenant_id)
                ->where('type', ChannelType::INSTAGRAM)
                ->where('instagram_config_id', $config->id)
                ->first();

            if ($existing) {
                $existing->fill(['status' => 'active'])->save();

                return $existing;
            }

            $name = $page['username'] ? '@'.$page['username'] : ($request->input('name', 'Instagram'));

            return Channel::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'instagram_config_id' => $config->id,
                'type' => ChannelType::INSTAGRAM,
                'external_id' => $page['ig_user_id'],
                'name' => $name,
                'status' => 'active',
            ]);
        });

        $warnings = [];
        if (! $this->subscribeToWebhooks($page['page_id'], $page['page_access_token'])) {
            $warnings[] = 'No se pudo suscribir a los webhooks de Meta. Los mensajes entrantes pueden no llegar.';
        }

        return response()->json([
            'success' => true,
            'message' => 'Cuenta de Instagram conectada exitosamente.',
            'warnings' => $warnings,
        ], 200);
    }

    /**
     * Suscribe la app a los webhooks de mensajes de la página (campo `messages`).
     */
    private function subscribeToWebhooks(string $pageId, string $pageToken): bool
    {
        $version = config('services.facebook.graph_version', 'v21.0');

        try {
            $response = Http::withToken($pageToken)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$version}/{$pageId}/subscribed_apps", [
                    'subscribed_fields' => 'messages',
                ]);

            if (! $response->successful()) {
                Log::error('Instagram subscribeToWebhooks failed', [
                    'status' => $response->status(),
                    'error' => MetaOAuth::describeMetaError($response->json()),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Instagram subscribeToWebhooks exception', MetaOAuth::describeException($e));

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
            Log::warning('Instagram webhook: firma X-Hub-Signature-256 inválida');

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        // Una misma página de Facebook entrega los eventos de Messenger
        // (object=page) y los de Instagram (object=instagram). Procesar el ajeno
        // roba el evento al otro canal y, peor, el backfill de webhook_object_id
        // lo dejaría apuntando al id equivocado de forma permanente.
        $object = $request->input('object');
        if ($object !== 'instagram') {
            Log::info('Instagram webhook: object ajeno ignorado', ['object' => $object]);

            return response()->json(['status' => 'EVENT_RECEIVED'], 200);
        }

        try {
            // No asumimos la forma exacta del payload. Meta puede entregar los
            // mensajes en entry[].messaging[] o en entry[].changes[] (field=messages).
            // Logueamos el payload para poder ajustar el parser con datos reales.
            Log::info('Instagram webhook payload', ['payload' => $request->all()]);

            foreach ($request->input('entry', []) as $entry) {
                $entryId = $entry['id'] ?? null;

                $events = $this->extractMessagingEvents($entry);
                foreach ($events as $event) {
                    $this->messageService->processIncomingMessage($entryId, $event);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Instagram webhook: error procesando evento', MetaOAuth::describeException($e));
        }

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }

    /**
     * Extrae los eventos de mensajería de un `entry`, soportando ambos formatos
     * que Meta puede usar: `messaging[]` directo o `changes[].value` con
     * `field=messages`.
     *
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    private function extractMessagingEvents(array $entry): array
    {
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

            // El value puede ser un único evento (con sender/recipient/message)
            // o un contenedor con messaging[].
            if (! empty($value['messaging']) && is_array($value['messaging'])) {
                $events = array_merge($events, array_values($value['messaging']));
            } else {
                $events[] = $value;
            }
        }

        return $events;
    }

    /**
     * Verifica la cabecera X-Hub-Signature-256 (HMAC-SHA256 del raw body con
     * el app secret).
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
