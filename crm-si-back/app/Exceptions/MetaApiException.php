<?php

namespace App\Exceptions;

use App\Enums\ChannelType;
use RuntimeException;

/**
 * Error de la Graph API de Meta al enviar un mensaje, tipado por caso para que
 * el controller lo mapee a un mensaje accionable sin depender de str_contains
 * sobre el body crudo. Cubre los casos que el usuario puede resolver:
 * token inválido/revocado, ventana de mensajería cerrada, media no soportada
 * y permisos faltantes.
 */
class MetaApiException extends RuntimeException
{
    public const REASON_TOKEN_INVALID = 'token_invalid';

    public const REASON_WINDOW_CLOSED = 'window_closed';

    public const REASON_UNSUPPORTED_MEDIA = 'unsupported_media';

    public const REASON_MISSING_PERMISSION = 'missing_permission';

    /**
     * Error 131009 de reacciones: el mensaje objetivo tiene más de 30 días,
     * fue eliminado, no existe, o es en sí mismo una reacción. Meta no
     * distingue estos casos entre sí, así que el copy cubre el caso general.
     */
    public const REASON_REACTION_TARGET_INVALID = 'reaction_target_invalid';

    public const REASON_UNKNOWN = 'unknown';

    public function __construct(
        public readonly ChannelType $channelType,
        public readonly string $reason,
        // No usar $code/$subcode: chocan con Exception::$code (no readonly).
        public readonly ?int $metaCode = null,
        public readonly ?int $metaSubcode = null,
        string $message = '',
    ) {
        parent::__construct($message);
    }

    /**
     * Construye la excepción a partir del body de error de Graph API,
     * mapeando code/subcode al motivo correspondiente.
     *
     * @param  array<string, mixed>|null  $body
     */
    public static function fromGraphResponse(ChannelType $channelType, ?array $body): self
    {
        $code = data_get($body, 'error.code');
        $subcode = data_get($body, 'error.error_subcode');
        $type = (string) data_get($body, 'error.type', '');
        $rawMessage = (string) data_get($body, 'error.message', '');
        $lowerMessage = strtolower($rawMessage);

        $code = is_numeric($code) ? (int) $code : null;
        $subcode = is_numeric($subcode) ? (int) $subcode : null;

        $reason = match (true) {
            // La ventana cerrada se evalúa ANTES que el genérico de OAuth: Meta
            // puede reportar este caso con code 10/subcode 2534022 y además
            // type=OAuthException. Si el brazo de OAuth ganara, le diríamos al
            // usuario que reconecte en vez de que el contacto vuelva a escribir.
            $code === 10 && $subcode === 2534022 => self::REASON_WINDOW_CLOSED,
            str_contains($lowerMessage, 'outside of allowed window')
                || str_contains($lowerMessage, 'outside the allowed window')
                || str_contains($lowerMessage, '24 hour') => self::REASON_WINDOW_CLOSED,
            $code === 131009 => self::REASON_REACTION_TARGET_INVALID,
            $code === 190 || $type === 'OAuthException' => self::REASON_TOKEN_INVALID,
            str_contains($lowerMessage, 'permission') => self::REASON_MISSING_PERMISSION,
            str_contains($lowerMessage, 'attachment') || str_contains($lowerMessage, 'media')
                || str_contains($lowerMessage, 'unsupported') => self::REASON_UNSUPPORTED_MEDIA,
            default => self::REASON_UNKNOWN,
        };

        return new self($channelType, $reason, $code, $subcode, $rawMessage);
    }
}
