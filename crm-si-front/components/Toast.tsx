"use client"

import { useState, useEffect, useCallback } from "react"
import { X, CheckCircle, AlertCircle, Info, Loader2 } from "lucide-react"
import { cn } from "@/lib/utils"

export interface Toast {
  id: string
  type: "success" | "error" | "info" | "loading"
  title: string
  description?: string
  duration?: number
  /** Hace clickeable el cuerpo del toast (ej. abrir la notificación). */
  onClick?: () => void
}

interface ToastProps {
  toast: Toast
  onRemove: (id: string) => void
}

function ToastComponent({ toast, onRemove }: ToastProps) {
  useEffect(() => {
    if (toast.type === "loading") return
    const timer = setTimeout(() => {
      onRemove(toast.id)
    }, toast.duration || 5000)

    return () => clearTimeout(timer)
  }, [toast.id, toast.duration, toast.type, onRemove])

  const icons = {
    success: CheckCircle,
    error: AlertCircle,
    info: Info,
    loading: Loader2,
  }

  const Icon = icons[toast.type]

  return (
    <div
      className={cn(
        "flex items-start gap-3 p-4 rounded-2xl border backdrop-blur-sm animate-in slide-in-from-right-full",
        toast.type === "success" &&
          "bg-green-50/90 border-green-200 text-green-800 dark:bg-green-950/90 dark:border-green-800 dark:text-green-200",
        toast.type === "error" &&
          "bg-red-50/90 border-red-200 text-red-800 dark:bg-red-950/90 dark:border-red-800 dark:text-red-200",
        toast.type === "info" &&
          "bg-blue-50/90 border-blue-200 text-blue-800 dark:bg-blue-950/90 dark:border-blue-800 dark:text-blue-200",
        toast.type === "loading" &&
          "bg-amber-50/90 border-amber-200 text-amber-800 dark:bg-amber-950/90 dark:border-amber-800 dark:text-amber-200",
      )}
    >
      <Icon className={cn("w-5 h-5 mt-0.5 shrink-0", toast.type === "loading" && "animate-spin")} />
      <div
        className={cn("flex-1 min-w-0", toast.onClick && "cursor-pointer")}
        role={toast.onClick ? "button" : undefined}
        tabIndex={toast.onClick ? 0 : undefined}
        onClick={toast.onClick ? () => { toast.onClick?.(); onRemove(toast.id) } : undefined}
        onKeyDown={toast.onClick ? (event) => {
          if (event.key === "Enter" || event.key === " ") { toast.onClick?.(); onRemove(toast.id) }
        } : undefined}
      >
        <p className="font-medium text-sm">{toast.title}</p>
        {toast.description && <p className="text-sm opacity-90 mt-1 line-clamp-2">{toast.description}</p>}
      </div>
      {toast.type !== "loading" && (
        <button
          onClick={() => onRemove(toast.id)}
          className="shrink-0 p-1 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
        >
          <X className="w-4 h-4" />
        </button>
      )}
    </div>
  )
}

let toastCounter = 0
let toastStore: Toast[] = []
const toastListeners = new Set<(toasts: Toast[]) => void>()

function emitToastChange() {
  toastListeners.forEach((listener) => listener(toastStore))
}

export function useToast() {
  const [toasts, setToasts] = useState<Toast[]>(toastStore)

  useEffect(() => {
    const listener = (nextToasts: Toast[]) => {
      setToasts(nextToasts)
    }

    toastListeners.add(listener)
    setToasts(toastStore)

    return () => {
      toastListeners.delete(listener)
    }
  }, [])

  const addToast = useCallback((toast: Omit<Toast, "id"> & { id?: string }) => {
    const id = toast.id || `toast-${++toastCounter}`
    toastStore = [...toastStore, { ...toast, id }]
    emitToastChange()
    return id
  }, [])

  const removeToast = useCallback((id: string) => {
    toastStore = toastStore.filter((toast) => toast.id !== id)
    emitToastChange()
  }, [])

  const ToastContainer = () => (
    <div className="fixed top-4 right-4 z-50 flex flex-col gap-2 max-w-sm">
      {toasts.map((toast) => (
        <ToastComponent key={toast.id} toast={toast} onRemove={removeToast} />
      ))}
    </div>
  )

  return { addToast, removeToast, ToastContainer }
}
