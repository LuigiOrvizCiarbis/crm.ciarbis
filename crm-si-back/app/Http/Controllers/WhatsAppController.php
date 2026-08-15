<?php

namespace App\Http\Controllers;

use App\Enums\ChannelType;
use App\Events\MessageStatusUpdated;
use App\Exceptions\ChannelAlreadyConnectedException;
use App\Http\Requests\ChannelStoreRequest;
use App\Jobs\VerifyContactSyncJob;
use App\Models\Channel;
use App\Models\Message;
use App\Models\WhatsAppConfig;
use App\Services\WhatsAppBusinessVerificationService;
use App\Services\WhatsAppContactSyncService;
use App\Services\WhatsAppMessageService;
use App\Support\MetaOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppController extends Controller
{
    /**
     * Meta devuelve la relación WABA ↔ app de forma consistente desde v26.0.
     * Esta versión se limita al endpoint /subscribed_apps; el resto del CRM
     * conserva la versión Graph configurada.
     */
    private const WEBHOOK_SUBSCRIPTION_GRAPH_VERSION = 'v26.0';

    // REQUISITO DE CONFIGURACIÓN MANUAL (no se puede hacer por código):
    // para que la coexistencia funcione, en App Dashboard > WhatsApp >
    // Configuration > Webhook fields tienen que estar tildados `messages`,
    // `smb_app_state_sync` y `smb_message_echoes`. Se configuran a nivel app: el
    // endpoint POST /{WABA_ID}/subscribed_apps no acepta elegir campos. En cambio,
    // su GET sí permite validar que esta WABA quedó vinculada a nuestra app.
    // Si falta `smb_app_state_sync` los contactos no llegan nunca y el onboarding
    // igual se ve exitoso: es lo primero a revisar ante ese síntoma.
    // https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users

    /**
     * Margen antes de verificar si llegaron los contactos. Meta manda los webhooks
     * en lotes y puede tardar varios minutos en cuentas con agenda grande, así que
     * verificar antes daría falsos negativos.
     */
    private const CONTACT_SYNC_VERIFY_DELAY_MINUTES = 15;

    /**
     * Memo por request de businessAppState(). Solo guarda respuestas efectivas de
     * Meta: el "no sabemos" (null) no se cachea para que se pueda reintentar.
     *
     * @var array<string,bool>
     */
    private array $isOnBusinessAppCache = [];

    public function __construct(
        private WhatsAppMessageService $messageService
    ) {}

    /**
     * Estado de la importación de contactos de la WhatsApp Business App.
     *
     * Existe porque el onboarding devolvía success sin saber si los contactos
     * llegaban: el usuario no tenía forma de distinguir "todavía no llegaron" de
     * "nunca van a llegar". Admin-only.
     *
     * GET /api/admin/channels/{id}/contact-sync
     */
    public function contactSyncStatus(string $id): JsonResponse
    {
        $channel = Channel::findOrFail($id);

        $this->authorize('connectWhatsapp', Channel::class);

        $config = $channel->whatsappConfig;

        if (! $config) {
            return response()->json([
                'message' => 'El canal no tiene configuración de WhatsApp.',
            ], 422);
        }

        $status = $config->contact_sync_status ?? WhatsAppConfig::SYNC_PENDING;

        return response()->json([
            'data' => [
                'status' => $status,
                'contacts_imported' => $config->contact_sync_contacts_count,
                'requested_at' => $config->contact_sync_requested_at?->toIso8601String(),
                'last_webhook_at' => $config->contact_sync_last_webhook_at?->toIso8601String(),
                'window_expires_at' => $config->contactSyncWindowExpiresAt()?->toIso8601String(),
                'can_retry' => in_array($status, [
                    WhatsAppConfig::SYNC_PENDING,
                    WhatsAppConfig::SYNC_FAILED,
                ], true)
                    && $config->contact_sync_retryable !== false
                    && $config->isWithinContactSyncWindow(),
                'error' => $config->contact_sync_error,
                'error_code' => $config->contact_sync_error_code,
                // El sync de contactos (agenda del teléfono) y el de historial
                // (conversaciones existentes) son dos permisos/eventos distintos
                // de Meta: un canal puede traer historial sin traer un solo
                // contacto nuevo a la agenda (números que escribieron pero no
                // están guardados), por eso van separados.
                'history_status' => $config->contact_history_sync_status,
                'history_messages_imported' => $config->contact_history_sync_messages_count,
                'history_can_retry' => in_array($config->contact_history_sync_status, [
                    null,
                    WhatsAppConfig::SYNC_PENDING,
                    WhatsAppConfig::SYNC_FAILED,
                ], true) && $config->isWithinContactSyncWindow(),
            ],
        ]);
    }

    /**
     * Reintenta la importación de contactos mientras Meta mantenga abierta la
     * ventana de 24 horas del onboarding.
     *
     * POST /api/admin/channels/{id}/contact-sync/retry
     */
    public function retryContactSync(
        string $id,
        WhatsAppContactSyncService $service
    ): JsonResponse {
        $channel = Channel::findOrFail($id);

        $this->authorize('connectWhatsapp', Channel::class);

        $config = $channel->whatsappConfig;

        if (! $config) {
            return response()->json([
                'message' => 'El canal no tiene configuración de WhatsApp.',
            ], 422);
        }

        if ($config->contact_sync_status === WhatsAppConfig::SYNC_COMPLETED) {
            return response()->json([
                'message' => 'Los contactos ya fueron importados.',
            ], 409);
        }

        if ($config->contact_sync_status === WhatsAppConfig::SYNC_SYNCING) {
            return response()->json([
                'message' => 'La importación ya fue aceptada por Meta y está en curso.',
            ], 409);
        }

        if ($config->contact_sync_retryable === false) {
            return response()->json([
                'message' => 'La importación no se puede reintentar. Hay que reconectar el canal.',
            ], 409);
        }

        if (! $config->isWithinContactSyncWindow()) {
            return response()->json([
                'message' => 'Meta ya no acepta el pedido. Hay que reconectar el canal.',
            ], 422);
        }

        if ($service->retrySync($config)) {
            return response()->json([
                'data' => [
                    'status' => WhatsAppConfig::SYNC_SYNCING,
                    'message' => 'La importación fue solicitada. Los contactos llegarán en los próximos minutos.',
                ],
            ]);
        }

        return response()->json([
            'message' => $config->fresh()->contact_sync_error
                ?? 'Meta rechazó el pedido de importación. Intentá reconectar el canal.',
        ], 422);
    }

    /**
     * Reintenta sólo el historial (paso 2), sin depender del estado del sync de
     * contactos (paso 1). Necesario porque retryContactSync() rechaza con 409
     * en cuanto contact_sync_status ya es `completed`, aunque el historial haya
     * quedado `failed` — el caso real es un rate limit de Meta detectado antes
     * de la request (guard preventivo), que deja el historial bloqueado para
     * siempre si no hay una forma de reintentarlo por separado.
     *
     * POST /api/admin/channels/{id}/contact-sync/retry-history
     */
    public function retryHistorySync(
        string $id,
        WhatsAppContactSyncService $service
    ): JsonResponse {
        $channel = Channel::findOrFail($id);

        $this->authorize('connectWhatsapp', Channel::class);

        $config = $channel->whatsappConfig;

        if (! $config) {
            return response()->json([
                'message' => 'El canal no tiene configuración de WhatsApp.',
            ], 422);
        }

        if ($config->contact_history_sync_status === WhatsAppConfig::SYNC_COMPLETED) {
            return response()->json([
                'message' => 'El historial ya fue importado.',
            ], 409);
        }

        if ($config->contact_history_sync_status === WhatsAppConfig::SYNC_SYNCING) {
            return response()->json([
                'message' => 'La importación del historial ya fue aceptada por Meta y está en curso.',
            ], 409);
        }

        // Comparten la ventana de 24h del onboarding: el historial nunca se
        // puede pedir antes que el contact sync, así que no tiene sentido
        // propio fuera de esa ventana.
        if (! $config->isWithinContactSyncWindow()) {
            return response()->json([
                'message' => 'Meta ya no acepta el pedido. Hay que reconectar el canal.',
            ], 422);
        }

        if ($service->retryHistorySync($config)) {
            return response()->json([
                'data' => [
                    'status' => WhatsAppConfig::SYNC_SYNCING,
                    'message' => 'La importación del historial fue solicitada.',
                ],
            ]);
        }

        return response()->json([
            'message' => $config->fresh()->contact_history_sync_error
                ?? 'Meta rechazó el pedido de historial. Intentá reconectar el canal.',
        ], 422);
    }

    /**
     * Devuelve el estado de verificación de negocio (Meta Business Verification)
     * de un canal de WhatsApp. Admin-only (mismo permiso que conectar el canal).
     *
     * GET /api/admin/channels/{id}/business-verification
     */
    public function businessVerification(
        string $id,
        WhatsAppBusinessVerificationService $service
    ): JsonResponse {
        // El TenantScope global filtra por tenant; findOrFail devuelve 404 fuera de él.
        $channel = Channel::findOrFail($id);

        $this->authorize('connectWhatsapp', Channel::class);

        $config = $channel->whatsappConfig;

        if (! $config) {
            return response()->json([
                'message' => 'El canal no tiene configuración de WhatsApp.',
            ], 422);
        }

        return response()->json([
            'data' => $service->statusFor($config),
        ]);
    }

    public function handleAuth(ChannelStoreRequest $request): JsonResponse
    {
        try {
            // 502 si Meta no responde o rechaza el code
            $businessToken = $this->exchangeCodeForToken($request->code);
            if (! $businessToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo obtener el token de Meta. Intentá de nuevo.',
                ], 502);
            }

            $channel = $this->saveChannel($request, $businessToken);
            $config = $channel->whatsappConfig;
            $warnings = [];

            // Registrar el número en Cloud API. Obligatorio en coexistencia
            // (featureType=whatsapp_business_app_onboarding): Meta crea y verifica
            // el número pero el register es nuestro. Sin él, las llamadas siguientes
            // devuelven 133010 "Account not registered" y el número no rutea.
            // Falla dura: no devolvemos success:true para que el front reintente.
            $registerOk = $this->registerPhoneNumber($config, $businessToken);
            if (! $registerOk) {
                Log::error('handleAuth: no se pudo registrar el número en Cloud API', [
                    'tenant_id' => $channel->tenant_id,
                    'phone_number_id' => $config->phone_number_id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo registrar el número en WhatsApp Cloud API. '.
                        'Si el número ya tenía verificación en dos pasos configurada con un PIN propio, '.
                        'desactivala en la app de WhatsApp Business e intentá de nuevo.',
                ], 422);
            }

            // La suscripción va ANTES del sync: si los webhooks no están activos
            // cuando Meta empieza a mandar los contactos, esos lotes se pierden y
            // el sync no se puede volver a pedir desde cero.
            $webhookOk = $this->subscribeToWebhooks($config);
            if (! $webhookOk) {
                // No seguimos: `smb_app_data` sólo se puede pedir una vez por
                // onboarding y, sin la WABA suscripta, Meta no podrá entregar los
                // contactos que acepte importar.
                return response()->json([
                    'success' => false,
                    'message' => 'Meta no confirmó la suscripción de webhooks para esta cuenta de WhatsApp. Intentá reconectar el canal.',
                ], 422);
            }

            // Solo los números que vienen de la WhatsApp Business App tienen agenda
            // para importar. Distinguimos "no es SMB" de "no pudimos averiguarlo":
            // ante la duda intentamos el sync igual, porque marcar not_applicable
            // por un error de red dejaría al cliente sin contactos en silencio.
            $bizAppState = $this->businessAppState($config->phone_number_id, $businessToken);

            if ($bizAppState === false) {
                $config->forceFill([
                    'contact_sync_status' => WhatsAppConfig::SYNC_NOT_APPLICABLE,
                ])->save();
            } else {
                // Docs: ventana de 24h y un solo disparo por onboarding.
                $syncOk = $this->triggerContactSync($config, $businessToken);
                if (! $syncOk) {
                    $warnings[] = 'No se pudo iniciar la sincronización de contactos. Contactá a soporte.';
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Cuenta conectada exitosamente',
                'warnings' => $warnings,
            ], 200);

        } catch (\InvalidArgumentException $e) {
            // 422 cuando no se puede obtener el phone_number_id (error de negocio, no interno)
            Log::warning('handleAuth: phone_number_id no disponible', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);

        } catch (ChannelAlreadyConnectedException $e) {
            // 409 cuando el número ya está conectado por otro usuario del mismo tenant
            Log::warning('handleAuth: número ya conectado por otro usuario del tenant', [
                'tenant_id' => $e->tenantId,
                'existing_user_id' => $e->existingUserId,
                'requesting_user' => $e->requestingUserId,
                'phone_number_id' => $e->phoneNumberId,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);

        } catch (\Exception $e) {
            // No loguear getTraceAsString(): el stack trace de PHP serializa los
            // argumentos de cada frame, lo que filtraría $businessToken en claro.
            Log::error('handleAuth: error interno', $this->describeException($e));

            return response()->json([
                'success' => false,
                'message' => 'Error interno al procesar la solicitud.',
            ], 500);
        }
    }

    /**
     * Resume una excepción a campos seguros para loguear.
     *
     * @return array{exception: class-string<\Throwable>, message: string, file: string, line: int}
     */
    private function describeException(\Throwable $e): array
    {
        return MetaOAuth::describeException($e);
    }

    /**
     * Saca tokens y secretos de un mensaje libre antes de loguearlo.
     */
    private function scrubMessage(string $message): string
    {
        return MetaOAuth::scrubMessage($message);
    }

    /**
     * Extrae solo los campos seguros de un error de Graph API.
     *
     * @return array{code: int|null, type: string|null, subcode: int|null, message: string|null}
     */
    private function describeMetaError(?array $body): array
    {
        return MetaOAuth::describeMetaError($body);
    }

    private function exchangeCodeForToken(string $code): ?string
    {
        // El token del Embedded Signup nace con ~60 días de vida; el helper lo
        // extiende a long-lived antes de devolverlo.
        return MetaOAuth::exchangeCodeForToken($code);
    }

    private function saveChannel(Request $request, string $businessToken): Channel
    {
        $user = $request->user();

        if (! $user) {
            throw new \Exception('Usuario no autenticado');
        }

        $wabaId = $request->data['waba_id'] ?? null;
        $phoneNumberId = $request->data['phone_number_id'] ?? null;
        // business_id: ID del Business Manager dueño del WABA. El front lo envía
        // dentro de data (evento WA_EMBEDDED_SIGNUP) y también top-level. Se usa
        // para leer el verification_status del negocio. No es secreto.
        $businessId = $request->data['business_id'] ?? $request->input('business_id');

        // Paso 1: obtener datos del número de teléfono desde la Graph API.
        // Siempre intentamos para obtener display_phone_number, y si no teníamos
        // phone_number_id (flujo coexistencia), lo obtenemos también.
        $displayPhoneNumber = null;
        if ($wabaId) {
            $phoneData = $this->fetchFirstPhoneNumber($wabaId, $businessToken);
            if ($phoneData) {
                if (! $phoneNumberId) {
                    $phoneNumberId = $phoneData['id'] ?? null;
                }
                $displayPhoneNumber = $phoneData['display_phone_number'] ?? null;
            }
        }

        // Paso 2: si la API de Meta falló (fallo transitorio / re-auth), recuperar
        // el phone_number_id del registro existente en DB para no bloquear al usuario.
        // Nota: el match incluye phone_number_id no nulo solamente para tener un
        // fallback útil; un WABA con varios números obliga a la API de Meta de todos modos.
        if (! $phoneNumberId && $wabaId) {
            $existing = WhatsAppConfig::where('waba_id', $wabaId)
                ->whereNotNull('phone_number_id')
                ->first();

            if ($existing) {
                $phoneNumberId = $existing->phone_number_id;
                Log::info('saveChannel: phone_number_id recuperado de config existente (re-auth)', [
                    'waba_id' => $wabaId,
                    'phone_number_id' => $phoneNumberId,
                ]);
            }
        }

        // Paso 3: sin phone_number_id el canal no puede rutear mensajes → error 422.
        if (! $phoneNumberId) {
            throw new \InvalidArgumentException(
                'No se pudo obtener el número de teléfono de Meta. '.
                'Verificá los permisos del token o intentá de nuevo.'
            );
        }

        // Paso 4: validar ownership ANTES de escribir credenciales.
        // Si ya existe una WhatsAppConfig para (waba_id, phone_number_id) con un
        // Channel del tenant cuyo dueño es otro usuario, se rechaza con 409 sin
        // sobrescribir el token. Reasignar el dueño es una feature admin separada.
        $existingConfig = WhatsAppConfig::where('waba_id', $wabaId)
            ->where('phone_number_id', $phoneNumberId)
            ->first();

        $existingChannel = null;
        if ($existingConfig) {
            $existingChannel = Channel::where('tenant_id', $user->tenant_id)
                ->where('type', ChannelType::WHATSAPP)
                ->where('whatsapp_config_id', $existingConfig->id)
                ->first();

            if ($existingChannel && $existingChannel->user_id !== $user->id) {
                throw new ChannelAlreadyConnectedException(
                    tenantId: $user->tenant_id,
                    existingUserId: (int) $existingChannel->user_id,
                    requestingUserId: $user->id,
                    phoneNumberId: $phoneNumberId,
                );
            }
        }

        // Paso 5: persistir/actualizar la WhatsAppConfig.
        // El match es por (waba_id, phone_number_id): identifica un número real en Meta.
        // Cambiar de phone_number_id (segundo número del mismo WABA, o WABA distinto)
        // crea una config nueva en lugar de pisar el token del número anterior.
        $updateData = ['bussines_token' => Crypt::encryptString($businessToken)];
        if ($displayPhoneNumber) {
            $updateData['display_phone_number'] = $displayPhoneNumber;
        }
        if ($businessId) {
            $updateData['business_id'] = $businessId;
        }

        $whatsappConfig = WhatsAppConfig::updateOrCreate(
            [
                'waba_id' => $wabaId,
                'phone_number_id' => $phoneNumberId,
            ],
            $updateData
        );

        // Paso 6: resolver el Channel del tenant para esa config.
        if ($existingChannel) {
            $existingChannel->fill(['status' => 'active'])->save();

            return $existingChannel;
        }

        return Channel::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'whatsapp_config_id' => $whatsappConfig->id,
            'type' => ChannelType::WHATSAPP,
            'name' => $request->input('name', 'WhatsApp Business'),
            'status' => 'active',
        ]);
    }

    /**
     * Obtiene el ID del primer número de teléfono del WABA vía Graph API.
     * Se usa en el flujo de coexistencia donde Meta no devuelve phone_number_id.
     *
     * Advertencia: si el WABA tiene más de un número, se toma el primero.
     * En ese caso habría que agregar UI para que el usuario elija.
     */
    private function fetchFirstPhoneNumber(string $wabaId, string $token): ?array
    {
        $version = config('services.facebook.graph_version', 'v21.0');

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get("https://graph.facebook.com/{$version}/{$wabaId}/phone_numbers", [
                    'fields' => 'id,display_phone_number,verified_name',
                ]);

            if ($response->successful()) {
                $numbers = $response->json('data', []);

                if (count($numbers) > 1) {
                    Log::warning('fetchFirstPhoneNumberId: WABA tiene múltiples números, se tomó el primero', [
                        'waba_id' => $wabaId,
                        'count' => count($numbers),
                    ]);
                }

                return $numbers[0] ?? null;
            }

            Log::error('fetchFirstPhoneNumberId failed', [
                'status' => $response->status(),
                'error' => $this->describeMetaError($response->json()),
            ]);
        } catch (\Throwable $e) {
            Log::error('fetchFirstPhoneNumberId exception', $this->describeException($e));
        }

        return null;
    }

    private function registerPhoneNumber(WhatsAppConfig $whatsAppConfig, string $token): bool
    {
        $phoneNumberId = $whatsAppConfig->phone_number_id;

        if (! $phoneNumberId) {
            Log::warning('registerPhoneNumber: phone_number_id no disponible, registro omitido');

            return false;
        }

        $version = config('services.facebook.graph_version', 'v21.0');

        // Flujo coexistencia (WhatsApp Business App / SMB): Meta registra el número
        // solo durante el Embedded Signup. La doc dice explícitamente "skip the phone
        // number registration step as the number is already registered" y el endpoint
        // /register devuelve "Register endpoint is not available for SMB businesses".
        // Si el número ya está en la Business App, saltamos el register (éxito).
        if ($this->isOnBusinessApp($phoneNumberId, $token)) {
            Log::info('registerPhoneNumber: número en coexistencia (SMB), register omitido', [
                'phone_number_id' => $phoneNumberId,
            ]);

            return true;
        }

        // El endpoint /register exige siempre un `pin` de 6 dígitos (two-step
        // verification). En el alta de un número nuevo, ese PIN lo define el partner
        // (nosotros) y queda seteado como el two-step del número. Lo persistimos
        // porque en re-registros futuros Meta pedirá ese mismo PIN. Si ya hay uno
        // guardado lo reusamos; si no, generamos uno nuevo.
        $pin = $whatsAppConfig->getDecryptedRegistrationPin() ?? $this->generateRegistrationPin();

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/register", [
                    'messaging_product' => 'whatsapp',
                    'pin' => $pin,
                ]);

            if ($response->successful()) {
                $whatsAppConfig->setEncryptedRegistrationPin($pin);

                Log::info('registerPhoneNumber: número registrado en Cloud API', [
                    'phone_number_id' => $phoneNumberId,
                ]);

                return true;
            }

            $body = $response->json();
            $errorCode = data_get($body, 'error.code');
            $errorMessage = strtolower((string) data_get($body, 'error.message', ''));

            $alreadyRegistered = in_array($errorCode, [133015], true)
                || str_contains($errorMessage, 'already registered')
                || str_contains($errorMessage, 'already been registered');

            if ($alreadyRegistered) {
                Log::info('registerPhoneNumber: número ya estaba registrado (idempotente)', [
                    'phone_number_id' => $phoneNumberId,
                    'error_code' => $errorCode,
                ]);

                return true;
            }

            // Red de seguridad: si el chequeo is_on_biz_app falló o dio falso negativo
            // pero el número es SMB, Meta rechaza el register con este mensaje. Para
            // coexistencia el register no aplica → lo tratamos como éxito, no error.
            if (str_contains($errorMessage, 'not available for smb')) {
                Log::info('registerPhoneNumber: register no disponible para SMB, omitido (idempotente)', [
                    'phone_number_id' => $phoneNumberId,
                ]);

                return true;
            }

            Log::error('registerPhoneNumber failed', [
                'status' => $response->status(),
                'error' => $this->describeMetaError($body),
                'phone_number_id' => $phoneNumberId,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('registerPhoneNumber exception', $this->describeException($e));

            return false;
        }
    }

    /**
     * Genera un PIN de 6 dígitos para la verificación en dos pasos del número.
     */
    private function generateRegistrationPin(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Indica si el número está conectado a la WhatsApp Business App (coexistencia/SMB).
     * En ese caso Meta ya lo registró y el endpoint /register no aplica.
     * Docs: GET /{phone_number_id}?fields=is_on_biz_app,platform_type
     *
     * Ante cualquier error de la consulta devuelve false: que el register lo intente
     * y, si es SMB, el manejo del error "not available for SMB" actúa de red de seguridad.
     */
    private function isOnBusinessApp(string $phoneNumberId, string $token): bool
    {
        // Para el register, "no sabemos" se trata como false: que lo intente y el
        // manejo del error "not available for SMB" actúa de red de seguridad.
        return $this->businessAppState($phoneNumberId, $token) === true;
    }

    /**
     * Estado de coexistencia del número, distinguiendo el caso "no pudimos saberlo".
     *
     * @return bool|null true = está en la Business App, false = no lo está,
     *                   null = Meta no respondió y no podemos afirmar ninguna de las dos.
     */
    private function businessAppState(string $phoneNumberId, string $token): ?bool
    {
        // El onboarding consulta esto dos veces (registro y sync). Memoizamos por
        // request para no pegarle dos veces a Meta con la misma pregunta.
        if (array_key_exists($phoneNumberId, $this->isOnBusinessAppCache)) {
            return $this->isOnBusinessAppCache[$phoneNumberId];
        }

        $version = config('services.facebook.graph_version', 'v21.0');

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get("https://graph.facebook.com/{$version}/{$phoneNumberId}", [
                    'fields' => 'is_on_biz_app,platform_type',
                ]);

            if ($response->successful()) {
                return $this->isOnBusinessAppCache[$phoneNumberId]
                    = (bool) $response->json('is_on_biz_app', false);
            }

            Log::warning('isOnBusinessApp: no se pudo consultar el estado del número', [
                'status' => $response->status(),
                'error' => $this->describeMetaError($response->json()),
                'phone_number_id' => $phoneNumberId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('isOnBusinessApp exception', $this->describeException($e));
        }

        // No cacheamos: es "no sabemos", no una respuesta de Meta.
        return null;
    }

    private function subscribeToWebhooks(WhatsAppConfig $whatsAppConfig): bool
    {
        $wabaId = $whatsAppConfig->waba_id;

        if (! $wabaId) {
            Log::warning('subscribeToWebhooks: WABA ID ausente');

            return false;
        }

        $token = $whatsAppConfig->getDecryptedToken();

        if (! $token) {
            Log::error('subscribeToWebhooks: no se pudo descifrar el token');

            return false;
        }

        $version = self::WEBHOOK_SUBSCRIPTION_GRAPH_VERSION;

        try {
            // Sin body: el endpoint sólo acepta override_callback_uri/verify_token.
            // Los campos se seleccionan en App Dashboard; este POST vincula la WABA
            // con nuestra app para que esos campos puedan ser entregados.
            $response = Http::withToken($token)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$version}/{$wabaId}/subscribed_apps");

            if (! $response->successful()) {
                Log::error('subscribeToWebhooks failed', [
                    'status' => $response->status(),
                    'error' => $this->describeMetaError($response->json()),
                ]);

                return false;
            }

            $verification = Http::withToken($token)
                ->timeout(15)
                ->get("https://graph.facebook.com/{$version}/{$wabaId}/subscribed_apps");

            if (! $verification->successful()) {
                Log::error('subscribeToWebhooks verification failed', [
                    'status' => $verification->status(),
                    'error' => $this->describeMetaError($verification->json()),
                    'waba_id' => $wabaId,
                ]);

                return false;
            }

            $appId = (string) config('services.facebook.app_id');
            $subscribed = $appId !== '' && collect($verification->json('data', []))
                ->contains(fn (array $app): bool => (string) (data_get($app, 'id')
                    ?? data_get($app, 'whatsapp_business_api_data.id')) === $appId);

            if (! $subscribed) {
                Log::error('subscribeToWebhooks: Meta no confirmó la WABA suscripta', [
                    'waba_id' => $wabaId,
                    'app_id' => $appId,
                ]);
            }

            return $subscribed;
        } catch (\Throwable $e) {
            Log::error('subscribeToWebhooks exception', $this->describeException($e));

            return false;
        }
    }

    /**
     * Dispara la sincronización inicial de contactos del WhatsApp Business App.
     *
     * Docs Meta: "You have a 24-hour window to synchronize contacts and messaging
     * history. Failure to do so will require the customer to be offboarded."
     * "This step can only be performed once."
     *
     * @see https://developers.facebook.com/docs/whatsapp/embedded-signup/onboarding-business-app-users
     */
    private function triggerContactSync(WhatsAppConfig $whatsAppConfig, string $token): bool
    {
        $phoneNumberId = $whatsAppConfig->phone_number_id;

        if (! $phoneNumberId) {
            Log::warning('triggerContactSync: phone_number_id no disponible, sync omitida');
            $this->markSyncFailed($whatsAppConfig, 'phone_number_id no disponible');

            return false;
        }

        // Guard de idempotencia: Meta sólo acepta este pedido una vez por
        // onboarding. Un reauth del mismo número (handleAuth corriendo de nuevo)
        // no debe volver a llamar smb_app_data ni gastar cuota de rate limit de
        // la app. El UPDATE condicional reserva el slot de forma atómica: si dos
        // requests llegan a la vez, sólo uno pasa (0 filas afectadas para el otro).
        $reserved = WhatsAppConfig::where('id', $whatsAppConfig->id)
            ->whereNull('contact_sync_requested_at')
            ->where(function ($query) {
                $query->whereNull('contact_sync_status')
                    ->orWhere('contact_sync_status', WhatsAppConfig::SYNC_PENDING);
            })
            ->update(['contact_sync_status' => WhatsAppConfig::SYNC_SYNCING]);

        if ($reserved === 0) {
            Log::info('triggerContactSync: sync ya disparada antes, se omite el pedido duplicado', [
                'phone_number_id' => $phoneNumberId,
                'contact_sync_status' => $whatsAppConfig->fresh()->contact_sync_status,
            ]);

            // Si ya está completo/syncing es éxito (el canal quedó conectado);
            // sólo failed/not_applicable cuentan como fallo del onboarding.
            $currentStatus = $whatsAppConfig->fresh()->contact_sync_status;

            return in_array($currentStatus, [WhatsAppConfig::SYNC_COMPLETED, WhatsAppConfig::SYNC_SYNCING], true);
        }

        $whatsAppConfig->refresh();

        // Guard preventivo: la cuota de smb_app_data es la del business token del
        // cliente, invisible desde el dashboard de la app. Si la última lectura
        // del header X-App-Usage la vio crítica y sigue vigente, no llamamos:
        // un rechazo por cuota consumiría igual el único disparo permitido.
        if ($whatsAppConfig->hasCriticalMetaUsage()) {
            Log::warning('triggerContactSync: cuota de Meta crítica, se omite el pedido', [
                'phone_number_id' => $phoneNumberId,
                'meta_app_usage_pct' => $whatsAppConfig->meta_app_usage_pct,
            ]);

            $this->markSyncFailed(
                $whatsAppConfig,
                'Meta está limitando las llamadas de la app. Esperá unos minutos y reintentá.',
                'rate_limit',
                retryable: true
            );

            return false;
        }

        $version = config('services.facebook.graph_version', 'v21.0');

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/smb_app_data", [
                    'messaging_product' => 'whatsapp',
                    'sync_type' => 'smb_app_state_sync',
                ]);

            $this->recordMetaUsage($whatsAppConfig, $response);

            if ($response->successful()) {
                // OJO: un 2xx solo dice que Meta aceptó el pedido. Los contactos
                // llegan después por webhook, así que el estado queda en `syncing`
                // hasta que VerifyContactSyncJob confirme que llegaron.
                $whatsAppConfig->forceFill([
                    'contact_sync_status' => WhatsAppConfig::SYNC_SYNCING,
                    'contact_sync_requested_at' => now(),
                    // Meta devuelve un request_id: es lo que pide su soporte para
                    // rastrear un sync que no llegó.
                    'contact_sync_request_id' => $response->json('request_id'),
                    'contact_sync_error' => null,
                    'contact_sync_error_code' => null,
                    'contact_sync_retryable' => false,
                ])->save();

                Log::info('triggerContactSync: sync pedida a Meta, esperando webhooks', [
                    'phone_number_id' => $phoneNumberId,
                    'request_id' => $response->json('request_id'),
                ]);

                VerifyContactSyncJob::dispatch($whatsAppConfig->id)
                    ->delay(now()->addMinutes(self::CONTACT_SYNC_VERIFY_DELAY_MINUTES));

                $this->triggerHistorySync($whatsAppConfig, $token);

                return true;
            }

            // 400: puede ser "ya sincronizado antes" (esperado) u otro error real.
            // Validamos el mensaje para no ocultar 400s por payload/permisos inválidos.
            if ($response->status() === 400) {
                $body = $response->json();
                $errorCode = data_get($body, 'error.code');
                $errorSubcode = data_get($body, 'error.error_subcode');
                $errorMessage = strtolower((string) data_get($body, 'error.message', ''));

                $alreadySynced = str_contains($errorMessage, 'already')
                    && str_contains($errorMessage, 'smb_app_data');

                if ($alreadySynced) {
                    Log::info('triggerContactSync: sync ya realizada previamente (400 esperado)', [
                        'phone_number_id' => $phoneNumberId,
                        'error_code' => $errorCode,
                        'error_subcode' => $errorSubcode,
                    ]);

                    // Meta ya consumió el único disparo permitido. Si nunca llegaron
                    // contactos, reintentar es inútil: verificamos y, si está vacío,
                    // el estado queda en `failed` para que se vea en el CRM.
                    $whatsAppConfig->forceFill([
                        'contact_sync_status' => WhatsAppConfig::SYNC_SYNCING,
                        'contact_sync_requested_at' => $whatsAppConfig->contact_sync_requested_at ?? now(),
                        'contact_sync_retryable' => false,
                    ])->save();

                    VerifyContactSyncJob::dispatch($whatsAppConfig->id)
                        ->delay(now()->addMinutes(self::CONTACT_SYNC_VERIFY_DELAY_MINUTES));

                    $this->triggerHistorySync($whatsAppConfig, $token);

                    return true;
                }

                Log::warning('triggerContactSync: 400 inesperado de Meta', [
                    'phone_number_id' => $phoneNumberId,
                    'error' => $this->describeMetaError($body),
                ]);

                $this->markSyncFailed(
                    $whatsAppConfig,
                    MetaOAuth::formatMetaError($body),
                    $this->classifyMetaErrorCode($body)
                );

                return false;
            }

            $errorBody = $response->json();

            Log::warning('triggerContactSync: respuesta inesperada de Meta', [
                'status' => $response->status(),
                'error' => $this->describeMetaError($errorBody),
                'phone_number_id' => $phoneNumberId,
            ]);

            $errorCode = $this->classifyMetaErrorCode($errorBody);

            $this->markSyncFailed(
                $whatsAppConfig,
                MetaOAuth::formatMetaError($errorBody),
                $errorCode,
                retryable: $errorCode === 'rate_limit'
            );

            return false;

        } catch (\Throwable $e) {
            Log::error('triggerContactSync exception', $this->describeException($e));
            $this->markSyncFailed($whatsAppConfig, $e->getMessage());

            return false;
        }
    }

    /**
     * Deja registrado por qué falló el sync para poder mostrarlo en el CRM.
     * Antes estos errores solo iban al log y el usuario nunca se enteraba.
     *
     * `$errorCode` es un identificador tipado (mismo patrón que AiTestErrorCode
     * en el front) para que la UI pueda traducir el mensaje en vez de mostrar el
     * texto crudo de Meta. `$retryable` sólo se marca true para fallos que de
     * verdad ameritan reintentar (hoy: rate limit); el resto queda en false
     * como antes, porque Meta ya consumió el disparo único.
     */
    private function markSyncFailed(
        WhatsAppConfig $whatsAppConfig,
        ?string $error,
        ?string $errorCode = null,
        bool $retryable = false
    ): void {
        $whatsAppConfig->forceFill([
            'contact_sync_status' => WhatsAppConfig::SYNC_FAILED,
            'contact_sync_error' => Str::limit((string) $error, 500),
            'contact_sync_error_code' => $errorCode,
            'contact_sync_retryable' => $retryable,
        ])->save();
    }

    /**
     * Clasifica el error de Meta a un código tipado que el front puede traducir.
     * Hoy sólo distingue rate limit (código 4, "Application request limit
     * reached"): es el caso real que motivó esto — el rechazo no dice nada
     * accionable y el usuario no tiene forma de saber que hay que esperar.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function classifyMetaErrorCode(?array $body): ?string
    {
        $code = data_get($body, 'error.code');

        return (int) $code === 4 ? 'rate_limit' : null;
    }

    /**
     * Persiste la última lectura del header X-App-Usage de una respuesta de
     * Graph API. Se llama en cada request a smb_app_data, con éxito o error:
     * el header viene igual en ambos casos y es la única señal que tenemos de
     * esta cuota (ver WhatsAppConfig::hasCriticalMetaUsage).
     */
    private function recordMetaUsage(WhatsAppConfig $whatsAppConfig, \Illuminate\Http\Client\Response $response): void
    {
        $usagePct = MetaOAuth::parseAppUsage($response);

        if ($usagePct === null) {
            return;
        }

        $whatsAppConfig->forceFill([
            'meta_app_usage_pct' => $usagePct,
            'meta_app_usage_at' => now(),
        ])->save();
    }

    /**
     * Paso 2 de la coexistencia: sincronización del historial de mensajes.
     *
     * Docs Meta: "Use the SMB App Data API again, this time to initiate messaging
     * history synchronization... you can only perform this step once." Va después
     * del sync de contactos (nunca antes) y comparte su ventana de 24h.
     *
     * Best-effort: si falla, no revertimos el éxito ya devuelto por
     * triggerContactSync. Los contactos son la parte crítica del onboarding; el
     * historial es un complemento documentado que no debe bloquear la conexión.
     *
     * @see https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users
     */
    private function triggerHistorySync(WhatsAppConfig $whatsAppConfig, string $token): void
    {
        $phoneNumberId = $whatsAppConfig->phone_number_id;

        if (! $phoneNumberId) {
            return;
        }

        // Mismo guard atómico que triggerContactSync: handleAuth puede volver a
        // correr para el mismo canal (reauth) y este método se llama desde dos
        // puntos de triggerContactSync. Sin esto, cada reintento de onboarding
        // vuelve a pedir smb_app_data (sync_type=history) y quema cuota de rate
        // limit de la app de Meta de forma innecesaria.
        $reserved = WhatsAppConfig::where('id', $whatsAppConfig->id)
            ->whereNull('contact_history_sync_requested_at')
            ->where(function ($query) {
                $query->whereNull('contact_history_sync_status')
                    ->orWhere('contact_history_sync_status', WhatsAppConfig::SYNC_PENDING);
            })
            ->update(['contact_history_sync_status' => WhatsAppConfig::SYNC_SYNCING]);

        if ($reserved === 0) {
            Log::info('triggerHistorySync: sync de historial ya disparada antes, se omite el pedido duplicado', [
                'phone_number_id' => $phoneNumberId,
            ]);

            return;
        }

        $whatsAppConfig->refresh();

        // Mismo guard preventivo que triggerContactSync: ver ese método para el
        // detalle de por qué esta cuota es invisible fuera de este header.
        if ($whatsAppConfig->hasCriticalMetaUsage()) {
            Log::warning('triggerHistorySync: cuota de Meta crítica, se omite el pedido', [
                'phone_number_id' => $phoneNumberId,
                'meta_app_usage_pct' => $whatsAppConfig->meta_app_usage_pct,
            ]);

            $whatsAppConfig->forceFill([
                'contact_history_sync_status' => WhatsAppConfig::SYNC_FAILED,
                'contact_history_sync_error' => 'Meta está limitando las llamadas de la app. Esperá unos minutos y reintentá.',
            ])->save();

            return;
        }

        $version = config('services.facebook.graph_version', 'v21.0');

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/smb_app_data", [
                    'messaging_product' => 'whatsapp',
                    'sync_type' => 'history',
                ]);

            $this->recordMetaUsage($whatsAppConfig, $response);

            if ($response->successful()) {
                $whatsAppConfig->forceFill([
                    'contact_history_sync_status' => WhatsAppConfig::SYNC_SYNCING,
                    'contact_history_sync_requested_at' => now(),
                    'contact_history_sync_request_id' => $response->json('request_id'),
                    'contact_history_sync_error' => null,
                ])->save();

                Log::info('triggerHistorySync: sync de historial pedida a Meta', [
                    'phone_number_id' => $phoneNumberId,
                    'request_id' => $response->json('request_id'),
                ]);

                return;
            }

            $body = $response->json();
            $errorMessage = strtolower((string) data_get($body, 'error.message', ''));

            // Igual que en triggerContactSync: un 400 "already" es el caso esperado
            // cuando el paso 2 ya se pidió antes (reconexión del mismo canal).
            if ($response->status() === 400 && str_contains($errorMessage, 'already') && str_contains($errorMessage, 'smb_app_data')) {
                Log::info('triggerHistorySync: historial ya sincronizado previamente (400 esperado)', [
                    'phone_number_id' => $phoneNumberId,
                ]);

                $whatsAppConfig->forceFill([
                    'contact_history_sync_status' => WhatsAppConfig::SYNC_SYNCING,
                    'contact_history_sync_requested_at' => $whatsAppConfig->contact_history_sync_requested_at ?? now(),
                ])->save();

                return;
            }

            Log::warning('triggerHistorySync: Meta rechazó el pedido de historial', [
                'phone_number_id' => $phoneNumberId,
                'status' => $response->status(),
                'error' => $this->describeMetaError($body),
            ]);

            $whatsAppConfig->forceFill([
                'contact_history_sync_status' => WhatsAppConfig::SYNC_FAILED,
                'contact_history_sync_error' => Str::limit(MetaOAuth::formatMetaError($body), 500),
            ])->save();
        } catch (\Throwable $e) {
            Log::error('triggerHistorySync exception', $this->describeException($e));

            $whatsAppConfig->forceFill([
                'contact_history_sync_status' => WhatsAppConfig::SYNC_FAILED,
                'contact_history_sync_error' => Str::limit($e->getMessage(), 500),
            ])->save();
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

        try {
            foreach ($request->input('entry', []) as $entry) {
                foreach ($entry['changes'] ?? [] as $change) {
                    $field = $change['field'] ?? '';
                    $value = $change['value'] ?? [];

                    if ($field === 'messages' && isset($value['statuses'])) {
                        $this->processStatusUpdates($value['statuses']);
                    }

                    if ($field === 'messages' && isset($value['messages'])) {
                        $this->messageService->processIncomingMessage($change);

                    } elseif ($field === 'smb_message_echoes' && isset($value['message_echoes'])) {
                        $this->messageService->processSmbMessageEchoes($change);

                    } elseif ($field === 'smb_app_state_sync') {
                        $this->handleSmbAppStateSync($entry['id'] ?? null, $value);

                    } elseif ($field === 'history') {
                        $this->handleHistorySync($entry['id'] ?? null, $value);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Error processing webhook', $this->describeException($e));
        }

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }

    /**
     * Procesar los status updates que Meta envía por webhook (sent/delivered/
     * read/failed). El mensaje saliente se resuelve por su wamid, guardado en
     * `messages.external_id`. Antes esto solo se logueaba: un `failed` de Meta
     * (p. ej. template de documento sin filename) quedaba invisible y el mensaje
     * seguía figurando como enviado en el CRM.
     */
    private function processStatusUpdates(array $statuses): void
    {
        foreach ($statuses as $status) {
            $wamid = $status['id'] ?? null;
            $state = $status['status'] ?? null;

            if (! $wamid || ! $state) {
                continue;
            }

            $message = Message::where('external_id', $wamid)->first();

            if (! $message) {
                // El status puede llegar antes de que persistamos el mensaje, o
                // corresponder a un envío que no originamos. Dejamos rastro sin frenar.
                Log::info('WhatsApp status sin mensaje asociado', [
                    'wamid' => $wamid,
                    'status' => $state,
                ]);

                continue;
            }

            $changed = false;

            switch ($state) {
                case 'delivered':
                    if (! $message->isDelivered()) {
                        $message->markAsDelivered();
                        $changed = true;
                    }
                    break;

                case 'read':
                    // `read` implica entregado; completamos ambos si faltan.
                    if (! $message->isDelivered()) {
                        $message->markAsDelivered();
                        $changed = true;
                    }
                    if (! $message->isRead()) {
                        $message->markAsRead();
                        $changed = true;
                    }
                    break;

                case 'failed':
                    if (! $message->isFailed()) {
                        $error = $this->describeStatusError($status['errors'] ?? []);
                        $message->markAsFailed($error);
                        $changed = true;

                        Log::warning('WhatsApp message failed', [
                            'wamid' => $wamid,
                            'message_id' => $message->id,
                            'conversation_id' => $message->conversation_id,
                            'error' => $error,
                            'errors' => $status['errors'] ?? [],
                        ]);
                    }
                    break;

                    // `sent` no aporta más que el wamid que ya tenemos al crear el mensaje.
            }

            // Solo emitimos si el estado realmente cambió: Meta reenvía statuses
            // duplicados y no queremos spamear el canal ni re-renderizar el front.
            if ($changed && $message->conversation_id) {
                broadcast(new MessageStatusUpdated($message));
            }
        }
    }

    /**
     * Aplanar el array `errors[]` de un status `failed` de Meta a un string
     * legible para persistir en `messages.error_message` y mostrar en el CRM.
     * Shape del webhook: [{ code, title, message, error_data: { details } }].
     */
    private function describeStatusError(array $errors): ?string
    {
        $parts = [];

        foreach ($errors as $error) {
            $code = $error['code'] ?? null;
            $detail = $error['error_data']['details'] ?? $error['message'] ?? $error['title'] ?? null;

            $label = trim(($code !== null ? "[{$code}] " : '').(string) $detail);

            if ($label !== '') {
                $parts[] = $label;
            }
        }

        return $parts !== [] ? implode('; ', $parts) : null;
    }

    private function handleSmbAppStateSync(?string $wabaId, array $value): void
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        $whatsappConfig = null;

        if ($phoneNumberId) {
            $whatsappConfig = WhatsAppConfig::with('channels')
                ->where('phone_number_id', $phoneNumberId)
                ->first();
        }

        if (! $whatsappConfig && $wabaId) {
            $whatsappConfig = WhatsAppConfig::with('channels')
                ->where('waba_id', $wabaId)
                ->first();
        }

        if (! $whatsappConfig || $whatsappConfig->channels->isEmpty()) {
            Log::warning('smb_app_state_sync: canal no encontrado', [
                'waba_id' => $wabaId,
                'phone_number_id' => $phoneNumberId,
            ]);

            return;
        }

        $tenantId = $whatsappConfig->channels->first()->tenant_id;
        $upserted = $this->messageService->processSmbAppStateSync($value, $tenantId);

        // Marca el sync como completado: es la prueba de que los contactos
        // realmente llegaron, no solo que Meta aceptó el pedido.
        app(WhatsAppContactSyncService::class)->recordWebhookBatch($whatsappConfig, $upserted);
    }

    /**
     * Código que Meta devuelve cuando el negocio NO aceptó compartir su
     * historial de mensajes durante el Embedded Signup. En ese caso el webhook
     * `history` llega igual, pero sin threads y con este error en su lugar.
     *
     * @see https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users
     */
    private const HISTORY_NOT_SHARED_ERROR_CODE = 2593109;

    /**
     * Webhook del paso 2 (sync_type=history) de la coexistencia.
     *
     * Importa los threads/mensajes al CRM (Contact/Conversation/Message) igual
     * que un mensaje entrante normal. Meta entrega el historial en chunks: cada
     * webhook trae metadata.progress (0-100) y sólo el chunk con progress=100
     * marca el sync como terminado. Formato confirmado contra la doc oficial de
     * Meta y payloads reales de producción (ver processHistorySync).
     */
    private function handleHistorySync(?string $wabaId, array $value): void
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        $whatsappConfig = null;

        if ($phoneNumberId) {
            $whatsappConfig = WhatsAppConfig::with('channels')
                ->where('phone_number_id', $phoneNumberId)
                ->first();
        }

        if (! $whatsappConfig && $wabaId) {
            $whatsappConfig = WhatsAppConfig::with('channels')
                ->where('waba_id', $wabaId)
                ->first();
        }

        Log::info('history: webhook recibido', [
            'waba_id' => $wabaId,
            'phone_number_id' => $phoneNumberId,
            'value' => $value,
        ]);

        if (! $whatsappConfig || $whatsappConfig->channels->isEmpty()) {
            Log::warning('history: canal no encontrado', [
                'waba_id' => $wabaId,
                'phone_number_id' => $phoneNumberId,
            ]);

            return;
        }

        try {
            $result = $this->messageService->processHistorySync($value, $whatsappConfig->channels->first());

            Log::info('history: chunk procesado', [
                'whatsapp_config_id' => $whatsappConfig->id,
                'imported' => $result['imported'],
                'progress' => $result['progress'],
                'phase' => $result['phase'],
                'error_code' => $result['error_code'],
            ]);

            if ($result['error_code'] === self::HISTORY_NOT_SHARED_ERROR_CODE) {
                // El negocio no aceptó compartir su historial: no es un fallo
                // nuestro, no hay nada que reintentar.
                $whatsappConfig->forceFill([
                    'contact_history_sync_status' => WhatsAppConfig::SYNC_NOT_APPLICABLE,
                    'contact_history_sync_error' => null,
                ])->save();

                return;
            }

            // Cada webhook es un chunk del mismo sync (Meta entrega el historial
            // en varios POSTs, potencialmente en paralelo): se acumula, igual
            // que contact_sync_contacts_count. Usa un UPDATE atómico
            // (increment) en vez de leer+sumar+save(), porque dos webhooks
            // concurrentes leyendo el mismo valor viejo pisarían el incremento
            // uno del otro y subreportarían el conteo real.
            if ($result['imported'] > 0) {
                WhatsAppConfig::where('id', $whatsappConfig->id)
                    ->increment('contact_history_sync_messages_count', $result['imported']);

                $whatsappConfig->refresh();
            }

            // progress llega por chunk: sólo el último (100) prueba que terminó.
            // Marcar completed antes cortaría chunks siguientes en curso.
            if ($result['progress'] >= 100) {
                $whatsappConfig->forceFill([
                    'contact_history_sync_status' => WhatsAppConfig::SYNC_COMPLETED,
                    'contact_history_sync_error' => null,
                ])->save();
            }
        } catch (\Throwable $e) {
            Log::error('history: error importando mensajes', [
                'whatsapp_config_id' => $whatsappConfig->id,
                'error' => $e->getMessage(),
            ]);

            $whatsappConfig->forceFill([
                'contact_history_sync_status' => WhatsAppConfig::SYNC_FAILED,
                'contact_history_sync_error' => $e->getMessage(),
            ])->save();
        }
    }
}
