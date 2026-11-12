"use client"

import { Message, TranslationLanguage, SharedContact } from "@/data/types"
import { MessageStatus } from "./MessageStatus"
import { MessageReactions } from "./MessageReactions"
import { ReactionPicker } from "./ReactionPicker"
import { EmojiPicker } from "./EmojiPicker"
import { MoreHorizontal, Pencil, Trash2, Bot, Languages, EyeOff, Phone, Mail, Ban, AlertCircle } from "lucide-react"
import { useTranslation } from "@/hooks/useTranslation"
import { useLongPress } from "@/hooks/useLongPress"
import { memo, useState } from "react"
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
  parseTemplateContent,
  MessageBubbleImage,
  MessageBubbleSticker,
  MessageBubbleVideo,
  MessageBubbleDocument,
} from "./messageThreadUtils"
import { MessageText } from "./MessageText"
import { LinkPreviewCard } from "./LinkPreviewCard"
import { AudioPlayer } from "./AudioPlayer"
import { FlowMessageCard } from "./FlowMessageCard"

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

/**
 * Memoizada: el hilo re-renderiza en cada mensaje nuevo, cambio de estado o
 * reacción; sólo la burbuja cuyo `message` (u otra prop) cambió vuelve a pintarse.
 * MessageList debe pasar callbacks estables y props de búsqueda acotadas a esta burbuja.
 */
