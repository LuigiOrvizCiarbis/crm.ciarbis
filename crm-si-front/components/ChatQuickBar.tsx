"use client"

import { useState, useEffect } from "react"
import { Button } from "@/components/ui/button"
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet"
import { useToast } from "@/components/Toast"
import { Archive, Loader2, Mail, MailOpen, Tag as TagLucideIcon } from "lucide-react"
import { getPipelineStages, PipelineStage } from "@/lib/api/pipeline"
import { getUsers } from "@/lib/api/users"
import { updateConversationStage, assignConversationUser } from "@/lib/api/conversations"
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

interface ChatQuickBarValue {
  stageId?: number // Cambiado de stage: Stage a stageId: number
  priority?: Priority
  assigneeId?: number | string
  archived?: boolean
}

interface ChatQuickBarProps {
  chatId: number
  value: ChatQuickBarValue
  team: TeamUser[]
  onChangeStage: (stageId: number, stageName: string) => void // Ajustado para recibir ID y nombre
  onChangePriority: (priority: Priority) => void
  onChangeAssignee: (id: number) => void
  onToggleArchive: () => void
  isUnread?: boolean
  onToggleReadStatus?: () => void
  tags?: Tag[]
  onTagsChange?: (tags: Tag[]) => void
}

// Eliminado TEAM fijo: ahora se cargan usuarios reales desde API. Se mantiene posibilidad de pasar prop "team" como override.

