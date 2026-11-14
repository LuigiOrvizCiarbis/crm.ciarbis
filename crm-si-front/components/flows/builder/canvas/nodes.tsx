"use client"

import { memo, useEffect } from "react"
import { Handle, Position, useConnection, useUpdateNodeInternals, type NodeProps } from "@xyflow/react"
import { CalendarClock, Flag, Play, TriangleAlert } from "lucide-react"

import { Button } from "@/components/ui/button"
import { useTranslation } from "@/hooks/useTranslation"
import { SCREEN_NODE_WIDTH, APPOINTMENT_NODE_WIDTH, questionCount } from "@/lib/flow-builder/layout"
import { isQuestionBlock } from "@/lib/flow-builder/limits"
import { cn } from "@/lib/utils"

import { BlockTypeIcon } from "../BlockTypeIcon"
import { blockSummary } from "../BlockEditor"
import { useCanvasActions } from "./CanvasContext"
import {
  DROP_HANDLE,
  IN_HANDLE,
  OUT_HANDLE,
  type AppointmentNodeType,
  type ScreenNodeType,
  type TerminalNodeType,
} from "./graph"

const MAX_SUMMARY_ROWS = 5

/** Estado de la caja mientras se arrastra una conexión hacia ella. */
function useDropState(id: string): { connecting: boolean; hovered: "valid" | "invalid" | null } {
  return useConnection((connection) => {
    if (!connection.inProgress || connection.fromNode.id === id) return { connecting: false, hovered: null }
    const hovered = connection.toNode?.id === id ? (connection.isValid ? "valid" : "invalid") : null
    return { connecting: true, hovered }
  })
}

function DropTarget({ id, connecting }: { id: string; connecting: boolean }) {
  return (
    <>
      <Handle id={IN_HANDLE} type="target" position={Position.Left} className="flow-port" isConnectableStart={false} />
      <Handle
        id={DROP_HANDLE}
        type="target"
        position={Position.Left}
        className="flow-drop"
        isConnectableStart={false}
        style={{ pointerEvents: connecting ? "all" : "none" }}
        aria-hidden
        data-node={id}
      />
    </>
  )
}

function ScreenNodeComponent({ id, data, selected }: NodeProps<ScreenNodeType>) {
  const { t } = useTranslation()
  const actions = useCanvasActions()
  const { connecting, hovered } = useDropState(id)
  const { screen, index, ports, errorCount, unreachable, pendingRemoval } = data
  const visibleBlocks = screen.blocks.slice(0, MAX_SUMMARY_ROWS)
  const hiddenBlocks = screen.blocks.length - visibleBlocks.length
  const branching = ports.some((port) => port.option)
  const questions = questionCount(screen)

  // Los ids de las salidas siguen al título de cada opción: xyflow sólo vuelve a medir los handles
  // si la caja cambia de tamaño, así que se le avisa cuando cambian (si no, la flecha se pierde).
  const updateNodeInternals = useUpdateNodeInternals()
  const portKey = ports.map((port) => port.port).join("\u0000")
  useEffect(() => {
    updateNodeInternals(id)
  }, [id, portKey, updateNodeInternals])

  return (
    <div
      style={{ width: SCREEN_NODE_WIDTH }}
      className={cn(
        "relative rounded-lg border bg-card text-card-foreground shadow-sm transition-[border-color,box-shadow] duration-150 dark:shadow-none",
        selected ? "border-primary ring-2 ring-primary/30" : "border-border hover:border-muted-foreground/40",
        unreachable && !selected && "border-dashed",
        hovered === "valid" && "border-primary ring-2 ring-primary/30",
        hovered === "invalid" && "border-destructive ring-2 ring-destructive/25",
      )}
    >
      <DropTarget id={id} connecting={connecting} />

      <div className={cn("flex items-center gap-2 px-3 pb-2 pt-2.5", unreachable && "opacity-70")}>
        <span className="flex size-5 shrink-0 items-center justify-center rounded bg-muted font-mono text-[11px] text-muted-foreground">
          {index + 1}
        </span>
        <span className="min-w-0 flex-1 truncate text-sm font-medium" title={screen.title}>
          {screen.title || t("flows.builder.screens.untitled")}
        </span>
        {index === 0 ? <Flag className="size-3.5 shrink-0 text-muted-foreground" aria-label={t("flows.builder.canvas.entry")} /> : null}
        {errorCount > 0 ? (
          <span
            className="flex shrink-0 items-center gap-1 rounded bg-destructive/10 px-1.5 py-0.5 text-[11px] font-medium text-destructive"
            title={t("flows.builder.canvas.errors", { count: errorCount })}
          >
            <TriangleAlert className="size-3" aria-hidden />
            {errorCount}
          </span>
        ) : null}
      </div>

      <ul className={cn("space-y-1 px-3 pb-2.5", unreachable && "opacity-70")}>
        {visibleBlocks.map((block) => (
          <li key={block.uid} className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
            <BlockTypeIcon type={block.type} className="size-3.5 shrink-0" />
            <span className="truncate">
              {blockSummary(block, t) || t(`flows.builder.blockTypes.${block.type}`)}
              {isQuestionBlock(block) && block.required ? <span className="text-foreground/70"> *</span> : null}
            </span>
          </li>
        ))}
        {hiddenBlocks > 0 ? (
          <li className="pl-5.5 text-xs text-muted-foreground">{t("flows.builder.canvas.moreBlocks", { count: hiddenBlocks })}</li>
        ) : null}
        {screen.blocks.length === 0 ? <li className="text-xs italic text-muted-foreground">{t("flows.builder.canvas.noBlocks")}</li> : null}
      </ul>

      {unreachable ? (
        <p className="border-t border-dashed border-border px-3 py-1.5 text-xs text-amber-700 dark:text-amber-400">
          {t("flows.builder.canvas.unreachable")}
        </p>
      ) : null}

      {pendingRemoval ? (
        <div className="space-y-2 border-t border-border px-3 py-2.5" role="alertdialog" aria-label={t("flows.builder.canvas.removeTitle")}>
          <p className="text-xs text-foreground">
            {t("flows.builder.canvas.removeConfirm", { title: screen.title, count: questions })}
          </p>
          <div className="nodrag flex gap-2">
            <Button size="sm" variant="destructive" className="h-7 flex-1" autoFocus onClick={() => actions.confirmRemoveScreen(id)}>
              {t("flows.builder.canvas.remove")}
            </Button>
            <Button size="sm" variant="outline" className="h-7 flex-1" onClick={actions.cancelRemoveScreen}>
              {t("flows.builder.cancel")}
            </Button>
          </div>
        </div>
      ) : null}
      <ul className="border-t border-border py-1">
        {ports.map(({ port, option }) => (
          <li key={port} className="relative flex min-h-7 items-center justify-end gap-2 py-1 pl-3 pr-4 text-xs">
            <span className={cn("truncate", option ? "text-foreground" : "text-muted-foreground")}>
              {option
                ? option.title || option.id
                : branching
                  ? t("flows.builder.canvas.noAnswer")
                  : t("flows.builder.canvas.next", { label: screen.footer_label })}
            </span>
            <Handle id={port} type="source" position={Position.Right} className="flow-port" isConnectableEnd={false} />
          </li>
        ))}
      </ul>
    </div>
  )
}

