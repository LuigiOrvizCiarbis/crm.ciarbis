"use client"

import { useCallback, useEffect, useState } from "react"
import { useRouter } from "next/navigation"
import { Card, CardContent } from "@/components/ui/card"
import { Badge, LeadScoreBadge } from "@/components/Badges"
import { ContactAvatar } from "@/components/contact-avatar"
import { Button } from "@/components/ui/button"
import { useToast } from "@/components/Toast"
import { getPipelineStages } from "@/lib/api/pipeline"
import { getOpportunities, updateOpportunityStage } from "@/lib/api/opportunities"
import { Phone, Mail, Calendar, GripVertical, Clock, Loader2, TrendingUp, MessageSquare } from "lucide-react"
import {
  DndContext,
  DragOverlay,
  closestCenter,
  pointerWithin,
  KeyboardSensor,
  PointerSensor,
  useDroppable,
  useSensor,
  useSensors,
  type DragStartEvent,
  type DragEndEvent,
  type DragCancelEvent,
} from "@dnd-kit/core"
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from "@dnd-kit/sortable"
import { CSS } from "@dnd-kit/utilities"
import { formatDistanceToNow } from "date-fns"
import { es } from "date-fns/locale"

interface Opportunity {
  id: number
  conversation_id?: number | string | null
  title: string
  status: "open" | "won" | "lost" | "archived"
  source_type: "manual" | "conversation"
  source?: string | null
  channel_type?: number | string | null
  value?: string | number | null
  notes?: string | null
  last_activity_at: string | null
  pipeline_stage_id: number | null
  contact: {
    id: number | string
    name: string
    phone: string | null
    email?: string | null
    source?: string | null
    channel_type?: number | string | null
  }
  conversation?: {
    id: number
    last_message_content?: string | null
    last_message_at?: string | null
    channel?: {
      id: number
      name: string
      type: number
    } | null
  } | null
}

interface PipelineStage {
  id: number
  name: string
  sort_order: number
  color?: string | null
}

interface Column {
  id: number
  title: string
  opportunities: Opportunity[]
  color: string
}

function toStageId(value: number | string | null | undefined) {
  if (value === null || value === undefined || value === "") return null

  const numericValue = Number(value)
  return Number.isNaN(numericValue) ? null : numericValue
}

function getOpportunityScore(opportunity: Opportunity): number {
  if (opportunity.status === "won") return 92
  if (opportunity.status === "lost" || opportunity.status === "archived") return 28

  const hasRecentActivity = Boolean(opportunity.last_activity_at)
  const hasConversation = Boolean(opportunity.conversation)
  const hasValue = Number(opportunity.value || 0) > 0

  return 45 + (hasRecentActivity ? 15 : 0) + (hasConversation ? 15 : 0) + (hasValue ? 10 : 0)
}

function getOpportunityProbability(opportunity: Opportunity): number {
  if (opportunity.status === "won") return 100
  if (opportunity.status === "lost" || opportunity.status === "archived") return 0

  return Math.min(getOpportunityScore(opportunity), 85)
}

function formatShortDate(value: string | null | undefined): string {
  if (!value) return "Sin fecha"

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return "Sin fecha"

  return date.toLocaleDateString("es-ES", { month: "short", day: "numeric" })
}

const getColumnDropId = (columnId: number) => `column-${columnId}`

const getChannelIcon = (channelType: number) => {
  switch (channelType) {
    case 1:
      return "📱"
    case 2:
      return "📸"
    case 3:
      return "👍"
    default:
      return "💬"
  }
}

const getChannelColor = (channelType: number) => {
  switch (channelType) {
    case 1:
      return "bg-green-500/10 text-green-600"
    case 2:
      return "bg-pink-500/10 text-pink-600"
    case 3:
      return "bg-blue-500/10 text-blue-600"
    default:
      return "bg-slate-500/10 text-slate-600"
  }
}

function normalizeSource(value: number | string | null | undefined): string | null {
  if (value === null || value === undefined || value === "") return null

  const normalizedValue = String(value).toLowerCase()
  const sourceMap: Record<string, string> = {
    "1": "whatsapp",
    whatsapp: "whatsapp",
    "2": "instagram",
    instagram: "instagram",
    "3": "facebook",
    facebook: "facebook",
    "8": "manual",
    manual: "manual",
  }

  return sourceMap[normalizedValue] ?? normalizedValue
}

