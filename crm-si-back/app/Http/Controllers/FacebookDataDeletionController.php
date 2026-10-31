<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FacebookDataDeletionController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $signedRequest = $request->input('signed_request');

        if (! $signedRequest) {
            return response()->json(['error' => 'Missing signed_request'], 400);
        }

        $data = $this->parseSignedRequest($signedRequest);

        if (! $data) {
            return response()->json(['error' => 'Invalid signed_request'], 400);
        }

        $userId = $data['user_id'] ?? null;

        if (! $userId) {
            return response()->json(['error' => 'Missing user_id'], 400);
        }

        $confirmationCode = $this->processDataDeletion($userId);

        $statusUrl = config('app.frontend_url', 'https://socialimpulse.com').'/data-deletion?code='.$confirmationCode;

        return response()->json([
            'url' => $statusUrl,
            'confirmation_code' => $confirmationCode,
        ]);
    }

    /**
     * Parse and verify the signed_request from Meta.
     *
     * @return array<string, mixed>|null
     */
    private function parseSignedRequest(string $signedRequest): ?array
    {
        $appSecret = config('services.facebook.app_secret');

        $parts = explode('.', $signedRequest, 2);

        if (count($parts) !== 2) {
            Log::warning('Facebook data deletion: malformed signed_request');

            return null;
        }

        [$encodedSig, $payload] = $parts;

        $sig = $this->base64UrlDecode($encodedSig);
        $data = json_decode($this->base64UrlDecode($payload), true);

        if (! $data) {
            Log::warning('Facebook data deletion: could not decode payload');

            return null;
        }

        $expectedSig = hash_hmac('sha256', $payload, $appSecret, true);

        if (! hash_equals($expectedSig, $sig)) {
            Log::warning('Facebook data deletion: signature verification failed');

            return null;
        }

        return $data;
    }

    private function base64UrlDecode(string $input): string
    {
        return base64_decode(strtr($input, '-_', '+/'));
    }

    /**
     * Procesa el borrado de datos de un usuario de Facebook y devuelve el código
     * de confirmación.
     *
     * IMPORTANTE — el id que manda Meta acá es un ASID (app-scoped user id), NO
     * el PSID (page-scoped) que guardamos en contacts.external_id. La doc del
     * Messenger Platform lo dice explícitamente: "user ID's from Facebook Login
     * integrations are app-scoped and will not work with the Messenger
     * platform". No existe forma de traducir ASID → PSID por API.
     *
     * Consecuencia: el borrado por external_id casi nunca va a encontrar al
     * contacto. Igual dejamos el intento (por si el id coincide en algún flujo
     * de Facebook Login) y logueamos el caso sin match, que es lo auditable.
     *
     * Se corre sin usuario autenticado (endpoint público), así que los global
     * scopes de tenant no aplican: hay que ser explícito en cada query.
     */
    private function processDataDeletion(string $facebookUserId): string
    {
        $confirmationCode = 'del_'.bin2hex(random_bytes(16));

        Log::info('Facebook data deletion request', [
            'facebook_user_id' => $facebookUserId,
            'confirmation_code' => $confirmationCode,
        ]);

        // Match exacto y acotado a las fuentes de Meta. El external_id de un
        // contacto de otra fuente (o de otro proveedor) no se toca.
        $contacts = Contact::withoutGlobalScopes()
            ->where('external_id', $facebookUserId)
            ->whereIn('source', ['facebook', 'instagram'])
            ->get();

        if ($contacts->isEmpty()) {
            // Caso esperado por el desfasaje ASID/PSID descripto arriba.
            Log::warning('Facebook data deletion: sin contactos para el id recibido', [
                'facebook_user_id' => $facebookUserId,
                'confirmation_code' => $confirmationCode,
            ]);

            return $confirmationCode;
        }

        $conversationIds = Conversation::withoutGlobalScopes()
            ->whereIn('contact_id', $contacts->pluck('id'))
            ->pluck('id');

        // Orden obligatorio: mensajes ANTES que conversaciones. La FK
        // messages.conversation_id es onDelete('set null'), así que borrar la
        // conversación primero dejaría los mensajes huérfanos en vez de
        // eliminarlos — justo lo contrario de lo que pide un data deletion.
        //
        // forceDelete porque Message usa SoftDeletes: un delete() normal dejaría
        // el contenido en la base con deleted_at.
        //
        // Antes era `Message::where('external_id', 'LIKE', "%{$id}%")`: el
        // external_id de un mensaje es el mid de Meta, no el id del usuario, así
        // que ese LIKE no borraba los mensajes correctos y además podía matchear
        // por substring mids de otros contactos y de otros tenants.
        $deletedMessages = Message::withoutGlobalScopes()
            ->whereIn('conversation_id', $conversationIds)
            ->forceDelete();

        // Conversation y Contact no usan SoftDeletes: delete() ya es definitivo.
        Conversation::withoutGlobalScopes()->whereIn('id', $conversationIds)->delete();

        $deletedContacts = Contact::withoutGlobalScopes()
            ->whereIn('id', $contacts->pluck('id'))
            ->delete();

        Log::info('Facebook data deletion completed', [
            'facebook_user_id' => $facebookUserId,
            'confirmation_code' => $confirmationCode,
            'contacts_deleted' => $deletedContacts,
            'conversations_deleted' => $conversationIds->count(),
            'messages_deleted' => $deletedMessages,
        ]);

        return $confirmationCode;
    }
}
