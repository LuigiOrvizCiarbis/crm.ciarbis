"use client"

import { Message, TranslationLanguage, SharedContact } from "@/data/types"
import { MessageStatus } from "./MessageStatus"
import { MessageReactions } from "./MessageReactions"
import { ReactionPicker } from "./ReactionPicker"
import { EmojiPicker } from "./EmojiPicker"
import { MoreHorizontal, Pencil, Trash2, Bot, Languages, EyeOff } from "lucide-react"
import { useTranslation } from "@/hooks/useTranslation"
import { useLongPress } from "@/hooks/useLongPress"
import { useState } from "react"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Popover, PopoverContent, PopoverAnchor } from "@/components/ui/popover"
import { Button } from "@/components/ui/button"
import {
  highlightText,
  parseTemplateContent,
  MessageBubbleImage,
  MessageBubbleSticker,
  MessageBubbleAudio,
} from "./messageThreadUtils"

export interface TranslationState {
  content?: string
  sourceContent: string
  targetLanguage: TranslationLanguage
  visible: boolean
  loading: boolean
  error?: string
}

interface MessageBubbleProps {
  message: Message
  /** Último de una tanda del mismo emisor: sólo ese lleva cola y hora. */
  isLastOfGroup: boolean
  /** Primero de la tanda: separa del bloque anterior. */
  isFirstOfGroup: boolean
  translationLanguage: TranslationLanguage
  translationState?: TranslationState
  normalizedQuery: string
  activeMatchKey: string | null
  onTranslate: (message: Message) => void
  onEdit?: (message: Message) => void
  onDelete?: (message: Message) => void
  onReact?: (message: Message, emoji: string) => void
  onSaveContact?: (message: Message, index: number) => void
  canEdit: boolean
  canDelete: boolean
  canTranslate: boolean
  canReact: boolean
  currentUserId?: number
  /** Muestra el nombre de quien escribió sobre las burbujas inbound de un grupo. */
  isGroupConversation?: boolean
}

