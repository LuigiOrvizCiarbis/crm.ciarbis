"use client"

import { createContext, useContext } from "react"

import type { RoutePort } from "@/lib/flow-builder/routing"

/** Acciones que disparan las cajas y flechas del diagrama; las resuelve el constructor. */
export interface CanvasActions {
  hasAppointment: boolean
  canAddScreen: boolean
  requestRemoveScreen: (uid: string) => void
  confirmRemoveScreen: (uid: string) => void
  cancelRemoveScreen: () => void
  /** Agrega una pantalla en medio del camino que sale de `from` por `port`. */
  insertScreen: (from: number, port: RoutePort) => void
  addAppointment: () => void
  /** Quita la flecha: el botón final pasa al fin y una opción, al destino del botón. */
  clearRoute: (from: number, port: RoutePort) => void
}

export const CanvasActionsContext = createContext<CanvasActions | null>(null)

export function useCanvasActions(): CanvasActions {
  const actions = useContext(CanvasActionsContext)
  if (!actions) throw new Error("useCanvasActions fuera del diagrama")
  return actions
}