export const ScreenNode = memo(ScreenNodeComponent)

function AppointmentNodeComponent({ id, data, selected }: NodeProps<AppointmentNodeType>) {
  const { t } = useTranslation()
  const { connecting, hovered } = useDropState(id)
  return (
    <div
      style={{ width: APPOINTMENT_NODE_WIDTH }}
      className={cn(
        "relative rounded-lg border bg-card px-3 py-2.5 text-card-foreground shadow-sm transition-[border-color,box-shadow] duration-150 dark:shadow-none",
        selected ? "border-primary ring-2 ring-primary/30" : "border-border hover:border-muted-foreground/40",
        hovered === "valid" && "border-primary ring-2 ring-primary/30",
      )}
    >
      <DropTarget id={id} connecting={connecting} />
      <div className="flex items-center gap-2">
        <CalendarClock className="size-4 shrink-0 text-muted-foreground" aria-hidden />
        <span className="min-w-0 flex-1 truncate text-sm font-medium">{t("flows.builder.canvas.appointment")}</span>
        {data.errorCount > 0 ? (
          <span className="flex shrink-0 items-center gap-1 rounded bg-destructive/10 px-1.5 py-0.5 text-[11px] font-medium text-destructive">
            <TriangleAlert className="size-3" aria-hidden />
            {data.errorCount}
          </span>
        ) : null}
      </div>
      <p className="mt-1.5 text-xs text-muted-foreground">{t("flows.builder.canvas.appointmentSteps")}</p>
      <Handle id={OUT_HANDLE} type="source" position={Position.Right} className="flow-port" isConnectable={false} />
    </div>
  )
}

export const AppointmentNode = memo(AppointmentNodeComponent)

function StartNodeComponent() {
  const { t } = useTranslation()
  return (
    <div className="flex h-8 items-center gap-1.5 rounded-full border border-border bg-card pl-2.5 pr-3.5 text-xs font-medium text-muted-foreground">
      <Play className="size-3" aria-hidden />
      {t("flows.builder.canvas.start")}
      <Handle id={OUT_HANDLE} type="source" position={Position.Right} className="flow-port" isConnectableEnd={false} />
    </div>
  )
}

export const StartNode = memo(StartNodeComponent)

function EndNodeComponent({ id }: NodeProps<TerminalNodeType>) {
  const { t } = useTranslation()
  const { connecting, hovered } = useDropState(id)
  return (
    <div
      className={cn(
        "relative flex h-8 items-center rounded-full border bg-card px-3.5 text-xs font-medium text-muted-foreground transition-colors duration-150",
        hovered === "valid" ? "border-primary text-foreground" : "border-border",
      )}
    >
      <DropTarget id={id} connecting={connecting} />
      {t("flows.builder.canvas.end")}
    </div>
  )
}

export const EndNode = memo(EndNodeComponent)

export const NODE_TYPES = {
  screen: ScreenNode,
  appointment: AppointmentNode,
  start: StartNode,
  end: EndNode,
}
