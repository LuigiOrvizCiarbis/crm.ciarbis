"use client"

import { useState, useEffect, useCallback, useRef, useId } from "react"
import { Button } from "@/components/ui/button"
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet"
import { useToast } from "@/components/Toast"
import { AlertCircle, Archive, Briefcase, ChevronDown, Loader2, Mail, MailOpen, Plus, Tag as TagLucideIcon } from "lucide-react"
import { fetchPipelineStages, PipelineStage } from "@/lib/api/pipeline"
import { fetchUsers } from "@/lib/api/users"
import { updateConversationStage, assignConversationUser } from "@/lib/api/conversations"
import { getOpportunities, updateOpportunityStage } from "@/lib/api/opportunities"
import { ChatOpportunityRail, type ChatOpportunity } from "@/components/ChatOpportunityRail"
import { ContactPipelineDialog } from "@/components/contact-pipeline-dialog"
import type { Tag } from "@/lib/api/tags"
import { useTranslation } from "@/hooks/useTranslation"
import { TagChips } from "@/components/tags/TagChips"
import { TagPicker } from "@/components/tags/TagPicker"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"

// Types
type Priority = "baja" | "media" | "alta" | "hot"

interface TeamUser {
  id: number
  name: string
  role: string
}

function StageDot({ color }: { color?: string }) {
  return <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-muted-foreground" style={color ? { backgroundColor: color } : undefined} />
}

/** Error compacto dentro de un control que no pudo cargar sus opciones. */
function LoadError({ message, retryLabel, onRetry }: { message: string; retryLabel: string; onRetry: () => void }) {
  return (
    <span className="inline-flex min-w-0 items-center gap-1.5 text-destructive">
      <AlertCircle className="size-3.5 shrink-0" aria-hidden="true" />
      <span className="truncate">{message}</span>
      <button
        type="button"
        onClick={onRetry}
        className="shrink-0 font-medium underline underline-offset-2 hover:no-underline focus-visible:outline-none"
      >
        {retryLabel}
      </button>
    </span>
  )
}

interface ChatQuickBarValue {
  stageId?: number
  priority?: Priority
  assigneeId?: number | string
  archived?: boolean
}

interface ChatQuickBarProps {
  chatId: number
  /** Contacto del chat; null en grupos, que no pueden tener oportunidades. */
  contact?: { id: number; name: string } | null
  value: ChatQuickBarValue
  team: TeamUser[]
  onChangeStage: (stageId: number, stageName: string) => void
  onChangePriority: (priority: Priority) => void
  onChangeAssignee: (id: number) => void
  onToggleArchive: () => void
  isUnread?: boolean
  onToggleReadStatus?: () => void
  tags?: Tag[]
  onTagsChange?: (tags: Tag[]) => void
  /** Etapas de las oportunidades del chat, cada vez que se cargan o se mueven. */
  onOpportunityStagesChange?: (chatId: number, stageIds: number[]) => void
}

// Los controles van sin borde dentro de su "pastilla"; el anillo de foco se dibuja en la pastilla.
const PILL_FOCUS_RING = "has-[:focus-visible]:border-ring has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50"

// Eliminado TEAM fijo: ahora se cargan usuarios reales desde API. Se mantiene posibilidad de pasar prop "team" como override.

