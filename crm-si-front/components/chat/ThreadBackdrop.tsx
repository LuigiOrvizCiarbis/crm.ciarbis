"use client"

/**
 * Textura del hilo de mensajes.
 *
 * Deliberadamente NO es el patrón de garabatos de WhatsApp: la bandeja es
 * omnicanal y un fondo que grita "WhatsApp" desorienta en una conversación de
 * Instagram o Mail. Es una retícula de puntos neutra, del CRM, con el tinte
 * azulado de los tokens de marca.
 *
 * Dos capas de puntos a distinta escala se desplazan contra el scroll a
 * velocidades apenas distintas (parálax). El recorrido total son ~10px: se
 * percibe como profundidad, no como movimiento. Arriba del hilo la retícula
 * está densa y presente; al bajar hacia los mensajes recientes se abre y
 * se apaga, dejando la superficie limpia donde ocurre la conversación.
 *
 * Todo por CSS scroll-driven animations: no toca el hilo principal y degrada
 * a un fondo estático donde no hay soporte. La animación vive en globals.css
 * (`.thread-depth`); acá sólo la geometría de cada capa.
 */
export function ThreadBackdrop() {
  return (
    <div aria-hidden="true" className="pointer-events-none absolute inset-0 z-0 overflow-hidden">
      {/* Capa lejana: puntos chicos y juntos, recorre menos. */}
      <div
        className="thread-depth absolute inset-[-6%]"
        style={
          {
            backgroundImage: "radial-gradient(currentColor 1px, transparent 1px)",
            backgroundSize: "18px 18px",
            color: "var(--muted-foreground)",
            maskImage: "linear-gradient(to bottom, black 0%, black 30%, transparent 80%)",
            WebkitMaskImage: "linear-gradient(to bottom, black 0%, black 30%, transparent 80%)",
            "--thread-depth-from": "0.08",
            "--thread-depth-to": "0.03",
          } as React.CSSProperties
        }
      />

      {/* Capa cercana: puntos más separados y marcados, recorre más. */}
      <div
        className="thread-depth thread-depth--near absolute inset-[-8%]"
        style={
          {
            backgroundImage:
              "radial-gradient(currentColor 1.2px, transparent 1.2px), radial-gradient(currentColor 1.2px, transparent 1.2px)",
            backgroundSize: "34px 34px",
            backgroundPosition: "0 0, 17px 17px",
            color: "var(--muted-foreground)",
            maskImage: "radial-gradient(ellipse 130% 95% at 50% 0%, black 35%, transparent 100%)",
            WebkitMaskImage: "radial-gradient(ellipse 130% 95% at 50% 0%, black 35%, transparent 100%)",
            "--thread-depth-from": "0.16",
            "--thread-depth-to": "0.06",
          } as React.CSSProperties
        }
      />
    </div>
  )
}
