"use client"

import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from "react"
import { X, CheckCircle, AlertCircle, AlertTriangle, Info, Loader2 } from "lucide-react"
import { cn } from "@/lib/utils"
import { useTranslation } from "@/hooks/useTranslation"

export interface Toast {
  id: string
  type: "success" | "error" | "warning" | "info" | "loading"
  title: string
  description?: string
  duration?: number
  /** Hace clickeable el cuerpo del toast (ej. abrir la notificación). */
  onClick?: () => void
  /** Acción secundaria (ej. "Deshacer"); cierra el toast al ejecutarse. */
  action?: { label: string; onClick: () => void }
  /** Se llama una sola vez cuando el toast se cierra sin ejecutar la acción (tiempo, X o clic en el cuerpo). */
  onExpire?: () => void
}

type ToastInput = Omit<Toast, "id"> & { id?: string }

// Los errores quedan más tiempo en pantalla: hay que leerlos y decidir qué hacer.
const DEFAULT_DURATION = 5000
const ERROR_DURATION = 8000

let toastCounter = 0
let toastStore: Toast[] = []
const toastListeners = new Set<() => void>()

function emitToastChange() {
  toastListeners.forEach((listener) => listener())
}

function subscribe(listener: () => void) {
  toastListeners.add(listener)
  return () => {
    toastListeners.delete(listener)
  }
}

function getSnapshot() {
  return toastStore
}

const EMPTY: Toast[] = []
function getServerSnapshot() {
  return EMPTY
}

function addToastToStore(toast: ToastInput) {
  const id = toast.id || `toast-${++toastCounter}`
  const next = { ...toast, id }
  // Reusar un id reemplaza el toast (ej. "cargando" → "listo") en lugar de duplicarlo.
  toastStore = toastStore.some((item) => item.id === id)
    ? toastStore.map((item) => (item.id === id ? next : item))
    : [...toastStore, next]
  emitToastChange()
  return id
}

function removeToastFromStore(id: string) {
  toastStore = toastStore.filter((toast) => toast.id !== id)
  emitToastChange()
}

// Cierre sin acción: solo notifica si el toast seguía vivo, así onExpire corre una única vez.
function expireToast(id: string) {
  const toast = toastStore.find((item) => item.id === id)
  if (!toast) return
  removeToastFromStore(id)
  toast.onExpire?.()
}

export function useToast() {
  const addToast = useCallback((toast: ToastInput) => addToastToStore(toast), [])
  const removeToast = useCallback((id: string) => removeToastFromStore(id), [])
  return { addToast, removeToast }
}

const icons = {
  success: CheckCircle,
  error: AlertCircle,
  warning: AlertTriangle,
  info: Info,
  loading: Loader2,
}

function ToastItem({ toast, dismissLabel }: { toast: Toast; dismissLabel: string }) {
  const [paused, setPaused] = useState(false)
  const remainingRef = useRef(toast.duration || (toast.type === "error" ? ERROR_DURATION : DEFAULT_DURATION))

  // El temporizador se pausa con hover o foco dentro del toast (WCAG 2.2.1) y retoma lo que faltaba.
  useEffect(() => {
    if (toast.type === "loading" || paused) return
    const startedAt = Date.now()
    const timer = setTimeout(() => expireToast(toast.id), remainingRef.current)
    return () => {
      clearTimeout(timer)
      remainingRef.current = Math.max(remainingRef.current - (Date.now() - startedAt), 1000)
    }
  }, [toast.id, toast.type, paused])

  const Icon = icons[toast.type]
  const handleBodyActivate = () => {
    toast.onClick?.()
    expireToast(toast.id)
  }

  return (
    <div
      className={cn(
        "pointer-events-auto flex items-start gap-3 rounded-2xl border p-4 backdrop-blur-sm motion-safe:animate-in motion-safe:slide-in-from-right-full",
        // Tinte /10 mezclado sobre popover: el toast flota sobre contenido y necesita fondo opaco.
        toast.type === "success" &&
          "border-success/30 bg-[color-mix(in_oklab,var(--success)_10%,var(--popover))] text-success",
        toast.type === "error" &&
          "border-destructive/30 bg-[color-mix(in_oklab,var(--destructive)_10%,var(--popover))] text-destructive",
        toast.type === "info" &&
          "border-info/30 bg-[color-mix(in_oklab,var(--info)_10%,var(--popover))] text-info",
        (toast.type === "warning" || toast.type === "loading") &&
          "border-warning/30 bg-[color-mix(in_oklab,var(--warning)_10%,var(--popover))] text-warning",
      )}
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocus={() => setPaused(true)}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setPaused(false)
      }}
    >
      <Icon className={cn("mt-0.5 h-5 w-5 shrink-0", toast.type === "loading" && "animate-spin")} aria-hidden="true" />
      <div
        className={cn("min-w-0 flex-1", toast.onClick && "cursor-pointer rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current")}
        role={toast.onClick ? "button" : undefined}
        tabIndex={toast.onClick ? 0 : undefined}
        onClick={toast.onClick ? handleBodyActivate : undefined}
        onKeyDown={
          toast.onClick
            ? (event) => {
                if (event.key === "Enter" || event.key === " ") {
                  event.preventDefault()
                  handleBodyActivate()
                }
              }
            : undefined
        }
      >
        <p className="text-sm font-medium">{toast.title}</p>
        {toast.description && <p className="mt-1 line-clamp-2 text-sm">{toast.description}</p>}
        {toast.action && (
          <button
            type="button"
            className="mt-2 min-h-8 rounded-md text-sm font-semibold underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current"
            onClick={(event) => {
              event.stopPropagation()
              toast.action?.onClick()
              removeToastFromStore(toast.id)
            }}
          >
            {toast.action.label}
          </button>
        )}
      </div>
      {toast.type !== "loading" && (
        <button
          type="button"
          onClick={() => expireToast(toast.id)}
          aria-label={dismissLabel}
          className="-m-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-colors hover:bg-black/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-current dark:hover:bg-white/5"
        >
          <X className="h-4 w-4" aria-hidden="true" />
        </button>
      )}
    </div>
  )
}

/**
 * Único contenedor de toasts de la app. Las dos regiones viven siempre en el DOM para que
 * los lectores de pantalla anuncien lo que se agrega: errores de forma inmediata (alert),
 * el resto sin interrumpir (status).
 */
export function Toaster() {
  const toasts = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot)
  const { t } = useTranslation()
  const dismissLabel = t("common.dismissNotification")
  const urgent = toasts.filter((toast) => toast.type === "error")
  const polite = toasts.filter((toast) => toast.type !== "error")

  return (
    <section
      aria-label={t("common.notifications")}
      className="pointer-events-none fixed left-4 right-4 top-[max(1rem,env(safe-area-inset-top))] z-50 flex flex-col gap-2 sm:left-auto sm:w-full sm:max-w-sm"
    >
      {/* aria-live explícito: los modales de Radix ocultan (aria-hidden) todo lo de afuera
          salvo los elementos con [aria-live]; sin él, un error dentro de un diálogo no se anuncia. */}
      <div role="alert" aria-live="assertive" className="flex flex-col gap-2">
        {urgent.map((toast) => (
          <ToastItem key={toast.id} toast={toast} dismissLabel={dismissLabel} />
        ))}
      </div>
      <div role="status" aria-live="polite" className="flex flex-col gap-2">
        {polite.map((toast) => (
          <ToastItem key={toast.id} toast={toast} dismissLabel={dismissLabel} />
        ))}
      </div>
    </section>
  )
}
