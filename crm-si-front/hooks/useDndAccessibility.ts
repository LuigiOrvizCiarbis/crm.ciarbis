"use client"

import { useMemo, useRef } from "react"
import type { Announcements, ScreenReaderInstructions, UniqueIdentifier } from "@dnd-kit/core"
import { useTranslation } from "@/hooks/useTranslation"

type Labeler = (id: UniqueIdentifier) => string | undefined

/**
 * Textos localizados para el lector de pantalla de dnd-kit (por defecto vienen en inglés
 * y nombran los elementos por id). `getItemLabel` nombra lo que se arrastra;
 * `getTargetLabel` nombra dónde cae (columna, etapa o el elemento sobre el que pasa).
 */
export function useDndAccessibility(getItemLabel: Labeler, getTargetLabel: Labeler = getItemLabel) {
  const { t } = useTranslation()

  // Ref (no variable del memo): los labelers suelen ser inline y el memo se recrea durante el arrastre.
  const leftOriginRef = useRef(false)

  return useMemo(() => {
    const item = (id: UniqueIdentifier) => getItemLabel(id) || t("dnd.fallbackItem")
    const target = (id: UniqueIdentifier) => getTargetLabel(id) || t("dnd.fallbackTarget")

    const announcements: Announcements = {
      onDragStart: ({ active }) => {
        leftOriginRef.current = false
        return t("dnd.pickedUp", { item: item(active.id) })
      },
      onDragOver: ({ active, over }) => {
        // Al tomarlo, dnd-kit emite "over" sobre sí mismo: no pisar "Tomaste…".
        if (over?.id === active.id) {
          return leftOriginRef.current ? t("dnd.backToOrigin", { item: item(active.id) }) : undefined
        }
        leftOriginRef.current = true
        return over
          ? t("dnd.movedOver", { item: item(active.id), target: target(over.id) })
          : t("dnd.notOverTarget", { item: item(active.id) })
      },
      onDragEnd: ({ active, over }) => {
        if (over?.id === active.id) return t("dnd.droppedInPlace", { item: item(active.id) })
        return over
          ? t("dnd.dropped", { item: item(active.id), target: target(over.id) })
          : t("dnd.droppedOutside", { item: item(active.id) })
      },
      onDragCancel: ({ active }) => t("dnd.cancelled", { item: item(active.id) }),
    }
    const screenReaderInstructions: ScreenReaderInstructions = { draggable: t("dnd.instructions") }

    return { announcements, screenReaderInstructions }
  }, [t, getItemLabel, getTargetLabel])
}
