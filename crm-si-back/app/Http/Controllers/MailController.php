<?php

namespace App\Http\Controllers;

use App\Enums\ChannelType;
use App\Http\Requests\MailChannelStoreRequest;
use App\Jobs\SyncMailChannelJob;
use App\Models\Channel;
use App\Models\MailConfig;
use App\Services\MailMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MailController extends Controller
{
    public function __construct(
        private MailMessageService $messageService
    ) {}

    /**
     * Conecta una casilla IMAP/SMTP como canal MAIL.
     *
     * A diferencia de WhatsApp/Instagram no hay OAuth: las credenciales las
     * escribe el usuario, así que las probamos contra el servidor antes de
     * persistir nada. Si el login falla, no se crea ni config ni canal.
     */
    public function handleAuth(MailChannelStoreRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no autenticado.',
            ], 401);
        }

        $this->authorize('connectMail', Channel::class);

        $emailAddress = strtolower(trim($request->email_address));

        try {
            // Una casilla = un canal dentro del tenant. Sin esto, dos conexiones
            // de la misma casilla competirían por el mismo cursor UID.
            $alreadyConnected = MailConfig::where('tenant_id', $user->tenant_id)
                ->where('email_address', $emailAddress)
                ->exists();

            if ($alreadyConnected) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta casilla ya está conectada como canal.',
                ], 409);
            }

            // Config en memoria (sin persistir) sólo para probar la conexión.
            $probe = new MailConfig([
                'tenant_id' => $user->tenant_id,
                'email_address' => $emailAddress,
                'imap_host' => $request->imap_host,
                'imap_port' => $request->imap_port,
                'imap_encryption' => $request->imap_encryption,
                'smtp_host' => $request->smtp_host,
                'smtp_port' => $request->smtp_port,
                'smtp_encryption' => $request->smtp_encryption,
            ]);

            $this->messageService->assertImapCredentials($probe, $request->password);
            $this->messageService->assertSmtpCredentials($probe, $request->password);

            $channel = DB::transaction(function () use ($request, $user, $emailAddress) {
                $config = MailConfig::create([
                    'tenant_id' => $user->tenant_id,
                    'email_address' => $emailAddress,
                    'from_name' => $request->input('from_name') ?: null,
                    'imap_host' => $request->imap_host,
                    'imap_port' => $request->imap_port,
                    'imap_encryption' => $request->imap_encryption,
                    'smtp_host' => $request->smtp_host,
                    'smtp_port' => $request->smtp_port,
                    'smtp_encryption' => $request->smtp_encryption,
                    'password' => Crypt::encryptString($request->password),
                ]);

                return Channel::create([
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'mail_config_id' => $config->id,
                    'type' => ChannelType::MAIL,
                    'external_id' => $emailAddress,
                    'name' => $request->input('name') ?: $emailAddress,
                    'status' => 'active',
                ]);
            });

            // Primera sincronización inmediata: el usuario ve su bandeja sin
            // esperar al tick del scheduler.
            SyncMailChannelJob::dispatch($channel->mail_config_id);

            return response()->json([
                'success' => true,
                'message' => 'Casilla de email conectada exitosamente.',
                'data' => $channel->load('mailConfig'),
            ], 200);

        } catch (\InvalidArgumentException $e) {
            // Credenciales o host inválidos: el mensaje ya viene redactado para
            // el usuario desde el servicio.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Mail handleAuth: error interno', [
                'error' => $e->getMessage(),
                'tenant_id' => $user->tenant_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno al conectar la casilla.',
            ], 500);
        }
    }

    /**
     * Fuerza una sincronización manual de la casilla del canal.
     */
    public function sync(int $id): JsonResponse
    {
        $channel = Channel::findOrFail($id);
        $this->authorize('update', $channel);

        if ($channel->type !== ChannelType::MAIL || ! $channel->mail_config_id) {
            return response()->json([
                'success' => false,
                'message' => 'El canal no es de tipo email.',
            ], 422);
        }

        SyncMailChannelJob::dispatch($channel->mail_config_id);

        return response()->json([
            'success' => true,
            'message' => 'Sincronización encolada.',
        ]);
    }
}