export function MessageBubble({
  message: msg,
  isLastOfGroup,
  isFirstOfGroup,
  translationLanguage,
  translationState,
  normalizedQuery,
  activeMatchKey,
  onTranslate,
  onEdit,
  onDelete,
  onReact,
  canEdit,
  canDelete,
  canTranslate,
  canReact,
  currentUserId,
  isGroupConversation = false,
  onSaveContact,
}: MessageBubbleProps) {
  const { t } = useTranslation()

  const isUser = msg.sender_type === "user"
  const isBot = msg.sender_type === "system" && msg.direction === "outbound"
  const isOwn = isUser || isBot
  const isDeleted = !!msg.deleted_at
  const isEdited = !!msg.edited_at && !isDeleted
  const hasOriginalContent =
    isEdited && !!msg.original_content && msg.original_content !== msg.content

  const mediaUrl = msg.media_full_url || msg.media_url
  const isImage = msg.message_type === "image" && mediaUrl
  const isSticker = msg.message_type === "sticker" && mediaUrl
  const isAudio = msg.message_type === "audio" && mediaUrl
  const isContacts = msg.message_type === "contacts" && Array.isArray(msg.contacts)

  const parsed = !isImage && !isSticker && !isAudio && !isContacts && !isDeleted
    ? parseTemplateContent(msg.content || "")
    : { isTemplate: false, title: "", body: "" }

  const matchKeyPrefix = `msg-${msg.id}`
  const isCurrentTranslation =
    translationState?.sourceContent === msg.content &&
    translationState.targetLanguage === translationLanguage

  const reactions = msg.reaction_summary ?? []
  const myReaction = reactions.find((r) => !!currentUserId && (r.reactor_user_ids ?? []).includes(currentUserId))

  // Un mensaje sin ninguna otra acción (p.ej. imagen inbound sin texto) igual
  // necesita menú/long-press si se puede reaccionar: sin esto en hasActions,
  // esos mensajes se quedan sin forma de abrir el picker.
  const hasActions = canEdit || canDelete || canTranslate || canReact

  // El menú por hover es inalcanzable en touch: ahí las acciones se abren con
  // toque sostenido sobre la burbuja, igual que en WhatsApp.
  const [isActionSheetOpen, setIsActionSheetOpen] = useState(false)
  const longPress = useLongPress(() => {
    if (hasActions) setIsActionSheetOpen(true)
  })

  // Picker completo de emoji, separado del DropdownMenu/Sheet de acciones:
  // anidar un Popover/Sheet dentro de otro portal de Radix rompe el focus
  // trap, así que primero se cierra el contenedor de acciones y luego se abre
  // este.
  const [isEmojiPopoverOpen, setIsEmojiPopoverOpen] = useState(false)
  const [isEmojiSheetOpen, setIsEmojiSheetOpen] = useState(false)

  const handleReactSelect = (emoji: string) => {
    onReact?.(msg, emoji)
    setIsEmojiPopoverOpen(false)
    setIsEmojiSheetOpen(false)
  }

  const sheetActions = [
    ...(canTranslate
      ? [{
          key: "translate",
          label:
            isCurrentTranslation && translationState.visible
              ? t("chats.hideTranslation")
              : isCurrentTranslation && translationState.content
                ? t("chats.showTranslation")
                : t("chats.translateMessage"),
          Icon: isCurrentTranslation && translationState?.visible ? EyeOff : Languages,
          destructive: false,
          onSelect: () => onTranslate(msg),
        }]
      : []),
    ...(canEdit && onEdit
      ? [{
          key: "edit",
          label: t("chats.editMessage"),
          Icon: Pencil,
          destructive: false,
          onSelect: () => onEdit(msg),
        }]
      : []),
    ...(canDelete && onDelete
      ? [{
          key: "delete",
          label: t("chats.deleteMessage"),
          Icon: Trash2,
          destructive: true,
          onSelect: () => onDelete(msg),
        }]
      : []),
  ]

  const actionsMenu = hasActions ? (
    <Popover open={isEmojiPopoverOpen} onOpenChange={setIsEmojiPopoverOpen}>
      <PopoverAnchor asChild>
        <div
          className={`flex items-center opacity-0 transition-opacity group-hover/msg:opacity-100 focus-within:opacity-100 ${
            isUser ? "mr-1" : "ml-1"
          }`}
        >
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="h-7 w-7">
                <MoreHorizontal className="h-4 w-4 text-muted-foreground" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align={isUser ? "end" : "start"}>
              {canReact && onReact && (
                <>
                  <ReactionPicker
                    onSelect={handleReactSelect}
                    onMore={() => setIsEmojiPopoverOpen(true)}
                    currentEmoji={myReaction?.emoji}
                  />
                  <DropdownMenuSeparator />
                </>
              )}
              {canTranslate && (
                <DropdownMenuItem
                  onClick={() => onTranslate(msg)}
                  disabled={isCurrentTranslation && translationState.loading}
                >
                  {isCurrentTranslation && translationState.visible ? (
                    <EyeOff className="h-4 w-4 mr-2" />
                  ) : (
                    <Languages className="h-4 w-4 mr-2" />
                  )}
                  {isCurrentTranslation && translationState.visible
                    ? t("chats.hideTranslation")
                    : isCurrentTranslation && translationState.content
                      ? t("chats.showTranslation")
                      : t("chats.translateMessage")}
                </DropdownMenuItem>
              )}
              {canEdit && onEdit && (
                <DropdownMenuItem onClick={() => onEdit(msg)}>
                  <Pencil className="h-4 w-4 mr-2" />
                  {t("chats.editMessage")}
                </DropdownMenuItem>
              )}
              {canDelete && onDelete && (
                <DropdownMenuItem
                  className="text-destructive focus:text-destructive"
                  onClick={() => onDelete(msg)}
                >
                  <Trash2 className="h-4 w-4 mr-2" />
                  {t("chats.deleteMessage")}
                </DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </PopoverAnchor>
      {canReact && onReact && (
        <PopoverContent align={isUser ? "end" : "start"} className="w-80 p-3">
          <EmojiPicker onSelect={handleReactSelect} />
        </PopoverContent>
      )}
    </Popover>
  ) : null

  // Radio asimétrico: la esquina del lado del emisor se endereza sólo en el
  // último mensaje de la tanda, que es el que lleva la cola.
  const corner = !isLastOfGroup
    ? "rounded-2xl"
    : isOwn
      ? "rounded-2xl rounded-br-sm"
      : "rounded-2xl rounded-bl-sm"

  const surface = isUser
    ? "bg-primary text-primary-foreground"
    : isBot
      ? "border border-primary/30 bg-primary/10"
      : "bg-muted"

  const body = isDeleted ? (
    <p className="text-sm italic opacity-60">{t("chats.messageDeleted")}</p>
  ) : hasOriginalContent ? (
    <div className="space-y-2">
      <p className="text-xs opacity-70 line-through whitespace-pre-wrap [overflow-wrap:anywhere]">
        {msg.original_content}
      </p>
      <p className="text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">
        {normalizedQuery
          ? highlightText(msg.content || "", normalizedQuery, activeMatchKey, matchKeyPrefix)
          : msg.content}
      </p>
    </div>
  ) : isImage && mediaUrl ? (
    <div className="space-y-1">
      <MessageBubbleImage mediaUrl={mediaUrl} isUser={isUser} />
      {msg.content && (
        <p className="mt-1 text-sm">
          {normalizedQuery
            ? highlightText(msg.content, normalizedQuery, activeMatchKey, matchKeyPrefix)
            : msg.content}
        </p>
      )}
    </div>
  ) : isSticker && mediaUrl ? (
    <div className="space-y-1">
      <MessageBubbleSticker mediaUrl={mediaUrl} />
      {msg.content && (
        <p className="mt-1 text-sm">
          {normalizedQuery
            ? highlightText(msg.content, normalizedQuery, activeMatchKey, matchKeyPrefix)
            : msg.content}
        </p>
      )}
    </div>
  ) : isAudio && mediaUrl ? (
    <MessageBubbleAudio mediaUrl={mediaUrl} filename={msg.media_filename} />
  ) : isContacts ? (
    <div className="space-y-2">
      {(msg.contacts as SharedContact[]).map((contact, index) => (
        <div key={`${contact.name.formatted_name}-${index}`} className="min-w-56 rounded-lg border border-current/15 bg-background/40 p-2.5">
          <p className="text-sm font-semibold">{contact.name.formatted_name}</p>
          {contact.phones?.map((phone, phoneIndex) => phone.phone && <p key={`p-${phoneIndex}`} className="text-xs opacity-80">📞 {phone.phone}</p>)}
          {contact.emails?.map((email, emailIndex) => email.email && <p key={`e-${emailIndex}`} className="truncate text-xs opacity-80">✉️ {email.email}</p>)}
          {contact.org?.company && <p className="text-xs opacity-70">{contact.org.company}{contact.org.title ? ` · ${contact.org.title}` : ""}</p>}
          {msg.direction === "inbound" && onSaveContact && <button type="button" className="mt-2 text-xs font-medium underline underline-offset-2" onClick={() => onSaveContact(msg, index)}>Guardar en contactos</button>}
        </div>
      ))}
    </div>
  ) : parsed.isTemplate ? (
    <div className="space-y-1">
      <span className="text-xs font-medium opacity-75">{parsed.title}</span>
      {parsed.body && (
        <p className="text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">
          {normalizedQuery
            ? highlightText(parsed.body, normalizedQuery, activeMatchKey, matchKeyPrefix)
            : parsed.body}
        </p>
      )}
    </div>
  ) : (
    <p className="text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">
      {normalizedQuery
        ? highlightText(msg.content || "", normalizedQuery, activeMatchKey, matchKeyPrefix)
        : msg.content}
    </p>
  )

  const timestamp = msg.delivered_at || msg.created_at
  // Los mensajes fallidos siempre conservan su estado visible, aunque otro
  // mensaje del mismo emisor los siga dentro de la ventana de agrupación.
  const showStatusRow = isLastOfGroup || (msg.direction === "outbound" && !!msg.failed_at)

  return (
    <div
      className={`group/msg flex ${isOwn ? "justify-end" : "justify-start"} ${
        isFirstOfGroup ? "mt-3" : "mt-0.5"
      }`}
    >
      {isUser && actionsMenu}

      {/* Wrapper en columna: la burbuja y los chips de reacción son hermanos
          en flujo normal (no absolute), así una tanda de mensajes agrupados
          con mt-0.5 nunca queda tapada por los chips del anterior. */}
      <div className={`flex max-w-[80%] flex-col sm:max-w-[75%] ${isOwn ? "items-end" : "items-start"}`}>
        <div
          className={`relative w-fit max-w-full overflow-visible break-words px-3 py-2 ${corner} ${surface} ${
            hasActions ? "[-webkit-touch-callout:none]" : ""
          }`}
          {...(hasActions ? longPress : {})}
        >
          {isBot && (
            <div className="mb-1 flex items-center gap-1 text-[11px] font-medium text-primary">
              <Bot className="h-3 w-3" />
              {t("chats.aiBadge")}
            </div>
          )}

          {isGroupConversation && !isOwn && isFirstOfGroup && msg.sender?.name && (
            <div className="mb-0.5 text-[11px] font-semibold text-primary">
              {msg.sender.name}
            </div>
          )}

          {body}

          {translationState?.visible && isCurrentTranslation && (
            <div className="mt-2 border-t border-current/15 pt-2" aria-live="polite">
              <div className="mb-1 flex items-center justify-between gap-3 text-[11px] font-medium opacity-70">
                <span className="inline-flex items-center gap-1">
                  <Languages className="h-3 w-3" />
                  {t("chats.translationTo", { language: t(`chats.language.${translationLanguage}`) })}
                </span>
                {!translationState.loading && !translationState.error && (
                  <button
                    type="button"
                    className="rounded-sm underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    onClick={() => onTranslate(msg)}
                  >
                    {t("chats.hideTranslation")}
                  </button>
                )}
              </div>
              {translationState.loading ? (
                <div className="space-y-1.5 py-1" aria-label={t("chats.translating")}>
                  <div className="h-3 w-full animate-pulse rounded-sm bg-current/10 motion-reduce:animate-none" />
                  <div className="h-3 w-2/3 animate-pulse rounded-sm bg-current/10 motion-reduce:animate-none" />
                </div>
              ) : translationState.error ? (
                <div className="flex items-start justify-between gap-2 text-xs">
                  <span className="opacity-80">{translationState.error}</span>
                </div>
              ) : (
                <p className="text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">
                  {translationState.content}
                </p>
              )}
            </div>
          )}

          {/* La hora vive dentro de la burbuja, alineada abajo a la derecha junto
              al estado. Normalmente sólo el último de la tanda la muestra; los
              fallos mantienen su estado visible en cada mensaje. */}
          {showStatusRow && (timestamp || msg.failed_at) && (
            <div
              className={`mt-0.5 flex items-center justify-end gap-1 text-[11px] ${
                isUser ? "" : "text-muted-foreground"
              }`}
            >
              {isEdited && <span className="opacity-80">{t("chats.edited")}</span>}
              {timestamp && (
                <span className={`tabular-nums ${isUser ? "text-primary-foreground/70" : ""}`}>
                  {new Date(timestamp).toLocaleTimeString([], {
                    hour: "2-digit",
                    minute: "2-digit",
                    hour12: true,
                  })}
                </span>
              )}
              <MessageStatus message={msg} onAccent={isUser} />
            </div>
          )}
        </div>

        <MessageReactions
          reactions={reactions}
          currentUserId={currentUserId}
          onToggle={(emoji) => onReact?.(msg, emoji)}
          disabled={!canReact || !onReact}
        />
      </div>

      {!isUser && actionsMenu}

      <Sheet open={isActionSheetOpen} onOpenChange={setIsActionSheetOpen}>
        <SheetContent side="bottom" className="gap-0 rounded-t-2xl pb-[env(safe-area-inset-bottom)]">
          <SheetHeader className="pb-1">
            <SheetTitle className="text-base">{t("chats.messageActions")}</SheetTitle>
            <p className="truncate text-left text-xs text-muted-foreground">
              {msg.content || t("chats.messageWithoutText")}
            </p>
          </SheetHeader>
          {canReact && onReact && (
            <div className="border-b border-border px-2 pb-3">
              <ReactionPicker
                size="touch"
                onSelect={(emoji) => {
                  setIsActionSheetOpen(false)
                  handleReactSelect(emoji)
                }}
                onMore={() => {
                  setIsActionSheetOpen(false)
                  setIsEmojiSheetOpen(true)
                }}
                currentEmoji={myReaction?.emoji}
              />
            </div>
          )}
          <div className="flex flex-col px-2 pb-6">
            {sheetActions.map((action) => (
              <button
                key={action.key}
                type="button"
                className={`flex items-center gap-3 rounded-lg px-3 py-3 text-left text-sm transition-colors hover:bg-accent ${
                  action.destructive ? "text-destructive" : ""
                }`}
                onClick={() => {
                  setIsActionSheetOpen(false)
                  action.onSelect()
                }}
              >
                <action.Icon className={`h-4 w-4 shrink-0 ${action.destructive ? "" : "text-muted-foreground"}`} />
                {action.label}
              </button>
            ))}
          </div>
        </SheetContent>
      </Sheet>

      {canReact && onReact && (
        <Sheet open={isEmojiSheetOpen} onOpenChange={setIsEmojiSheetOpen}>
          <SheetContent side="bottom" className="gap-0 rounded-t-2xl pb-[env(safe-area-inset-bottom)]">
            <SheetHeader className="pb-2">
              <SheetTitle className="text-base">{t("chats.reactionsTitle")}</SheetTitle>
            </SheetHeader>
            <div className="px-4 pb-6">
              <EmojiPicker size="touch" onSelect={handleReactSelect} />
            </div>
          </SheetContent>
        </Sheet>
      )}
    </div>
  )
}
