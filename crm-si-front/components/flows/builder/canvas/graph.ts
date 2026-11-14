import type { Edge, Node } from "@xyflow/react"

import type { GroupedErrors } from "@/lib/flow-builder/errors"
import { terminalPositions } from "@/lib/flow-builder/layout"
import { isRepeaterScreen, screenBlocks } from "@/lib/flow-builder/repeater"
import {
  canBeEntry,
  canRouteTo,
  resolveTarget,
  screenPorts,
  unreachableScreens,
  type RoutePort,
  type ScreenPort,
} from "@/lib/flow-builder/routing"
import type { CanvasPosition, ContentScreen, FlowDefinition, FlowScreen, RepeaterScreen } from "@/lib/flow-builder/types"

export const START_ID = "__start"
export const END_ID = "__end"
export const APPOINTMENT_ID = "__appointment"
/** Entrada visible de una caja (punto a la izquierda). */
export const IN_HANDLE = "in"
/** Entrada que cubre toda la caja mientras se arrastra una conexión: se puede soltar en cualquier parte. */
export const DROP_HANDLE = "drop"
export const OUT_HANDLE = "out"

export interface StepNodeData extends Record<string, unknown> {
  index: number
  ports: ScreenPort[]
  errorCount: number
  unreachable: boolean
  canRemove: boolean
  pendingRemoval: boolean
}

export interface ScreenNodeData extends StepNodeData {
  screen: ContentScreen
}

export interface RepeaterNodeData extends StepNodeData {
  group: RepeaterScreen
}

export interface AppointmentNodeData extends Record<string, unknown> {
  errorCount: number
}

export type ScreenNodeType = Node<ScreenNodeData, "screen">
export type RepeaterNodeType = Node<RepeaterNodeData, "repeater">
export type AppointmentNodeType = Node<AppointmentNodeData, "appointment">
export type TerminalNodeType = Node<Record<string, never>, "start" | "end">
export type CanvasNode = ScreenNodeType | RepeaterNodeType | AppointmentNodeType | TerminalNodeType

export interface RouteEdgeData extends Record<string, unknown> {
  /** Pantalla y salida de la que parte; null en las flechas fijas (inicio y reserva). */
  from: number | null
  port: RoutePort | null
  /** Si la flecha va al final: quitarla no cambia nada. */
  toEnd: boolean
}

export type RouteEdgeType = Edge<RouteEdgeData, "route">

export interface Measured {
  width: number
  height: number
}

function errorCount(screen: FlowScreen, errors: GroupedErrors): number {
  const count = (fields: Record<string, string[]> | undefined) =>
    Object.values(fields ?? {}).reduce((total, messages) => total + messages.length, 0)
  return (
    count(screen.uid ? errors.screens[screen.uid] : undefined) +
    screenBlocks(screen).reduce((total, block) => total + count(block.uid ? errors.blocks[block.uid] : undefined), 0)
  )
}

export function edgeId(screenUid: string, port: RoutePort): string {
  return `${screenUid}::${port}`
}

interface BuildGraphInput {
  definition: FlowDefinition
  errors: GroupedErrors
  selectedId: string | null
  selectedEdgeId: string | null
  pendingRemovalUid: string | null
  /** Posiciones mientras se arrastra, antes de guardarlas en la definición. */
  dragging: Record<string, CanvasPosition>
  measured: Record<string, Measured>
  interactive: boolean
}