export function ChatQuickBar({
  chatId,
  contact = null,
  value,
  team = [],
  onChangeStage,
  onChangePriority,
  onChangeAssignee,
  onToggleArchive,
  isUnread = false,
  onToggleReadStatus,
  tags = [],
  onTagsChange,
  onOpportunityStagesChange,
}: ChatQuickBarProps) {
  const { addToast } = useToast()
  const { t } = useTranslation()
  const fieldId = useId()
  const stageLabelId = `${fieldId}-stage`
  const priorityLabelId = `${fieldId}-priority`
  const assigneeLabelId = `${fieldId}-assignee`
  const [isTagsSheetOpen, setIsTagsSheetOpen] = useState(false)
  const [isManageSheetOpen, setIsManageSheetOpen] = useState(false)

  // Etapa del chat (pipeline predeterminado): dispara automatizaciones y filtra campañas.
  const [pipelineStages, setPipelineStages] = useState<PipelineStage[]>([])
  const [isLoadingStages, setIsLoadingStages] = useState(true)
  const [stagesFailed, setStagesFailed] = useState(false)
  // Oportunidades vinculadas al chat; cada una se mueve dentro de su propio pipeline.
  // Las oportunidades se guardan con el chat al que pertenecen: el componente
  // no se remonta al cambiar de chat y una respuesta tardía no debe mostrarse
  // (ni moverse) en otro.
  const [loadedOpportunities, setLoadedOpportunities] = useState<{ chatId: number; items: ChatOpportunity[] } | null>(null)
  const opportunities = loadedOpportunities?.chatId === chatId ? loadedOpportunities.items : []
  // Chat cuyas oportunidades (o las etapas de sus pipelines) no se pudieron cargar.
  const [opportunitiesFailedChatId, setOpportunitiesFailedChatId] = useState<number | null>(null)
  const [pipelineStagesFailed, setPipelineStagesFailed] = useState(false)
  const [pipelineStagesReloadKey, setPipelineStagesReloadKey] = useState(0)
  const currentChatIdRef = useRef(chatId)
  const [stagesByPipeline, setStagesByPipeline] = useState<Record<number, PipelineStage[]>>({})
  const [isNewOpportunityOpen, setIsNewOpportunityOpen] = useState(false)
  const [pendingOpportunityId, setPendingOpportunityId] = useState<number | null>(null)
  const [users, setUsers] = useState<TeamUser[]>([])
  const [isLoadingUsers, setIsLoadingUsers] = useState(true)
  const [usersFailed, setUsersFailed] = useState(false)

  // Helpers de normalización
  const toNumber = (v: unknown): number | undefined => {
    if (typeof v === 'number' && Number.isFinite(v)) return v
    if (typeof v === 'string') {
      const n = parseInt(v, 10)
      return Number.isFinite(n) ? n : undefined
    }
    return undefined
  }

  const loadStages = useCallback(async () => {
    setIsLoadingStages(true)
    setStagesFailed(false)
    try {
      setPipelineStages(await fetchPipelineStages())
    } catch {
      setStagesFailed(true)
    } finally {
      setIsLoadingStages(false)
    }
  }, [])

  const loadUsers = useCallback(async () => {
    setIsLoadingUsers(true)
    setUsersFailed(false)
    try {
      const fetchedUsers = await fetchUsers()
      setUsers(fetchedUsers.map((user) => ({
        id: user.id,
        name: user.name,
        role: typeof user.role === "string"
          ? user.role
          : user.role?.name || "Vendedor",
      })))
    } catch {
      setUsersFailed(true)
    } finally {
      setIsLoadingUsers(false)
    }
  }, [])

  useEffect(() => {
    void loadStages()
    void loadUsers()
  }, [loadStages, loadUsers])

  useEffect(() => {
    currentChatIdRef.current = chatId
  }, [chatId])

  // Avisa a la bandeja las etapas actuales: su filtro por etapa lee de ahí.
  const onOpportunityStagesChangeRef = useRef(onOpportunityStagesChange)
  useEffect(() => {
    onOpportunityStagesChangeRef.current = onOpportunityStagesChange
  })
  const opportunityStagesKey = loadedOpportunities
    ? `${loadedOpportunities.chatId}:${loadedOpportunities.items.map((item) => item.pipeline_stage_id ?? "").join(",")}`
    : ""
  useEffect(() => {
    if (!loadedOpportunities) return
    const stageIds = loadedOpportunities.items.flatMap((item) => item.pipeline_stage_id ? [item.pipeline_stage_id] : [])
    onOpportunityStagesChangeRef.current?.(loadedOpportunities.chatId, [...new Set(stageIds)])
    // eslint-disable-next-line react-hooks/exhaustive-deps -- sólo cuando cambian las etapas
  }, [opportunityStagesKey])

  /** Trae las oportunidades del chat; descarta la respuesta si ya se cambió de chat. */
  const fetchOpportunities = useCallback(async (targetChatId: number): Promise<ChatOpportunity[] | null> => {
    let items: ChatOpportunity[]
    try {
      items = (await getOpportunities({ conversationId: targetChatId })) as ChatOpportunity[]
    } catch {
      // Sin pisar las etapas que la bandeja ya conoce: sólo se marca el error.
      if (currentChatIdRef.current === targetChatId) setOpportunitiesFailedChatId(targetChatId)
      return null
    }
    if (currentChatIdRef.current !== targetChatId) return null
    setOpportunitiesFailedChatId(null)
    setLoadedOpportunities({ chatId: targetChatId, items })
    return items
  }, [])

  useEffect(() => {
    void fetchOpportunities(chatId)
  }, [chatId, fetchOpportunities])

  // Etapas de cada pipeline presente en las oportunidades del chat.
  const pipelineIdsKey = [...new Set(opportunities.map((item) => item.pipeline_id))].sort((a, b) => a - b).join(",")

  useEffect(() => {
    if (!pipelineIdsKey) return
    let cancelled = false
    const pipelineIds = pipelineIdsKey.split(",").map(Number)
    Promise.allSettled(
      pipelineIds.map(async (pipelineId) => {
        const stages = await fetchPipelineStages(pipelineId)
        return [...stages].sort((a, b) => a.sort_order - b.sort_order)
      }),
    ).then((results) => {
      if (cancelled) return
      setStagesByPipeline(Object.fromEntries(results.map((result, index) => (
        [pipelineIds[index], result.status === "fulfilled" ? result.value : []]
      ))))
      setPipelineStagesFailed(results.some((result) => result.status === "rejected"))
    })
    return () => {
      cancelled = true
    }
  }, [pipelineIdsKey, pipelineStagesReloadKey])

  const opportunitiesFailed = opportunitiesFailedChatId === chatId || (pipelineStagesFailed && opportunities.length > 0)
  const retryOpportunities = () => {
    if (opportunitiesFailedChatId === chatId) void fetchOpportunities(chatId)
    if (pipelineStagesFailed) setPipelineStagesReloadKey((key) => key + 1)
  }

  const handleOpportunityCreated = async () => {
    const before = new Set(opportunities.map((item) => item.id))
    const items = await fetchOpportunities(chatId)
    if (!items) return
    const created = items.find((item) => !before.has(item.id))
    // El backend ya movió la etapa del chat; reflejarlo en la barra.
    if (created?.pipeline?.is_default && created.pipeline_stage_id) {
      const chatStage = pipelineStages.find((s) => s.id === created.pipeline_stage_id)
      if (chatStage) onChangeStage(chatStage.id, chatStage.name)
    }
    addToast({ type: "success", title: t("chats.opportunityCreated"), description: created?.title ?? t("chats.opportunityLinkedToChat") })
  }

  const handleOpportunityStageChange = (opportunity: ChatOpportunity, stageId: number) => {
    const stage = stagesByPipeline[opportunity.pipeline_id]?.find((s) => s.id === stageId)
    if (!stage || stageId === opportunity.pipeline_stage_id) return

    const originChatId = chatId
    setPendingOpportunityId(opportunity.id)
    updateOpportunityStage(opportunity.id, stageId, opportunity.pipeline_id)
      .then(() => {
        setLoadedOpportunities((current) => current?.chatId === originChatId
          ? { ...current, items: current.items.map((item) => item.id === opportunity.id ? { ...item, pipeline_stage_id: stageId } : item) }
          : current)
        // Si ya se cambió de chat, la etapa del chat sincronizada es del anterior.
        if (opportunity.pipeline?.is_default && currentChatIdRef.current === originChatId) onChangeStage(stageId, stage.name)
        addToast({
          type: "success",
          title: t("chats.opportunityUpdated"),
          description: `${opportunity.title} → ${stage.name}`,
        })
      })
      .catch((error) => {
        addToast({
          type: "error",
          title: t("chats.stageUpdateError"),
          description: error.message,
        })
      })
      .finally(() => setPendingOpportunityId(null))
  }

  const openNewOpportunity = () => setIsNewOpportunityOpen(true)

  const handleStageChange = (stageId: number) => {
    // Encontrar la etapa por ID
    const stage = pipelineStages.find(s => s.id === stageId);
    if (!stage) {
      addToast({
        type: "error",
        title: t("chats.genericError"),
        description: t("chats.stageNotFound"),
      });
      return;
    }

    // Llamar a la API para actualizar (ahora con el ID numérico)
    const originChatId = chatId
    updateConversationStage(chatId, stageId)
      .then(() => {
        // ✅ IMPORTANTE: Notificar al padre para que actualice su estado
        onChangeStage(stageId, stage.name);
        // El backend creó la oportunidad del chat: mostrarla en el riel.
        void fetchOpportunities(originChatId);

        addToast({
          type: "success",
          title: t("chats.stageUpdated"),
          description: `${t("chats.stageChangedTo")} ${stage.name}`,
        });
      })
      .catch((error) => {
        addToast({
          type: "error",
          title: t("chats.stageUpdateError"),
          description: error.message,
        });
      });
  };

  // Keyboard shortcuts
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      const tag = (e.target as HTMLElement)?.tagName
      const isInput = tag === "INPUT" || tag === "TEXTAREA" || (e.target as HTMLElement)?.isContentEditable
      if (isInput) return

      if (e.key === "e" || e.key === "E") {
        e.preventDefault()
        onToggleArchive()
        addToast({
          type: "success",
          title: value.archived ? t("chats.chatUnarchived") : t("chats.chatArchived"),
          description: value.archived ? t("chats.chatUnarchivedDesc") : t("chats.chatArchivedDesc"),
        })
      }
    }

    window.addEventListener("keydown", handleKeyDown)
    return () => window.removeEventListener("keydown", handleKeyDown)
  }, [onToggleArchive, value.archived, addToast, t])

  const handlePriorityChange = (priority: Priority) => {
    onChangePriority(priority)
    addToast({
      type: "success",
      title: t("chats.priorityUpdated"),
      description: `${t("chats.priorityChangedTo")} ${getPriorityLabel(priority)}`,
    })
  }

  const handleAssigneeChange = async (assigneeId: number) => {
    const effectiveTeam = team.length ? team : users
    const assignee = effectiveTeam.find((t) => t.id === assigneeId)


    try {
      // Llamar al backend para guardar
      const response = await assignConversationUser(chatId, assigneeId)

      // Notificar al componente padre
      onChangeAssignee(assigneeId)

      addToast({
        type: "success",
        title: t("chats.chatAssigned"),
        description: `${t("chats.chatAssignedTo")} ${assignee?.name}`,
      })
    } catch (error) {
      console.error("Error asignando usuario:", error)
      addToast({
        type: "error",
        title: t("chats.assignError"),
        description: error instanceof Error ? error.message : t("chats.assignErrorDesc"),
      })
    }
  }

  const handleToggleArchive = () => {
    onToggleArchive()
    addToast({
      type: "success",
      title: value.archived ? t("chats.chatUnarchived") : t("chats.chatArchived"),
      description: value.archived ? t("chats.chatUnarchivedDesc") : t("chats.chatArchivedDesc"),
    })
  }

  /**
   * Los hex fijos anteriores (#9AA4B2, #00E18C, #00F7FF) estaban elegidos sólo
   * para el tema oscuro: sobre fondo claro daban 2.4:1, 1.7:1 y 1.28:1 de
   * contraste, los tres por debajo del mínimo AA de 4.5:1 ("Alta" en cian sobre
   * blanco era prácticamente invisible). Cada nivel lleva ahora un tono por
   * tema: el oscuro para fondo claro, el claro para fondo oscuro.
   */
  const getPriorityColor = (priority: Priority) => {
    switch (priority) {
      case "media":
        return "text-emerald-700 dark:text-emerald-400"
      case "alta":
        return "text-cyan-700 dark:text-cyan-300"
      case "hot":
        return "text-red-600 dark:text-red-400"
      case "baja":
      default:
        return "text-slate-600 dark:text-slate-400"
    }
  }

  const getPriorityLabel = (priority: Priority) => {
    switch (priority) {
      case "baja":
        return t("chats.priorityLow")
      case "media":
        return t("chats.priorityMedium")
      case "alta":
        return t("chats.priorityHigh")
      case "hot":
        return t("chats.priorityHot")
      default:
        return t("chats.priorityLow")
    }
  }

  return (
    // Derivados calculados para selects
    (() => {
      const effectiveTeam = team.length ? team : users
      const desktopFallbackId = effectiveTeam[0]?.id
      const selectedAssigneeId = toNumber(value.assigneeId) ?? desktopFallbackId ?? ''
      const selectedAssigneeName = effectiveTeam.find((m) => String(m.id) === String(selectedAssigneeId))?.name || t("chats.select")
      const selectedStageName = pipelineStages.find((s) => s.id === value.stageId)?.name || t("chats.select")
      const chatStageColor = pipelineStages.find((s) => s.id === value.stageId)?.color
      // Con una oportunidad en el pipeline predeterminado, la etapa del chat
      // la sigue y el riel ya la muestra: el selector sobra.
      const showChatStage = !opportunities.some((item) => item.pipeline?.is_default)
      const hasOpportunities = Boolean(contact) && opportunities.length > 0

      return (
    <div className="sticky top-0 z-10 rounded-xl border border-border bg-card/80 p-1.5 backdrop-blur sm:p-2">
      {/* Desktop Layout */}
      <div className="hidden md:flex items-center gap-2">
        {/* Stage Select: etapa del chat (sólo si ninguna oportunidad la maneja) */}
        {showChatStage && (
        <div className={`inline-flex shrink-0 items-center gap-2 text-[12px] text-muted-foreground bg-muted border border-border rounded-xl px-3 py-1.5 ${PILL_FOCUS_RING}`}>
          <StageDot color={chatStageColor} />
          <span id={stageLabelId}>{t("chats.chatStageLabel")}:</span>
          {isLoadingStages ? (
            <Loader2 className="h-3 w-3 animate-spin" />
          ) : stagesFailed ? (
            <LoadError message={t("chats.stagesLoadError")} retryLabel={t("chats.retry")} onRetry={() => void loadStages()} />
          ) : (
            <Select
              key={`${value.stageId}-${pipelineStages.length}`}
              value={value.stageId?.toString() || ""}
              onValueChange={(val) => handleStageChange(Number(val))}
            >
              <SelectTrigger
                id={`${stageLabelId}-trigger`}
                aria-labelledby={`${stageLabelId} ${stageLabelId}-trigger`}
                className="h-auto w-35 border-0 bg-transparent p-0 text-foreground text-sm shadow-none focus-visible:ring-0 gap-2 hover:bg-transparent data-placeholder:text-foreground"
              >
                <SelectValue placeholder={t("chats.select")} className="truncate">
                  <span className="truncate block w-full text-left">
                    {selectedStageName}
                  </span>
                </SelectValue>
              </SelectTrigger>
              <SelectContent>
                {pipelineStages.map((stage) => (
                  <SelectItem
                    key={stage.id}
                    value={String(stage.id)}
                    className="cursor-pointer"
                  >
                    {stage.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </div>
        )}

        {/* Priority Select */}
        <div className={`inline-flex items-center gap-2 text-[12px] text-muted-foreground bg-muted border border-border rounded-xl px-3 py-1.5 ${PILL_FOCUS_RING}`}>
          <span id={priorityLabelId}>{t("chats.priorityLabel")}:</span>
          <select
            value={value.priority || "baja"}
            onChange={(e) => handlePriorityChange(e.target.value as Priority)}
            aria-labelledby={priorityLabelId}
            className={`bg-transparent text-sm focus-visible:outline-none ${getPriorityColor(value.priority || "baja")}`}
          >
            <option value="baja">{t("chats.priorityLow")}</option>
            <option value="media">{t("chats.priorityMedium")}</option>
            <option value="alta">{t("chats.priorityHigh")}</option>
            <option value="hot">{t("chats.priorityHot")}</option>
          </select>
        </div>

        {/* Assignee Select (shadcn Select) */}
        <div className={`inline-flex items-center gap-2 text-[12px] text-muted-foreground bg-muted border border-border rounded-xl px-3 py-1.5 ${PILL_FOCUS_RING}`}>
          <span id={assigneeLabelId}>{t("chats.assignToLabel")}:</span>
          {isLoadingUsers ? (
            <Loader2 className="h-3 w-3 animate-spin" />
          ) : usersFailed && !team.length ? (
            <LoadError message={t("chats.usersLoadError")} retryLabel={t("chats.retry")} onRetry={() => void loadUsers()} />
          ) : (
            <Select
              value={String(selectedAssigneeId)}
              onValueChange={(val) => handleAssigneeChange(Number(val))}
            >
              <SelectTrigger
                id={`${assigneeLabelId}-trigger`}
                aria-labelledby={`${assigneeLabelId} ${assigneeLabelId}-trigger`}
                className="h-auto w-40 border-0 bg-transparent p-0 text-foreground text-sm shadow-none focus-visible:ring-0 gap-2"
              >
                <SelectValue placeholder={t("chats.select")} className="truncate">
                  <span className="truncate block w-full text-left">
                    {selectedAssigneeName}
                  </span>
                </SelectValue>
              </SelectTrigger>
              <SelectContent className="max-h-64 overflow-y-auto">
                {effectiveTeam.map((member) => (
                  <SelectItem key={member.id} value={String(member.id)} className="cursor-pointer">
                    {member.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </div>

        {/* Etiquetas: una sola línea (un chip + "+N") y el selector como ícono, para que la
            barra no se desborde en laptops; la lista completa está en el selector. */}
        <div className="flex min-w-0 max-w-[280px] items-center gap-1.5 rounded-xl border border-border bg-muted px-2 py-1.5">
          <TagChips tags={tags} maxVisible={1} className="min-w-0 flex-nowrap overflow-hidden" />
          <TagPicker
            target="conversation"
            targetId={chatId}
            value={tags}
            onChange={onTagsChange}
            buttonLabel=""
            compact
            className="shrink-0"
          />
        </div>

        {/* Action Buttons */}
        <div className="flex items-center gap-1 ml-auto">
          {/* Sin oportunidades: acceso discreto para crear la primera */}
          {contact && !hasOpportunities && (
            <Button
              variant="ghost"
              size="sm"
              className="relative p-2 rounded-lg border border-border hover:bg-accent text-foreground"
              onClick={openNewOpportunity}
              title={t("chats.newOpportunity")}
              aria-label={t("chats.newOpportunity")}
            >
              <Briefcase className="w-4 h-4" />
              <Plus className="absolute bottom-1 right-1 h-2.5 w-2.5 rounded-full bg-background stroke-[3]" aria-hidden="true" />
            </Button>
          )}
          {onToggleReadStatus && (
            <Button
              variant="ghost"
              size="sm"
              className="p-2 rounded-lg border border-border hover:bg-accent text-foreground"
              onClick={onToggleReadStatus}
              title={isUnread ? t("chats.markAsRead") : t("chats.markAsUnread")}
              aria-label={isUnread ? t("chats.markAsRead") : t("chats.markAsUnread")}
            >
              {isUnread ? <Mail className="w-4 h-4" /> : <MailOpen className="w-4 h-4" />}
            </Button>
          )}
          <Button
            variant="ghost"
            size="sm"
            className="p-2 rounded-lg border border-border hover:bg-accent text-foreground"
            onClick={handleToggleArchive}
            title={value.archived ? t("chats.unarchiveShortcut") : t("chats.archiveShortcut")}
            aria-label={value.archived ? t("chats.unarchiveShortcut") : t("chats.archiveShortcut")}
          >
            <Archive className="w-4 h-4" aria-hidden="true" />
          </Button>
        </div>
      </div>

      {/* Mobile Layout: una sola fila, sin scroll horizontal.
          Tres selects a 16px (el mínimo para que iOS no haga zoom) y 40px de
          alto no entran junto a las acciones en 360px, así que la fila muestra
          un resumen tocable (etapa y prioridad) que abre la hoja "Gestionar
          chat" con los tres controles apilados y con su etiqueta visible. */}
      <div className="flex items-center gap-1.5 md:hidden">
        <Sheet open={isManageSheetOpen} onOpenChange={setIsManageSheetOpen}>
          <div className={`flex min-w-0 flex-1 items-center rounded-lg border border-border bg-muted/50 ${PILL_FOCUS_RING}`}>
            <SheetTrigger asChild>
              <button
                type="button"
                className="flex h-10 w-full min-w-0 items-center gap-1.5 px-3 text-left text-base focus-visible:outline-none"
                aria-label={[
                  t("chats.manageChat"),
                  showChatStage ? `${t("chats.chatStageLabel")}: ${selectedStageName}` : null,
                  `${t("chats.priorityLabel")}: ${getPriorityLabel(value.priority || "baja")}`,
                  `${t("chats.assignToLabel")}: ${selectedAssigneeName}`,
                ].filter(Boolean).join(". ")}
              >
                {showChatStage && <StageDot color={chatStageColor} />}
                {/* La prioridad no se recorta: si falta lugar, cede el nombre de la etapa. */}
                {showChatStage && (
                  <>
                    <span className="min-w-0 truncate text-foreground">{selectedStageName}</span>
                    <span className="shrink-0 text-muted-foreground" aria-hidden="true">·</span>
                  </>
                )}
                <span className={`shrink-0 ${getPriorityColor(value.priority || "baja")}`}>{getPriorityLabel(value.priority || "baja")}</span>
                <ChevronDown className="ml-auto size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
              </button>
            </SheetTrigger>
          </div>
          <SheetContent
            side="bottom"
            aria-describedby={undefined}
            className="max-h-[85dvh] gap-0 overflow-y-auto rounded-t-xl px-4 pt-4 pb-[max(env(safe-area-inset-bottom),1rem)]"
          >
            <SheetHeader className="px-0 pt-0 pb-4">
              <SheetTitle>{t("chats.manageChat")}</SheetTitle>
            </SheetHeader>
            <div className="space-y-4">
              {showChatStage && (
                <div className="space-y-1.5">
                  <label htmlFor={`${stageLabelId}-mobile`} className="text-sm font-medium text-foreground">
                    {t("chats.chatStageLabel")}
                  </label>
                  <div className={`flex h-11 items-center gap-2 rounded-lg border border-border bg-muted/50 px-3 ${PILL_FOCUS_RING}`}>
                    <StageDot color={chatStageColor} />
                    {isLoadingStages ? (
                      <Loader2 className="size-4 animate-spin text-muted-foreground" aria-label={t("chats.loading")} />
                    ) : stagesFailed ? (
                      <span className="flex min-w-0 text-sm">
                        <LoadError message={t("chats.stagesLoadError")} retryLabel={t("chats.retry")} onRetry={() => void loadStages()} />
                      </span>
                    ) : (
                      <select
                        id={`${stageLabelId}-mobile`}
                        value={String(value.stageId ?? "")}
                        onChange={(e) => handleStageChange(Number(e.target.value))}
                        className="h-full w-full min-w-0 truncate bg-transparent text-base text-foreground focus-visible:outline-none"
                      >
                        {pipelineStages.map((stage) => (
                          <option key={stage.id} value={String(stage.id)}>
                            {stage.name}
                          </option>
                        ))}
                      </select>
                    )}
                  </div>
                </div>
              )}

              <div className="space-y-1.5">
                <label htmlFor={`${priorityLabelId}-mobile`} className="text-sm font-medium text-foreground">
                  {t("chats.priorityLabel")}
                </label>
                <div className={`flex h-11 items-center rounded-lg border border-border bg-muted/50 px-3 ${PILL_FOCUS_RING}`}>
                  <select
                    id={`${priorityLabelId}-mobile`}
                    value={value.priority || "baja"}
                    onChange={(e) => handlePriorityChange(e.target.value as Priority)}
                    className={`h-full w-full min-w-0 truncate bg-transparent text-base focus-visible:outline-none ${getPriorityColor(value.priority || "baja")}`}
                  >
                    <option value="baja">{t("chats.priorityLow")}</option>
                    <option value="media">{t("chats.priorityMedium")}</option>
                    <option value="alta">{t("chats.priorityHigh")}</option>
                    <option value="hot">{t("chats.priorityHot")}</option>
                  </select>
                </div>
              </div>

              <div className="space-y-1.5">
                <label htmlFor={`${assigneeLabelId}-mobile`} className="text-sm font-medium text-foreground">
                  {t("chats.assignToLabel")}
                </label>
                <div className={`flex h-11 items-center rounded-lg border border-border bg-muted/50 px-3 ${PILL_FOCUS_RING}`}>
                  {isLoadingUsers ? (
                    <Loader2 className="size-4 animate-spin text-muted-foreground" aria-label={t("chats.loadingUsers")} />
                  ) : usersFailed && !team.length ? (
                    <span className="flex min-w-0 text-sm">
                      <LoadError message={t("chats.usersLoadError")} retryLabel={t("chats.retry")} onRetry={() => void loadUsers()} />
                    </span>
                  ) : (
                    <select
                      id={`${assigneeLabelId}-mobile`}
                      value={String(selectedAssigneeId)}
                      onChange={(e) => handleAssigneeChange(Number(e.target.value))}
                      className="h-full w-full min-w-0 truncate bg-transparent text-base text-foreground focus-visible:outline-none"
                    >
                      {effectiveTeam.map((member) => (
                        <option key={member.id} value={String(member.id)}>
                          {member.name}
                        </option>
                      ))}
                    </select>
                  )}
                </div>
              </div>
            </div>
            <Button type="button" className="mt-6 h-11 w-full" onClick={() => setIsManageSheetOpen(false)}>
              {t("chats.manageDone")}
            </Button>
          </SheetContent>
        </Sheet>

        <div className="flex shrink-0 items-center gap-0.5">
          {contact && !hasOpportunities && (
            <Button
              variant="ghost"
              size="sm"
              className="relative h-10 w-10 rounded-lg p-0"
              onClick={openNewOpportunity}
              title={t("chats.newOpportunity")}
              aria-label={t("chats.newOpportunity")}
            >
              <Briefcase className="h-4 w-4" />
              <Plus className="absolute bottom-2 right-2 h-2.5 w-2.5 rounded-full bg-background stroke-[3]" aria-hidden="true" />
            </Button>
          )}

          <Sheet open={isTagsSheetOpen} onOpenChange={setIsTagsSheetOpen}>
            <SheetTrigger asChild>
              <Button
                variant="ghost"
                size="sm"
                className="relative h-10 w-10 rounded-lg p-0"
                title={t("chats.tagsLabel")}
                aria-label={t("chats.tagsLabel")}
              >
                <TagLucideIcon className="h-4 w-4" />
                {tags.length > 0 && (
                  <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold leading-none text-primary-foreground">
                    {tags.length}
                  </span>
                )}
              </Button>
            </SheetTrigger>
            <SheetContent side="bottom" className="max-h-[85dvh] overflow-y-auto">
              <SheetHeader>
                <SheetTitle>{t("chats.tagsLabel")}</SheetTitle>
              </SheetHeader>
              <div className="mt-4">
                <TagPicker
                  target="conversation"
                  targetId={chatId}
                  value={tags}
                  onChange={onTagsChange}
                  variant="inline"
                />
              </div>
            </SheetContent>
          </Sheet>

          {onToggleReadStatus && (
            <Button
              variant="ghost"
              size="sm"
              className="h-10 w-10 rounded-lg p-0"
              onClick={onToggleReadStatus}
              title={isUnread ? t("chats.markAsRead") : t("chats.markAsUnread")}
              aria-label={isUnread ? t("chats.markAsRead") : t("chats.markAsUnread")}
            >
              {isUnread ? <Mail className="h-4 w-4" /> : <MailOpen className="h-4 w-4" />}
            </Button>
          )}
          <Button
            variant="ghost"
            size="sm"
            className="h-10 w-10 rounded-lg p-0"
            onClick={handleToggleArchive}
            title={value.archived ? t("chats.unarchiveShortcut") : t("chats.archiveShortcut")}
            aria-label={value.archived ? t("chats.unarchiveShortcut") : t("chats.archiveShortcut")}
          >
            <Archive className="h-4 w-4" />
          </Button>
        </div>
      </div>

      {contact && opportunitiesFailed && (
        <div className="mt-1.5 flex items-center rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-1 text-xs">
          <LoadError message={t("chats.opportunitiesLoadError")} retryLabel={t("chats.retry")} onRetry={retryOpportunities} />
        </div>
      )}

      {hasOpportunities && (
        <ChatOpportunityRail
          opportunities={opportunities}
          stagesByPipeline={stagesByPipeline}
          pendingOpportunityId={pendingOpportunityId}
          contactName={contact?.name}
          onMove={handleOpportunityStageChange}
          onNew={openNewOpportunity}
        />
      )}

      <ContactPipelineDialog
        contact={contact}
        conversationId={chatId}
        open={isNewOpportunityOpen && Boolean(contact)}
        onOpenChange={setIsNewOpportunityOpen}
        onSaved={() => void handleOpportunityCreated()}
      />
    </div>
      )
    })()
  )
}
