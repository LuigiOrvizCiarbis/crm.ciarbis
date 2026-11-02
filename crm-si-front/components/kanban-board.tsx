"use client"

import { createContext, useCallback, useContext, useDeferredValue, useEffect, useMemo, useState } from "react"
import { useRouter } from "next/navigation"
import { Card, CardContent } from "@/components/ui/card"
import { EmptyState } from "@/components/EmptyState"
import { StatStrip } from "@/components/ui/stat-strip"
import { Badge, LeadScoreBadge } from "@/components/Badges"
import { ContactAvatar } from "@/components/contact-avatar"
import { PlatformIcon } from "@/components/chat/PlatformIcon"
import { Button } from "@/components/ui/button"
import { useToast } from "@/components/Toast"
import { useDndAccessibility } from "@/hooks/useDndAccessibility"
import { useIsMobile } from "@/hooks/use-mobile"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet"
import { NewTaskModal } from "@/components/tasks/NewTaskModal"
import { useAuthStore } from "@/store/useAuthStore"
import { fetchPipelineStages } from "@/lib/api/pipeline"
import { getOpportunities, updateOpportunityStage } from "@/lib/api/opportunities"
import { EMPTY_PIPELINE_FILTERS, hasActiveFilters, type PipelineFilters } from "@/components/pipeline-compact-header"
import { Phone, Mail, Calendar, GripVertical, Clock, Loader2, TrendingUp, MessageSquare, ArrowRightLeft, Check, Columns3 } from "lucide-react"
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
import { channelChipClass } from "@/lib/utils/channelHelpers"

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
  assigned_to?: number | null
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

interface StageMoveContextValue {
  /** Etapas destino posibles: todas las columnas menos "Sin asignar" (id 0). */
  stages: Pick<Column, "id" | "title" | "color">[]
  /** En teléfono el selector es una hoja inferior a nivel tablero; en escritorio, un menú por tarjeta. */
  isMobile: boolean
  openMoveSheet: (opportunity: Opportunity) => void
  moveOpportunity: (opportunityId: number, destColumnId: number) => Promise<void>
}

const StageMoveContext = createContext<StageMoveContextValue | null>(null)

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
  whatsapp: "WhatsApp",
  instagram: "Instagram",
  facebook: "Facebook",
  manual: "Manual",
}

function getOpportunitySource(opportunity: Opportunity): { label: string; className: string; platform: string } {
  const channel = opportunity.conversation?.channel
  if (channel) {
    return {
      label: channel.name,
      className: channelChipClass(channel.type),
      platform: normalizeSource(channel.type) ?? "manual",
    }
  }

  const source = normalizeSource(
    opportunity.contact.source ?? opportunity.contact.channel_type ?? opportunity.source ?? opportunity.channel_type
  )

  return {
    label: source ? sourceLabelMap[source] ?? source : "Manual",
    className: channelChipClass(source),
    platform: source ?? "manual",
  }
}

// Botones de ícono de la tarjeta: compactos con mouse, blanco de 40px en pantallas táctiles.
const iconActionClassName = "h-6 w-6 p-0 pointer-coarse:size-10"

