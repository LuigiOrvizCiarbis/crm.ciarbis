"use client"

import "@xyflow/react/dist/base.css"

import { useCallback, useEffect, useMemo, useState } from "react"
import {
  Background,
  BackgroundVariant,
  MarkerType,
  Panel,
  ReactFlow,
  useReactFlow,
  type Connection,
  type EdgeChange,
  type FinalConnectionState,
  type IsValidConnection,
  type NodeChange,
  type OnConnectEnd,
} from "@xyflow/react"
import { useTheme } from "next-themes"
import { CalendarClock, LayoutGrid, Maximize, Minus, Plus, Repeat, SquarePlus } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import { useTranslation } from "@/hooks/useTranslation"
import type { GroupedErrors } from "@/lib/flow-builder/errors"
import { MAX_SCREENS } from "@/lib/flow-builder/limits"
import { expandedScreenCount } from "@/lib/flow-builder/repeater"
import type { RoutePort } from "@/lib/flow-builder/routing"
import type { CanvasPosition, FlowDefinition } from "@/lib/flow-builder/types"

import { CanvasActionsContext, type CanvasActions } from "./CanvasContext"
import { ConnectionLine, EDGE_TYPES } from "./edges"
import {
  APPOINTMENT_ID,
  START_ID,
  buildGraph,
  checkConnection,
  type CanvasNode,
  type Measured,
  type RouteEdgeType,
} from "./graph"
import { NODE_TYPES } from "./nodes"

const FIT_PADDING = 0.2
const MIN_ZOOM = 0.3
const MAX_ZOOM = 1.5

function prefersReducedMotion(): boolean {
  return typeof window !== "undefined" && window.matchMedia("(prefers-reduced-motion: reduce)").matches
}

function motion(duration: number): number {
  return prefersReducedMotion() ? 0 : duration
}

/** Pedido de centrar una caja (al ir a un error o crear una pantalla); `nonce` permite repetirlo. */
export interface FocusRequest {
  id: string
  nonce: number
}

interface FlowCanvasProps {
  definition: FlowDefinition
  errors: GroupedErrors
  selectedId: string | null
  pendingRemovalUid: string | null
  /** En pantallas angostas el diagrama sólo se recorre: se conecta desde el panel. */
  interactive: boolean
  /** Si el formulario se puede editar (agregar pantallas también funciona sin arrastrar). */
  editable: boolean
  focusRequest: FocusRequest | null
  actions: Omit<CanvasActions, "canAddScreen" | "hasAppointment">
  onSelect: (id: string | null) => void
  onConnectRoute: (from: number, port: RoutePort, target: string) => void
  onSetEntry: (index: number) => void
  /** Pantalla nueva soltando una conexión en un lugar vacío. */
  onDropNewScreen: (from: number, port: RoutePort, position: CanvasPosition) => void
  onMoveScreens: (positions: Record<string, CanvasPosition>) => void
  onAddScreen: () => void
  /** Agrega un grupo repetible (una pantalla por fila de un campo repeater). */
  onAddRepeater: () => void
  /** Ordena las pantallas en columnas con los altos medidos. */
  onArrange: (heights: Record<string, number>) => void
}

