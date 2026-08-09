"use client"

import { useEffect } from "react"

/**
 * Callback compartido del OAuth de Meta (Facebook Login vía popup).
 *
 * Meta redirige acá con ?code=... (o ?error=...). Le pasamos el resultado a la
 * ventana que abrió el popup y cerramos.
 *
 * El `provider` viaja dentro del `state` con el formato `${provider}:${uuid}`:
 * así un solo redirect URI sirve para varios canales sin agregar query params
 * (Meta exige que el redirect_uri del canje sea idéntico al del diálogo, así que
 * conviene mantenerlo estable) y el `state` sigue cumpliendo su rol anti-CSRF.
 *
 * El callback de Instagram (/instagram/callback) sigue existiendo aparte: está
 * registrado en el dashboard de Meta y funcionando, y un redirect URI mal
 * registrado rompe el onboarding con el error 36008.
 *
 * Nota: Meta puede appendear `#_` al final de la URL — es un fragment, no afecta
 * los query params.
 */
export default function MetaCallbackPage() {
  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const state = params.get("state")
    const provider = state?.includes(":") ? state.split(":")[0] : null

    if (window.opener) {
      window.opener.postMessage(
        {
          type: "meta-auth",
          provider,
          code: params.get("code"),
          error: params.get("error"),
          state,
        },
        window.location.origin,
      )
    }

    window.close()
  }, [])

  return (
    <div className="flex min-h-screen items-center justify-center">
      <p className="text-sm text-muted-foreground">
        Conectando con Meta… podés cerrar esta ventana.
      </p>
    </div>
  )
}