export const MessageBubble = memo(function MessageBubble({
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
  const { t, language } = useTranslation()
  const [showOriginal, setShowOriginal] = useState(false)

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
  const isVideo = msg.message_type === "video" && mediaUrl
  // El documento no depende de mediaUrl (público, legado): se sirve por el
  // endpoint autenticado /api/messages/{id}/media, así que basta con el tipo.
  const isDocument = msg.message_type === "document"
  const isContacts = msg.message_type === "contacts" && Array.isArray(msg.contacts)
  const isFlow = msg.message_type === "flow" || msg.message_type === "flow_response"

  const parsed = !isImage && !isSticker && !isAudio && !isVideo && !isDocument && !isContacts && !isFlow && !isDeleted
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
            isOwn ? "mr-1" : "ml-1"
          }`}
        >
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="h-7 w-7" aria-label={t("chats.messageActions")}>
                <MoreHorizontal className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align={isOwn ? "end" : "start"}>
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

  // La IA tiene su propio color: se distingue de lo que escribió una persona del equipo.
  // Un mensaje borrado pierde el color de su emisor: queda como hueco punteado, legible y sin peso.
  const surface = isDeleted
    ? "border border-dashed border-border text-muted-foreground"
    : isUser
      ? "bg-primary text-primary-foreground"
      : isBot
        ? "border border-ai/30 bg-ai/10"
        : "bg-muted"
  const onAccent = isUser && !isDeleted

  const timestamp = msg.delivered_at || msg.created_at
  // Los mensajes fallidos siempre conservan su estado visible, aunque otro
  // mensaje del mismo emisor los siga dentro de la ventana de agrupación.
  const showStatusRow = isLastOfGroup || (msg.direction === "outbound" && !!msg.failed_at)
  const playsAudio = !isDeleted && Boolean(isAudio && mediaUrl)

  // La hora vive dentro de la burbuja, abajo a la derecha junto al estado. Normalmente sólo
  // el último de la tanda la muestra; los fallos mantienen su estado visible en cada mensaje.
  const statusContent = showStatusRow && (timestamp || msg.failed_at) ? (
    <>
      {isEdited && (hasOriginalContent ? (
        <button
          type="button"
          className="rounded-sm underline decoration-current/40 underline-offset-2 hover:decoration-current focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          onClick={() => setShowOriginal((value) => !value)}
          aria-expanded={showOriginal}
          title={showOriginal ? t("chats.hideOriginal") : t("chats.showOriginal")}
        >
          {t("chats.edited")}
        </button>
      ) : (
        <span>{t("chats.edited")}</span>
      ))}
      {timestamp && (
        <span className={`tabular-nums ${onAccent ? "text-primary-foreground" : ""}`}>
          {new Date(timestamp).toLocaleTimeString(language, { hour: "2-digit", minute: "2-digit" })}
        </span>
      )}
      <MessageStatus message={msg} onAccent={onAccent} />
    </>
  ) : null

  const body = isDeleted ? (
    <p className="flex items-center gap-1.5 text-sm italic">
      <Ban className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
      {t("chats.messageDeleted")}
    </p>
  ) : hasOriginalContent ? (
    <div>
      {/* El original queda a un toque de "editado": a la vista ensuciaba cada mensaje corregido. */}
      {showOriginal && (
        <p className="mb-1.5 border-b border-current/20 pb-1.5 text-xs line-through whitespace-pre-wrap [overflow-wrap:anywhere]">
          {msg.original_content}
        </p>
      )}
      <p className="text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">
        <MessageText
          content={msg.content || ""}
          query={normalizedQuery}
          activeMatchKey={activeMatchKey}
          matchKeyPrefix={matchKeyPrefix}
          onAccent={onAccent}
        />
      </p>
    </div>
  ) : isImage && mediaUrl ? (
    <div className="space-y-1">
      <MessageBubbleImage mediaUrl={mediaUrl} isUser={isUser} />
      {msg.content && (
        <p className="mt-1 text-sm">
          <MessageText
            content={msg.content}
            query={normalizedQuery}
            activeMatchKey={activeMatchKey}
            matchKeyPrefix={matchKeyPrefix}
            onAccent={onAccent}
          />
        </p>
      )}
    </div>
  ) : isSticker && mediaUrl ? (
    <div className="space-y-1">
      <MessageBubbleSticker mediaUrl={mediaUrl} />
      {msg.content && (
        <p className="mt-1 text-sm">
          <MessageText
            content={msg.content}
            query={normalizedQuery}
            activeMatchKey={activeMatchKey}
            matchKeyPrefix={matchKeyPrefix}
            onAccent={onAccent}
          />
        </p>
      )}
    </div>
  ) : isAudio && mediaUrl ? (
    <AudioPlayer src={mediaUrl} filename={msg.media_filename} onAccent={onAccent} meta={statusContent} />
  ) : isVideo && mediaUrl ? (
    <div className="space-y-1">
      <MessageBubbleVideo mediaUrl={mediaUrl} />
      {msg.content && (
        <p className="mt-1 text-sm">
          <MessageText
            content={msg.content}
            query={normalizedQuery}
            activeMatchKey={activeMatchKey}
            matchKeyPrefix={matchKeyPrefix}
            onAccent={onAccent}
          />
        </p>
      )}
    </div>
  ) : isDocument ? (
    <div className="space-y-1">
      <MessageBubbleDocument
        messageId={msg.id}
        filename={msg.media_filename}
        mimeType={msg.media_mime_type}
        isUser={isUser}
      />
      {msg.content && (
        <p className="mt-1 text-sm">
          <MessageText
            content={msg.content}
            query={normalizedQuery}
            activeMatchKey={activeMatchKey}
            matchKeyPrefix={matchKeyPrefix}
            onAccent={onAccent}
          />
        </p>
      )}
    </div>
  ) : isContacts ? (
    <div className="space-y-2">
      {(msg.contacts as SharedContact[]).map((contact, index) => (
        <div key={`${contact.name.formatted_name}-${index}`} className="min-w-56 rounded-lg border border-current/15 bg-background/40 p-2.5">
          <p className="text-sm font-semibold">{contact.name.formatted_name}</p>
          {contact.phones?.map((phone, phoneIndex) => phone.phone && <p key={`p-${phoneIndex}`} className="flex items-center gap-1.5 text-xs opacity-80"><Phone className="size-3 shrink-0" aria-hidden="true" />{phone.phone}</p>)}
          {contact.emails?.map((email, emailIndex) => email.email && <p key={`e-${emailIndex}`} className="flex items-center gap-1.5 text-xs opacity-80"><Mail className="size-3 shrink-0" aria-hidden="true" /><span className="min-w-0 truncate">{email.email}</span></p>)}
          {contact.org?.company && <p className="text-xs opacity-70">{contact.org.company}{contact.org.title ? ` · ${contact.org.title}` : ""}</p>}
          {msg.direction === "inbound" && onSaveContact && <button type="button" className="mt-2 text-xs font-medium underline underline-offset-2" onClick={() => onSaveContact(msg, index)}>Guardar en contactos</button>}
        </div>
      ))}
    </div>
  ) : isFlow ? (
    <FlowMessageCard message={msg} isUser={isUser} />
  ) : parsed.isTemplate ? (
    <div className="space-y-1">
      <span className="text-xs font-medium opacity-75">{parsed.title}</span>
      {parsed.body && (
        <p className="text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">
          <MessageText
            content={parsed.body}
            query={normalizedQuery}
            activeMatchKey={activeMatchKey}
            matchKeyPrefix={matchKeyPrefix}
            onAccent={onAccent}
          />
        </p>
      )}
    </div>
  ) : (
    <div>
      <p className="text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">
        <MessageText
          content={msg.content || ""}
          query={normalizedQuery}
          activeMatchKey={activeMatchKey}
          matchKeyPrefix={matchKeyPrefix}
          onAccent={onAccent}
        />
      </p>
      {msg.link_preview && <LinkPreviewCard preview={msg.link_preview} isUser={isUser} />}
    </div>
  )

  return (
    <div
      className={`group/msg flex ${isOwn ? "justify-end" : "justify-start"} ${
        isFirstOfGroup ? "mt-3" : "mt-0.5"
      }`}
    >
      {isOwn && actionsMenu}

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
            <div className="mb-1 flex items-center gap-1 text-[11px] font-medium text-ai">
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
                <MessageText
                  content={translationState.content || ""}
                  query=""
                  activeMatchKey={null}
                  matchKeyPrefix={`${matchKeyPrefix}-translation`}
                  onAccent={onAccent}
                />
              </p>
            )}
          </div>
        )}

          {/* En los audios la hora va en la fila del reproductor. */}
          {statusContent && !playsAudio && (
            <div
              className={`mt-0.5 flex items-center justify-end gap-1 text-[11px] ${
                onAccent ? "" : "text-muted-foreground"
              }`}
            >
              {statusContent}
            </div>
          )}
        </div>

        {/* El motivo del fallo a la vista: sólo con el ícono, nadie se enteraba de que el mensaje no salió. */}
        {msg.direction === "outbound" && msg.failed_at && (
          <p className="mt-1 flex max-w-full items-start gap-1 text-xs text-destructive">
            <AlertCircle className="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span>
              {msg.error_message ? `${t("chats.messageFailed")}: ${msg.error_message}` : t("chats.messageFailed")}
            </span>
          </p>
        )}

        <MessageReactions
          reactions={reactions}
          currentUserId={currentUserId}
          onToggle={(emoji) => onReact?.(msg, emoji)}
          disabled={!canReact || !onReact}
        />
      </div>

      {!isOwn && actionsMenu}

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
})
