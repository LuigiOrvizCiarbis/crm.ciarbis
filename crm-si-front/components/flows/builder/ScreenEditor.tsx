"use client"

import { useState, type ReactNode } from "react"
import {
  DndContext,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core"
import { SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from "@dnd-kit/sortable"
import { CSS } from "@dnd-kit/utilities"
import { GripVertical } from "lucide-react"

import { Input } from "@/components/ui/input"
import { useDndAccessibility } from "@/hooks/useDndAccessibility"
import { useTranslation } from "@/hooks/useTranslation"
import type { ContactField } from "@/lib/api/contact-fields"
import type { GroupedErrors } from "@/lib/flow-builder/errors"
import { FOOTER_LABEL_MAX, isQuestionBlock } from "@/lib/flow-builder/limits"
import type { FlowBlock, FlowScreen } from "@/lib/flow-builder/types"
import { cn } from "@/lib/utils"

import { AddBlockMenu } from "./AddBlockMenu"
import { BlockEditor } from "./BlockEditor"
import { BuilderField, FieldErrorText } from "./BuilderField"
import { newUid } from "./useFlowBuilderState"

const NO_ERRORS: Record<string, string[]> = {}

interface ScreenEditorProps {
  screen: FlowScreen
  index: number
  /** Si es la última pantalla y no hay turno, su botón cierra el formulario. */
  isLast: boolean
  hasAppointment: boolean
  errors: GroupedErrors
  contactFields: ContactField[]
  /** Clave de cada pregunta del formulario, con el uid de su bloque. */
  questionKeys: Array<{ uid: string | undefined; key: string }>
  onUpdateScreen: (patch: Partial<Pick<FlowScreen, "title" | "footer_label">>) => void
  onAddBlock: (block: FlowBlock) => void
  onRemoveBlock: (index: number) => void
  onMoveBlock: (from: number, to: number) => void
  onUpdateBlock: (index: number, block: FlowBlock) => void
}

export function ScreenEditor({
  screen,
  index,
  isLast,
  hasAppointment,
  errors,
  contactFields,
  questionKeys,
  onUpdateScreen,
  onAddBlock,
  onRemoveBlock,
  onMoveBlock,
  onUpdateBlock,
}: ScreenEditorProps) {
  const { t } = useTranslation()
  const [lastAddedUid, setLastAddedUid] = useState<string | null>(null)
  const screenErrors = (screen.uid && errors.screens[screen.uid]) || NO_ERRORS
  const blockIds = screen.blocks.map((block) => block.uid ?? "")

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )
  const dndAccessibility = useDndAccessibility((id) => {
    const block = screen.blocks.find((item) => item.uid === id)
    if (!block) return undefined
    return isQuestionBlock(block) ? block.label : block.text
  })

  const handleDragEnd = ({ active, over }: DragEndEvent) => {
    if (!over || active.id === over.id) return
    const from = blockIds.indexOf(String(active.id))
    const to = blockIds.indexOf(String(over.id))
    if (from >= 0 && to >= 0) onMoveBlock(from, to)
  }

  const handleAdd = (block: FlowBlock) => {
    const uid = newUid()
    setLastAddedUid(uid)
    onAddBlock({ ...block, uid })
  }

  const footerHint = hasAppointment && isLast
    ? t("flows.builder.screen.footerHintAppointment")
    : isLast
      ? t("flows.builder.screen.footerHintLast")
      : t("flows.builder.screen.footerHintNext")

  return (
    <div className="space-y-6">
      <BuilderField
        id={`screen-${screen.uid}-title`}
        label={t("flows.builder.screen.title", { n: index + 1 })}
        errors={screenErrors.title}
      >
        <Input
          id={`screen-${screen.uid}-title`}
          value={screen.title}
          aria-invalid={!!screenErrors.title}
          onChange={(event) => onUpdateScreen({ title: event.target.value })}
        />
      </BuilderField>

      <section className="space-y-3" aria-label={t("flows.builder.screen.blocks")}>
        <h3 className="text-xs font-medium text-muted-foreground">{t("flows.builder.screen.blocks")}</h3>
        <FieldErrorText errors={[...(screenErrors.blocks ?? []), ...(screenErrors[""] ?? [])]} />
        <DndContext
          sensors={sensors}
          accessibility={dndAccessibility}
          collisionDetection={closestCenter}
          onDragEnd={handleDragEnd}
        >
          <SortableContext items={blockIds} strategy={verticalListSortingStrategy}>
            <ul className="space-y-2">
              {screen.blocks.map((block, blockIndex) => (
                <SortableBlock key={block.uid} uid={block.uid ?? ""}>
                  {(dragHandle) => (
                    <BlockEditor
                      block={block}
                      errors={(block.uid && errors.blocks[block.uid]) || NO_ERRORS}
                      contactFields={contactFields}
                      otherKeys={questionKeys.filter((item) => item.uid !== block.uid).map((item) => item.key)}
                      defaultOpen={block.uid === lastAddedUid}
                      dragHandle={dragHandle}
                      onChange={(next) => onUpdateBlock(blockIndex, next)}
                      onRemove={() => onRemoveBlock(blockIndex)}
                    />
                  )}
                </SortableBlock>
              ))}
            </ul>
          </SortableContext>
        </DndContext>
        <AddBlockMenu existingKeys={questionKeys.map((item) => item.key)} onAdd={handleAdd} />
      </section>

      <BuilderField
        id={`screen-${screen.uid}-footer`}
        label={t("flows.builder.screen.footerLabel")}
        value={screen.footer_label}
        max={FOOTER_LABEL_MAX}
        errors={screenErrors.footer_label}
        hint={footerHint}
      >
        <Input
          id={`screen-${screen.uid}-footer`}
          value={screen.footer_label}
          aria-invalid={!!screenErrors.footer_label}
          onChange={(event) => onUpdateScreen({ footer_label: event.target.value })}
        />
      </BuilderField>
    </div>
  )
}

function SortableBlock({ uid, children }: { uid: string; children: (dragHandle: ReactNode) => ReactNode }) {
  const { t } = useTranslation()
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: uid })

  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(isDragging && "relative z-10 opacity-80 shadow-md")}
    >
      {children(
        <button
          type="button"
          className="cursor-grab text-muted-foreground/50 transition-colors hover:text-muted-foreground active:cursor-grabbing"
          aria-label={t("flows.builder.block.reorder")}
          {...attributes}
          {...listeners}
        >
          <GripVertical className="size-4" />
        </button>,
      )}
    </li>
  )
}