const sourceLabelMap: Record<string, string> = {
  whatsapp: "📱 WhatsApp",
  instagram: "📸 Instagram",
  facebook: "👍 Facebook",
  manual: "Manual",
}

const sourceClassNameMap: Record<string, string> = {
  whatsapp: "bg-green-500/10 text-green-600",
  instagram: "bg-pink-500/10 text-pink-600",
  facebook: "bg-blue-500/10 text-blue-600",
  manual: "bg-slate-500/10 text-slate-500",
}

function getOpportunitySource(opportunity: Opportunity): { label: string; className: string } {
  const channel = opportunity.conversation?.channel
  if (channel) {
    return {
      label: `${getChannelIcon(channel.type)} ${channel.name}`,
      className: getChannelColor(channel.type),
    }
  }

  const source = normalizeSource(
    opportunity.contact.source ?? opportunity.contact.channel_type ?? opportunity.source ?? opportunity.channel_type
  )

  return {
    label: source ? sourceLabelMap[source] ?? source : "Manual",
    className: source ? sourceClassNameMap[source] ?? "bg-slate-500/10 text-slate-600" : "bg-slate-500/10 text-slate-500",
  }
}

function OpportunityCard({
  opportunity,
  dragHandleProps,
  isDragging = false,
  isOverlay = false,
}: {
  opportunity: Opportunity
  dragHandleProps?: Record<string, unknown>
  isDragging?: boolean
  isOverlay?: boolean
}) {
  const router = useRouter()
  const lastActivityText = opportunity.last_activity_at
    ? formatDistanceToNow(new Date(opportunity.last_activity_at), { locale: es, addSuffix: true })
    : "Sin actividad"

  const { label: sourceLabel, className: sourceClassName } = getOpportunitySource(opportunity)
  const valueFormatted = opportunity.value
    ? formatColumnCurrency(Number(opportunity.value))
    : "—"
  const probability = getOpportunityProbability(opportunity)
  const score = getOpportunityScore(opportunity)
  const referenceDate = opportunity.conversation?.last_message_at || opportunity.last_activity_at
  const activityOwner = opportunity.source_type === "conversation" ? "Conversación" : sourceLabel
  const conversationId = opportunity.conversation?.id ?? opportunity.conversation_id

  const openConversation = () => {
    if (conversationId) router.push(`/chats?chat=${conversationId}`)
  }

  return (
    <Card
      className={`cursor-pointer transition-[box-shadow,transform,opacity] border-border/60 ${
        isOverlay ? "rotate-1 shadow-2xl ring-1 ring-primary/20" : "hover:shadow-md hover:border-border"
      } ${isDragging ? "opacity-40 scale-[0.98]" : "opacity-100"}`}
    >
      <CardContent className="p-4">
        <div className="flex items-start justify-between gap-2">
          <button
            type="button"
            onClick={openConversation}
            disabled={!conversationId}
            className={`flex items-center gap-2 flex-1 min-w-0 rounded-md text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-background ${
              conversationId ? "cursor-pointer hover:bg-muted/60 active:bg-muted" : "cursor-default"
            }`}
            title={conversationId ? "Abrir conversación" : "Sin conversación"}
          >
            <ContactAvatar
              contactId={opportunity.contact.id}
              name={opportunity.contact.name}
              className="w-8 h-8 shrink-0"
              fallbackClassName="text-xs bg-cyan-500/15 text-cyan-400 font-semibold"
            />
            <span className="min-w-0 flex-1">
              <span className="block font-medium text-sm truncate">{opportunity.contact.name}</span>
              <span className="block text-xs text-muted-foreground truncate">{opportunity.title}</span>
            </span>
          </button>
          <div className="flex items-center gap-1 shrink-0">
            <LeadScoreBadge score={score} />
            <Button
              variant="ghost"
              size="sm"
              className={`h-6 w-6 p-0 ${isOverlay ? "cursor-grabbing" : "cursor-grab active:cursor-grabbing"}`}
              {...dragHandleProps}
            >
              <GripVertical className="w-3 h-3" />
            </Button>
          </div>
        </div>

        <div className="space-y-2 mt-3">
          <div className="flex items-center justify-between gap-2">
            <Badge variant="default" className={`text-xs max-w-[9rem] truncate ${sourceClassName}`}>
              {sourceLabel}
            </Badge>
            <span className="text-sm font-bold text-foreground tabular-nums">{valueFormatted}</span>
          </div>

          <div className="flex items-center justify-between gap-2 text-xs">
            <div className="flex items-center gap-1">
              <TrendingUp className="w-3 h-3 text-green-500" />
              <span className="font-medium">{probability}%</span>
            </div>
            <div className="flex items-center gap-1 text-muted-foreground">
              <Clock className="w-3 h-3 shrink-0" />
              <span>{formatShortDate(referenceDate)}</span>
            </div>
          </div>

          <div className="flex items-center gap-2 text-xs text-muted-foreground">
            <span className="truncate">{lastActivityText}</span>
            <span>•</span>
            <span>{activityOwner}</span>
          </div>

          {opportunity.notes && (
            <p className="line-clamp-2 text-xs text-muted-foreground">{opportunity.notes}</p>
          )}

          <div className="flex items-center gap-1">
            <Button
              variant="ghost"
              size="sm"
              className="h-8 min-h-10 gap-2 px-2 text-muted-foreground hover:text-foreground"
              disabled={!conversationId}
              onClick={openConversation}
              title={conversationId ? "Abrir conversación" : "Sin conversación"}
            >
              <MessageSquare className="w-3.5 h-3.5" />
              <span className="text-xs">{conversationId ? "Abrir chat" : "Sin chat"}</span>
            </Button>
            {opportunity.contact.phone && (
              <Button variant="ghost" size="sm" className="h-6 w-6 p-0">
                <Phone className="w-3 h-3" />
              </Button>
            )}
            {opportunity.contact.email && (
              <Button variant="ghost" size="sm" className="h-6 w-6 p-0">
                <Mail className="w-3 h-3" />
              </Button>
            )}
            <Button variant="ghost" size="sm" className="h-6 w-6 p-0">
              <Calendar className="w-3 h-3" />
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function SortableOpportunityCard({ opportunity }: { opportunity: Opportunity }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: opportunity.id.toString(),
  })

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  }

  return (
    <div ref={setNodeRef} style={style}>
      <OpportunityCard
        opportunity={opportunity}
        isDragging={isDragging}
        dragHandleProps={{ ...attributes, ...listeners }}
      />
    </div>
  )
}

