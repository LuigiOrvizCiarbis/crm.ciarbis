"use client"

import { useCallback } from "react"
import { useToast } from "@/components/Toast"
import { useTaskStore } from "@/store/useTaskStore"

/**
 * Saca la tarea de la vista al instante y la borra en el back recién cuando el
 * toast vence sin "Deshacer" (o al salir de la página).
 */
export function useDeleteTaskWithUndo() {
  const deleteTask = useTaskStore((state) => state.deleteTask)
  const undoDelete = useTaskStore((state) => state.undoDelete)
  const commitDelete = useTaskStore((state) => state.commitDelete)
  const { addToast, removeToast } = useToast()

  return useCallback(
    (taskId: string) => {
      const toastId = `task-delete-${taskId}`
      deleteTask(taskId).then(
        () => removeToast(toastId),
        (error) => {
          removeToast(toastId)
          addToast({ type: "error", title: error instanceof Error ? error.message : "No se pudo eliminar la tarea" })
        },
      )
      addToast({
        id: toastId,
        type: "success",
        title: "Tarea eliminada",
        duration: 6000,
        action: { label: "Deshacer", onClick: () => undoDelete(taskId) },
        onExpire: () => void commitDelete(taskId),
      })
    },
    [addToast, commitDelete, deleteTask, removeToast, undoDelete],
  )
}