function OpportunityCard({
  opportunity,
  dragHandleProps,
  isDragging = false,
  isOverlay = false,
  onScheduleMeeting,
}: {
  opportunity: Opportunity
  dragHandleProps?: Record<string, unknown>
  isDragging?: boolean
  isOverlay?: boolean
  onScheduleMeeting?: (opportunity: Opportunity) => void
}) {
  const router = useRouter()
  const lastActivityText = opportunity.last_activity_at
    ? formatDistanceToNow(new Date(opportunity.last_activity_at), { locale: es, addSuffix: true })
    : "Sin actividad"

  const { label: sourceLabel, className: sourceClassName, platform: sourcePlatform } = getOpportunitySource(opportunity)
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
      className={`transition-[box-shadow,transform,opacity] border-border/60 max-md:gap-0 max-md:py-0 ${
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
              className={`h-6 w-6 p-0 touch-none pointer-coarse:size-10 ${isOverlay ? "cursor-grabbing" : "cursor-grab active:cursor-grabbing"}`}
              aria-label={`Mover oportunidad de ${opportunity.contact.name}`}
              {...dragHandleProps}
            >
              <GripVertical className="w-3 h-3" aria-hidden="true" />
            </Button>
          </div>
        </div>

        <div className="space-y-2 mt-3">
          <div className="flex items-center justify-between gap-2">
            <Badge variant="default" className={`inline-flex items-center gap-1 text-xs max-w-[9rem] ${sourceClassName}`}>
              <PlatformIcon type={sourcePlatform} className="size-3.5 shrink-0" aria-hidden="true" />
              <span className="truncate">{sourceLabel}</span>
            </Badge>
            <span className="text-sm font-bold text-foreground tabular-nums">{valueFormatted}</span>
          </div>

          <div className="flex items-center justify-between gap-2 text-xs">
            <div className="flex items-center gap-1">
              <TrendingUp className="w-3 h-3 text-success" />
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

          <div className="flex flex-wrap items-center gap-1 pointer-coarse:gap-0">
            <Button
              variant="ghost"
              size="sm"
              className="h-8 min-h-10 gap-2 px-2 text-muted-foreground hover:text-foreground pointer-coarse:gap-1.5 pointer-coarse:has-[>svg]:px-1.5"
              disabled={!conversationId}
              onClick={openConversation}
              title={conversationId ? "Abrir conversación" : "Sin conversación"}
            >
              <MessageSquare className="w-3.5 h-3.5" />
              <span className="text-xs">{conversationId ? "Abrir chat" : "Sin chat"}</span>
            </Button>
            {opportunity.contact.phone && (
              <Button variant="ghost" size="sm" className={iconActionClassName} asChild>
                <a href={`tel:${opportunity.contact.phone}`} aria-label={`Llamar a ${opportunity.contact.name}`}>
                  <Phone className="w-3 h-3" aria-hidden="true" />
                </a>
              </Button>
            )}
            {opportunity.contact.email && (
              <Button variant="ghost" size="sm" className={iconActionClassName} asChild>
                <a href={`mailto:${opportunity.contact.email}`} aria-label={`Escribir a ${opportunity.contact.name}`}>
                  <Mail className="w-3 h-3" aria-hidden="true" />
                </a>
              </Button>
            )}
            <Button
              variant="ghost"
              size="sm"
              className={iconActionClassName}
              disabled={!onScheduleMeeting}
              onClick={() => onScheduleMeeting?.(opportunity)}
              title="Agendar reunión"
              aria-label={`Agendar reunión con ${opportunity.contact.name}`}
            >
              <Calendar className="w-3 h-3" aria-hidden="true" />
            </Button>
            <MoveToStageButton opportunity={opportunity} />
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function MoveToStageButton({ opportunity }: { opportunity: Opportunity }) {
  const context = useContext(StageMoveContext)
  if (!context || context.stages.length === 0) return null

  const { stages, isMobile, openMoveSheet, moveOpportunity } = context
  const label = `Mover a ${opportunity.contact.name} a otra etapa`

  if (isMobile) {
    return (
      <Button
        variant="ghost"
        size="sm"
        className={iconActionClassName}
        aria-label={label}
        aria-haspopup="dialog"
        title="Mover a etapa"
        onClick={() => openMoveSheet(opportunity)}
      >
        <ArrowRightLeft className="w-3 h-3" aria-hidden="true" />
      </Button>
    )
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="sm" className={iconActionClassName} aria-label={label} title="Mover a etapa">
          <ArrowRightLeft className="w-3 h-3" aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        <DropdownMenuLabel>Mover a etapa</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {stages.map((stage) => {
          const isCurrent = stage.id === opportunity.pipeline_stage_id
          return (
            <DropdownMenuItem
              key={stage.id}
              disabled={isCurrent}
              onSelect={() => void moveOpportunity(opportunity.id, stage.id)}
            >
              <span
                className="size-2.5 shrink-0 rounded-full"
                style={{ backgroundColor: resolveColumnColor(stage.color) }}
                aria-hidden="true"
              />
              <span className="flex-1 truncate">{stage.title}</span>
              {isCurrent && (
                <>
                  <Check className="text-muted-foreground" aria-hidden="true" />
                  <span className="sr-only">(etapa actual)</span>
                </>
              )}
            </DropdownMenuItem>
          )
        })}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

function SortableOpportunityCard({
  opportunity,
  onScheduleMeeting,
}: {
  opportunity: Opportunity
  onScheduleMeeting: (opportunity: Opportunity) => void
}) {
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
        onScheduleMeeting={onScheduleMeeting}
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

function KanbanColumn({
  column,
  opportunities,
  onScheduleMeeting,
}: {
  column: Column
  opportunities: Opportunity[]
  onScheduleMeeting: (opportunity: Opportunity) => void
}) {
  const { setNodeRef, isOver } = useDroppable({
    id: getColumnDropId(column.id),
  })

  const totalValue = opportunities.reduce((sum, item) => sum + Number(item.value || 0), 0)
  const wonCount = opportunities.filter((item) => item.status === "won").length
  const avgProb = opportunities.length > 0 ? Math.round((wonCount / opportunities.length) * 100) : 0
  const stripeColor = resolveColumnColor(column.color)

  return (
    // Teléfono: columna casi a pantalla completa con un asomo de la siguiente, imantada al deslizar;
    // la cabecera queda fija y las tarjetas scrollean dentro de la columna.
    <div className="flex-1 min-w-80 max-md:flex max-md:w-[min(20rem,calc(100vw-3.5rem))] max-md:min-w-0 max-md:flex-none max-md:snap-start max-md:flex-col">
      <Card className={`bg-muted/20 overflow-hidden transition-colors max-md:min-h-0 max-md:flex-1 max-md:gap-0 max-md:py-0 ${isOver ? "ring-2 ring-primary/30 bg-primary/5" : ""}`}>
        <div className="h-1.5 w-full shrink-0" style={{ backgroundColor: stripeColor }} />
        <CardContent className="p-4 max-md:flex max-md:min-h-0 max-md:flex-1 max-md:flex-col max-md:p-2">
          <div className="flex items-center gap-2 mb-2 max-md:px-1 max-md:pt-1">
            <div className="w-3 h-3 rounded-full" style={{ backgroundColor: stripeColor }} />
            <h3 className="font-semibold flex-1 truncate">{column.title}</h3>
            <Badge variant="default" className="ml-1 shrink-0">
              {opportunities.length}
            </Badge>
          </div>

          <div className="space-y-1 mb-4 max-md:mb-3 max-md:px-1">
            <p className="text-sm font-medium">{formatColumnCurrency(totalValue)}</p>
            <p className="text-xs text-muted-foreground">Prob. promedio: {avgProb}%</p>
          </div>

          <SortableContext items={opportunities.map((item) => item.id.toString())} strategy={verticalListSortingStrategy}>
            <div
              ref={setNodeRef}
              className={`space-y-2 min-h-96 rounded-md border border-transparent transition-colors max-md:min-h-0 max-md:flex-1 max-md:overflow-y-auto max-md:overscroll-contain ${
                isOver ? "bg-primary/5 border-primary/30" : ""
              }`}
            >
              {opportunities.map((opportunity) => (
                <SortableOpportunityCard key={opportunity.id} opportunity={opportunity} onScheduleMeeting={onScheduleMeeting} />
              ))}

              {opportunities.length === 0 && (
                <div className={`flex items-center justify-center h-32 text-sm rounded-md border border-dashed ${
                  isOver ? "border-primary/40 text-primary" : "border-border text-muted-foreground"
                }`}>
                  <span className="md:hidden">Sin oportunidades</span>
                  <span className="max-md:hidden">Arrastrá leads acá</span>
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
  pipelineId: number
  /** Filtros del encabezado; se aplican sobre lo ya cargado, sin volver a pedir datos. */
  filters?: PipelineFilters
  onClearFilters?: () => void
  /** Abre el gestor de etapas; sin él el estado vacío no ofrece acción (falta permiso). */
  onManageStages?: () => void
}

// Saca la oportunidad de su columna y la pone al final de otra; sirve para mover y para deshacer.
function relocateOpportunity(columns: Column[], opportunity: Opportunity, fromId: number, toId: number): Column[] {
  return columns.map((col) => {
    if (col.id === fromId) return { ...col, opportunities: col.opportunities.filter((item) => item.id !== opportunity.id) }
    if (col.id === toId) return { ...col, opportunities: [...col.opportunities, opportunity] }
    return col
  })
}

// Minúsculas y sin tildes para que "jose" encuentre "José".
function normalizeText(value: string): string {
  return value.normalize("NFD").replace(/\p{Diacritic}/gu, "").toLowerCase()
}

// Rangos [mínimo, máximo) alineados con las etiquetas del filtro de valor.
const VALUE_RANGES: Record<Exclude<PipelineFilters["value"], "all">, [number, number]> = {
  low: [0, 10_000],
  medium: [10_000, 50_000],
  high: [50_000, Infinity],
}

function getPeriodStart(period: PipelineFilters["period"]): number | null {
  if (period === "all") return null
  const start = new Date()
  start.setHours(0, 0, 0, 0)
  // La semana arranca el lunes.
  if (period === "week") start.setDate(start.getDate() - ((start.getDay() + 6) % 7))
  if (period === "month") start.setDate(1)
  return start.getTime()
}

/** Devuelve null si no hay filtros activos, para no recorrer el tablero en vano. */
function createOpportunityMatcher(filters: PipelineFilters): ((opportunity: Opportunity) => boolean) | null {
  if (!hasActiveFilters(filters)) return null

  const query = normalizeText(filters.search.trim())
  const queryDigits = query.replace(/\D/g, "")
  // Si la búsqueda parece un teléfono, se compara sólo por dígitos ("11 5555" encuentra "+541155550000").
  const isPhoneQuery = queryDigits.length > 0 && /^[\d\s()+-]+$/.test(query)
  const periodStart = getPeriodStart(filters.period)
  const valueRange = filters.value === "all" ? null : VALUE_RANGES[filters.value]

  return (opportunity) => {
    if (filters.status !== "all" && opportunity.status !== filters.status) return false
    if (filters.channel !== "all" && getOpportunitySource(opportunity).platform !== filters.channel) return false
    if (filters.assignee !== "all" && String(opportunity.assigned_to ?? "none") !== filters.assignee) return false
    if (valueRange) {
      // Sin valor cargado no entra en ningún rango.
      if (opportunity.value === null || opportunity.value === undefined || opportunity.value === "") return false
      const value = Number(opportunity.value)
      if (Number.isNaN(value) || value < valueRange[0] || value >= valueRange[1]) return false
    }
    if (periodStart !== null) {
      const activityAt = opportunity.last_activity_at ? new Date(opportunity.last_activity_at).getTime() : Number.NaN
      if (!(activityAt >= periodStart)) return false
    }
    if (!query) return true

    const { contact } = opportunity
    if ([opportunity.title, contact.name, contact.phone, contact.email].some((field) => field && normalizeText(field).includes(query))) {
      return true
    }
    return isPhoneQuery && (contact.phone ?? "").replace(/\D/g, "").includes(queryDigits)
  }
}

export function KanbanBoard({ refreshKey = 0, pipelineId, filters = EMPTY_PIPELINE_FILTERS, onClearFilters, onManageStages }: KanbanBoardProps) {
  const [columns, setColumns] = useState<Column[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [activeOpportunityId, setActiveOpportunityId] = useState<number | null>(null)
  // Oportunidad para la que se está agendando una reunión desde su tarjeta.
  const [meetingOpportunity, setMeetingOpportunity] = useState<Opportunity | null>(null)
  const currentUser = useAuthStore((state) => state.user)
  const { addToast } = useToast()
  const isMobile = useIsMobile()
  // Oportunidad cuyo destino se elige en la hoja inferior (sólo teléfono).
  const [movingOpportunity, setMovingOpportunity] = useState<Opportunity | null>(null)

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

  const dndAccessibility = useDndAccessibility(
    (id) => {
      const opportunity = columns.flatMap((column) => column.opportunities).find((item) => item.id === Number(id))
      return opportunity ? opportunity.contact?.name : undefined
    },
    (id) => {
      const overId = String(id)
      const column = overId.startsWith("column-")
        ? columns.find((col) => col.id === Number(overId.replace("column-", "")))
        : columns.find((col) => col.opportunities.some((item) => item.id === Number(overId)))
      return column?.title
    },
  )

  const fetchPipelineData = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const [stages, opportunities] = await Promise.all([
        fetchPipelineStages(pipelineId),
        getOpportunities({ pipelineId }),
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
      setError(err instanceof Error ? err.message : "Revisá tu conexión e intentá de nuevo.")
    } finally {
      setLoading(false)
    }
  }, [pipelineId])

  useEffect(() => {
    fetchPipelineData()
  }, [fetchPipelineData, refreshKey])

  const deferredSearch = useDeferredValue(filters.search)
  const { channel, assignee, status, value, period } = filters
  // Se filtra al mostrar: `columns` conserva todo lo cargado, así arrastrar y mover siguen operando sobre el tablero completo.
  const visibleColumns = useMemo(() => {
    const matches = createOpportunityMatcher({ search: deferredSearch, channel, assignee, status, value, period })
    if (!matches) return columns
    return columns.map((col) => ({ ...col, opportunities: col.opportunities.filter(matches) }))
  }, [columns, deferredSearch, channel, assignee, status, value, period])

  const loadedOpportunities = columns.reduce((sum, col) => sum + col.opportunities.length, 0)
  const totalOpportunities = visibleColumns.reduce((sum, col) => sum + col.opportunities.length, 0)
  const avgValue =
    totalOpportunities > 0
      ? Math.round(
          visibleColumns.reduce(
            (sum, col) => sum + col.opportunities.reduce((colSum, item) => colSum + Number(item.value || 0), 0),
            0
          ) / totalOpportunities
        )
      : 0
  const filtersHideAll = loadedOpportunities > 0 && totalOpportunities === 0 && hasActiveFilters(filters)

  const activeOpportunity =
    activeOpportunityId === null
      ? null
      : columns.flatMap((column) => column.opportunities).find((item) => item.id === activeOpportunityId) ?? null

  // Camino único para cambiar de etapa: lo usan el arrastre y la acción "Mover a etapa".
  const moveOpportunity = useCallback(
    async (opportunityId: number, destColumnId: number) => {
      const sourceColumn = columns.find((col) => col.opportunities.some((item) => item.id === opportunityId))
      const destColumn = columns.find((col) => col.id === destColumnId)
      if (!sourceColumn || !destColumn || sourceColumn.id === destColumn.id) return

      const sourceOpportunity = sourceColumn.opportunities.find((item) => item.id === opportunityId)
      if (!sourceOpportunity) return
      // "Sin asignar" no es una etapa real: no se puede mover hacia ahí.
      if (destColumn.id === 0) return

      // Optimista: la tarjeta queda en destino mientras guarda y vuelve si el servidor falla.
      const movedOpportunity = { ...sourceOpportunity, pipeline_stage_id: destColumn.id }
      setColumns((prev) => relocateOpportunity(prev, movedOpportunity, sourceColumn.id, destColumn.id))

      try {
        await updateOpportunityStage(opportunityId, destColumn.id, pipelineId)
        addToast({
          type: "success",
          title: "Oportunidad movida",
          description: `${sourceOpportunity.contact.name} pasó a ${destColumn.title}.`,
        })
      } catch {
        setColumns((prev) => relocateOpportunity(prev, sourceOpportunity, destColumn.id, sourceColumn.id))
        addToast({
          type: "error",
          title: "No se pudo mover la oportunidad",
          description: `${sourceOpportunity.contact.name} volvió a ${sourceColumn.title}. Intentá de nuevo.`,
        })
      }
    },
    [columns, pipelineId, addToast]
  )

  const stageMoveContext = useMemo<StageMoveContextValue>(
    () => ({
      stages: columns.filter((col) => col.id !== 0).map(({ id, title, color }) => ({ id, title, color })),
      isMobile,
      openMoveSheet: setMovingOpportunity,
      moveOpportunity,
    }),
    [columns, isMobile, moveOpportunity]
  )

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

    if (sourceColumn.id !== destColumn.id) {
      await moveOpportunity(activeId, destColumn.id)
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
      <div role="status" className="flex items-center justify-center py-12">
        <Loader2 className="w-8 h-8 animate-spin text-muted-foreground" aria-hidden="true" />
        <span className="sr-only">Cargando embudo</span>
      </div>
    )
  }

  if (error) {
    return (
      <div role="alert" className="text-center py-12">
        <p className="font-medium text-destructive">No se pudo cargar el embudo.</p>
        <p className="mt-1 mb-4 text-sm text-muted-foreground">{error}</p>
        <Button onClick={fetchPipelineData} variant="outline">
          Reintentar
        </Button>
      </div>
    )
  }

  const hasStages = columns.some((col) => col.id !== 0)
  if (!hasStages && loadedOpportunities === 0) {
    return (
      <EmptyState
        icon={Columns3}
        title="Este embudo no tiene etapas"
        description={
          onManageStages
            ? "Creá las etapas para empezar a mover oportunidades por el tablero."
            : "Pedile a un administrador que configure las etapas de este embudo."
        }
        action={onManageStages ? { label: "Configurar etapas", onClick: onManageStages } : undefined}
      />
    )
  }

  // Sin "Etapas del pipeline": las columnas ya están a la vista.
  const kpis = [
    { value: totalOpportunities, label: "Total de oportunidades" },
    { value: formatColumnCurrency(avgValue), label: "Valor promedio" },
  ]

  return (
    <div className="space-y-6 max-md:flex max-md:min-h-0 max-md:flex-1 max-md:flex-col max-md:gap-3 max-md:space-y-0">
      <StatStrip stats={kpis} />

      {filtersHideAll && (
        <div role="status" className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm">
          <p className="text-muted-foreground">Ninguna oportunidad coincide con los filtros.</p>
          {onClearFilters && (
            <Button variant="outline" size="sm" onClick={onClearFilters}>
              Limpiar filtros
            </Button>
          )}
        </div>
      )}

      <StageMoveContext.Provider value={stageMoveContext}>
        <DndContext
          sensors={sensors}
          accessibility={dndAccessibility}
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
          <div className="flex gap-6 overflow-x-auto pb-4 max-md:-mx-4 max-md:min-h-0 max-md:flex-1 max-md:snap-x max-md:snap-mandatory max-md:scroll-px-4 max-md:gap-3 max-md:px-4 max-md:pb-1">
            {visibleColumns.map((column) => (
              <KanbanColumn
                key={column.id}
                column={column}
                opportunities={column.opportunities}
                onScheduleMeeting={setMeetingOpportunity}
              />
            ))}
          </div>
          <DragOverlay dropAnimation={null}>
            {activeOpportunity ? <OpportunityCard opportunity={activeOpportunity} isOverlay /> : null}
          </DragOverlay>
        </DndContext>
      </StageMoveContext.Provider>

      <Sheet
        open={movingOpportunity !== null}
        onOpenChange={(open) => {
          if (!open) setMovingOpportunity(null)
        }}
      >
        <SheetContent side="bottom" className="max-h-[85dvh] gap-0 overflow-y-auto rounded-t-2xl pb-[env(safe-area-inset-bottom)]">
          <SheetHeader className="pb-1">
            <SheetTitle className="text-base">Mover a etapa</SheetTitle>
            <SheetDescription className="truncate text-left text-xs">
              {movingOpportunity ? `${movingOpportunity.contact.name}: ${movingOpportunity.title}` : ""}
            </SheetDescription>
          </SheetHeader>
          <div className="flex flex-col px-2 pb-6">
            {movingOpportunity && stageMoveContext.stages.map((stage) => {
              const isCurrent = stage.id === movingOpportunity.pipeline_stage_id
              return (
                <button
                  key={stage.id}
                  type="button"
                  disabled={isCurrent}
                  aria-current={isCurrent ? "true" : undefined}
                  className="flex min-h-12 items-center gap-3 rounded-lg px-3 text-left text-sm transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-default disabled:hover:bg-transparent"
                  onClick={() => {
                    const { id } = movingOpportunity
                    setMovingOpportunity(null)
                    void moveOpportunity(id, stage.id)
                  }}
                >
                  <span
                    className="size-3 shrink-0 rounded-full"
                    style={{ backgroundColor: resolveColumnColor(stage.color) }}
                    aria-hidden="true"
                  />
                  <span className="flex-1 truncate font-medium">{stage.title}</span>
                  {isCurrent && (
                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                      <Check className="size-4" aria-hidden="true" />
                      Etapa actual
                    </span>
                  )}
                </button>
              )
            })}
          </div>
        </SheetContent>
      </Sheet>

      {meetingOpportunity && (
        <NewTaskModal
          key={meetingOpportunity.id}
          open
          onOpenChange={(open) => {
            if (!open) setMeetingOpportunity(null)
          }}
          onCreateTask={() => undefined}
          prefilledData={{
            type: "reunion",
            name: `Reunión con ${meetingOpportunity.contact.name}`,
            relationType: "pipeline",
            relationId: String(meetingOpportunity.id),
            relationLabel: meetingOpportunity.title || meetingOpportunity.contact.name,
            lockRelation: true,
            ...(meetingOpportunity.contact.email ? { meetingGuestEmail: meetingOpportunity.contact.email } : {}),
            ...(currentUser ? { assigneeId: String(currentUser.id) } : {}),
          }}
        />
      )}
    </div>
  )
}
