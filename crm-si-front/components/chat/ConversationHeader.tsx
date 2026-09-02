import { ContactAvatar } from "@/components/contact-avatar"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Switch } from "@/components/ui/switch"
import { LeadScoreBadge } from "@/components/Badges"
import { ArrowLeft, Info, History, Pencil, Check, X, User, Users, Plus, Bot, MoreVertical } from "lucide-react"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Conversation } from "@/data/types"
import { useEffect, useRef, useState } from "react"
import { ContactHistoryDrawer } from "./ContactHistoryDrawer"
import { ContactTimeline } from "./timeline/ContactTimeline"
import { NewTaskModal } from "@/components/tasks/NewTaskModal"
import { useAuthStore } from "@/store/useAuthStore"
import { useTranslation } from "@/hooks/useTranslation"

interface ConversationHeaderProps {
  conversation: Conversation
  isContactInfoOpen: boolean
  onBack: () => void
  onToggleContactInfo: () => void
  onRenameContact?: (name: string) => Promise<void> | void
  onRenameGroup?: (subject: string) => Promise<void> | void
  onToggleAiAutoreply?: (enabled: boolean) => Promise<void> | void
}

export function ConversationHeader({
  conversation,
  isContactInfoOpen,
  onBack,
  onToggleContactInfo,
  onRenameContact,
  onRenameGroup,
  onToggleAiAutoreply,
}: ConversationHeaderProps) {
  const [historyOpen, setHistoryOpen] = useState(false)
  const [timelineOpen, setTimelineOpen] = useState(false)
  const [taskModalOpen, setTaskModalOpen] = useState(false)
  const isGroup = conversation.kind === "group"
  const contactId = conversation.contact_id ?? Number(conversation.contact?.id)
  const hasContactId = !isGroup && Boolean(contactId) && !Number.isNaN(contactId)
  const displayName = isGroup
    ? conversation.group?.subject ?? "Grupo"
    : conversation.contact?.name ?? ""
  const onRename = isGroup ? onRenameGroup : onRenameContact
  const [isEditingName, setIsEditingName] = useState(false)
  const [nameDraft, setNameDraft] = useState(displayName)
  const [isSavingName, setIsSavingName] = useState(false)
  const nameInputRef = useRef<HTMLInputElement>(null)
  const currentUser = useAuthStore((state) => state.user)
  const { t } = useTranslation()
  const leadScore = conversation.leadScore ?? 0

  useEffect(() => {
    if (!isEditingName) {
      setNameDraft(displayName)
    }
  }, [displayName, isEditingName])

  useEffect(() => {
    if (isEditingName) {
      nameInputRef.current?.focus()
      nameInputRef.current?.select()
    }
  }, [isEditingName])

  const startEditingName = () => {
    setNameDraft(displayName)
    setIsEditingName(true)
  }

  const cancelEditingName = () => {
    setIsEditingName(false)
    setNameDraft(displayName)
  }

  const saveName = async () => {
    const trimmed = nameDraft.trim()
    if (!trimmed || trimmed === displayName) {
      cancelEditingName()
      return
    }
    try {
      setIsSavingName(true)
      await onRename?.(trimmed)
      setIsEditingName(false)
    } finally {
      setIsSavingName(false)
    }
  }

  const handleNameKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
    if (event.key === "Enter") {
      event.preventDefault()
      void saveName()
    } else if (event.key === "Escape") {
      event.preventDefault()
      cancelEditingName()
    }
  }

  return (
    <div className="border-b border-border bg-card px-3 py-2.5 sm:p-4">
      <div className="flex min-w-0 items-center justify-between gap-3">
        <div className="flex min-w-0 flex-1 items-center gap-2.5 sm:gap-3">
          <Button
            variant="ghost"
            size="sm"
            className="h-10 w-10 shrink-0 rounded-full p-0 sm:h-8 sm:w-auto sm:rounded-md sm:px-2.5"
            onClick={onBack}
            aria-label={t("chats.back")}
          >
            <ArrowLeft className="w-4 h-4" />
          </Button>
          {isGroup ? (
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted ring-1 ring-border">
              <Users className="h-5 w-5 text-muted-foreground" />
            </div>
          ) : (
            <ContactAvatar
              contactId={conversation.contact?.id}
              name={conversation.contact?.name}
              className="h-10 w-10 ring-1 ring-border"
              fallbackClassName="text-sm font-semibold"
            />
          )}
          <div className="flex-1 min-w-0">
            <div className="flex min-w-0 items-center gap-1.5 sm:gap-2">
              {isEditingName ? (
                <div className="flex min-w-0 flex-1 items-center gap-1">
                  <Input
                    ref={nameInputRef}
                    value={nameDraft}
                    onChange={(e) => setNameDraft(e.target.value)}
                    onKeyDown={handleNameKeyDown}
                    disabled={isSavingName}
                    maxLength={255}
                    className="h-8 min-w-0 flex-1 px-2 text-sm font-medium sm:w-48 sm:flex-none"
                    aria-label={t("chats.editContactName")}
                  />
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 w-8 shrink-0 p-0 text-primary"
                    onClick={() => void saveName()}
                    disabled={isSavingName}
                    title={t("chats.save")}
                  >
                    <Check className="w-4 h-4" />
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 w-8 shrink-0 p-0"
                    onClick={cancelEditingName}
                    disabled={isSavingName}
                    title={t("chats.cancel")}
                  >
                    <X className="w-4 h-4" />
                  </Button>
                </div>
              ) : (
                <div className="group flex min-w-0 items-center gap-0.5">
                  <h3 className="truncate text-sm font-semibold leading-tight sm:text-base sm:font-medium">
                    {displayName}
                  </h3>
                  {onRename && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-7 w-7 shrink-0 rounded-full p-0 text-muted-foreground opacity-100 transition-opacity md:opacity-0 md:group-hover:opacity-100 focus-visible:opacity-100"
                      onClick={startEditingName}
                      title={t("chats.editContactName")}
                      aria-label={t("chats.editContactName")}
                    >
                      <Pencil className="w-3.5 h-3.5" />
                    </Button>
                  )}
                </div>
              )}
              <LeadScoreBadge
                score={leadScore}
                className="hidden cursor-help sm:inline-flex"
                title={`${t("chats.leadScore")}: ${leadScore}/100 - ${t("chats.leadScoreHint")}`}
              />
            </div>
            <p className="mt-0.5 truncate text-xs text-muted-foreground">
              {isGroup
                ? `${conversation.group?.total_participant_count ?? 0} participantes`
                : conversation.contact?.phone || t("chats.online")}
            </p>
          </div>
        </div>
        {/* Mobile: sólo el estado de la IA queda a la vista. Es lo único que
            se mira mientras se lee (dice si el bot está contestando por vos);
            el resto son acciones puntuales y viven en el menú. */}
        <div className="flex shrink-0 items-center gap-0.5 sm:hidden">
          {onToggleAiAutoreply && (
            <Button
              variant="ghost"
              size="sm"
              className={`h-10 w-10 shrink-0 rounded-full p-0 ${
                conversation.aiAutoreplyEnabled ? "bg-primary/10 text-primary" : "text-muted-foreground"
              }`}
              onClick={() => void onToggleAiAutoreply(!conversation.aiAutoreplyEnabled)}
              aria-pressed={Boolean(conversation.aiAutoreplyEnabled)}
              title={t("chats.aiAutoreplyHint")}
              aria-label={t("chats.aiAutoreply")}
            >
              <Bot className="h-[18px] w-[18px]" />
            </Button>
          )}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="sm"
                className="h-10 w-10 shrink-0 rounded-full p-0"
                aria-label={t("chats.moreActions")}
              >
                <MoreVertical className="h-[18px] w-[18px]" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
              <DropdownMenuItem onSelect={() => setTaskModalOpen(true)}>
                <Plus className="mr-2 h-4 w-4" />
                {t("chats.createTask")}
              </DropdownMenuItem>
              <DropdownMenuItem onSelect={() => setTimelineOpen(true)} disabled={!hasContactId}>
                <User className="mr-2 h-4 w-4" />
                {t("timeline.title")}
              </DropdownMenuItem>
              <DropdownMenuItem onSelect={() => setHistoryOpen(true)} disabled={!hasContactId}>
                <History className="mr-2 h-4 w-4" />
                {t("chats.viewFullHistory")}
              </DropdownMenuItem>
              <DropdownMenuItem onSelect={onToggleContactInfo}>
                <Info className="mr-2 h-4 w-4" />
                {t("chats.contactInfo")}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>

        <div className="hidden items-center gap-2 sm:flex">
          {onToggleAiAutoreply && (
            <label
              className="flex cursor-pointer items-center gap-1.5 rounded-md px-2 py-1 text-xs text-muted-foreground hover:bg-muted/60"
              title={t("chats.aiAutoreplyHint")}
            >
              <Bot className={`h-4 w-4 ${conversation.aiAutoreplyEnabled ? "text-primary" : ""}`} />
              <span className="hidden lg:inline">{t("chats.aiAutoreply")}</span>
              <Switch
                checked={Boolean(conversation.aiAutoreplyEnabled)}
                onCheckedChange={(checked) => void onToggleAiAutoreply(checked)}
                aria-label={t("chats.aiAutoreply")}
              />
            </label>
          )}
          <Button
            variant="ghost"
            size="sm"
            className="gap-2"
            onClick={() => setTaskModalOpen(true)}
            title={t("chats.createTask")}
          >
            <Plus className="w-4 h-4" />
            {t("chats.createTask")}
          </Button>
          {hasContactId && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setTimelineOpen(true)}
              title={t("timeline.title")}
            >
              <User className="w-4 h-4" />
            </Button>
          )}
          {hasContactId && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setHistoryOpen(true)}
              title={t("chats.viewFullHistory")}
            >
              <History className="w-4 h-4" />
            </Button>
          )}
          <Button
            variant="ghost"
            size="sm"
            onClick={onToggleContactInfo}
            aria-pressed={isContactInfoOpen}
            title={t("chats.contactInfo")}
          >
            <Info className="w-4 h-4" />
          </Button>
        </div>
      </div>

      {hasContactId && conversation.contact && (
        <ContactHistoryDrawer
          open={historyOpen}
          onOpenChange={setHistoryOpen}
          contactId={conversation.contact.id}
          contactName={conversation.contact.name}
        />
      )}

      {hasContactId && (
        <ContactTimeline
          open={timelineOpen}
          onOpenChange={setTimelineOpen}
          contactId={contactId}
          conversationId={conversation.id}
          contactName={conversation.contact?.name ?? ""}
        />
      )}

      <NewTaskModal
        open={taskModalOpen}
        onOpenChange={setTaskModalOpen}
        onCreateTask={() => {}}
        prefilledData={{
          relationType: "chat",
          relationId: String(conversation.id),
          relationLabel: displayName,
          lockRelation: true,
          ...(currentUser ? { assigneeId: String(currentUser.id) } : {}),
        }}
      />
    </div>
  )
}