function formatColumnCurrency(value: number): string {
  if (value >= 1000) {
    return `$${(value / 1000).toFixed(value >= 10000 ? 0 : 1)}k`
  }
  return `$${new Intl.NumberFormat("es-AR").format(Math.round(value))}`
}

const COLUMN_COLOR_MAP: Record<string, string> = {
  "bg-blue-500": "#3B82F6",
  "bg-cyan-500": "#06B6D4",
  "bg-indigo-500": "#6366F1",
  "bg-purple-500": "#8B5CF6",
  "bg-violet-500": "#8B5CF6",
  "bg-pink-500": "#EC4899",
  "bg-amber-500": "#F59E0B",
  "bg-yellow-500": "#EAB308",
  "bg-orange-500": "#F97316",
  "bg-emerald-500": "#10B981",
  "bg-green-500": "#22C55E",
  "bg-red-500": "#EF4444",
  "bg-teal-500": "#14B8A6",
  "bg-lime-500": "#84CC16",
}

function resolveColumnColor(color: string | null | undefined): string {
  if (!color) return "#3B82F6"
  if (color.startsWith("#")) return color
  return COLUMN_COLOR_MAP[color] ?? "#3B82F6"
}

function KanbanColumn({ column, opportunities }: { column: Column; opportunities: Opportunity[] }) {
  const { setNodeRef, isOver } = useDroppable({
    id: getColumnDropId(column.id),
  })

  const totalValue = opportunities.reduce((sum, item) => sum + Number(item.value || 0), 0)
  const wonCount = opportunities.filter((item) => item.status === "won").length
  const avgProb = opportunities.length > 0 ? Math.round((wonCount / opportunities.length) * 100) : 0
  const stripeColor = resolveColumnColor(column.color)

  return (
    <div className="flex-1 min-w-80">
      <Card className={`bg-muted/20 overflow-hidden transition-colors ${isOver ? "ring-2 ring-primary/30 bg-primary/5" : ""}`}>
        <div className="h-1.5 w-full" style={{ backgroundColor: stripeColor }} />
        <CardContent className="p-4">
          <div className="flex items-center gap-2 mb-2">
            <div className="w-3 h-3 rounded-full" style={{ backgroundColor: stripeColor }} />
            <h3 className="font-semibold flex-1 truncate">{column.title}</h3>
            <Badge variant="default" className="ml-1 shrink-0">
              {opportunities.length}
            </Badge>
          </div>

          <div className="space-y-1 mb-4">
            <p className="text-sm font-medium">{formatColumnCurrency(totalValue)}</p>
            <p className="text-xs text-muted-foreground">Prob. promedio: {avgProb}%</p>
          </div>

          <SortableContext items={opportunities.map((item) => item.id.toString())} strategy={verticalListSortingStrategy}>
            <div
              ref={setNodeRef}
              className={`space-y-2 min-h-96 rounded-md border border-transparent transition-colors ${
                isOver ? "bg-primary/5 border-primary/30" : ""
              }`}
            >
              {opportunities.map((opportunity) => (
                <SortableOpportunityCard key={opportunity.id} opportunity={opportunity} />
              ))}

              {opportunities.length === 0 && (
                <div className={`flex items-center justify-center h-32 text-sm rounded-md border border-dashed ${
                  isOver ? "border-primary/40 text-primary" : "border-border text-muted-foreground"
                }`}>
                  Arrastra leads aquí
                </div>
              )}
            </div>
          </SortableContext>
        </CardContent>
      </Card>
    </div>
  )
}