export function buildGraph({
  definition,
  errors,
  selectedId,
  selectedEdgeId,
  pendingRemovalUid,
  dragging,
  measured,
  interactive,
}: BuildGraphInput): { nodes: CanvasNode[]; edges: RouteEdgeType[] } {
  const { screens } = definition
  const hasAppointment = definition.appointment !== null
  const unreachable = unreachableScreens(screens)
  const heights = Object.fromEntries(Object.entries(measured).map(([id, size]) => [id, size.height]))
  const placed = screens.map((screen) => ({ ...screen, position: dragging[screen.uid ?? ""] ?? screen.position }))
  const terminals = terminalPositions(placed, heights, hasAppointment)
  const endNodeId = hasAppointment ? APPOINTMENT_ID : END_ID

  const withMeasure = <T extends CanvasNode>(node: T): T =>
    measured[node.id] ? ({ ...node, measured: measured[node.id] } as T) : node

  const nodes: CanvasNode[] = [
    withMeasure<TerminalNodeType>({
      id: START_ID,
      type: "start",
      position: terminals.start,
      data: {},
      draggable: false,
      selectable: false,
      deletable: false,
    }),
    ...placed.map((screen, index) => {
      const base = {
        id: screen.uid ?? screen.id,
        position: screen.position ?? { x: 0, y: 0 },
        selected: selectedId === screen.uid,
        draggable: interactive,
        deletable: false,
        ariaLabel: screen.title,
      }
      const data = {
        index,
        ports: screenPorts(screen),
        errorCount: errorCount(screen, errors),
        unreachable: unreachable.has(index),
        canRemove: screens.length > 1,
        pendingRemoval: pendingRemovalUid === screen.uid,
      }
      return isRepeaterScreen(screen)
        ? withMeasure<RepeaterNodeType>({ ...base, type: "repeater", data: { ...data, group: screen } })
        : withMeasure<ScreenNodeType>({ ...base, type: "screen", data: { ...data, screen } })
    }),
    ...(terminals.appointment
      ? [
          withMeasure<AppointmentNodeType>({
            id: APPOINTMENT_ID,
            type: "appointment",
            position: terminals.appointment,
            selected: selectedId === APPOINTMENT_ID,
            draggable: false,
            deletable: false,
            data: {
              errorCount: Object.values(errors.appointment).reduce((total, messages) => total + messages.length, 0),
            },
          }),
        ]
      : []),
    withMeasure<TerminalNodeType>({
      id: END_ID,
      type: "end",
      position: terminals.end,
      data: {},
      draggable: false,
      selectable: false,
      deletable: false,
    }),
  ]

  const fixedEdge = (id: string, source: string, target: string): RouteEdgeType => ({
    id,
    type: "route",
    source,
    sourceHandle: OUT_HANDLE,
    target,
    targetHandle: IN_HANDLE,
    selectable: false,
    focusable: false,
    deletable: false,
    data: { from: null, port: null, toEnd: false },
  })

  const edges: RouteEdgeType[] = []
  if (screens[0]?.uid) edges.push(fixedEdge(`${START_ID}::entry`, START_ID, screens[0].uid))
  screens.forEach((screen, index) => {
    if (!screen.uid) return
    for (const { port, target } of screenPorts(screen)) {
      const resolved = resolveTarget(target, index, screens)
      if (resolved === null) continue
      const targetId = resolved === "end" ? endNodeId : screens[resolved].uid
      if (!targetId) continue
      const id = edgeId(screen.uid, port)
      edges.push({
        id,
        type: "route",
        source: screen.uid,
        sourceHandle: port,
        target: targetId,
        targetHandle: IN_HANDLE,
        selected: selectedEdgeId === id,
        selectable: interactive,
        focusable: interactive,
        deletable: false,
        className: "route-edge",
        data: { from: index, port, toEnd: resolved === "end" },
      })
    }
  })
  if (hasAppointment) edges.push(fixedEdge(`${APPOINTMENT_ID}::end`, APPOINTMENT_ID, END_ID))

  return { nodes, edges }
}

export type ConnectionCheck = { valid: true } | { valid: false; reason: "backward" | "entry" | "other" }

/** Si se puede conectar `source`/`sourceHandle` con `target`, y por qué no. */
export function checkConnection(
  screens: FlowScreen[],
  source: string,
  sourceHandle: string | null | undefined,
  target: string,
): ConnectionCheck {
  if (target === START_ID || target === source) return { valid: false, reason: "other" }
  const targetIndex = screens.findIndex((screen) => screen.uid === target)

  if (source === START_ID) {
    if (targetIndex === -1) return { valid: false, reason: "other" }
    return targetIndex === 0 || canBeEntry(screens, targetIndex) ? { valid: true } : { valid: false, reason: "entry" }
  }

  const sourceIndex = screens.findIndex((screen) => screen.uid === source)
  if (sourceIndex === -1 || !sourceHandle) return { valid: false, reason: "other" }
  if (target === END_ID || target === APPOINTMENT_ID) return { valid: true }
  if (targetIndex === -1) return { valid: false, reason: "other" }
  return canRouteTo(screens, sourceIndex, targetIndex) ? { valid: true } : { valid: false, reason: "backward" }
}
