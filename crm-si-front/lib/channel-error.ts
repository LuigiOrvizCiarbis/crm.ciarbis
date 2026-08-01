/**
 * Contrato del evento `channel-error` que escucha app/chats/page.tsx.
 *
 * - `code`: clave dentro de `chats.*` en los locales; el front la traduce al
 *   idioma activo. Usar para errores generados en el front o en los proxies.
 * - `message`: texto ya redactado por el backend; se muestra tal cual.
 */
export interface ChannelErrorDetail {
  code?: string;
  message?: string;
}

/**
 * Error de conexión de canal: transporta el detail que termina en el toast.
 * El `message` de Error queda para logs/Sentry; la UI usa `detail`.
 */
export class ChannelError extends Error {
  constructor(public readonly detail: ChannelErrorDetail) {
    super(detail.message ?? detail.code ?? "channel-error");
    this.name = "ChannelError";
  }
}
