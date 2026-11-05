<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\ContactAvatarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Foto manual del contacto. Es la única vía para contactos de WhatsApp: la
 * Cloud API no expone la foto del cliente. Una foto manual tiene prioridad
 * sobre la que trae Messenger/Instagram.
 */
class ContactAvatarController extends Controller
{
    public function __construct(
        private ContactAvatarService $avatars,
    ) {}

    public function store(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize('update', $contact);

        // Mismas reglas que el avatar de usuario (ProfileController::uploadAvatar).
        $validated = $request->validate([
            'avatar' => [
                'required',
                'image',
                'mimes:jpeg,png,webp',
                'max:2048',
                'dimensions:max_width=4000,max_height=4000',
            ],
        ]);

        $this->avatars->storeUpload($contact, $validated['avatar']);

        return response()->json(['data' => $this->payload($contact)]);
    }

    public function destroy(Contact $contact): JsonResponse
    {
        $this->authorize('update', $contact);

        $this->avatars->removeManual($contact);

        return response()->json(['data' => $this->payload($contact)]);
    }

    /**
     * @return array{avatar_url: string|null, avatar_source: string|null}
     */
    private function payload(Contact $contact): array
    {
        return [
            'avatar_url' => $contact->avatar_url,
            'avatar_source' => $contact->avatar_source,
        ];
    }
}