interface KanbanBoardProps {
  refreshKey?: number
}

export function KanbanBoard({ refreshKey = 0 }: KanbanBoardProps) {
  const [columns, setColumns] = useState<Column[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [activeOpportunityId, setActiveOpportunityId] = useState<number | null>(null)
  const { addToast } = useToast()

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: {
        distance: 8,
      },
    }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  )

  const fetchPipelineData = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const [stages, opportunities] = await Promise.all([
        getPipelineStages(),
        getOpportunities(),
      ])

      const normalizedStageIds = new Set(stages.map((stage: PipelineStage) => Number(stage.id)))
      const normalizedOpportunities = opportunities.map((opportunity: Opportunity) => ({
        ...opportunity,
        pipeline_stage_id: toStageId(opportunity.pipeline_stage_id),
      }))

      const fallbackColors = ["bg-blue-500", "bg-yellow-500", "bg-orange-500", "bg-green-500", "bg-purple-500"]

      const columnsData: Column[] = stages.map((stage: PipelineStage, index: number) => {
        const stageId = Number(stage.id)
        const stageOpportunities = normalizedOpportunities.filter(
          (opportunity: Opportunity) => opportunity.pipeline_stage_id === stageId
        )

        return {
          id: stage.id,
          title: stage.name,
          opportunities: stageOpportunities,
          color: stage.color || fallbackColors[index % fallbackColors.length],
        }
      })

      const unassignedOpportunities = normalizedOpportunities.filter(
        (opportunity: Opportunity) =>
          opportunity.pipeline_stage_id === null || !normalizedStageIds.has(opportunity.pipeline_stage_id)
      )

      if (unassignedOpportunities.length > 0 || columnsData.length === 0) {
        columnsData.unshift({
          id: 0,
          title: "Sin asignar",
          opportunities: unassignedOpportunities,
          color: "bg-gray-500",
        })
      }

      setColumns(columnsData)
    } catch (err) {
      setError(err instanceof Error ? err.message : "Error desconocido")
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchPipelineData()
  }, [fetchPipelineData, refreshKey])

  const totalOpportunities = columns.reduce((sum, col) => sum + col.opportunities.length, 0)
  const avgValue =
    totalOpportunities > 0
      ? Math.round(
          columns.reduce(
            (sum, col) => sum + col.opportunities.reduce((colSum, item) => colSum + Number(item.value || 0), 0),
            0
          ) / totalOpportunities
        )
      : 0

  const activeOpportunity =
    activeOpportunityId === null
      ? null
      : columns.flatMap((column) => column.opportunities).find((item) => item.id === activeOpportunityId) ?? null

  function handleDragStart(event: DragStartEvent) {
    setActiveOpportunityId(parseInt(String(event.active.id), 10))
  }

  function handleDragCancel(_event: DragCancelEvent) {
    setActiveOpportunityId(null)
  }

  async function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event
    setActiveOpportunityId(null)

    if (!over) return

    const activeId = parseInt(active.id as string)
    const overId = String(over.id)
    const destinationColumnId = overId.startsWith("column-")
      ? parseInt(overId.replace("column-", ""))
      : undefined

    const sourceColumn = columns.find((col) => col.opportunities.some((item) => item.id === activeId))
    const destColumn = columns.find(
      (col) =>
        col.id === destinationColumnId ||
        col.opportunities.some((item) => item.id === parseInt(overId))
    )

    if (!sourceColumn || !destColumn) return

    const sourceOpportunity = sourceColumn.opportunities.find((item) => item.id === activeId)
    if (!sourceOpportunity) return

    if (sourceColumn.id !== destColumn.id) {
      try {
        await updateOpportunityStage(activeId, destColumn.id === 0 ? null : destColumn.id)

        setColumns((prevColumns) =>
          prevColumns.map((col) => {
            if (col.id === sourceColumn.id) {
              return {
                ...col,
                opportunities: col.opportunities.filter((item) => item.id !== activeId),
              }
            }

            if (col.id === destColumn.id) {
              return {
                ...col,
                opportunities: [
                  ...col.opportunities,
                  { ...sourceOpportunity, pipeline_stage_id: destColumn.id === 0 ? null : destColumn.id },
                ],
              }
            }

            return col
          })
        )

        addToast({
          type: "success",
          title: "Oportunidad movida",
          description: `${sourceOpportunity.contact.name} movido a ${destColumn.title}`,
        })
      } catch {
        addToast({
          type: "error",
          title: "Error",
          description: "No se pudo actualizar la etapa",
        })
      }

      return
    }

    const sourceIndex = sourceColumn.opportunities.findIndex((item) => item.id === activeId)
    const destIndex = sourceColumn.opportunities.findIndex((item) => item.id === parseInt(overId))

    if (destIndex >= 0 && sourceIndex !== destIndex) {
      setColumns((prevColumns) =>
        prevColumns.map((col) => {
          if (col.id !== sourceColumn.id) return col
          return {
            ...col,
            opportunities: arrayMove(col.opportunities, sourceIndex, destIndex),
          }
        })
      )
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="w-8 h-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (error) {
    return (
      <div className="text-center py-12">
        <p className="text-destructive mb-4">{error}</p>
        <Button onClick={fetchPipelineData} variant="outline">
          Reintentar
        </Button>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card>
          <CardContent className="p-4">
            <div className="text-2xl font-bold">{totalOpportunities}</div>
            <p className="text-sm text-muted-foreground">Total oportunidades</p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-4">
            <div className="text-2xl font-bold">{avgValue}</div>
            <p className="text-sm text-muted-foreground">Valor promedio</p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-4">
            <div className="text-2xl font-bold">{columns.length}</div>
            <p className="text-sm text-muted-foreground">Etapas del pipeline</p>
          </CardContent>
        </Card>
      </div>

      <DndContext
        sensors={sensors}
        collisionDetection={(args) => {
          const pointerIntersections = pointerWithin(args)
          if (pointerIntersections.length > 0) {
            return pointerIntersections
          }

          return closestCenter(args)
        }}
        onDragStart={handleDragStart}
        onDragCancel={handleDragCancel}
        onDragEnd={handleDragEnd}
      >
        <div className="flex gap-6 overflow-x-auto pb-4">
          {columns.map((column) => (
            <KanbanColumn key={column.id} column={column} opportunities={column.opportunities} />
          ))}
        </div>
        <DragOverlay dropAnimation={null}>
          {activeOpportunity ? <OpportunityCard opportunity={activeOpportunity} isOverlay /> : null}
        </DragOverlay>
      </DndContext>
    </div>
  )
}