export function FlowCanvas({
  definition,
  errors,
  selectedId,
  pendingRemovalUid,
  interactive,
  editable,
  focusRequest,
  actions,
  onSelect,
  onConnectRoute,
  onSetEntry,
  onDropNewScreen,
  onMoveScreens,
  onAddScreen,
  onAddRepeater,
  onArrange,
}: FlowCanvasProps) {
  const { t } = useTranslation()
  const { resolvedTheme } = useTheme()
  const { screenToFlowPosition, fitView, zoomIn, zoomOut } = useReactFlow()
  const [dragging, setDragging] = useState<Record<string, CanvasPosition>>({})
  const [measured, setMeasured] = useState<Record<string, Measured>>({})
  // La flecha elegida vale mientras no cambie la caja seleccionada (crear o elegir una pantalla la descarta).
  const [edgeSelection, setEdgeSelection] = useState<{ id: string; forNode: string | null } | null>(null)
  const selectedEdgeId = edgeSelection?.forNode === selectedId ? edgeSelection.id : null
  const setSelectedEdgeId = useCallback(
    (id: string | null) => setEdgeSelection(id === null ? null : { id, forNode: selectedId }),
    [selectedId],
  )

  const { screens } = definition
  const hasAppointment = definition.appointment !== null
  // Los grupos repetibles ocupan una pantalla de Meta por fila.
  const generatedScreens = expandedScreenCount(screens)
  const canAddScreen = generatedScreens < MAX_SCREENS

  const { nodes, edges } = useMemo(
    () =>
      buildGraph({
        definition,
        errors,
        selectedId,
        selectedEdgeId,
        pendingRemovalUid,
        dragging,
        measured,
        interactive,
      }),
    [definition, errors, selectedId, selectedEdgeId, pendingRemovalUid, dragging, measured, interactive],
  )

  const screenIndex = useCallback((uid: string) => screens.findIndex((screen) => screen.uid === uid), [screens])

  const onNodesChange = useCallback(
    (changes: NodeChange<CanvasNode>[]) => {
      const moving: Record<string, CanvasPosition> = {}
      const committed: Record<string, CanvasPosition> = {}
      const sizes: Record<string, Measured> = {}
      for (const change of changes) {
        if (change.type === "dimensions" && change.dimensions) {
          sizes[change.id] = { width: change.dimensions.width, height: change.dimensions.height }
        } else if (change.type === "position") {
          const position = change.position
          if (!position) continue
          if (change.dragging) moving[change.id] = position
          else committed[change.id] = { x: Math.round(position.x), y: Math.round(position.y) }
        } else if (change.type === "select" && change.selected) {
          onSelect(change.id)
          setEdgeSelection(null)
        }
      }
      if (Object.keys(sizes).length > 0) setMeasured((previous) => ({ ...previous, ...sizes }))
      if (Object.keys(moving).length > 0) setDragging((previous) => ({ ...previous, ...moving }))
      if (Object.keys(committed).length > 0) {
        onMoveScreens(committed)
        setDragging((previous) => {
          const rest = { ...previous }
          for (const id of Object.keys(committed)) delete rest[id]
          return rest
        })
      }
    },
    [onMoveScreens, onSelect],
  )

  const onNodeDragStop = useCallback(
    (_: unknown, __: CanvasNode, moved: CanvasNode[]) => {
      const committed = Object.fromEntries(
        moved
          .filter((node) => node.type === "screen")
          .map((node) => [node.id, { x: Math.round(node.position.x), y: Math.round(node.position.y) }]),
      )
      if (Object.keys(committed).length === 0) return
      onMoveScreens(committed)
      setDragging((previous) => {
        const rest = { ...previous }
        for (const id of Object.keys(committed)) delete rest[id]
        return rest
      })
    },
    [onMoveScreens],
  )

  const isValidConnection: IsValidConnection = useCallback(
    (connection) => checkConnection(screens, connection.source, connection.sourceHandle, connection.target).valid,
    [screens],
  )

  const onConnect = useCallback(
    (connection: Connection) => {
      if (!checkConnection(screens, connection.source, connection.sourceHandle, connection.target).valid) return
      if (connection.source === START_ID) {
        onSetEntry(screenIndex(connection.target))
        return
      }
      const from = screenIndex(connection.source)
      if (from !== -1 && connection.sourceHandle) onConnectRoute(from, connection.sourceHandle as RoutePort, connection.target)
    },
    [screens, screenIndex, onConnectRoute, onSetEntry],
  )

  const onConnectEnd: OnConnectEnd = useCallback(
    (event, state: FinalConnectionState) => {
      if (state.isValid || state.toNode || !state.fromNode || !state.fromHandle?.id) return
      const from = screenIndex(state.fromNode.id)
      if (from === -1 || !canAddScreen) return
      const point = "changedTouches" in event ? event.changedTouches[0] : event
      // Sólo soltar sobre el lienzo vacío crea una pantalla: soltar sobre la misma caja, el inicio,
      // una flecha o la barra cancela, como en cualquier arrastre.
      const released = document.elementFromPoint(point.clientX, point.clientY)
      if (!released?.classList.contains("react-flow__pane")) return
      const position = screenToFlowPosition({ x: point.clientX, y: point.clientY })
      onDropNewScreen(from, state.fromHandle.id as RoutePort, { x: Math.round(position.x), y: Math.round(position.y - 24) })
    },
    [screenIndex, canAddScreen, screenToFlowPosition, onDropNewScreen],
  )

  // Enter o espacio sobre una flecha enfocada la eligen, igual que un clic.
  const onEdgesChange = useCallback(
    (changes: EdgeChange<RouteEdgeType>[]) => {
      for (const change of changes) {
        if (change.type !== "select") continue
        const edge = edges.find((item) => item.id === change.id)
        if (change.selected && interactive && edge?.data?.from != null) setSelectedEdgeId(change.id)
        else if (!change.selected && change.id === selectedEdgeId) setSelectedEdgeId(null)
      }
    },
    [edges, interactive, selectedEdgeId, setSelectedEdgeId],
  )

  useEffect(() => {
    if (!focusRequest) return
    // Espera a que la caja nueva exista y esté medida.
    const frame = requestAnimationFrame(() => {
      void fitView({ nodes: [{ id: focusRequest.id }], padding: 0.6, maxZoom: 1, duration: motion(200) })
    })
    return () => cancelAnimationFrame(frame)
  }, [focusRequest, fitView])

  const contextValue = useMemo<CanvasActions>(
    () => ({ ...actions, canAddScreen, hasAppointment }),
    [actions, canAddScreen, hasAppointment],
  )

  const arrange = () => {
    onArrange(Object.fromEntries(Object.entries(measured).map(([id, size]) => [id, size.height])))
    requestAnimationFrame(() => void fitView({ padding: FIT_PADDING, duration: motion(200) }))
  }

  return (
    <CanvasActionsContext.Provider value={contextValue}>
      <ReactFlow<CanvasNode, RouteEdgeType>
        nodes={nodes}
        edges={edges}
        nodeTypes={NODE_TYPES}
        edgeTypes={EDGE_TYPES}
        colorMode={resolvedTheme === "dark" ? "dark" : "light"}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        onNodeDragStop={onNodeDragStop}
        onConnect={onConnect}
        onConnectEnd={onConnectEnd}
        isValidConnection={isValidConnection}
        connectionLineComponent={ConnectionLine}
        connectionRadius={28}
        onNodeClick={(_, node) => {
          if (node.id === START_ID || node.type === "end") return
          onSelect(node.id)
          setSelectedEdgeId(null)
        }}
        onEdgeClick={(_, edge) => {
          if (!interactive || edge.data?.from === null) return
          setSelectedEdgeId(edge.id)
        }}
        onPaneClick={() => {
          onSelect(null)
          setSelectedEdgeId(null)
        }}
        onKeyDown={(event) => {
          const target = event.target as HTMLElement
          if (target.closest('input, textarea, select, [contenteditable=true], [role="menu"]')) return
          if (event.key !== "Delete" && event.key !== "Backspace") return
          // Con una flecha elegida, Supr actúa sobre ella; una flecha al fin no se puede quitar.
          const edge = selectedEdgeId ? edges.find((item) => item.id === selectedEdgeId) : undefined
          if (edge) {
            event.preventDefault()
            if (edge.data && edge.data.from !== null && edge.data.port !== null && !edge.data.toEnd) {
              actions.clearRoute(edge.data.from, edge.data.port)
              setSelectedEdgeId(null)
            }
            return
          }
          if (target.closest(".react-flow__edge")) return
          if (selectedId && selectedId !== APPOINTMENT_ID && screenIndex(selectedId) !== -1) {
            event.preventDefault()
            actions.requestRemoveScreen(selectedId)
          }
        }}
        defaultEdgeOptions={{ markerEnd: { type: MarkerType.ArrowClosed, width: 16, height: 16 } }}
        nodesDraggable={interactive}
        nodesConnectable={interactive}
        elementsSelectable
        deleteKeyCode={null}
        selectionKeyCode={null}
        multiSelectionKeyCode={null}
        fitView
        fitViewOptions={{ padding: FIT_PADDING, maxZoom: 1 }}
        minZoom={MIN_ZOOM}
        maxZoom={MAX_ZOOM}
        proOptions={{ hideAttribution: true }}
        ariaLabelConfig={{
          "node.a11yDescription.default": t("flows.builder.canvas.a11y.node"),
          "node.a11yDescription.keyboardDisabled": t("flows.builder.canvas.a11y.node"),
          "edge.a11yDescription.default": t("flows.builder.canvas.a11y.edge"),
        }}
      >
        <Background variant={BackgroundVariant.Dots} gap={20} size={1.6} />
        <Panel position="top-left" className="flex items-center gap-1 rounded-md border border-border bg-background/95 p-1 shadow-sm dark:shadow-none">
          <ToolButton label={canAddScreen ? t("flows.builder.screens.add") : t("flows.builder.screens.max", { max: MAX_SCREENS })} onClick={onAddScreen} disabled={!canAddScreen || !editable}>
            <SquarePlus className="size-4" />
            <span className="hidden sm:inline">{t("flows.builder.screens.add")}</span>
          </ToolButton>
          <ToolButton
            label={canAddScreen ? t("flows.builder.repeater.addHint") : t("flows.builder.screens.max", { max: MAX_SCREENS })}
            onClick={onAddRepeater}
            disabled={!canAddScreen || !editable}
          >
            <Repeat className="size-4" />
            <span className="hidden sm:inline">{t("flows.builder.repeater.add")}</span>
          </ToolButton>
          <ToolButton label={t("flows.builder.canvas.arrangeHint")} onClick={arrange} disabled={!editable}>
            <LayoutGrid className="size-4" />
            <span className="hidden sm:inline">{t("flows.builder.canvas.arrange")}</span>
          </ToolButton>
          {hasAppointment ? null : (
            <ToolButton label={t("flows.builder.canvas.addAppointment")} onClick={actions.addAppointment} disabled={!editable}>
              <CalendarClock className="size-4" />
              <span className="hidden sm:inline">{t("flows.builder.canvas.appointment")}</span>
            </ToolButton>
          )}
          <span className="px-2 font-mono text-xs tabular-nums text-muted-foreground" aria-label={t("flows.builder.canvas.screenCountLabel", { count: generatedScreens, max: MAX_SCREENS })}>
            {generatedScreens}/{MAX_SCREENS}
          </span>
        </Panel>
        <Panel position="bottom-left" className="flex items-center gap-0.5 rounded-md border border-border bg-background/95 p-1 shadow-sm dark:shadow-none">
          <ToolButton label={t("flows.builder.canvas.zoomOut")} onClick={() => void zoomOut({ duration: motion(150) })} icon>
            <Minus className="size-4" />
          </ToolButton>
          <ToolButton label={t("flows.builder.canvas.zoomIn")} onClick={() => void zoomIn({ duration: motion(150) })} icon>
            <Plus className="size-4" />
          </ToolButton>
          <ToolButton label={t("flows.builder.canvas.fit")} onClick={() => void fitView({ padding: FIT_PADDING, maxZoom: 1, duration: motion(200) })} icon>
            <Maximize className="size-4" />
          </ToolButton>
        </Panel>
      </ReactFlow>
    </CanvasActionsContext.Provider>
  )
}

function ToolButton({
  label,
  onClick,
  disabled,
  icon,
  children,
}: {
  label: string
  onClick: () => void
  disabled?: boolean
  icon?: boolean
  children: React.ReactNode
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span>
          <Button
            variant="ghost"
            size={icon ? "icon" : "sm"}
            className={icon ? "size-8" : "h-8"}
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
          >
            {children}
          </Button>
        </span>
      </TooltipTrigger>
      <TooltipContent>{label}</TooltipContent>
    </Tooltip>
  )
}
