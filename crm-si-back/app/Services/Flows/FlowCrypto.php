<?php

namespace App\Services\Flows;

use JsonException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use Throwable;

/**
 * Cifrado del endpoint de datos de WhatsApp Flows.
 *
 * La llave AES llega cifrada con RSA-OAEP (SHA-256 para hash y MGF1); el
 * payload, con AES-128-GCM y el tag de 16 bytes al final. La respuesta se
 * cifra con la misma llave y el IV invertido bit a bit.
 */
class FlowCrypto
{
    private const CIPHER = 'aes-128-gcm';

    private const TAG_LENGTH = 16;

    public function decryptRequest(array $body, string $privateKeyPem): DecryptedFlowRequest
    {
        $encryptedData = $this->decodeField($body, 'encrypted_flow_data');
        $encryptedKey = $this->decodeField($body, 'encrypted_aes_key');
        $iv = $this->decodeField($body, 'initial_vector');

        $aesKey = $this->decryptAesKey($encryptedKey, $privateKeyPem);

        if (strlen($encryptedData) <= self::TAG_LENGTH) {
            throw new FlowDecryptionException('Payload cifrado demasiado corto.');
        }

        $tag = substr($encryptedData, -self::TAG_LENGTH);
        $cipherText = substr($encryptedData, 0, -self::TAG_LENGTH);
        $plain = openssl_decrypt($cipherText, self::CIPHER, $aesKey, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plain === false) {
            throw new FlowDecryptionException('No se pudo descifrar el payload.');
        }

        try {
            $payload = json_decode($plain, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new FlowDecryptionException('El payload descifrado no es JSON válido.', previous: $e);
        }

        if (! is_array($payload)) {
            throw new FlowDecryptionException('El payload descifrado no es un objeto.');
        }

        return new DecryptedFlowRequest($payload, $aesKey, $iv);
    }

    public function encryptResponse(array $payload, string $aesKey, string $iv): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tag = '';
        $cipherText = openssl_encrypt($json, self::CIPHER, $aesKey, OPENSSL_RAW_DATA, ~$iv, $tag, '', self::TAG_LENGTH);

        return base64_encode($cipherText.$tag);
    }

    private function decodeField(array $body, string $field): string
    {
        $value = $body[$field] ?? null;
        $decoded = is_string($value) ? base64_decode($value, true) : false;

        if ($decoded === false || $decoded === '') {
            throw new FlowDecryptionException("Falta o es inválido el campo {$field}.");
        }

        return $decoded;
    }

    private function decryptAesKey(string $encryptedKey, string $privateKeyPem): string
    {
        try {
            /** @var RSA\PrivateKey $key */
            $key = PublicKeyLoader::loadPrivateKey($privateKeyPem);

            $aesKey = $key->withPadding(RSA::ENCRYPTION_OAEP)
                ->withHash('sha256')
                ->withMGFHash('sha256')
                ->decrypt($encryptedKey);
        } catch (Throwable $e) {
            throw new FlowDecryptionException('No se pudo descifrar la llave AES.', previous: $e);
        }

        if (! is_string($aesKey) || $aesKey === '') {
            throw new FlowDecryptionException('No se pudo descifrar la llave AES.');
        }

        return $aesKey;
    }
}