export function ChatQuickBar({
  chatId,
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
}: ChatQuickBarProps) {
  const { addToast } = useToast()
  const { t } = useTranslation()
  const [isTagsSheetOpen, setIsTagsSheetOpen] = useState(false)

  const [pipelineStages, setPipelineStages] = useState<PipelineStage[]>([])
  const [isLoadingStages, setIsLoadingStages] = useState(true)
  const [users, setUsers] = useState<TeamUser[]>([])
  const [isLoadingUsers, setIsLoadingUsers] = useState(true)

  // Helpers de normalización
  const toNumber = (v: unknown): number | undefined => {
    if (typeof v === 'number' && Number.isFinite(v)) return v
    if (typeof v === 'string') {
      const n = parseInt(v, 10)
      return Number.isFinite(n) ? n : undefined
    }
    return undefined
  }

  useEffect(() => {
    const loadData = async () => {
      try {
        const [stages, fetchedUsers] = await Promise.all([
          getPipelineStages(),
          getUsers(),
        ])
        setPipelineStages(stages)
        setUsers(fetchedUsers.map((user) => ({
          id: user.id,
          name: user.name,
          role: typeof user.role === "string"
            ? user.role
            : user.role?.name || "Vendedor",
        })))
      } catch (error) {
        console.error('Error loading stages/users', error)
      } finally {
        setIsLoadingStages(false)
        setIsLoadingUsers(false)
      }
    }
    loadData()
  }, [])

  const handleStageChange = (stageId: number) => {
    // Encontrar la etapa por ID
    const stage = pipelineStages.find(s => s.id === stageId);
    console.log("🚀 ~ handleStageChange ~ stage:", stage)
    if (!stage) {
      addToast({
        type: "error",
        title: t("chats.genericError"),
        description: t("chats.stageNotFound"),
      });
      return;
    }

    // Llamar a la API para actualizar (ahora con el ID numérico)
    updateConversationStage(chatId, stageId)
      .then(() => {
        // ✅ IMPORTANTE: Notificar al padre para que actualice su estado
        onChangeStage(stageId, stage.name);

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

      return (
    <div className="sticky top-0 z-10 rounded-xl border border-border bg-card/80 p-1.5 backdrop-blur sm:p-2">
      {/* Desktop Layout */}
      <div className="hidden md:flex items-center gap-2">
        {/* Stage Select */}
        <div className="inline-flex items-center gap-2 text-[12px] text-[#9AA4B2] bg-[#131722] border border-[#1e2533] rounded-xl px-3 py-1.5">
          <span>{t("chats.stageLabel")}:</span>
          {isLoadingStages ? (
            <Loader2 className="h-3 w-3 animate-spin" />
          ) : (
            <Select
              key={`${value.stageId}-${pipelineStages.length}`}
              value={value.stageId?.toString() || ""}
              onValueChange={(val) => handleStageChange(Number(val))}
            >
              <SelectTrigger className="h-auto w-35 border-0 bg-transparent p-0 text-[#D8DEE9] text-sm focus:ring-0 focus:ring-offset-0 gap-2 hover:bg-transparent data-placeholder:text-[#D8DEE9]">
                <SelectValue placeholder={t("chats.select")} className="truncate">
                  <span className="truncate block w-full text-left">
                    {selectedStageName}
                  </span>
                </SelectValue>
              </SelectTrigger>
              <SelectContent className="bg-[#0F1117] border-[#1e2533] text-[#D8DEE9]">
                {pipelineStages.map((stage) => (
                  <SelectItem 
                    key={stage.id} 
                    value={String(stage.id)}
                    className="focus:bg-[#1A1F2B] focus:text-[#D8DEE9] cursor-pointer"
                  >
                    {stage.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </div>

        {/* Priority Select */}
        <div className="inline-flex items-center gap-2 text-[12px] text-[#9AA4B2] bg-[#131722] border border-[#1e2533] rounded-xl px-3 py-1.5">
          <span>{t("chats.priorityLabel")}:</span>
          <select
            value={value.priority || "baja"}
            onChange={(e) => handlePriorityChange(e.target.value as Priority)}
            className={`bg-transparent text-sm focus:outline-none ${getPriorityColor(value.priority || "baja")}`}
          >
            <option value="baja">{t("chats.priorityLow")}</option>
            <option value="media">{t("chats.priorityMedium")}</option>
            <option value="alta">{t("chats.priorityHigh")}</option>
            <option value="hot">{t("chats.priorityHot")}</option>
          </select>
        </div>

        {/* Assignee Select (shadcn Select) */}
        <div className="inline-flex items-center gap-2 text-[12px] text-[#9AA4B2] bg-[#131722] border border-[#1e2533] rounded-xl px-3 py-1.5">
          <span>{t("chats.assignToLabel")}:</span>
          {isLoadingUsers ? (
            <Loader2 className="h-3 w-3 animate-spin" />
          ) : (
            <Select
              value={String(selectedAssigneeId)}
              onValueChange={(val) => handleAssigneeChange(Number(val))}
            >
              <SelectTrigger className="h-auto w-40 border-0 bg-transparent p-0 text-[#D8DEE9] text-sm focus:ring-0 focus:ring-offset-0 gap-2">
                <SelectValue placeholder={t("chats.select")} className="truncate">
                  <span className="truncate block w-full text-left">
                    {selectedAssigneeName}
                  </span>
                </SelectValue>
              </SelectTrigger>
              <SelectContent className="bg-[#0F1117] border-[#1e2533] text-[#D8DEE9] max-h-64 overflow-y-auto">
                {effectiveTeam.map((member) => (
                  <SelectItem key={member.id} value={String(member.id)} className="focus:bg-[#1A1F2B] focus:text-[#D8DEE9] cursor-pointer">
                    {member.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </div>

        {/* Action Buttons */}
        <div className="flex min-w-0 max-w-[280px] items-center gap-2 rounded-xl border border-[#1e2533] bg-[#131722] px-3 py-1.5">
          <TagChips tags={tags} maxVisible={2} />
          <TagPicker
            target="conversation"
            targetId={chatId}
            value={tags}
            onChange={onTagsChange}
            buttonLabel="Tags"
            compact
          />
        </div>

        {/* Action Buttons */}
        <div className="flex items-center gap-1 ml-auto">
          {onToggleReadStatus && (
            <Button
              variant="ghost"
              size="sm"
              className="p-2 rounded-lg border border-[#1e2533] hover:bg-[#1A1F2B] text-[#D8DEE9]"
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
            className="p-2 rounded-lg border border-[#1e2533] hover:bg-[#1A1F2B] text-[#D8DEE9]"
            onClick={handleToggleArchive}
            title={value.archived ? t("chats.unarchiveShortcut") : t("chats.archiveShortcut")}
          >
            <Archive className="w-4 h-4" />
          </Button>
        </div>
      </div>

      {/* Mobile Layout: una sola fila, sin scroll horizontal.
          Los tres selects se reparten el ancho disponible (`flex-1 min-w-0`) y
          las acciones quedan fijas a la derecha, siempre alcanzables.
          Los prefijos ("Etapa:", "Prioridad:", "Derivar a:") existen sólo como
          `aria-label`: gastaban ~200px repitiendo lo que el propio valor ya
          dice, y ese exceso era justo lo que empujaba las acciones fuera de
          pantalla. Se distinguen por posición, que es estable, y por el color
          en el caso de prioridad. */}
      <div className="flex items-center gap-1.5 md:hidden">
        <div className="flex min-w-0 flex-1 items-center gap-1.5">
          <div className="flex min-w-0 flex-1 items-center rounded-lg border border-border bg-muted/50 px-2 py-1">
            {isLoadingStages ? (
              <Loader2 className="h-3 w-3 animate-spin text-muted-foreground" />
            ) : (
              <select
                value={String(value.stageId ?? "")}
                onChange={(e) => handleStageChange(Number(e.target.value))}
                className="w-full min-w-0 truncate bg-transparent text-xs text-foreground focus:outline-none"
                aria-label={t("chats.stageLabel")}
                title={t("chats.stageLabel")}
              >
                {pipelineStages.map((stage) => (
                  <option key={stage.id} value={String(stage.id)}>
                    {stage.name}
                  </option>
                ))}
              </select>
            )}
          </div>

          <div className="flex min-w-0 shrink items-center rounded-lg border border-border bg-muted/50 px-2 py-1">
            <select
              value={value.priority || "baja"}
              onChange={(e) => handlePriorityChange(e.target.value as Priority)}
              className={`w-full min-w-0 truncate bg-transparent text-xs focus:outline-none ${getPriorityColor(value.priority || "baja")}`}
              aria-label={t("chats.priorityLabel")}
              title={t("chats.priorityLabel")}
            >
              <option value="baja">{t("chats.priorityLow")}</option>
              <option value="media">{t("chats.priorityMedium")}</option>
              <option value="alta">{t("chats.priorityHigh")}</option>
              <option value="hot">{t("chats.priorityHot")}</option>
            </select>
          </div>

          <div className="flex min-w-0 flex-1 items-center rounded-lg border border-border bg-muted/50 px-2 py-1">
            {isLoadingUsers ? (
              <Loader2 className="h-3 w-3 animate-spin text-muted-foreground" />
            ) : (
              <select
                value={String(selectedAssigneeId)}
                onChange={(e) => handleAssigneeChange(Number(e.target.value))}
                className="w-full min-w-0 truncate bg-transparent text-xs text-foreground focus:outline-none"
                aria-label={t("chats.assignToLabel")}
                title={t("chats.assignToLabel")}
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

        <div className="flex shrink-0 items-center gap-0.5">
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
    </div>
      )
    })()
  )
}
