"use client"

import { useLayoutEffect } from "react"

/**
 * Enciende el piloto de identidad BIMS mientras la pantalla que lo monta está abierta. La clase va
 * en <html> para que también la tomen el sidebar y los portales (diálogos, popovers, toasts).
 * Se borra cuando la identidad se aprueba y sus tokens pasan a ser los globales.
 */
export function BrandPilot() {
  useLayoutEffect(() => {
    const root = document.documentElement
    root.classList.add("brand-bims")
    return () => root.classList.remove("brand-bims")
  }, [])

  return null
}
