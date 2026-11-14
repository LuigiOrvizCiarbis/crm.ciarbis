"use client"

import { memo } from "react"
import {
  BaseEdge,
  EdgeLabelRenderer,
  getBezierPath,
  type ConnectionLineComponentProps,
  type EdgeProps,
} from "@xyflow/react"
import { CalendarClock, Plus, SquarePlus, X } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"

import { useCanvasActions } from "./CanvasContext"
import { START_ID, type CanvasNode, type RouteEdgeType } from "./graph"

function RouteEdgeComponent({
  id,
  sourceX,
  sourceY,
  targetX,
  targetY,
  sourcePosition,
  targetPosition,
  selected,
  data,
  markerEnd,
}: EdgeProps<RouteEdgeType>) {
  const { t } = useTranslation()
  const actions = useCanvasActions()
  const [path, labelX, labelY] = getBezierPath({ sourceX, sourceY, targetX, targetY, sourcePosition, targetPosition })
  const editable = data && data.from !== null && data.port !== null

  return (
    <>
      <BaseEdge id={id} path={path} markerEnd={markerEnd} interactionWidth={18} />
      {editable && selected ? (
        <EdgeLabelRenderer>
          <div
            className="nodrag nopan pointer-events-auto absolute flex items-center gap-1 rounded-md border border-border bg-popover p-0.5 shadow-md dark:shadow-none"
            style={{ transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)` }}
          >
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7" aria-label={t("flows.builder.canvas.insert")}>
                  <Plus className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="center" className="w-52">
                <DropdownMenuItem
                  disabled={!actions.canAddScreen}
                  onSelect={() => data.from !== null && data.port !== null && actions.insertScreen(data.from, data.port)}
                >
                  <SquarePlus className="size-4" />
                  {t("flows.builder.canvas.insertScreen")}
                </DropdownMenuItem>
                {!actions.hasAppointment && data.toEnd ? (
                  <DropdownMenuItem onSelect={actions.addAppointment}>
                    <CalendarClock className="size-4" />
                    {t("flows.builder.canvas.appointment")}
                  </DropdownMenuItem>
                ) : null}
              </DropdownMenuContent>
            </DropdownMenu>
            {!data.toEnd ? (
              <Button
                variant="ghost"
                size="icon"
                className="size-7 text-muted-foreground hover:text-destructive"
                aria-label={t("flows.builder.canvas.clearRoute")}
                onClick={() => data.from !== null && data.port !== null && actions.clearRoute(data.from, data.port)}
              >
                <X className="size-4" />
              </Button>
            ) : null}
          </div>
        </EdgeLabelRenderer>
      ) : null}
    </>
  )
}

export const RouteEdge = memo(RouteEdgeComponent)

export const EDGE_TYPES = { route: RouteEdge }

/** Línea mientras se conecta: roja y con el motivo cuando ese destino no se puede usar. */
export function ConnectionLine({
  fromX,
  fromY,
  toX,
  toY,
  fromPosition,
  toPosition,
  connectionStatus,
  fromNode,
  toNode,
}: ConnectionLineComponentProps<CanvasNode>) {
  const { t } = useTranslation()
  const [path] = getBezierPath({
    sourceX: fromX,
    sourceY: fromY,
    sourcePosition: fromPosition,
    targetX: toX,
    targetY: toY,
    targetPosition: toPosition,
  })
  const invalid = connectionStatus === "invalid" && toNode !== null
  const reason = fromNode.id === START_ID ? t("flows.builder.canvas.entryBlocked") : t("flows.builder.canvas.backwardBlocked")

  return (
    <g>
      <path
        d={path}
        fill="none"
        strokeWidth={1.5}
        className={cn(invalid ? "stroke-destructive" : "stroke-primary")}
        strokeDasharray={invalid ? "4 4" : undefined}
      />
      <circle cx={toX} cy={toY} r={3.5} className={cn(invalid ? "fill-destructive" : "fill-primary")} />
      {invalid ? (
        <text
          x={toX + 12}
          y={toY - 12}
          className="fill-destructive text-xs font-medium"
          style={{ paintOrder: "stroke", stroke: "var(--background)", strokeWidth: 4, strokeLinejoin: "round" }}
        >
          {reason}
        </text>
      ) : null}
    </g>
  )
}
