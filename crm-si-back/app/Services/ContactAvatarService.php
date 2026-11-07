<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Foto de perfil de los contactos.
 *
 * Dos orígenes: la que sube un agente (`manual`) y la que entrega el canal
 * (Messenger/Instagram). La manual siempre gana: el canal nunca la pisa.
 *
 * WhatsApp Cloud API no expone la foto del cliente (sólo la del negocio), así
 * que para esos contactos el único origen posible es el manual.
 *
 * Todas las escrituras usan saveQuietly(): la foto no es un dato sobre el que
 * actúe una automatización ni una extracción de documentos, así que no debe
 * disparar `contact.field_changed` ni avanzar lock_version (lo que haría
 * fallar con 409 una extracción pendiente sin que nadie tocara sus campos).
 */
class ContactAvatarService
{
    public const SOURCE_MANUAL = 'manual';

    private const DISK = 'public';

    private const MAX_DOWNLOAD_BYTES = 5 * 1024 * 1024;

    /** Cada cuánto se vuelve a consultar la foto del canal (el contacto puede cambiarla). */
    private const CHANNEL_REFRESH_DAYS = 7;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function isChannelRefreshDue(Contact $contact): bool
    {
        if ($contact->avatar_source === self::SOURCE_MANUAL) {
            return false;
        }

        return $contact->avatar_checked_at === null
            || $contact->avatar_checked_at->lt(now()->subDays(self::CHANNEL_REFRESH_DAYS));
    }

    /**
     * Aplica la foto que devolvió el perfil del canal.
     *
     * `$pictureUrl` null significa que el perfil respondió sin foto (la quitó o
     * es privada): se borra la del canal que hubiera. Si la descarga falla se
     * conserva la anterior y se reintenta en el próximo refresco.
     */
    public function syncFromChannel(Contact $contact, ?string $pictureUrl, string $source): void
    {
        if ($contact->avatar_source === self::SOURCE_MANUAL) {
            return;
        }

        if ($pictureUrl === null || $pictureUrl === '') {
            $this->persist($contact, null, null, now());

            return;
        }

        $path = $this->download($pictureUrl, $contact);
        if ($path === null) {
            $this->markChannelChecked($contact);

            return;
        }

        $this->persist($contact, $path, $source, now());
    }

    /**
     * Registra un intento fallido contra el canal (token inválido, perfil
     * restringido) para no reintentar en cada mensaje entrante.
     */
    public function markChannelChecked(Contact $contact): void
    {
        $contact->forceFill(['avatar_checked_at' => now()])->saveQuietly();
    }

    public function storeUpload(Contact $contact, UploadedFile $file): void
    {
        $path = $file->store($this->directory($contact), self::DISK);

        $this->persist($contact, $path, self::SOURCE_MANUAL, $contact->avatar_checked_at);
    }

    /**
     * Quita la foto manual. `avatar_checked_at` vuelve a null para que el
     * próximo mensaje entrante traiga de nuevo la del canal, si la hay.
     */
    public function removeManual(Contact $contact): void
    {
        if ($contact->avatar_source !== self::SOURCE_MANUAL) {
            return;
        }

        $this->persist($contact, null, null, null);
    }

    public function deleteFile(Contact $contact): void
    {
        if ($contact->avatar_path !== null) {
            Storage::disk(self::DISK)->delete($contact->avatar_path);
        }
    }

    private function persist(Contact $contact, ?string $path, ?string $source, ?Carbon $checkedAt): void
    {
        $previousPath = $contact->avatar_path;

        $contact->forceFill([
            'avatar_path' => $path,
            'avatar_source' => $source,
            'avatar_checked_at' => $checkedAt,
        ])->saveQuietly();

        if ($previousPath !== null && $previousPath !== $path) {
            Storage::disk(self::DISK)->delete($previousPath);
        }
    }

    private function download(string $url, Contact $contact): ?string
    {
        try {
            $response = Http::timeout(10)->get($url);
        } catch (\Throwable $e) {
            Log::info('Avatar de contacto: no se pudo descargar la foto', [
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $mimeType = trim(explode(';', (string) $response->header('Content-Type'))[0]);
        $extension = self::EXTENSIONS[$mimeType] ?? null;
        $body = $response->body();

        if (! $response->successful() || $extension === null || $body === '' || strlen($body) > self::MAX_DOWNLOAD_BYTES) {
            Log::info('Avatar de contacto: respuesta de foto descartada', [
                'contact_id' => $contact->id,
                'status' => $response->status(),
                'content_type' => $mimeType,
                'bytes' => strlen($body),
            ]);

            return null;
        }

        // Nombre aleatorio: la URL cambia con cada foto nueva y el navegador no
        // muestra la anterior desde su caché.
        $path = $this->directory($contact).'/'.$contact->id.'-'.Str::random(12).'.'.$extension;
        Storage::disk(self::DISK)->put($path, $body);

        return $path;
    }

    private function directory(Contact $contact): string
    {
        return 'contact-avatars/'.$contact->tenant_id;
    }
}
