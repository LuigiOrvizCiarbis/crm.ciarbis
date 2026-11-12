"use client"

import dynamic from "next/dynamic"
import { SidebarLayout } from "@/components/SidebarLayout"
import { BrandPilot } from "@/components/brand/BrandPilot"
import { ChatFilters } from "@/components/chat/ChatFilters"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"

const ContactInfoPanel = dynamic(
  () => import("@/components/ContactInfoPanel").then(m => m.ContactInfoPanel),
  { loading: () => null }
)
const GroupPanel = dynamic(
  () => import("@/components/groups/GroupPanel").then(m => m.GroupPanel),
  { loading: () => null }
)
const NewGroupDialog = dynamic(
  () => import("@/components/groups/NewGroupDialog").then(m => m.NewGroupDialog),
  { loading: () => null }
)
import { useChatState } from "@/hooks/useChatState"
import { useToast } from "@/components/Toast"
import { ChannelError, ChannelErrorDetail } from "@/lib/channel-error"
import { FilterType, Channel, Conversation, ConversationView, Message, TranslationLanguage } from "@/data/types"
import { ChannelType, filterTypeToChannelType } from "@/data/enums"
import { useState, useEffect, useCallback, useRef, useMemo, useSyncExternalStore } from "react"
import { useSearchParams, useRouter } from "next/navigation"
import { ChatQuickBar } from "@/components/ChatQuickBar"
import {
  archiveConversation,
  setAiAutoreply,
  bulkArchiveConversations,
  bulkAiAutoreplyConversations,
  bulkDeleteConversations,
  bulkMarkReadConversations,
  ConversationUnavailableError,
  getConversationMessages,
  getConversationsCursorPage,
  getConversationWithMessages,
  markConversationAsRead,
  markConversationAsUnread,
  searchConversationsByContent,
  setConversationTranslationLanguage,
  translateDraft,
} from "@/lib/api/conversations"
const BulkTagsConversationsDialog = dynamic(
  () => import("@/components/chat/BulkTagsConversationsDialog").then(m => m.BulkTagsConversationsDialog),
  { loading: () => null }
)
const BulkAssignConversationsDialog = dynamic(
  () => import("@/components/chat/BulkAssignConversationsDialog").then(m => m.BulkAssignConversationsDialog),
  { loading: () => null }
)
const BroadcastDialog = dynamic(
  () => import("@/components/chat/BroadcastDialog").then(m => m.BroadcastDialog),
  { loading: () => null }
)
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"

import { sendMessage, sendContactsMessage, saveSharedContact, sendMailMessage, editMessage, deleteMessage, translateMessage, reactToMessage, type SendMailMessageInput } from "@/lib/api/messages"
import { sendFlowMessage, type SendFlowInput } from "@/lib/api/whatsapp-flows"
import { ConversationHeader } from "@/components/chat/ConversationHeader"
import { PendingAiBookingsBanner } from "@/components/chat/PendingAiBookingsBanner"
import { HumanHandoffBanner } from "@/components/chat/HumanHandoffBanner"
import { MessageList } from "@/components/chat/MessageList"
import { applyReactionToggle } from "@/components/chat/messageThreadUtils"
import { MessageInput } from "@/components/chat/MessageInput"
import { MailMessageInput } from "@/components/chat/MailMessageInput"
import { ConversationList } from "@/components/chat/ConversationList"
import { FilteredConversationsHeader } from "@/components/chat/FilteredConversationsHeader"
import { ChannelsList } from "@/components/chat/ChannelsList"
import { getChannels, updateChannelName, updateChannelHandoffSettings, syncMailChannel, disconnectChannel, type ChannelHandoffSettings } from "@/lib/api/channels"
import { updateContact } from "@/lib/api/contacts"
import { updateWhatsAppGroup } from "@/lib/api/whatsapp-groups"
import { isExpectedBusinessErrorMessage } from "@/lib/observability/sentry"
import { ChannelHeader } from "@/components/chat/AccountHeader"
import { useConversationFilters } from "@/hooks/useConversationFilters"
import { TagFilterMenu } from "@/components/tags/TagFilterMenu"
import { StageFilterMenu, matchesStageFilter, stageKeysOf } from "@/components/chat/StageFilterMenu"
import { Sheet, SheetContent, SheetTitle } from "@/components/ui/sheet"
import { useSSEMessages, type MessageStatusUpdate, type MessageReactionUpdate, type HumanHandoffUpdate } from "@/hooks/useSSEMessages"
import { useTenantSSE } from "@/hooks/useTenantSSE"
import { getPusher } from "@/lib/pusher"
import { cancelManualAiDraft, getManualAiDraft, requestManualAiDraft, type ManualAiDraft } from "@/lib/api/manual-ai-drafts"
import { useTranslation } from "@/hooks/useTranslation"
import { useAuthStore } from "@/store/useAuthStore"
import { useComposerStore } from "@/store/useComposerStore"
import { useFacebookSDK } from "@/hooks/useFacebookSDK"
import { useInstagramLogin } from "@/hooks/useInstagramLogin"
import { InstagramPageSelectDialog } from "@/components/chat/InstagramPageSelectDialog"
import { useMessengerLogin } from "@/hooks/useMessengerLogin"
import { MessengerPageSelectDialog } from "@/components/chat/MessengerPageSelectDialog"
import { MailConnectDialog } from "@/components/chat/MailConnectDialog"
import { MailReviewInbox } from "@/components/chat/MailReviewInbox"
import { getMailIntakeCount } from "@/lib/api/mail-intakes"
import { setActiveConversationId } from "@/lib/activeConversation"
import { useNotificationsStore } from "@/store/useNotificationsStore"
import { useScheduledMessages } from "@/hooks/useScheduledMessages"
import { useMessagingWindowStatus } from "@/hooks/useMessagingWindowStatus"
import { createScheduledMessage, supportsScheduledMessages } from "@/lib/api/scheduled-messages"
import { ScheduledMessagesBar } from "@/components/chat/ScheduledMessagesBar"
import { EmptyState } from "@/components/EmptyState"
import { SkeletonList } from "@/components/Skeleton"
import {
  AlertCircle,
  Archive,
  ArchiveRestore,
  Bot,
  CheckSquare,
  ChevronDown,
  Facebook,
  Instagram,
  Loader2,
  Mail,
  MessageCircle,
  MailOpen,
  Megaphone,
  Menu,
  MoreHorizontal,
  Search,
  Tags,
  Trash2,
  UserPlus,
  Users,
  X,
  Zap,
} from "lucide-react"
import { channelIconClass } from "@/lib/utils/channelHelpers"

/** Si una conversación entra en la vista. "Por revisar" muestra mails, no conversaciones. */
function matchesConversationView(conversation: Conversation, view: ConversationView): boolean {
  switch (view) {
    case "archived":
      return Boolean(conversation.archived)
    case "review":
      return false
    case "unread":
      return Boolean(conversation.unread) && !conversation.archived
    case "waiting":
      return Boolean(conversation.humanHandoff) && !conversation.archived
    default:
      return !conversation.archived
  }
}

/**
 * Proveedor OAuth a conectar. Ojo: NO es lo mismo que el FilterType de los
 * filtros de la bandeja — Messenger se conecta como "messenger" pero sus
 * conversaciones se filtran como "facebook" (que es el ChannelType).
 */
type ConnectableChannel = "whatsapp" | "instagram" | "messenger" | "mail"

/**
 * Canales conectables. Está acá y se mapea en los 3 menús (desktop, móvil y
 * sidebar) para que agregar un canal no sean tres ediciones separadas.
 */
const CONNECTABLE_CHANNELS: {
  key: ConnectableChannel
  label: string
  Icon: typeof MessageCircle
  iconClassName: string
}[] = [
  { key: "whatsapp", label: "WhatsApp", Icon: MessageCircle, iconClassName: channelIconClass("whatsapp") },
  { key: "instagram", label: "Instagram", Icon: Instagram, iconClassName: channelIconClass("instagram") },
  { key: "messenger", label: "Messenger", Icon: Facebook, iconClassName: channelIconClass("facebook") },
  { key: "mail", label: "Email", Icon: Mail, iconClassName: channelIconClass("mail") },
]

interface ChatsCompactHeaderProps {
  searchQuery: string
  onSearchChange: (query: string) => void
  onConnectChannel: (channelType?: ConnectableChannel) => void
  onOpenChannels: () => void
  /**
   * En mobile la vista es una pila: al abrir un hilo, la lista de
   * conversaciones deja de estar en pantalla y este encabezado pierde sentido
   * (su buscador filtra una lista invisible). Se oculta por CSS, no
   * desmontando, para que el texto tipeado siga ahí al volver.
   */
  hideOnMobile?: boolean
}

function ChatsCompactHeader({
  searchQuery,
  onSearchChange,
  onConnectChannel,
  onOpenChannels,
  hideOnMobile = false,
}: ChatsCompactHeaderProps) {
  const { t } = useTranslation()
  return (
    <div
      className={`sticky top-0 z-40 border-b border-border bg-background ${
        hideOnMobile ? "hidden md:block" : ""
      }`}
    >
      <div className="flex h-18.75 items-center gap-3 px-4 md:px-6">
        <Button
          variant="ghost"
          size="icon"
          onClick={onOpenChannels}
          aria-label={t("chats.openChannels")}
          className="md:hidden"
        >
          <Menu className="h-5 w-5" />
        </Button>
        {/* Sin ícono ni subtítulo: el ✦ es el símbolo de la IA, y "Bandeja omnicanal" repetía el título. */}
        <h1 className="shrink-0 text-lg font-semibold tracking-tight text-foreground">Chats</h1>

        <div className="relative hidden max-w-md flex-1 md:block">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={searchQuery}
            placeholder={t("chats.searchPlaceholder")}
            className="h-9 border-border bg-muted/40 pl-9 shadow-none focus-visible:ring-primary/30"
            onChange={(event) => onSearchChange(event.target.value)}
          />
        </div>

        <div className="ml-auto flex shrink-0 items-center gap-2">
          <div className="hidden items-center gap-2 lg:flex">
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" className="gap-2 bg-transparent">
                  <Zap className="h-4 w-4" />
                  {t("chats.connectChannel")}
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {CONNECTABLE_CHANNELS.map(({ key, label, Icon, iconClassName }) => (
                  <DropdownMenuItem key={key} onClick={() => onConnectChannel(key)}>
                    <Icon className={`mr-2 h-4 w-4 ${iconClassName}`} />
                    {label}
                  </DropdownMenuItem>
                ))}
              </DropdownMenuContent>
            </DropdownMenu>
          </div>

          <DropdownMenu>
            <DropdownMenuTrigger asChild className="lg:hidden">
              <Button variant="ghost" size="icon" aria-label={t("chats.headerActions")}>
                <MoreHorizontal className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {CONNECTABLE_CHANNELS.map(({ key, label, Icon, iconClassName }) => (
                <DropdownMenuItem key={key} onClick={() => onConnectChannel(key)}>
                  <Icon className={`mr-2 h-4 w-4 ${iconClassName}`} />
                  {t("chats.connectChannelNamed", { name: label })}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      <div className="border-t border-border px-4 pb-3 md:hidden">
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={searchQuery}
            placeholder={t("chats.searchPlaceholder")}
            className="h-9 border-border bg-muted/40 pl-9 shadow-none"
            onChange={(event) => onSearchChange(event.target.value)}
          />
        </div>
      </div>
    </div>
  )
}

const EMPTY_MESSAGES: Message[] = []

/**
 * Reemplaza sólo el mensaje que coincide. Sin coincidencia o sin cambios devuelve
 * la misma conversación: React no re-renderiza y las demás burbujas (memoizadas)
 * conservan su referencia.
 */
function patchConversationMessage<C extends { messages?: Message[] }>(
  conversation: C | null,
  matches: (message: Message) => boolean,
  update: (message: Message) => Message,
): C | null {
  const messages = conversation?.messages
  if (!conversation || !messages) return conversation
  const index = messages.findIndex(matches)
  if (index === -1) return conversation
  const next = update(messages[index])
  if (next === messages[index]) return conversation
  const patched = messages.slice()
  patched[index] = next
  return { ...conversation, messages: patched }
}

// El panel de contacto va al costado desde lg; por debajo se abre como sheet.
const LG_VIEWPORT_QUERY = "(min-width: 1024px)"
function subscribeToLgViewport(onChange: () => void) {
  const mql = window.matchMedia(LG_VIEWPORT_QUERY)
  mql.addEventListener("change", onChange)
  return () => mql.removeEventListener("change", onChange)
}
const getLgViewportSnapshot = () => window.matchMedia(LG_VIEWPORT_QUERY).matches
const getLgViewportServerSnapshot = () => true

export default function ChatsPage() {
  const { addToast, removeToast } = useToast()
  const { t, language } = useTranslation()
  const router = useRouter()
  const searchParams = useSearchParams()
  const { chatStates, ...chatHandlers } = useChatState()
  const { user, permissions } = useAuthStore()
  const { launchWhatsAppSignup, isFacebookSDKLoaded } = useFacebookSDK()
  const {
    launchInstagramLogin,
    isFacebookSDKLoaded: isInstagramSDKLoaded,
    pageOptions: instagramPageOptions,
    selectPage: selectInstagramPage,
    cancelPageSelection: cancelInstagramPageSelection,
  } = useInstagramLogin()
  const {
    launchMessengerLogin,
    pageOptions: messengerPageOptions,
    selectPage: selectMessengerPage,
    cancelPageSelection: cancelMessengerPageSelection,
  } = useMessengerLogin()
  const currentUserId = user?.id
  const isAdmin = (permissions ?? []).includes("conversations.view_any")
  const canSendMessage = (permissions ?? []).includes("conversations.send_message")
  const canUpdateChannels = (permissions ?? []).includes("channels.update")
  const canDisconnectChannels = (permissions ?? []).includes("channels.disconnect")
  const canCreateGroups = (permissions ?? []).includes("whatsapp_groups.create")

  const chatIdFromUrl = searchParams.get("chat")



  const chatIdNumber = chatIdFromUrl ? parseInt(chatIdFromUrl, 10) : null


  const [selectedChannel, setSelectedChannel] = useState<number | null>(null)
  const [selectedChannelId, setSelectedChannelId] = useState<number | null>(null)
  const [isContactInfoOpen, setIsContactInfoOpen] = useState(false)
  // Conversación con el buscador del hilo abierto: con el id (y no un booleano) cambiar de chat lo cierra solo.
  const [threadSearchFor, setThreadSearchFor] = useState<number | null>(null)
  const contactInfoTriggerRef = useRef<HTMLElement | null>(null)
  const isLgViewport = useSyncExternalStore(subscribeToLgViewport, getLgViewportSnapshot, getLgViewportServerSnapshot)
  const [isChannelsSheetOpen, setIsChannelsSheetOpen] = useState(false)
  const [isMailConnectOpen, setMailConnectOpen] = useState(false)
  const [activeFilter, setActiveFilter] = useState<FilterType>("todos")
  const [tagFilterSlugs, setTagFilterSlugs] = useState<string[]>([])
  const [stageFilter, setStageFilter] = useState<string[]>([])
  const [viewType, setViewType] = useState<ConversationView>("inbox")
  const [mailReviewCount, setMailReviewCount] = useState(0)
  const [searchQuery, setSearchQuery] = useState("")
  const [messageSearchResults, setMessageSearchResults] = useState<Conversation[]>([])
  const [isSearchingMessages, setIsSearchingMessages] = useState(false)
  const [isLoading, setIsLoading] = useState(false)
  const [isChannelsLoading, setIsChannelsLoading] = useState(false)
  // Escribe el borrador del composer sin suscribirse: tipear no re-renderiza la página.
  const setMessage = useComposerStore((state) => state.setDraft)
  const [channels, setChannels] = useState<Channel[]>([])
  const [conversations, setConversations] = useState<Conversation[]>([])
  const [currentConversation, setCurrentConversation] = useState<Conversation | null>(null)
  const [selectedConversationId, setSelectedConversationId] = useState<number | null>(chatIdNumber)
  // Se incrementa con cada mensaje de sistema de la conversación abierta para
  // que el banner de turnos de la IA vuelva a consultar los pendientes.
  const [aiBookingsRefreshKey, setAiBookingsRefreshKey] = useState(0)

  // Un deep link puede apuntar a una conversación que todavía no llegó en la paginación:
  // mientras tanto el hilo usa la conversación cargada por id.
  const activeConversation = useMemo(
    () => conversations.find((c) => c.id === selectedConversationId)
      ?? (currentConversation?.id === selectedConversationId ? currentConversation : undefined),
    [conversations, currentConversation, selectedConversationId],
  )

  const hotkeyExpansionContext = useMemo(() => ({
    contactName: (currentConversation ?? activeConversation)?.contact?.name ?? null,
    userName: user?.name ?? null,
    tenantName: user?.tenant?.name ?? null,
  }), [activeConversation, currentConversation, user])

  const handleConversationTagsChange = useCallback((tags: NonNullable<Conversation["tags"]>) => {
    if (!selectedConversationId) return

    setConversations((prev) => prev.map((conversation) => (
      conversation.id === selectedConversationId ? { ...conversation, tags } : conversation
    )))
    setCurrentConversation((prev) => (prev ? { ...prev, tags } : prev))
  }, [selectedConversationId])

  const handleRenameContact = useCallback(async (name: string) => {
    if (!selectedConversationId) return

    const target = conversationsRef.current.find((c) => c.id === selectedConversationId)
    const contactId = target?.contact_id ?? (target?.contact?.id ? Number(target.contact.id) : undefined)
    if (!contactId || Number.isNaN(contactId)) {
      addToast({ type: "error", title: t("chats.renameContactError") })
      return
    }

    const belongsToContact = (c: Conversation) => (
      (c.contact_id ?? (c.contact?.id ? Number(c.contact.id) : undefined)) === contactId
    )

    const previousNames = new Map(
      conversationsRef.current
        .filter(belongsToContact)
        .map((c) => [c.id, c.contact?.name ?? ""] as const)
    )

    setConversations((prev) => prev.map((c) => (
      belongsToContact(c) && c.contact ? { ...c, contact: { ...c.contact, name } } : c
    )))
    setCurrentConversation((prev) => (
      prev && belongsToContact(prev) && prev.contact ? { ...prev, contact: { ...prev.contact, name } } : prev
    ))

    try {
      await updateContact(contactId, { name })
      addToast({ type: "success", title: t("chats.renameContactSuccess") })
    } catch (error) {
      setConversations((prev) => prev.map((c) => (
        previousNames.has(c.id) && c.contact ? { ...c, contact: { ...c.contact, name: previousNames.get(c.id)! } } : c
      )))
      setCurrentConversation((prev) => (
        prev && previousNames.has(prev.id) && prev.contact
          ? { ...prev, contact: { ...prev.contact, name: previousNames.get(prev.id)! } }
          : prev
      ))
      addToast({
        type: "error",
        title: t("chats.renameContactError"),
        description: error instanceof Error ? error.message : undefined,
      })
    }
  }, [selectedConversationId, addToast, t])

  // La foto la cambia el panel del contacto; la bandeja y el encabezado la
  // reflejan sin esperar a la próxima recarga de conversaciones.
  const handleContactAvatarChange = useCallback((contactId: number, avatarUrl: string | null) => {
    const withAvatar = (c: Conversation): Conversation => (
      c.contact && (c.contact_id ?? Number(c.contact.id)) === contactId
        ? { ...c, contact: { ...c.contact, avatar_url: avatarUrl } }
        : c
    )
    setConversations((prev) => prev.map(withAvatar))
    setCurrentConversation((prev) => (prev ? withAvatar(prev) : prev))
  }, [])

  const handleRenameGroup = useCallback(async (subject: string) => {
    if (!selectedConversationId) return

    const previous = conversationsRef.current.find((c) => c.id === selectedConversationId)?.group?.subject
    setConversations((prev) => prev.map((c) => (
      c.id === selectedConversationId && c.group ? { ...c, group: { ...c.group, subject } } : c
    )))
    setCurrentConversation((prev) => (
      prev && prev.id === selectedConversationId && prev.group ? { ...prev, group: { ...prev.group, subject } } : prev
    ))

    try {
      const groupId = conversationsRef.current.find((c) => c.id === selectedConversationId)?.group?.id
      if (!groupId) return
      await updateWhatsAppGroup(groupId, { subject })
      addToast({ type: "success", title: t("chats.groupRenamed") })
    } catch (error) {
      if (previous !== undefined) {
        setConversations((prev) => prev.map((c) => (
          c.id === selectedConversationId && c.group ? { ...c, group: { ...c.group, subject: previous } } : c
        )))
        setCurrentConversation((prev) => (
          prev && prev.id === selectedConversationId && prev.group ? { ...prev, group: { ...prev.group, subject: previous } } : prev
        ))
      }
      addToast({
        type: "error",
        title: t("chats.groupRenameError"),
        description: error instanceof Error ? error.message : undefined,
      })
    }
  }, [selectedConversationId, addToast, t])

  const activeChannel = useMemo(() => channels.find((channel) => channel.id === selectedChannelId), [channels, selectedChannelId])

  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(true)
  const [isLoadingMoreMessages, setIsLoadingMoreMessages] = useState(false)
  const [hasMoreConversations, setHasMoreConversations] = useState(false)
  const [isLoadingMoreConversations, setIsLoadingMoreConversations] = useState(false)
  const conversationRequestVersion = useRef(0)
  const conversationCursorRef = useRef<string | null>(null)
  const hasMoreConversationsRef = useRef(false)
  const isLoadingMoreConversationsRef = useRef(false)
  const [editingMessage, setEditingMessage] = useState<Message | null>(null)
  const [aiDraft, setAiDraft] = useState<ManualAiDraft | null>(null)

  const [selectionMode, setSelectionMode] = useState(false)
  const [selectedConversationIds, setSelectedConversationIds] = useState<Set<number>>(new Set())
  const [bulkTagsOpen, setBulkTagsOpen] = useState(false)
  const [bulkAssignOpen, setBulkAssignOpen] = useState(false)
  const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false)
  const [broadcastOpen, setBroadcastOpen] = useState(false)
  const [bulkSubmitting, setBulkSubmitting] = useState(false)
  const [newGroupOpen, setNewGroupOpen] = useState(false)
  const [groupsOnly, setGroupsOnly] = useState(false)
  // Un fallo de carga no debe verse como "no hay conversaciones".
  const [conversationsLoadFailed, setConversationsLoadFailed] = useState(false)
  const [channelsLoadFailed, setChannelsLoadFailed] = useState(false)
  // "unavailable": 404/403, reintentar no sirve; "failed": error recuperable.
  const [conversationLoadError, setConversationLoadError] = useState<"unavailable" | "failed" | null>(null)
  const [conversationReloadKey, setConversationReloadKey] = useState(0)
  const [channelsReloadKey, setChannelsReloadKey] = useState(0)
  const [loadMoreMessagesFailed, setLoadMoreMessagesFailed] = useState(false)

  const conversationsRef = useRef(conversations);
  useEffect(() => {
    conversationsRef.current = conversations;
  }, [conversations]);

  /**
   * Las vistas filtran sobre las páginas cargadas, y una derivada puede haber quedado más abajo.
   * Se piden aparte las que esperan a una persona: se suman a la lista y corrigen el estado de
   * derivación de las que ya estaban (si la respuesta vino completa, las demás no esperan).
   */
  const awaitingRequestVersion = useRef(0)
  const syncAwaitingHuman = useCallback(async () => {
    const requestVersion = ++awaitingRequestVersion.current
    try {
      const result = await getConversationsCursorPage({ channelId: selectedChannelId, awaitingHuman: true, perPage: 100 })
      if (requestVersion !== awaitingRequestVersion.current) return
      const waiting = new Map(result.data.map((conversation) => [conversation.id, conversation]))
      setConversations((current) => {
        const knownIds = new Set(current.map((conversation) => conversation.id))
        const updated = current.map((conversation) => {
          const match = waiting.get(conversation.id)
          if (match) return { ...conversation, humanHandoff: match.humanHandoff }
          return !result.hasMore && conversation.humanHandoff ? { ...conversation, humanHandoff: null } : conversation
        })
        return [...updated, ...result.data.filter((conversation) => !knownIds.has(conversation.id))]
      })
    } catch {
      // Es un complemento de la lista: si falla, la bandeja sigue con lo que ya cargó.
    }
  }, [selectedChannelId])

  const loadConversationPage = useCallback(async (reset = false) => {
    if (!reset && (!hasMoreConversationsRef.current || isLoadingMoreConversationsRef.current)) return

    const requestVersion = reset ? ++conversationRequestVersion.current : conversationRequestVersion.current
    if (reset) {
      setIsLoading(true)
      setConversationsLoadFailed(false)
      conversationCursorRef.current = null
      setHasMoreConversations(false)
      hasMoreConversationsRef.current = false
    } else {
      setIsLoadingMoreConversations(true)
      isLoadingMoreConversationsRef.current = true
    }

    try {
      const result = await getConversationsCursorPage({
        cursor: reset ? null : conversationCursorRef.current,
        channelId: selectedChannelId,
      })
      if (requestVersion !== conversationRequestVersion.current) return

      setConversations((current) => {
        if (reset) return result.data
        const knownIds = new Set(current.map((conversation) => conversation.id))
        return [...current, ...result.data.filter((conversation) => !knownIds.has(conversation.id))]
      })
      conversationCursorRef.current = result.nextCursor
      setHasMoreConversations(result.hasMore)
      hasMoreConversationsRef.current = result.hasMore
      // Después del reset, para que no lo pise.
      if (reset) void syncAwaitingHuman()
    } catch {
      if (requestVersion === conversationRequestVersion.current) {
        // La carga inicial ya muestra el error en la lista con "Reintentar"; el toast queda solo para "cargar más".
        if (reset) {
          setConversations([])
          setConversationsLoadFailed(true)
        } else {
          addToast({ type: "error", title: t("chats.loadConversationsError") })
        }
      }
    } finally {
      if (requestVersion === conversationRequestVersion.current) {
        setIsLoading(false)
        setIsLoadingMoreConversations(false)
        isLoadingMoreConversationsRef.current = false
      }
    }
  }, [addToast, selectedChannelId, syncAwaitingHuman, t])

  const isTemplateFallbackContent = useCallback((content?: string) => {
    if (!content) return false;
    const raw = content.trim();
    const normalized = raw.replace(/^[^A-Za-z0-9]+/, "").trim();
    const isLegacyTemplatePrefix = normalized.startsWith("Template:");
    const isSingleLineTemplateWithoutBody = raw.startsWith("📋") && !raw.includes("\n");
    return isLegacyTemplatePrefix || isSingleLineTemplateWithoutBody;
  }, []);

  const normalizeIncomingTemplateContent = useCallback(
    (incomingContent?: string, optimisticContent?: string) => {
      if (!incomingContent) return incomingContent ?? "";
      if (!optimisticContent) return incomingContent;

      const incomingIsFallback = isTemplateFallbackContent(incomingContent);
      const optimisticHasPreview =
        optimisticContent.startsWith("📋") && optimisticContent.includes("\n");

      // Si el backend devolvió fallback corto del template, conservamos el preview optimista.
      if (incomingIsFallback && optimisticHasPreview) {
        return optimisticContent;
      }

      return incomingContent;
    },
    [isTemplateFallbackContent]
  );

  const getRealtimeMessagePreview = useCallback(
    (incomingMessage: Message, currentPreview?: string) => {
      const fallbackPreview = incomingMessage.message_type === "sticker"
        ? "🏷️ Sticker"
        : incomingMessage.content;

      return normalizeIncomingTemplateContent(fallbackPreview, currentPreview);
    },
    [normalizeIncomingTemplateContent]
  );

  // Declarado antes de handleRealTimeMessage: su lista de deps lo referencia.
  const scheduledMessages = useScheduledMessages(selectedConversationId);
  const { noteInbound } = scheduledMessages;
  const windowStatus = useMessagingWindowStatus(scheduledMessages.window);

  const handleRealTimeMessage = useCallback((newMessage: Message) => {
    if (newMessage.direction === "inbound" && selectedConversationId === newMessage.conversation_id) {
      setAiDraft(null)
      noteInbound(newMessage)
    }
    // 1. Actualizar el chat abierto (si coincide el ID)
    if (selectedConversationId === newMessage.conversation_id) {
      setCurrentConversation((prev) => {
        if (!prev) return prev;

        const messages = prev.messages || [];

        // Evitar duplicados
        if (messages.some((m: Message) => m.id === newMessage.id)) {
          return prev;
        }

        // Reemplazar mensaje optimista si existe
        const optimisticIndex = messages.findIndex((m: any) => {
          if (m.status !== 'sending') return false;
          const timeDiff = Math.abs(new Date(m.created_at).getTime() - new Date(newMessage.created_at).getTime());
          if (timeDiff > 10000) return false;
          // Exact match
          if (m.content === newMessage.content) return true;
          // Template match: both start with 📋
          if (m.content?.startsWith('📋') && newMessage.content?.startsWith('📋')) return true;
          return false;
        });

        if (optimisticIndex !== -1) {
          const updatedMessages = [...messages];
          const optimisticMessage = messages[optimisticIndex] as any;
          updatedMessages[optimisticIndex] = {
            ...newMessage,
            content: normalizeIncomingTemplateContent(
              newMessage.content,
              optimisticMessage?.content
            ),
          };
          return { ...prev, messages: updatedMessages };
        }

        return { ...prev, messages: [...messages, newMessage] };
      });

      if (newMessage.sender_type === "system") {
        setAiBookingsRefreshKey((k) => k + 1);
      }
    }

    // 2. Actualizar la lista de conversaciones (Sidebar)
    setConversations(prevConversations => {
      const index = prevConversations.findIndex(c => c.id === newMessage.conversation_id);
      if (index === -1) return prevConversations;

      const updatedConversation = {
        ...prevConversations[index],
        last_message: getRealtimeMessagePreview(
          newMessage,
          prevConversations[index].last_message
        ),
        updated_at: newMessage.created_at,
      };

      const newConversations = [...prevConversations];
      newConversations.splice(index, 1);
      return [updatedConversation, ...newConversations];
    });
  }, [getRealtimeMessagePreview, normalizeIncomingTemplateContent, selectedConversationId, noteInbound]);

  const handleRealTimeEdit = useCallback((updatedMsg: Message) => {
    setCurrentConversation((prev) =>
      patchConversationMessage(prev, (m) => m.id === updatedMsg.id, (m) => ({
        ...m,
        content: updatedMsg.content,
        edited_at: updatedMsg.edited_at,
        original_content: updatedMsg.original_content ?? m.original_content,
      })),
    );
  }, []);

  const handleRealTimeDelete = useCallback((data: { id: number; conversation_id: number }) => {
    setCurrentConversation((prev) =>
      patchConversationMessage(prev, (m) => m.id === data.id, (m) =>
        m.deleted_at ? m : { ...m, deleted_at: new Date().toISOString() },
      ),
    );
  }, []);

  const handleRealTimeStatus = useCallback((status: MessageStatusUpdate) => {
    setCurrentConversation((prev) =>
      patchConversationMessage(prev, (m) => String(m.id) === String(status.id), (m) => {
        const next = {
          delivered_at: status.delivered_at ?? m.delivered_at,
          read_at: status.read_at ?? m.read_at,
          played_at: status.played_at ?? m.played_at,
          failed_at: status.failed_at ?? m.failed_at,
          error_message: status.error_message ?? m.error_message,
        };
        // Los webhooks repiten estados (p. ej. "delivered" dos veces): sin cambios, sin render.
        const unchanged = (Object.keys(next) as (keyof typeof next)[]).every((key) => next[key] === m[key]);
        return unchanged ? m : { ...m, ...next };
      }),
    );
  }, []);

  // El evento trae el estado completo de reacciones del mensaje, no un delta:
  // siempre pisa cualquier proyección optimista local, sin reconciliación
  // heurística.
  const handleRealTimeReaction = useCallback((data: MessageReactionUpdate) => {
    setCurrentConversation((prev) =>
      patchConversationMessage(prev, (m) => String(m.id) === String(data.message_id), (m) => ({
        ...m,
        reaction_summary: data.reactions,
      })),
    );
  }, []);

  // La derivación cambia por otros (la IA la crea, otro agente la toma, un
  // mensaje humano la resuelve): el evento trae el estado completo y se pisa
  // en la conversación abierta y en la lista.
  const handleRealTimeHumanHandoff = useCallback((data: HumanHandoffUpdate) => {
    const patch = (c: Conversation): Conversation => ({
      ...c,
      humanHandoff: data.human_handoff ?? null,
      assigneeId: data.assigned_to ?? undefined,
      ...(data.ai_autoreply_enabled !== undefined ? { aiAutoreplyEnabled: data.ai_autoreply_enabled } : {}),
    });
    setCurrentConversation((prev) => (prev && prev.id === data.conversation_id ? patch(prev) : prev));
    setConversations((prev) => prev.map((c) => (c.id === data.conversation_id ? patch(c) : c)));
  }, []);

  useSSEMessages({
    conversationId: selectedConversationId,
    onMessage: handleRealTimeMessage,
    onEdited: handleRealTimeEdit,
    onDeleted: handleRealTimeDelete,
    onStatus: handleRealTimeStatus,
    onReaction: handleRealTimeReaction,
    onScheduledMessage: scheduledMessages.upsert,
    onHumanHandoff: handleRealTimeHumanHandoff,
  });

  const selectedChannelIdRef = useRef(selectedChannelId);
  useEffect(() => { selectedChannelIdRef.current = selectedChannelId; }, [selectedChannelId]);

  const tenantRefreshVersionRef = useRef(0);
  const awaitingSyncTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  useEffect(() => () => { if (awaitingSyncTimer.current) clearTimeout(awaitingSyncTimer.current) }, []);

  const handleTenantMessage = useCallback((message: Message) => {
    // Un mensaje de la IA puede venir con una derivación nueva: se revisa la lista de las que esperan.
    if (message.sender_type === "system") {
      if (awaitingSyncTimer.current) clearTimeout(awaitingSyncTimer.current)
      awaitingSyncTimer.current = setTimeout(() => void syncAwaitingHuman(), 1500)
    }
    // Cualquier respuesta de una persona cierra la derivación en el backend.
    const answeredByHuman = message.sender_type === "user" && message.direction === "outbound"
    setConversations(prev => {
      const idx = prev.findIndex(c => c.id === message.conversation_id);
      if (idx !== -1) {
        const updated = {
          ...prev[idx],
          ...(answeredByHuman ? { humanHandoff: null } : {}),
          last_message: getRealtimeMessagePreview(
            message,
            prev[idx].last_message
          ),
          updated_at: message.created_at,
        };
        const next = [...prev];
        next.splice(idx, 1);
        return [updated, ...next];
      }
      // New conversation — refetch respecting the active channel filter
      const channelId = selectedChannelIdRef.current;
      const version = ++tenantRefreshVersionRef.current;
      getConversationsCursorPage({ channelId }).then(data => {
        if (version !== tenantRefreshVersionRef.current) return;
        if (selectedChannelIdRef.current !== channelId) return;
        setConversations(data.data);
        conversationCursorRef.current = data.nextCursor;
        setHasMoreConversations(data.hasMore);
        hasMoreConversationsRef.current = data.hasMore;
        void syncAwaitingHuman();
      }).catch(() => {});
      // Refresh channel list so conversations_count stays in sync after a new chat lands
      getChannels().then(setChannels).catch(() => {});
      return prev;
    });
  }, [getRealtimeMessagePreview, syncAwaitingHuman]);

  useTenantSSE({
    onMessage: handleTenantMessage,
  });

  const handleLoadMoreMessages = useCallback(async () => {
    if (!selectedConversationId || isLoadingMoreMessages || !hasMore) return

    setIsLoadingMoreMessages(true)
    setLoadMoreMessagesFailed(false)
    try {
      const nextPage = page + 1
      // Llamamos a la API pidiendo la siguiente página
      const response = await getConversationMessages(selectedConversationId, nextPage)

      // Asumiendo que Laravel devuelve { data: [], last_page: X }
      // Si tu API devuelve directo el array, ajusta esto.
      const newMessages = response.data || []
      const lastPage = response.last_page || 1

      if (newMessages.length > 0) {
        setCurrentConversation((prev) => {
          if (!prev) return prev;

          const existingIds = new Set((prev.messages || []).map((m) => m.id));
          const uniqueNewMessages = (newMessages as Message[]).filter((m) => !existingIds.has(m.id));
          const combinedMessages = [...uniqueNewMessages, ...(prev.messages || [])].sort((a, b) => a.id - b.id);

          return { ...prev, messages: combinedMessages };
        });

        setPage(nextPage)
        setHasMore(nextPage < lastPage)
      } else {
        setHasMore(false)
      }

    } catch (error) {
      console.error("Error cargando más mensajes:", error)
      addToast({ type: "error", title: t("chats.loadHistoryError") })
      // Corta la carga automática al scrollear: sin esto reintenta en bucle.
      setLoadMoreMessagesFailed(true)
    } finally {
      setIsLoadingMoreMessages(false)
    }
  }, [addToast, hasMore, isLoadingMoreMessages, page, selectedConversationId, t])


  const {
    filteredConversations,
    connectedChannels,
    disconnectedChannels,
  } = useConversationFilters({
    conversations,
    channels,
    activeFilter,
    selectedChannelId,
  })

  const tagFilteredConversations = useMemo(() => {
    if (tagFilterSlugs.length === 0 && stageFilter.length === 0) return filteredConversations

    const selected = new Set(tagFilterSlugs)
    const stages = new Set(stageFilter)
    return filteredConversations.filter((conversation) => (
      (selected.size === 0 || conversation.tags?.some((tag) => selected.has(tag.slug)))
      && matchesStageFilter(conversation, stages)
    ))
  }, [filteredConversations, tagFilterSlugs, stageFilter])

  // Chats por etapa con todos los filtros menos el de etapas: lo que mostraría elegirla.
  const stageFilterCounts = useMemo(() => {
    const selectedTags = new Set(tagFilterSlugs)
    const counts = new Map<string, number>()
    filteredConversations.forEach((conversation) => {
      if (selectedTags.size > 0 && !conversation.tags?.some((tag) => selectedTags.has(tag.slug))) return
      if (!matchesConversationView(conversation, viewType)) return
      stageKeysOf(conversation).forEach((key) => counts.set(key, (counts.get(key) ?? 0) + 1))
    })
    return counts
  }, [filteredConversations, tagFilterSlugs, viewType])

  const conversationViewCounts = useMemo(() => ({
    inbox: tagFilteredConversations.filter((conversation) => matchesConversationView(conversation, "inbox")).length,
    unread: tagFilteredConversations.filter((conversation) => matchesConversationView(conversation, "unread")).length,
    waiting: tagFilteredConversations.filter((conversation) => matchesConversationView(conversation, "waiting")).length,
    archived: tagFilteredConversations.filter((conversation) => matchesConversationView(conversation, "archived")).length,
    review: mailReviewCount,
  }), [tagFilteredConversations, mailReviewCount])

  const visibleConversations = useMemo(() => {
    const normalizedQuery = searchQuery.trim().toLowerCase()

    return tagFilteredConversations.filter((conversation) => {
      if (!matchesConversationView(conversation, viewType)) return false
      if (!normalizedQuery) return true

      const contactName = conversation.contact?.name?.toLowerCase() ?? ""
      const contactPhone = conversation.contact?.phone?.toLowerCase() ?? ""
      const lastMessage = conversation.last_message?.toLowerCase() ?? ""
      const channelName = conversation.channel?.name?.toLowerCase() ?? ""

      return (
        contactName.includes(normalizedQuery) ||
        contactPhone.includes(normalizedQuery) ||
        lastMessage.includes(normalizedQuery) ||
        channelName.includes(normalizedQuery)
      )
    })
  }, [tagFilteredConversations, searchQuery, viewType])

  useEffect(() => {
    getMailIntakeCount().then(({ data }) => setMailReviewCount(data.pending)).catch(() => undefined)
  }, [])

  // Búsqueda server-side por contenido de mensajes (debounce; el filtro client-side sigue instantáneo)
  useEffect(() => {
    const query = searchQuery.trim()
    if (query.length < 2) {
      setMessageSearchResults([])
      setIsSearchingMessages(false)
      return
    }

    let cancelled = false
    setIsSearchingMessages(true)
    const timeout = setTimeout(async () => {
      try {
        const results = await searchConversationsByContent(query, { channelId: selectedChannelId })
        if (!cancelled) setMessageSearchResults(results)
      } catch {
        if (!cancelled) setMessageSearchResults([])
      } finally {
        if (!cancelled) setIsSearchingMessages(false)
      }
    }, 400)

    return () => {
      cancelled = true
      clearTimeout(timeout)
    }
  }, [searchQuery, selectedChannelId])

  // Los resultados por contenido respetan los mismos filtros activos que la lista
  // principal (tipo de canal, no-leídos, tags y vista), además del dedupe.
  const messageOnlyResults = useMemo(() => {
    if (searchQuery.trim().length < 2) return []

    const visibleIds = new Set(visibleConversations.map((c) => c.id))
    const tagSet = new Set(tagFilterSlugs)
    const stageSet = new Set(stageFilter)
    const targetType = filterTypeToChannelType(activeFilter)
    const matchingChannelIds = targetType
      ? new Set(channels.filter((ch) => ch.type === targetType).map((ch) => ch.id))
      : null

    return messageSearchResults.filter((conversation) => {
      if (visibleIds.has(conversation.id)) return false
      if (matchingChannelIds && !matchingChannelIds.has(conversation.channelId)) return false
      if (tagSet.size > 0 && !conversation.tags?.some((tag) => tagSet.has(tag.slug))) return false
      if (!matchesStageFilter(conversation, stageSet)) return false
      return matchesConversationView(conversation, viewType)
    })
  }, [messageSearchResults, visibleConversations, searchQuery, activeFilter, channels, tagFilterSlugs, stageFilter, viewType])

  // Channels and the first lightweight cursor page are independent.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      setIsChannelsLoading(true)
      setChannelsLoadFailed(false)
      const [channelsResult] = await Promise.allSettled([
        getChannels(),
      ])
      if (cancelled) return

      if (channelsResult.status === "fulfilled") {
        setChannels(channelsResult.value)
      } else {
        setChannelsLoadFailed(true)
        addToast({ type: "error", title: t("chats.loadAccountsError"), description: t("chats.loadAccountsErrorDesc") })
      }

      setIsChannelsLoading(false)
    })()
    return () => { cancelled = true }
  }, [channelsReloadKey])

  // Channel connection events
  useEffect(() => {
    const CONNECTING_TOAST_ID = "channel-connecting"

    const onConnecting = () => {
      addToast({ id: CONNECTING_TOAST_ID, type: "loading", title: t("chats.channelConnecting"), description: t("chats.channelConnectingDesc") })
    }

    const onConnected = async () => {
      removeToast(CONNECTING_TOAST_ID)
      try {
        const data = await getChannels()
        setChannels(data)
        addToast({ type: "success", title: t("chats.channelConnected"), description: t("chats.channelConnectedDesc") })
      } catch (e) {
        console.error("Error refetching channels:", e)
      }
    }

    const onError = (event: Event) => {
      removeToast(CONNECTING_TOAST_ID)
      const detail = (event as CustomEvent<ChannelErrorDetail>).detail
      // `message` es texto redactado por el backend; `code` se traduce al idioma activo.
      const description = detail?.message
        || (detail?.code ? t(`chats.${detail.code}`) : t("chats.channelErrorDesc"))
      addToast({ type: "error", title: t("chats.channelError"), description })
    }

    window.addEventListener("channel-connecting", onConnecting)
    window.addEventListener("channel-connected", onConnected)
    window.addEventListener("channel-error", onError)
    return () => {
      window.removeEventListener("channel-connecting", onConnecting)
      window.removeEventListener("channel-connected", onConnected)
      window.removeEventListener("channel-error", onError)
    }
  }, [])

  useEffect(() => {
    if (chatIdFromUrl) {
      const id = parseInt(chatIdFromUrl, 10)
      setSelectedConversationId(id)
    } else {
      setSelectedConversationId(null)
    }
  }, [chatIdFromUrl])

  // Reset the cursor whenever the active channel changes.
  useEffect(() => {
    void loadConversationPage(true)
  }, [loadConversationPage, selectedChannelId]);

  // Single effect: fetch conversation with messages when selected
  useEffect(() => {
    if (!selectedConversationId) {
      setCurrentConversation(null);
      setAiDraft(null);
      return;
    }

    // Clear stale data immediately so we never show conversation A under conversation B's header
    setCurrentConversation(null);
    setConversationLoadError(null);
    setLoadMoreMessagesFailed(false);

    let cancelled = false;
    (async () => {
      try {
        const [data, draft] = await Promise.all([
          getConversationWithMessages(selectedConversationId),
          getManualAiDraft(selectedConversationId).catch(() => null),
        ]);
        if (!cancelled) { setCurrentConversation(data); setAiDraft(draft); }
      } catch (error) {
        if (cancelled) return;
        setCurrentConversation(null);
        if (error instanceof ConversationUnavailableError) {
          setConversationLoadError("unavailable");
          return;
        }
        setConversationLoadError("failed");
        addToast({
          type: "error",
          title: t("chats.loadConversationError"),
          description: error instanceof Error ? error.message : t("chats.unknownError"),
        });
      }
    })();
    return () => { cancelled = true };
  }, [selectedConversationId, conversationReloadKey]);

  // La conversación abierta por deep link puede no estar en la primera página: se suma a la
  // lista (en su lugar por fecha) para que las acciones que leen la lista funcionen. Si luego
  // llega por paginación, loadConversationPage ya descarta ids repetidos.
  useEffect(() => {
    if (!currentConversation || currentConversation.id !== selectedConversationId) return;
    if (selectedChannelId && currentConversation.channelId !== selectedChannelId) return;
    if (isLoading || conversations.some((c) => c.id === currentConversation.id)) return;

    // Sin mensajes: la lista sólo necesita el resumen.
    const entry: Conversation = { ...currentConversation, messages: undefined };
    const entryTime = new Date(entry.last_message_at || entry.timestamp || 0).getTime();
    setConversations((prev) => {
      if (prev.some((c) => c.id === entry.id)) return prev;
      const index = prev.findIndex((c) => new Date(c.last_message_at || c.timestamp || 0).getTime() < entryTime);
      if (index === -1) return [...prev, entry];
      return [...prev.slice(0, index), entry, ...prev.slice(index)];
    });
  }, [conversations, currentConversation, isLoading, selectedChannelId, selectedConversationId]);

  useEffect(() => {
    const userId = user?.id;
    if (!userId) return;
    const pusher = getPusher();
    const channel = pusher.subscribe(`private-App.Models.User.${userId}`);
    const onDraft = (event: { draft: ManualAiDraft }) => {
      if (event.draft.conversation_id === selectedConversationId) setAiDraft(event.draft.status === "cancelled" ? null : event.draft);
    };
    channel.bind("manual-ai-draft.updated", onDraft);
    // Sólo unbind: el canal del usuario lo comparte el listener global de
    // notificaciones (AppShell); desuscribirlo acá lo dejaría sordo.
    return () => { channel.unbind("manual-ai-draft.updated", onDraft); };
  }, [user?.id, selectedConversationId]);

  useEffect(() => {
    setActiveConversationId(selectedConversationId);
    if (selectedConversationId) {
      useNotificationsStore.getState().markGroupRead(`conversation:${selectedConversationId}`).catch(() => {});
    }
    return () => setActiveConversationId(null);
  }, [selectedConversationId]);

  useEffect(() => {
    if (!selectedConversationId || aiDraft?.status !== "pending") return;
    const timer = window.setInterval(() => {
      getManualAiDraft(selectedConversationId).then(setAiDraft).catch(() => {});
    }, 2500);
    return () => window.clearInterval(timer);
  }, [selectedConversationId, aiDraft?.status]);

  const latestInboundMessage = useMemo(() => {
    const latest = [...(currentConversation?.messages ?? [])].filter((item) => !item.deleted_at).reverse()[0]
    return latest?.direction === "inbound" && (latest.message_type === "text" || latest.message_type === "image") ? latest : undefined
  }, [currentConversation?.messages]);
  const handleRequestAiDraft = useCallback(async () => {
    if (!selectedConversationId || !latestInboundMessage) return;
    try {
      const draft = await requestManualAiDraft(selectedConversationId, latestInboundMessage.id);
      setAiDraft(draft);
    } catch (error) {
      addToast({ type: "error", title: t("chats.aiDraftError"), description: error instanceof Error ? error.message : undefined });
    }
  }, [selectedConversationId, latestInboundMessage, addToast, t]);
  const handleCancelAiDraft = useCallback(async () => {
    if (!selectedConversationId) return;
    await cancelManualAiDraft(selectedConversationId).catch(() => {});
    setAiDraft(null);
  }, [selectedConversationId]);
  const handleUseAiDraft = useCallback(() => {
    if (aiDraft?.content && !useComposerStore.getState().draft.trim()) setMessage(aiDraft.content);
  }, [aiDraft?.content, setMessage]);


  const handleConnectChannel = (channelType: ConnectableChannel = "whatsapp") => {
    if (channelType === "messenger") {
      // El flujo de Messenger es popup + redirect, no depende del SDK JS.
      launchMessengerLogin()
      return
    }

    if (channelType === "mail") {
      setMailConnectOpen(true)
      return
    }

    const sdkLoaded = channelType === "instagram" ? isInstagramSDKLoaded : isFacebookSDKLoaded
    if (!sdkLoaded) {
      addToast({
        type: "loading",
        title: t("chats.facebookLoadingTitle"),
        description: t("chats.facebookLoadingDesc"),
      })
      return
    }

    if (channelType === "instagram") {
      launchInstagramLogin()
    } else {
      launchWhatsAppSignup()
    }
  }

  const handleFilterChange = (filter: FilterType) => {
    tenantRefreshVersionRef.current++
    setActiveFilter(filter)
    setViewType("inbox")
    setSelectedChannel(null)
    setSelectedConversationId(null)
    setSelectedChannelId(null)
    router.replace("/chats")
  }

  const handleConversationClick = (conversationId: number) => {
    setSelectedConversationId(conversationId)
    router.push(`/chats?chat=${conversationId}`)

    const target = conversationsRef.current.find((c) => c.id === conversationId)
    if (target?.unread || (target?.unread_count ?? 0) > 0 || target?.manual_unread) {
      setConversations((prev) => prev.map((c) => (
        c.id === conversationId ? { ...c, unread: false, unread_count: 0, manual_unread: false } : c
      )))

      markConversationAsRead(conversationId)
        .then(() => {
          if (typeof window !== "undefined") {
            window.dispatchEvent(new Event("conversations:unread-changed"))
          }
        })
        .catch((err) => {
          console.warn("markConversationAsRead failed", err)
        })
    }
  }

  const handleToggleReadStatus = useCallback((conversationId: number) => {
    const target = conversationsRef.current.find((c) => c.id === conversationId)
    if (!target) {
      return
    }

    const originalUnread = Boolean(target.unread)
    const originalUnreadCount = target.unread_count ?? 0
    const originalManualUnread = Boolean(target.manual_unread)
    const isCurrentlyUnread = originalUnread || originalUnreadCount > 0 || originalManualUnread

    setConversations((prev) => prev.map((c) => (
      c.id === conversationId
        ? { ...c, unread: !isCurrentlyUnread, unread_count: isCurrentlyUnread ? 0 : c.unread_count, manual_unread: !isCurrentlyUnread }
        : c
    )))

    const action = isCurrentlyUnread
      ? markConversationAsRead(conversationId)
      : markConversationAsUnread(conversationId)

    const revertOptimisticUpdate = () => {
      setConversations((prev) => prev.map((c) => (
        c.id === conversationId
          ? { ...c, unread: originalUnread, unread_count: originalUnreadCount, manual_unread: originalManualUnread }
          : c
      )))
    }

    action
      .then((marked) => {
        if (marked === 0) {
          revertOptimisticUpdate()
          return
        }

        if (typeof window !== "undefined") {
          window.dispatchEvent(new Event("conversations:unread-changed"))
        }
      })
      .catch((err) => {
        console.warn("toggle read status failed", err)
        revertOptimisticUpdate()
      })
  }, [])

  const handleBackToConversations = useCallback(() => {
    setSelectedConversationId(null)
    router.replace("/chats")
  }, [router])

  const handleThreadSearchOpenChange = useCallback((open: boolean) => {
    setThreadSearchFor(open ? selectedConversationId : null)
  }, [selectedConversationId])

  const handleBackToChannels = useCallback(() => {
    tenantRefreshVersionRef.current++
    setSelectedChannel(null)
    setSelectedChannelId(null)
    setSelectedConversationId(null)
    router.replace("/chats")
  }, [router])

  const handleChannelSelect = useCallback((channelId: number) => {
    tenantRefreshVersionRef.current++
    setSelectedChannel(channelId)
    setSelectedChannelId(channelId)

    setSelectedConversationId(null)

    router.replace("/chats")

  }, [router])

  const handleRenameChannel = useCallback(async (channelId: number, name: string) => {
    const trimmed = name.trim()
    if (!trimmed) return
    try {
      const updated = await updateChannelName(channelId, trimmed)
      setChannels((prev) => prev.map((c) => (c.id === channelId ? { ...c, name: updated.name } : c)))
      addToast({ type: "success", title: t("chats.renameChannelSuccess") })
    } catch {
      addToast({ type: "error", title: t("chats.renameChannelError") })
    }
  }, [addToast, t])

  // El diálogo se cierra sólo si esto no tira: ante un error queda abierto
  // para que el usuario corrija o reintente.
  const handleSaveHandoffSettings = useCallback(async (channelId: number, settings: ChannelHandoffSettings) => {
    try {
      const updated = await updateChannelHandoffSettings(channelId, settings)
      setChannels((prev) => prev.map((c) => (c.id === channelId
        ? {
            ...c,
            handoff_responsible_user_id: updated.handoff_responsible_user_id ?? null,
            handoff_whatsapp_alert_enabled: updated.handoff_whatsapp_alert_enabled ?? c.handoff_whatsapp_alert_enabled,
          }
        : c)))
      addToast({ type: "success", title: t("chats.handoffSettings.saved") })
    } catch (err) {
      addToast({
        type: "error",
        title: t("chats.handoffSettings.error"),
        description: err instanceof Error ? err.message : undefined,
      })
      throw err
    }
  }, [addToast, t])

  // Sincronización manual de una casilla de email. El backend sólo encola el
  // job, así que confirmamos el pedido: los mensajes llegan por websocket.
  const handleSyncMailChannel = useCallback(async (channelId: number) => {
    try {
      await syncMailChannel(channelId)
      addToast({ type: "success", title: t("chats.mailSyncQueued") })
    } catch (err) {
      const title = err instanceof ChannelError
        ? (err.detail.message || (err.detail.code ? t(`chats.${err.detail.code}`) : t("chats.mailSyncError")))
        : t("chats.mailSyncError")
      addToast({ type: "error", title })
    }
  }, [addToast, t])

  // Desconexión "blanda": el canal pasa a la sección "Desconectados" pero
  // conserva su historial. Si era el canal seleccionado, hay que volver al
  // listado — si no, la bandeja queda filtrada por un canal ya no clickeable.
  const handleDisconnectChannel = useCallback(async (channelId: number) => {
    try {
      const { warnings } = await disconnectChannel(channelId)
      setChannels((prev) => prev.map((c) => (c.id === channelId ? { ...c, status: "disconnected" } : c)))
      if (selectedChannelIdRef.current === channelId) {
        setSelectedChannel(null)
        setSelectedChannelId(null)
        setSelectedConversationId(null)
      }
      if (warnings.length > 0) {
        addToast({ type: "info", title: t("chats.disconnectChannelWarning"), description: warnings.join(" ") })
      } else {
        addToast({ type: "success", title: t("chats.disconnectChannelSuccess") })
      }
    } catch (err) {
      const title = err instanceof Error && err.message ? err.message : t("chats.disconnectChannelError")
      addToast({ type: "error", title })
    }
  }, [addToast, t])

  // Devuelve el rollback que TemplatePicker invoca si el envío falla.
  const handleSendTemplate = (content: string) => {
    if (!selectedConversationId) return;
    const conversationId = selectedConversationId;
    const previousLastMessage = conversationsRef.current.find((c) => c.id === conversationId)?.last_message;

    const tempId = `temp-${Date.now()}-${Math.random()}`;
    const optimisticMessage: any = {
      id: tempId,
      content,
      type: "text",
      created_at: new Date().toISOString(),
      status: "sending",
      conversation_id: conversationId,
      direction: "outbound",
      sender_type: "user",
    };

    setCurrentConversation((prev) => {
      if (!prev) return prev;
      // Handoff: enviar una plantilla también apaga la auto-respuesta IA en el backend.
      return { ...prev, aiAutoreplyEnabled: false, messages: [...(prev.messages || []), optimisticMessage] };
    });

    setConversations((prevConversations) => {
      const index = prevConversations.findIndex((c) => c.id === conversationId);
      if (index === -1) return prevConversations;
      const updated = { ...prevConversations[index], aiAutoreplyEnabled: false, last_message: content, updated_at: new Date().toISOString() };
      const next = [...prevConversations];
      next.splice(index, 1);
      return [updated, ...next];
    });

    // Igual que con los mensajes de texto: si falla, el optimista se quita del hilo.
    return () => {
      setCurrentConversation((prev) => (
        prev && prev.id === conversationId
          ? { ...prev, messages: (prev.messages || []).filter((m) => String(m.id) !== tempId) }
          : prev
      ));
      if (previousLastMessage === undefined) return;
      setConversations((prev) => prev.map((c) => (
        c.id === conversationId && c.last_message === content ? { ...c, last_message: previousLastMessage } : c
      )));
    };
  };

  const handleEditMessage = useCallback((msg: Message) => {
    setEditingMessage(msg);
    setMessage(msg.content || "");
  }, []);

  const handleCancelEdit = useCallback(() => {
    setEditingMessage(null);
    setMessage("");
  }, []);

  const handleSaveEdit = async (content: string) => {
    if (!editingMessage) return;

    try {
      const updated = await editMessage(editingMessage.id, content);
      setCurrentConversation((prev) => {
        if (!prev) return prev;
        return {
          ...prev,
          messages: (prev.messages || []).map((m: Message) =>
            m.id === editingMessage.id
              ? {
                  ...m,
                  content: updated.content,
                  edited_at: updated.edited_at,
                  original_content: updated.original_content ?? m.original_content,
                }
              : m
          ),
        };
      });
      setEditingMessage(null);
      setMessage("");
      addToast({ type: "success", title: t("chats.messageEdited") });
    } catch (error) {
      console.error('[ChatsPage] Error editing message:', error);
      addToast({
        type: "error",
        title: t("chats.editMessageError"),
        description: error instanceof Error ? error.message : t("chats.unknownError"),
      });
    }
  };

  const handleToggleArchive = useCallback(async () => {
    if (!selectedConversationId) return;

    const targetId = selectedConversationId;
    const current = conversationsRef.current.find((c) => c.id === targetId);
    const previous = Boolean(current?.archived);
    const next = !previous;

    setConversations((prev) => prev.map((c) =>
      c.id === targetId ? { ...c, archived: next } : c
    ));
    setCurrentConversation((prev) => (prev && prev.id === targetId ? { ...prev, archived: next } : prev));

    try {
      await archiveConversation(targetId, next);
    } catch (error) {
      setConversations((prev) => prev.map((c) =>
        c.id === targetId ? { ...c, archived: previous } : c
      ));
      setCurrentConversation((prev) => (prev && prev.id === targetId ? { ...prev, archived: previous } : prev));
      addToast({
        type: "error",
        title: t("chats.archiveError"),
        description: error instanceof Error ? error.message : t("chats.unknownError"),
      });
    }
  }, [selectedConversationId, addToast, t]);

  const handleToggleAiAutoreply = useCallback(async (enabled: boolean) => {
    if (!selectedConversationId) return;

    const targetId = selectedConversationId;
    const current = conversationsRef.current.find((c) => c.id === targetId);
    const previous = Boolean(current?.aiAutoreplyEnabled);

    setConversations((prev) => prev.map((c) =>
      c.id === targetId ? { ...c, aiAutoreplyEnabled: enabled } : c
    ));
    setCurrentConversation((prev) => (prev && prev.id === targetId ? { ...prev, aiAutoreplyEnabled: enabled } : prev));

    try {
      await setAiAutoreply(targetId, enabled);
    } catch (error) {
      setConversations((prev) => prev.map((c) =>
        c.id === targetId ? { ...c, aiAutoreplyEnabled: previous } : c
      ));
      setCurrentConversation((prev) => (prev && prev.id === targetId ? { ...prev, aiAutoreplyEnabled: previous } : prev));
      addToast({
        type: "error",
        title: t("chats.aiAutoreplyError"),
        description: error instanceof Error ? error.message : t("chats.unknownError"),
      });
    }
  }, [selectedConversationId, addToast, t]);

  const handleTranslateMessage = useCallback(
    (targetMessage: Message, targetLanguage: TranslationLanguage) => (
      translateMessage(targetMessage.id, targetLanguage)
    ),
    [],
  )

  const handleTranslateDraft = useCallback(async (
    content: string,
    targetLanguage: TranslationLanguage,
  ) => {
    if (!selectedConversationId) throw new Error(t("chats.noConversationSelected"))
    const result = await translateDraft(selectedConversationId, content, targetLanguage)
    return result.translated_content
  }, [selectedConversationId, t])

  const handleTranslationLanguageChange = useCallback(async (nextLanguage: TranslationLanguage) => {
    if (!selectedConversationId) return

    const targetId = selectedConversationId
    const previous = (currentConversation ?? activeConversation)?.contactLanguage ?? "es"

    setConversations((items) => items.map((conversation) => (
      conversation.id === targetId ? { ...conversation, contactLanguage: nextLanguage } : conversation
    )))
    setCurrentConversation((conversation) => (
      conversation?.id === targetId ? { ...conversation, contactLanguage: nextLanguage } : conversation
    ))

    try {
      await setConversationTranslationLanguage(targetId, nextLanguage)
    } catch (error) {
      setConversations((items) => items.map((conversation) => (
        conversation.id === targetId ? { ...conversation, contactLanguage: previous } : conversation
      )))
      setCurrentConversation((conversation) => (
        conversation?.id === targetId ? { ...conversation, contactLanguage: previous } : conversation
      ))
      addToast({
        type: "error",
        title: t("chats.languageSaveError"),
        description: error instanceof Error ? error.message : undefined,
      })
    }
  }, [activeConversation, addToast, currentConversation, selectedConversationId, t])

  const handleReactToMessage = useCallback(async (msg: Message, emoji: string) => {
    const previous = msg.reaction_summary ?? [];
    const optimistic = applyReactionToggle(previous, emoji, currentUserId);

    const patch = (summary: Message["reaction_summary"]) =>
      setCurrentConversation((prev) => {
        if (!prev) return prev;
        return {
          ...prev,
          messages: (prev.messages || []).map((m: Message) =>
            m.id === msg.id ? { ...m, reaction_summary: summary } : m
          ),
        };
      });

    patch(optimistic);

    try {
      const confirmed = await reactToMessage(msg.id, emoji);
      patch(confirmed);
    } catch (error) {
      // Rollback sobre el estado ACTUAL del mensaje, no sobre el snapshot de
      // antes del click: si un evento SSE actualizó reaction_summary mientras
      // el POST estaba en vuelo (reacción del contacto, otra pestaña), un
      // rollback a `previous` lo pisaría y lo perdería silenciosamente.
      setCurrentConversation((prev) => {
        if (!prev) return prev;
        return {
          ...prev,
          messages: (prev.messages || []).map((m: Message) => {
            if (m.id !== msg.id) return m;
            const current = m.reaction_summary ?? [];
            // Si el estado actual sigue siendo la proyección optimista propia
            // (nadie más lo tocó), se revierte al snapshot pre-click; si ya
            // cambió por SSE, se deja como está.
            return current === optimistic ? { ...m, reaction_summary: previous } : m;
          }),
        };
      });
      addToast({
        type: "error",
        title: t("chats.reactionError"),
        description: error instanceof Error ? error.message : t("chats.unknownError"),
      });
    }
  }, [currentUserId, addToast, t]);

  const handleDeleteMessage = useCallback(async (msg: Message) => {
    try {
      await deleteMessage(msg.id);
      setCurrentConversation((prev) =>
        patchConversationMessage(prev, (m) => m.id === msg.id, (m) => ({ ...m, deleted_at: new Date().toISOString() })),
      );
      addToast({ type: "success", title: t("chats.messageDeletedSuccess") });
    } catch (error) {
      console.error('[ChatsPage] Error deleting message:', error);
      addToast({
        type: "error",
        title: t("chats.deleteMessageError"),
        description: error instanceof Error ? error.message : t("chats.unknownError"),
      });
    }
  }, [addToast, t]);

  const handleSendMessage = async (content: string, media?: File, voice = false) => {
    if (!content && !media) return;
    if (!selectedConversationId) return;

    const textToSend = content;
    const mediaType = media?.type.startsWith("audio/") ? "audio" : media ? "image" : "text";

    setMessage("");

    const tempId = `temp-${Date.now()}-${Math.random()}`;
    const optimisticMessage: any = {
      id: tempId,
      content: textToSend,
      message_type: mediaType,
      media_url: media ? URL.createObjectURL(media) : null,
      media_filename: media?.name ?? null,
      created_at: new Date().toISOString(),
      status: "sending",
      conversation_id: selectedConversationId,
      direction: "outbound",
      sender_type: "user",
      sender_id: currentUserId,
    };

    setCurrentConversation((prev) => {
      if (!prev) return prev;
      return { ...prev, messages: [...(prev.messages || []), optimisticMessage] };
    });

    const sidebarText = mediaType === "audio"
      ? "🎵 Audio"
      : mediaType === "image"
        ? `📷 ${textToSend || 'Imagen'}`
        : textToSend;
    setConversations((prevConversations) => {
      const index = prevConversations.findIndex(c => c.id === selectedConversationId);
      if (index === -1) return prevConversations;

      const updatedConversation = {
        ...prevConversations[index],
        last_message: sidebarText,
        updated_at: new Date().toISOString(),
      };

      const newConversations = [...prevConversations];
      newConversations.splice(index, 1);
      return [updatedConversation, ...newConversations];
    });

    try {
      const savedMessage = await sendMessage(selectedConversationId, textToSend, media, voice);

      setCurrentConversation((prev) => {
        if (!prev) return prev;

        return {
          ...prev,
          // Handoff: al responder un agente, el backend apaga la auto-respuesta
          // IA. Reflejarlo aquí para que el switch no quede encendido (estado
          // obsoleto) hasta un refetch.
          aiAutoreplyEnabled: false,
          messages: (prev.messages || []).map((m: Message) =>
            String(m.id) === tempId
              ? {
                  ...savedMessage,
                  content: normalizeIncomingTemplateContent(savedMessage.content, textToSend),
                }
              : m
          ),
        };
      });

      setConversations((prevConversations) => {
        const index = prevConversations.findIndex(c => c.id === selectedConversationId);
        if (index === -1) return prevConversations;

        const updatedConversation = {
          ...prevConversations[index],
          aiAutoreplyEnabled: false,
          last_message: normalizeIncomingTemplateContent(
            mediaType === "audio"
              ? "🎵 Audio"
              : mediaType === "image"
                ? `📷 ${savedMessage.content || "Imagen"}`
                : savedMessage.content,
            prevConversations[index].last_message
          ),
          updated_at: savedMessage.created_at,
        };

        const newConversations = [...prevConversations];
        newConversations.splice(index, 1);
        return [updatedConversation, ...newConversations];
      });
    } catch (error) {
      const errorMessage = error instanceof Error ? error.message : undefined;

      if (!isExpectedBusinessErrorMessage(errorMessage)) {
        console.error('[ChatsPage] Error sending message:', error);
      }

      if (optimisticMessage.media_url) {
        URL.revokeObjectURL(optimisticMessage.media_url);
      }

      addToast({
        type: "error",
        title: t("chats.sendMessageError"),
        description: errorMessage || t("chats.sendMessageErrorDesc")
      });

      setMessage(textToSend);

      setCurrentConversation((prev) => {
        if (!prev) return prev;
        return { ...prev, messages: (prev.messages || []).filter((m: any) => m.id !== tempId) };
      });

      setConversations((prevConversations) => {
        const index = prevConversations.findIndex(c => c.id === selectedConversationId);
        if (index === -1) return prevConversations;

        const conversation = prevConversations[index];
        const previousMessage = conversation.messages?.[conversation.messages.length - 2];

        return prevConversations.map(c =>
          c.id === selectedConversationId
            ? { ...c, last_message: previousMessage?.content || '', updated_at: previousMessage?.created_at }
            : c
        );
      });
    }
  };

  const handleScheduleMessage = async (content: string, scheduledFor: Date, media?: File): Promise<boolean> => {
    if (!selectedConversationId) return false;
    try {
      const scheduled = await createScheduledMessage(selectedConversationId, { content, scheduledFor, media });
      scheduledMessages.upsert(scheduled);
      setMessage("");
      addToast({
        type: "success",
        title: t("chats.scheduledToast", {
          when: scheduledFor.toLocaleString(language === "en" ? "en-US" : "es-AR", { dateStyle: "medium", timeStyle: "short" }),
        }),
      });
      return true;
    } catch (error) {
      addToast({
        type: "error",
        title: t("chats.sendMessageError"),
        description: error instanceof Error ? error.message : undefined,
      });
      return false;
    }
  };

  const handleShareContacts = async (contactIds: number[]) => {
    if (!selectedConversationId) return
    try {
      const savedMessage = await sendContactsMessage(selectedConversationId, contactIds)
      setCurrentConversation((prev) => prev ? { ...prev, aiAutoreplyEnabled: false, messages: [...(prev.messages || []), savedMessage] } : prev)
      setConversations((prev) => {
        const index = prev.findIndex((conversation) => conversation.id === selectedConversationId)
        if (index < 0) return prev
        const next = [...prev]
        const current = next[index]
        next.splice(index, 1)
        return [{ ...current, last_message: `👤 ${contactIds.length} contactos compartidos`, updated_at: savedMessage.created_at, aiAutoreplyEnabled: false }, ...next]
      })
      addToast({ type: "success", title: t("chats.contactsSent") })
    } catch (error) {
      addToast({ type: "error", title: t("chats.contactsSendError"), description: error instanceof Error ? error.message : undefined })
    }
  }

  const handleSendFlow = async (input: SendFlowInput) => {
    if (!selectedConversationId) return
    const conversationId = selectedConversationId
    const savedMessage = await sendFlowMessage(conversationId, input)
    setCurrentConversation((prev) => prev && prev.id === conversationId ? { ...prev, aiAutoreplyEnabled: false, messages: [...(prev.messages || []), savedMessage] } : prev)
    setConversations((prev) => {
      const index = prev.findIndex((conversation) => conversation.id === conversationId)
      if (index < 0) return prev
      const next = [...prev]
      const current = next[index]
      next.splice(index, 1)
      return [{ ...current, last_message: savedMessage.content, updated_at: savedMessage.created_at, aiAutoreplyEnabled: false }, ...next]
    })
    addToast({ type: "success", title: t("chats.flows.sent") })
  }

  const handleSaveSharedContact = useCallback(async (message: Message, index: number) => {
    try {
      await saveSharedContact(message.id, index)
      addToast({ type: "success", title: t("chats.contactSaved") })
    } catch (error) {
      addToast({ type: "error", title: t("chats.contactSaveError"), description: error instanceof Error ? error.message : undefined })
    }
  }, [addToast, t])

  const handleSendMail = async (input: SendMailMessageInput) => {
    if (!selectedConversationId) return

    const tempId = `temp-mail-${Date.now()}`
    const messages = currentConversation?.messages ?? []
    const latestSubject = [...messages].reverse().find((item) => item.mail_details?.subject)?.mail_details?.subject
      || t("chats.mailNoSubject")
    const optimisticMessage = {
      id: tempId,
      conversation_id: selectedConversationId,
      content: input.content,
      message_type: "text",
      direction: "outbound",
      sender_type: "user",
      sender_id: currentUserId,
      created_at: new Date().toISOString(),
      mail_details: {
        id: -1,
        message_id: -1,
        subject: latestSubject.startsWith("Re:") ? latestSubject : `Re: ${latestSubject}`,
        body_text: input.content,
        body_html: input.contentHtml,
        from: activeConversation?.channel?.mail_config
          ? { email: activeConversation.channel.mail_config.email_address, name: activeConversation.channel.mail_config.from_name }
          : null,
        to: [],
        cc: input.cc?.map((email) => ({ email })) ?? [],
        bcc: input.bcc?.map((email) => ({ email })) ?? [],
        has_remote_images: false,
      },
      mail_attachments: input.attachments?.map((file, index) => ({
        id: -(index + 1),
        conversation_id: selectedConversationId,
        content: "",
        message_type: "document" as const,
        media_filename: file.name,
        sender_type: "user" as const,
        direction: "outbound" as const,
        created_at: new Date().toISOString(),
      })) ?? [],
    } as unknown as Message

    setMessage("")
    setCurrentConversation((previous) => previous
      ? { ...previous, messages: [...(previous.messages ?? []), optimisticMessage] }
      : previous)

    try {
      const savedMessage = await sendMailMessage(selectedConversationId, input)
      setCurrentConversation((previous) => previous ? {
        ...previous,
        aiAutoreplyEnabled: false,
        messages: (previous.messages ?? []).map((item) => String(item.id) === tempId ? savedMessage : item),
      } : previous)
      setConversations((items) => items.map((conversation) => conversation.id === selectedConversationId
        ? {
            ...conversation,
            aiAutoreplyEnabled: false,
            last_message: `${savedMessage.mail_details?.subject || latestSubject} · ${savedMessage.content}`,
            updated_at: savedMessage.created_at,
          }
        : conversation))
    } catch (error) {
      setCurrentConversation((previous) => previous ? {
        ...previous,
        messages: (previous.messages ?? []).filter((item) => String(item.id) !== tempId),
      } : previous)
      setMessage(input.content)
      addToast({
        type: "error",
        title: t("chats.sendMessageError"),
        description: error instanceof Error ? error.message : t("chats.sendMessageErrorDesc"),
      })
      throw error
    }
  }



  const refreshConversations = useCallback(async () => {
    await loadConversationPage(true)
  }, [loadConversationPage])

  const handleToggleSelectionMode = useCallback(() => {
    setSelectionMode((prev) => {
      if (prev) setSelectedConversationIds(new Set())
      return !prev
    })
    setSelectedConversationId(null)
    router.replace("/chats")
  }, [router])

  const handleToggleSelectConversation = useCallback((id: number) => {
    setSelectedConversationIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }, [])

  const handleSelectAllVisible = useCallback(() => {
    setSelectedConversationIds((prev) => {
      const allVisibleIds = visibleConversations.map((c) => c.id)
      const allSelected = allVisibleIds.every((id) => prev.has(id))
      if (allSelected) {
        const next = new Set(prev)
        for (const id of allVisibleIds) next.delete(id)
        return next
      }
      const next = new Set(prev)
      for (const id of allVisibleIds) next.add(id)
      return next
    })
  }, [visibleConversations])

  const handleClearSelection = useCallback(() => {
    setSelectedConversationIds(new Set())
  }, [])

  const handleExitSelectionMode = useCallback(() => {
    setSelectionMode(false)
    setSelectedConversationIds(new Set())
  }, [])

  const handleBulkArchive = useCallback(async (archive: boolean) => {
    if (selectedConversationIds.size === 0) return
    setBulkSubmitting(true)
    try {
      const result = await bulkArchiveConversations({
        ids: Array.from(selectedConversationIds),
        archived: archive,
      })
      addToast({
        type: result.failed > 0 ? "info" : "success",
        title: result.failed > 0
          ? t("chats.bulk.result.partial", { updated: result.updated, failed: result.failed })
          : t("chats.bulk.result.success", { updated: result.updated }),
      })
      await refreshConversations()
      handleExitSelectionMode()
    } catch (err) {
      addToast({
        type: "error",
        title: t("chats.bulk.errors.apply"),
        description: err instanceof Error ? err.message : "",
      })
    } finally {
      setBulkSubmitting(false)
    }
  }, [selectedConversationIds, addToast, t, refreshConversations, handleExitSelectionMode])

  const handleBulkDelete = useCallback(async () => {
    if (selectedConversationIds.size === 0) return
    setBulkSubmitting(true)
    try {
      const result = await bulkDeleteConversations({
        ids: Array.from(selectedConversationIds),
      })
      addToast({
        type: result.failed > 0 ? "info" : "success",
        title: result.failed > 0
          ? t("chats.bulk.result.partialDelete", { deleted: result.deleted, failed: result.failed })
          : t("chats.bulk.result.successDelete", { deleted: result.deleted }),
      })
      await refreshConversations()
      setBulkDeleteOpen(false)
      handleExitSelectionMode()
    } catch (err) {
      addToast({
        type: "error",
        title: t("chats.bulk.errors.delete"),
        description: err instanceof Error ? err.message : "",
      })
    } finally {
      setBulkSubmitting(false)
    }
  }, [selectedConversationIds, addToast, t, refreshConversations, handleExitSelectionMode])

  const handleBulkAiAutoreply = useCallback(async (enabled: boolean) => {
    if (selectedConversationIds.size === 0) return
    setBulkSubmitting(true)
    try {
      const result = await bulkAiAutoreplyConversations({
        ids: Array.from(selectedConversationIds),
        enabled,
      })
      addToast({
        type: result.failed > 0 ? "info" : "success",
        title: result.failed > 0
          ? t("chats.bulk.result.partial", { updated: result.updated, failed: result.failed })
          : t("chats.bulk.result.success", { updated: result.updated }),
      })
      await refreshConversations()
      handleExitSelectionMode()
    } catch (err) {
      addToast({
        type: "error",
        title: t("chats.bulk.errors.apply"),
        description: err instanceof Error ? err.message : "",
      })
    } finally {
      setBulkSubmitting(false)
    }
  }, [selectedConversationIds, addToast, t, refreshConversations, handleExitSelectionMode])

  const handleBulkMarkRead = useCallback(async (read: boolean) => {
    if (selectedConversationIds.size === 0) return
    setBulkSubmitting(true)
    try {
      const result = await bulkMarkReadConversations({
        ids: Array.from(selectedConversationIds),
        read,
      })
      addToast({
        type: result.failed > 0 ? "info" : "success",
        title: result.failed > 0
          ? t("chats.bulk.result.partial", { updated: result.updated, failed: result.failed })
          : t("chats.bulk.result.success", { updated: result.updated }),
      })
      await refreshConversations()
      if (typeof window !== "undefined") {
        window.dispatchEvent(new Event("conversations:unread-changed"))
      }
      handleExitSelectionMode()
    } catch (err) {
      addToast({
        type: "error",
        title: t("chats.bulk.errors.apply"),
        description: err instanceof Error ? err.message : "",
      })
    } finally {
      setBulkSubmitting(false)
    }
  }, [selectedConversationIds, addToast, t, refreshConversations, handleExitSelectionMode])

  const allVisibleSelected = useMemo(
    () => visibleConversations.length > 0 && visibleConversations.every((c) => selectedConversationIds.has(c.id)),
    [visibleConversations, selectedConversationIds],
  )

  // Canal representativo de la selección para difusión. Las plantillas son por
  // NÚMERO de WhatsApp (whatsapp_config), no por canal: varios canales pueden
  // compartir config, así que se compara por config id (igual que el backend).
  // null si mezclan números o si algún id seleccionado no está en el estado
  // local (nunca calcular sobre un subconjunto y abrir el diálogo por accidente).
  const selectedCommonChannelId = useMemo(() => {
    if (selectedConversationIds.size === 0) return null
    const conversationById = new Map(conversations.map((c) => [c.id, c]))
    const configByChannelId = new Map(
      channels.map((ch) => [ch.id, ch.whatsapp_config?.id ?? null]),
    )
    let commonConfigId: number | null = null
    let representativeChannelId: number | null = null
    for (const id of selectedConversationIds) {
      const conversation = conversationById.get(id)
      const channelId = conversation?.channel?.id ?? conversation?.channelId
      if (channelId === undefined || channelId === null) return null
      const configId = configByChannelId.get(channelId)
      if (configId === undefined || configId === null) return null
      if (commonConfigId === null) {
        commonConfigId = configId
        representativeChannelId = channelId
      } else if (commonConfigId !== configId) {
        return null
      }
    }
    return representativeChannelId
  }, [selectedConversationIds, conversations, channels])

  const handleBroadcastClick = useCallback(() => {
    if (selectedCommonChannelId === null) {
      addToast({ type: "info", title: t("chats.broadcast.errors.mixedChannels") })
      return
    }
    setBroadcastOpen(true)
  }, [selectedCommonChannelId, addToast, t])

  const viewOptions: { key: ConversationView; label: string; title: string; count: number }[] = [
    { key: "inbox", label: t("chats.views.inbox"), title: t("chats.views.inbox"), count: conversationViewCounts.inbox },
    { key: "unread", label: t("chats.views.unread"), title: t("chats.views.unread"), count: conversationViewCounts.unread },
    { key: "waiting", label: t("chats.views.waiting"), title: t("chats.views.waitingHint"), count: conversationViewCounts.waiting },
    { key: "review", label: t("chats.views.review"), title: t("chats.views.review"), count: conversationViewCounts.review },
    { key: "archived", label: t("chats.views.archived"), title: t("chats.views.archived"), count: conversationViewCounts.archived },
  ]

  /** "Por revisar" muestra mails, no conversaciones: elegirla con un chat abierto vuelve a la bandeja. */
  const selectView = (view: ConversationView) => {
    if (view === "review" && selectedConversationId) handleBackToConversations()
    setViewType(view)
  }

  /** Vistas de la bandeja. En escritorio van en la columna de filtros; en el teléfono, sobre la lista. */
  const renderViewTabs = () => (
      <div
        role="tablist"
        aria-label={t("chats.views.label")}
        className="flex h-[70px] shrink-0 items-stretch border-b border-border bg-card"
      >
        {viewOptions.map((item) => {
          const isActive = viewType === item.key
          const hasUnreadCount = item.key === "unread" && item.count > 0
          // Que haya derivadas esperando es lo urgente de la bandeja: el contador va en ámbar.
          const hasWaiting = item.key === "waiting" && item.count > 0
          return (
            <button
              key={item.key}
              type="button"
              role="tab"
              aria-selected={isActive}
              onClick={() => selectView(item.key)}
              title={item.title}
              className={`group relative flex min-w-0 flex-1 flex-col items-center justify-center gap-1 px-1 text-xs font-medium tracking-[-0.01em] transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 ${
                isActive
                  ? "text-primary"
                  : "text-muted-foreground hover:text-foreground"
              }`}
            >
              <span
                className={`inline-flex h-5 min-w-5 items-center justify-center rounded-full border px-1.5 text-xs font-semibold tabular-nums transition-colors ${
                  isActive
                    ? "border-primary/35 bg-primary/10 text-primary"
                    : hasWaiting
                      ? "border-warning/30 bg-warning/10 text-warning"
                      : hasUnreadCount
                        ? "border-primary/25 bg-primary/10 text-primary"
                        : "border-border bg-background/80 text-muted-foreground"
                }`}
              >
                {item.count > 99 ? "99+" : item.count}
              </span>
              <span className="max-w-full truncate leading-none">{item.label}</span>
              <span
                className={`absolute inset-x-0 bottom-0 h-0.5 origin-left transition-transform duration-200 ease-out ${
                  isActive ? "scale-x-100 bg-primary" : "scale-x-0 bg-transparent"
                }`}
              />
            </button>
          )
        })}
      </div>
  )

  const renderChannelsSidebar = (
    onChannelSelect: (channelId: number) => void,
    onConnectChannelClick: (channelType?: ConnectableChannel) => void,
    { withViewTabs }: { withViewTabs: boolean },
  ) => (
    <>
      {withViewTabs && renderViewTabs()}

      <ChatFilters
        activeFilter={activeFilter}
        onFilterChange={handleFilterChange}
        availableChannelTypes={["whatsapp", "instagram", "facebook"]}
      />

      <div className="flex flex-wrap items-center gap-2 border-b border-border bg-card px-4 py-3">
        <TagFilterMenu
          selectedSlugs={tagFilterSlugs}
          onChange={setTagFilterSlugs}
          label={t("chats.tagsLabel")}
          align="start"
        />
        <StageFilterMenu
          selected={stageFilter}
          onChange={setStageFilter}
          counts={stageFilterCounts}
          resultCount={visibleConversations.length}
          onShowResults={() => setIsChannelsSheetOpen(false)}
        />
      </div>

      <div className="flex-1 overflow-y-auto">
        <ChannelsList
          channels={connectedChannels.concat(disconnectedChannels)}
          selectedChannel={selectedChannel}
          activeFilter={activeFilter}
          isLoading={isChannelsLoading}
          error={channelsLoadFailed ? new Error(t("chats.loadAccountsErrorDesc")) : null}
          onRetry={() => setChannelsReloadKey((key) => key + 1)}
          onChannelSelect={onChannelSelect}
          onRenameChannel={canUpdateChannels ? handleRenameChannel : undefined}
          onSyncMailChannel={canUpdateChannels ? handleSyncMailChannel : undefined}
          onDisconnectChannel={canDisconnectChannels ? handleDisconnectChannel : undefined}
          onSaveHandoffSettings={canUpdateChannels ? handleSaveHandoffSettings : undefined}
        />
      </div>

      {/* En escritorio "Conectar canal" ya está en el encabezado; acá sólo hace falta en el menú del teléfono. */}
      <div className="border-t border-border p-4 md:hidden">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="outline" className="w-full justify-center gap-2 bg-transparent">
              <Zap className="h-4 w-4 text-primary" />
              {t("chats.connectChannel")}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start" className="w-[--radix-dropdown-menu-trigger-width]">
            {CONNECTABLE_CHANNELS.map(({ key, label, Icon, iconClassName }) => (
              <DropdownMenuItem key={key} onClick={() => onConnectChannelClick(key)}>
                <Icon className={`mr-2 h-4 w-4 ${iconClassName}`} />
                {label}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </>
  )

  const handleMobileChannelSelect = (channelId: number) => {
    handleChannelSelect(channelId)
    setIsChannelsSheetOpen(false)
  }

  const listedConversations = groupsOnly
    ? visibleConversations.filter((c) => c.kind === "group")
    : visibleConversations

  const handleMobileConnectChannel = (channelType?: ConnectableChannel) => {
    setIsChannelsSheetOpen(false)
    handleConnectChannel(channelType)
  }

  return (
    <SidebarLayout>
      <BrandPilot />
      <ChatsCompactHeader
        searchQuery={searchQuery}
        onSearchChange={setSearchQuery}
        onConnectChannel={handleConnectChannel}
        onOpenChannels={() => setIsChannelsSheetOpen(true)}
        hideOnMobile={Boolean(selectedConversationId)}
      />

      <Sheet open={isChannelsSheetOpen} onOpenChange={setIsChannelsSheetOpen}>
        <SheetContent
          side="left"
          className="flex w-[320px] flex-col gap-0 p-0 sm:max-w-[360px] md:hidden"
        >
          <SheetTitle className="sr-only">{t("chats.channels")}</SheetTitle>
          {renderChannelsSidebar(handleMobileChannelSelect, handleMobileConnectChannel, { withViewTabs: false })}
        </SheetContent>
      </Sheet>

      {/* `flex-1 min-h-0` en vez de `h-[calc(100vh-75px)]`: el 75px era un
          número mágico que sólo valía para el encabezado de escritorio. En
          mobile el encabezado mide 125px (trae el buscador) y desaparece del
          todo con un hilo abierto, así que ninguna constante sirve para los
          tres casos. El flex mide lo que sobra, siempre. */}
      <div className="flex min-h-0 flex-1 overflow-hidden bg-background">
        {/* Con un hilo abierto, la columna de canales cede su lugar a la
            lista de conversaciones (salvo en pantallas muy anchas, donde
            entran las tres): saltar de un chat a otro no debería obligar a
            volver atrás. */}
        <div
          className={`hidden w-[360px] flex-col border-r border-border bg-card lg:w-[380px] ${
            selectedConversationId ? "2xl:flex" : "md:flex"
          }`}
        >
          {renderChannelsSidebar(handleChannelSelect, handleConnectChannel, { withViewTabs: true })}
        </div>

        {selectedConversationId && (
          <aside
            aria-label={t("chats.conversationRail.label")}
            className="hidden w-[300px] shrink-0 flex-col border-r border-border bg-card md:flex lg:w-[340px]"
          >
            {/* Con un chat abierto la columna de filtros no está: la vista se cambia desde acá. */}
            <div className="flex h-12 shrink-0 items-center justify-between gap-2 border-b border-border px-2">
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="sm" className="min-w-0 gap-1.5 px-2 text-sm font-semibold" aria-label={t("chats.views.switch")}>
                    <span className="truncate">{t(`chats.views.${viewType}`)}</span>
                    {activeChannel && <span className="truncate font-normal text-muted-foreground">· {activeChannel.name}</span>}
                    <ChevronDown className="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-56">
                  <DropdownMenuRadioGroup value={viewType} onValueChange={(value) => selectView(value as ConversationView)}>
                    {viewOptions.map((item) => (
                      <DropdownMenuRadioItem key={item.key} value={item.key} title={item.title}>
                        <span className="flex-1">{item.label}</span>
                        <span className={`ml-3 text-xs tabular-nums ${item.key === "waiting" && item.count > 0 ? "font-semibold text-warning" : "text-muted-foreground"}`}>
                          {item.count > 99 ? "99+" : item.count}
                        </span>
                      </DropdownMenuRadioItem>
                    ))}
                  </DropdownMenuRadioGroup>
                </DropdownMenuContent>
              </DropdownMenu>
              <span className="mr-2 shrink-0 rounded-full border border-border bg-background px-2 py-0.5 text-xs font-semibold tabular-nums text-muted-foreground">
                {listedConversations.length}
              </span>
            </div>
            <ConversationList
              compact
              conversations={listedConversations}
              channels={channels}
              isLoading={isLoading && listedConversations.length === 0}
              selectedConversationId={selectedConversationId}
              onConversationClick={handleConversationClick}
              messageResults={messageOnlyResults}
              isSearchingMessages={isSearchingMessages}
              hasMore={hasMoreConversations}
              isLoadingMore={isLoadingMoreConversations}
              onLoadMore={() => { void loadConversationPage() }}
              loadFailed={conversationsLoadFailed}
              onRetryLoad={() => { void loadConversationPage(true) }}
            />
          </aside>
        )}

        {/* Panel principal */}
        <div className="flex-1 bg-background flex flex-col overflow-hidden min-h-0">

          {/* En el teléfono no hay columna de filtros: las vistas se muestran sobre la lista. */}
          {!selectedConversationId && <div className="md:hidden">{renderViewTabs()}</div>}

          {!selectedChannelId && !selectedConversationId && (
            <div className="hidden md:block">
              <FilteredConversationsHeader view={viewType} activeFilter={activeFilter} />
            </div>
          )}

          {selectedChannelId && !selectedConversationId && activeChannel && (

            <ChannelHeader
              channel={activeChannel}
              conversationCount={filteredConversations.length}
              onBack={handleBackToChannels}
            />
          )}

          {!selectedConversationId && viewType === "review" && (
            <MailReviewInbox
              onCountChange={setMailReviewCount}
              canManageRules={canUpdateChannels}
              onAccepted={(conversationId) => {
                setViewType("inbox")
                handleConversationClick(conversationId)
              }}
            />
          )}

          {!selectedConversationId && viewType !== "review" && (
            <>
              {selectionMode ? (
                // Modo selección: arriba qué está elegido (y "Eliminar", separado del resto); abajo las acciones.
                // Dos filas fijas en vez de una que se corta donde caiga según el ancho.
                <div className="border-b border-border bg-card px-2 py-1.5">
                <div className="flex items-center gap-1">
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 w-8 p-0"
                    onClick={handleToggleSelectionMode}
                    aria-label={t("chats.bulk.exitSelection")}
                    title={t("chats.bulk.exitSelection")}
                  >
                    <X className="h-4 w-4" aria-hidden="true" />
                  </Button>
                  <span className="flex shrink-0 items-center gap-1.5 whitespace-nowrap px-1 text-sm font-medium tabular-nums" aria-live="polite">
                    {t(`chats.bulk.selectedCount_${selectedConversationIds.size === 1 ? "one" : "other"}`, { count: selectedConversationIds.size })}
                    {bulkSubmitting && <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" aria-label={t("chats.bulk.applying")} />}
                  </span>
                  {visibleConversations.length > 0 && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="text-muted-foreground"
                      onClick={selectedConversationIds.size > 0 && allVisibleSelected ? handleClearSelection : handleSelectAllVisible}
                      disabled={bulkSubmitting}
                    >
                      {selectedConversationIds.size > 0 && allVisibleSelected ? t("chats.bulk.clear") : t("chats.bulk.selectAll")}
                    </Button>
                  )}
                  {selectedConversationIds.size > 0 && (
                    <Button
                      size="sm"
                      variant="ghost"
                      className="ml-auto shrink-0 gap-1.5 text-destructive hover:bg-destructive/10 hover:text-destructive"
                      onClick={() => setBulkDeleteOpen(true)}
                      disabled={bulkSubmitting}
                      aria-label={t("chats.bulk.delete")}
                    >
                      <Trash2 className="h-4 w-4" aria-hidden="true" />
                      {/* En el teléfono sólo el ícono: con el texto la fila no entra en 390 px. */}
                      <span className="hidden sm:inline">{t("chats.bulk.delete")}</span>
                    </Button>
                  )}
                </div>

                  {selectedConversationIds.size > 0 && (
                    // En el teléfono las acciones se deslizan en una fila: apiladas ocupaban tres renglones.
                    <div className="-mx-2 mt-1.5 flex items-center gap-1 overflow-x-auto border-t border-border px-2 pt-1.5 [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0">
                      <Button size="sm" variant="ghost" className="gap-1.5" onClick={() => setBulkTagsOpen(true)} disabled={bulkSubmitting}>
                        <Tags className="h-4 w-4" aria-hidden="true" />
                        {t("chats.bulk.editTags")}
                      </Button>
                      <Button size="sm" variant="ghost" className="gap-1.5" onClick={() => setBulkAssignOpen(true)} disabled={bulkSubmitting}>
                        <UserPlus className="h-4 w-4" aria-hidden="true" />
                        {t("chats.bulk.assign")}
                      </Button>
                      <Button size="sm" variant="ghost" className="gap-1.5" onClick={() => handleBulkArchive(viewType !== "archived")} disabled={bulkSubmitting}>
                        {viewType === "archived" ? <ArchiveRestore className="h-4 w-4" aria-hidden="true" /> : <Archive className="h-4 w-4" aria-hidden="true" />}
                        {viewType === "archived" ? t("chats.bulk.unarchive") : t("chats.bulk.archive")}
                      </Button>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button size="sm" variant="ghost" className="gap-1.5" disabled={bulkSubmitting}>
                            <MailOpen className="h-4 w-4" aria-hidden="true" />
                            {t("chats.bulk.markAs")}
                            <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                          <DropdownMenuItem onSelect={() => void handleBulkMarkRead(true)}>
                            <MailOpen className="mr-2 h-4 w-4" aria-hidden="true" />
                            {t("chats.bulk.read")}
                          </DropdownMenuItem>
                          <DropdownMenuItem onSelect={() => void handleBulkMarkRead(false)}>
                            <Mail className="mr-2 h-4 w-4" aria-hidden="true" />
                            {t("chats.bulk.unread")}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button size="sm" variant="ghost" className="gap-1.5" disabled={bulkSubmitting}>
                            <Bot className="h-4 w-4 text-ai" aria-hidden="true" />
                            {t("chats.bulk.aiAssistant")}
                            <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                          <DropdownMenuItem onSelect={() => void handleBulkAiAutoreply(true)}>
                            <Bot className="mr-2 h-4 w-4 text-ai" aria-hidden="true" />
                            {t("chats.bulk.enableAi")}
                          </DropdownMenuItem>
                          <DropdownMenuItem onSelect={() => void handleBulkAiAutoreply(false)}>
                            <Bot className="mr-2 h-4 w-4 text-muted-foreground" aria-hidden="true" />
                            {t("chats.bulk.disableAi")}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                      <Button size="sm" variant="ghost" className="gap-1.5" onClick={handleBroadcastClick} disabled={bulkSubmitting}>
                        <Megaphone className="h-4 w-4" aria-hidden="true" />
                        {t("chats.bulk.broadcast")}
                      </Button>
                    </div>
                  )}
                </div>
              ) : (
                <div className="flex items-center gap-2 border-b border-border bg-card px-4 py-2">
                  <Button variant="outline" size="sm" onClick={handleToggleSelectionMode} className="gap-2">
                    <CheckSquare className="h-4 w-4" aria-hidden="true" />
                    {t("chats.bulk.selectMode")}
                  </Button>
                  {canCreateGroups && (
                    <Button variant="outline" size="sm" className="gap-2" onClick={() => setNewGroupOpen(true)}>
                      <Users className="h-4 w-4" aria-hidden="true" />
                      {t("chats.newGroup")}
                    </Button>
                  )}
                  <Button
                    variant={groupsOnly ? "default" : "ghost"}
                    size="sm"
                    className="gap-2"
                    onClick={() => setGroupsOnly((prev) => !prev)}
                    title={t("chats.groupsOnlyHint")}
                    aria-pressed={groupsOnly}
                  >
                    <Users className="h-4 w-4" aria-hidden="true" />
                    {t("chats.groups")}
                  </Button>
                </div>
              )}

              <ConversationList
                conversations={listedConversations}
                channels={channels}
                isLoading={isLoading}
                selectedConversationId={selectedConversationId}
                onConversationClick={handleConversationClick}
                selectionMode={selectionMode}
                selectedIds={selectedConversationIds}
                onToggleSelect={handleToggleSelectConversation}
                messageResults={messageOnlyResults}
                isSearchingMessages={isSearchingMessages}
                hasMore={hasMoreConversations}
                isLoadingMore={isLoadingMoreConversations}
                onLoadMore={() => { void loadConversationPage() }}
                loadFailed={conversationsLoadFailed}
                onRetryLoad={() => { void loadConversationPage(true) }}
                emptyState={
                  searchQuery
                    ? { title: t("chats.noSearchResults"), description: t("chats.noSearchResultsDesc") }
                    : tagFilterSlugs.length > 0 || stageFilter.length > 0 || groupsOnly
                    ? {
                        title: t("chats.noSearchResults"),
                        description: t("chats.noFilterMatchesDesc"),
                        action: {
                          label: t("chats.clearFilters"),
                          onClick: () => {
                            setTagFilterSlugs([])
                            setStageFilter([])
                            setGroupsOnly(false)
                          },
                        },
                      }
                    : { title: t(`chats.empty.${viewType}.title`), description: t(`chats.empty.${viewType}.description`) }
                }
              />
            </>
          )}

          {selectedConversationId && currentConversation && activeConversation && selectedConversationId && (

            <>
              <ConversationHeader
                conversation={activeConversation}
                isContactInfoOpen={isContactInfoOpen}
                onBack={handleBackToConversations}
                onToggleContactInfo={(trigger) => {
                  contactInfoTriggerRef.current = trigger
                  setIsContactInfoOpen(!isContactInfoOpen)
                }}
                onRenameContact={handleRenameContact}
                onRenameGroup={handleRenameGroup}
                onToggleAiAutoreply={handleToggleAiAutoreply}
                onSearchMessages={() => handleThreadSearchOpenChange(true)}
              />
              {currentConversation?.id === selectedConversationId && (
                <HumanHandoffBanner
                  conversation={currentConversation}
                  onUpdated={(updated) => {
                    setCurrentConversation(updated)
                    setConversations((prev) => prev.map((c) => (
                      c.id === updated.id ? { ...c, assigneeId: updated.assigneeId, humanHandoff: updated.humanHandoff } : c
                    )))
                  }}
                />
              )}
              <PendingAiBookingsBanner
                key={`ai-bookings-${selectedConversationId}`}
                conversationId={selectedConversationId}
                refreshKey={aiBookingsRefreshKey}
              />
              {/* Quick Bar */}
              <ChatQuickBar
                chatId={selectedConversationId}
                contact={(() => {
                  const contactId = activeConversation.contact_id ?? Number(activeConversation.contact?.id)
                  return contactId ? { id: contactId, name: activeConversation.contact?.name ?? t("chats.contactFallback") } : null
                })()}
                value={{
                  stageId: currentConversation?.pipeline_stage_id,
                  priority: currentConversation?.priority,
                  assigneeId: currentConversation?.assigneeId,
                  archived: currentConversation?.archived,
                }}
                team={[]}
                onChangeStage={(stage) => {
                  chatHandlers.handleChangeStage(selectedConversationId)(stage)
                  setCurrentConversation(prev => prev ? { ...prev, pipeline_stage_id: stage } : prev)
                  // La lista también: el filtro de etapas lee de acá.
                  setConversations(prev => prev.map(c => c.id === selectedConversationId ? { ...c, pipeline_stage_id: stage } : c))
                }}
                onOpportunityStagesChange={(chatId, stageIds) => {
                  setConversations(prev => prev.map(c => c.id === chatId ? { ...c, opportunity_stage_ids: stageIds } : c))
                }}
                onChangePriority={(priority) => {
                  chatHandlers.handleChangePriority(selectedConversationId)(priority)
                  setCurrentConversation(prev => prev ? { ...prev, priority } : prev)
                }}
                onChangeAssignee={(assigneeId) => {
                  chatHandlers.handleChangeAssignee(selectedConversationId)(assigneeId)
                  // Actualizar currentConversation localmente
                  setCurrentConversation(prev => prev ? { ...prev, assigneeId: String(assigneeId) } : prev)
                }}
                onToggleArchive={handleToggleArchive}
                isUnread={Boolean(activeConversation.unread || (activeConversation.unread_count ?? 0) > 0 || activeConversation.manual_unread)}
                onToggleReadStatus={() => handleToggleReadStatus(activeConversation.id)}
                tags={currentConversation?.tags || activeConversation?.tags || []}
                onTagsChange={handleConversationTagsChange}
              />
              <MessageList
                key={`thread-${selectedConversationId}`}
                messages={currentConversation?.messages ?? EMPTY_MESSAGES}
                onLoadMore={handleLoadMoreMessages}
                hasMore={hasMore}
                isLoadingMore={isLoadingMoreMessages}
                loadMoreFailed={loadMoreMessagesFailed}
                onEditMessage={handleEditMessage}
                onDeleteMessage={handleDeleteMessage}
                onReactMessage={handleReactToMessage}
                onSaveContact={handleSaveSharedContact}
                currentUserId={currentUserId}
                isAdmin={isAdmin}
                canSendMessage={canSendMessage}
                translationLanguage={language}
                onTranslateMessage={handleTranslateMessage}
                channelType={activeConversation?.channel?.type}
                isGroupConversation={activeConversation?.kind === "group"}
                searchOpen={threadSearchFor === selectedConversationId}
                onSearchOpenChange={handleThreadSearchOpenChange}
              />
              {activeConversation?.channel?.type === ChannelType.MAIL ? (
                <MailMessageInput
                  onSend={handleSendMail}
                  disabled={isLoading}
                  subject={[...(currentConversation?.messages ?? [])]
                    .reverse()
                    .find((item) => item.mail_details?.subject)
                    ?.mail_details?.subject}
                  aiDraft={aiDraft}
                  onRequestAiDraft={latestInboundMessage ? handleRequestAiDraft : undefined}
                  onCancelAiDraft={handleCancelAiDraft}
                  onUseAiDraft={handleUseAiDraft}
                />
              ) : (
                <>
                <ScheduledMessagesBar
                  items={scheduledMessages.items}
                  windowExpiresAt={scheduledMessages.window?.expiresAt ?? null}
                  currentUserId={currentUserId}
                  onChanged={scheduledMessages.upsert}
                  onRemoved={scheduledMessages.remove}
                />
                <MessageInput
                  onSend={editingMessage ? handleSaveEdit : handleSendMessage}
                  disabled={isLoading}
                  channelId={activeConversation?.channel?.id ?? activeConversation?.channelId}
                  conversationId={selectedConversationId}
                  onSendTemplate={handleSendTemplate}
                  supportsTemplates={activeConversation?.channel?.type === ChannelType.WHATSAPP}
                  supportsVoice={activeConversation?.channel?.type === ChannelType.WHATSAPP}
                  supportsContactSharing={activeConversation?.channel?.type === ChannelType.WHATSAPP}
                  onShareContacts={handleShareContacts}
                  supportsFlows={activeConversation?.channel?.type === ChannelType.WHATSAPP}
                  onSendFlow={handleSendFlow}
                  onSchedule={supportsScheduledMessages(activeConversation?.channel?.type) ? handleScheduleMessage : undefined}
                  windowExpiresAt={scheduledMessages.window?.expiresAt ?? null}
                  windowStatus={windowStatus}
                  humanAgentExpiresAt={scheduledMessages.window?.humanAgentExpiresAt ?? null}
                  channelType={activeConversation?.channel?.type}
                  editingMessage={editingMessage}
                  onCancelEdit={handleCancelEdit}
                  expansionContext={hotkeyExpansionContext}
                  contactLanguage={(currentConversation ?? activeConversation)?.contactLanguage ?? "es"}
                  onContactLanguageChange={handleTranslationLanguageChange}
                  onTranslateDraft={handleTranslateDraft}
                  aiDraft={aiDraft}
                  onRequestAiDraft={latestInboundMessage ? handleRequestAiDraft : undefined}
                  onCancelAiDraft={handleCancelAiDraft}
                  onUseAiDraft={handleUseAiDraft}
                />
                </>
              )}
            </>


          )}

          {/* Mientras carga o si falla el hilo, el panel no queda en blanco. */}
          {selectedConversationId && !currentConversation && (
            conversationLoadError === "unavailable" ? (
              <div className="flex flex-1 flex-col items-center justify-center overflow-y-auto p-4">
                <EmptyState
                  icon={AlertCircle}
                  title={t("chats.conversationUnavailable")}
                  description={t("chats.conversationUnavailableDesc")}
                  action={{ label: t("chats.back"), onClick: handleBackToConversations }}
                />
              </div>
            ) : conversationLoadError === "failed" ? (
              <div className="flex flex-1 flex-col items-center justify-center overflow-y-auto p-4">
                <EmptyState
                  icon={AlertCircle}
                  title={t("chats.loadConversationError")}
                  description={t("chats.channelErrorNetwork")}
                  action={{ label: t("chats.retry"), onClick: () => setConversationReloadKey((key) => key + 1) }}
                />
                <Button variant="ghost" size="sm" onClick={handleBackToConversations}>
                  {t("chats.back")}
                </Button>
              </div>
            ) : (
              <div role="status" aria-label={t("chats.loading")} className="flex-1 overflow-y-auto p-4">
                <SkeletonList count={4} />
              </div>
            )
          )}

        </div>

        {/* Panel de información de contacto o de grupo: columna lateral desde lg,
            sheet a pantalla completa (max-w-md en tablet) por debajo. */}
        {selectedConversationId && activeConversation && (() => {
          const isGroup = activeConversation.kind === "group"
          const contactId = activeConversation.contact_id ?? Number(activeConversation.contact?.id)
          if (isGroup ? !activeConversation.group : !contactId || Number.isNaN(contactId)) return null
          const panelClassName = isLgViewport ? undefined : "w-full border-l-0"
          const panel = isGroup && activeConversation.group ? (
            <GroupPanel
              groupId={activeConversation.group.id}
              isOpen={isContactInfoOpen}
              onClose={() => setIsContactInfoOpen(false)}
              onDeleted={() => {
                setIsContactInfoOpen(false)
                handleBackToConversations()
              }}
              className={panelClassName}
            />
          ) : (
            <ContactInfoPanel
              contactId={contactId}
              isOpen={isContactInfoOpen}
              onClose={() => setIsContactInfoOpen(false)}
              onAvatarChange={handleContactAvatarChange}
              className={panelClassName}
            />
          )

          if (isLgViewport) return panel

          // El panel ya trae su propio botón de cerrar: se oculta el de la sheet.
          return (
            <Sheet open={isContactInfoOpen} onOpenChange={setIsContactInfoOpen}>
              <SheetContent
                side="right"
                aria-describedby={undefined}
                className="w-full gap-0 p-0 sm:max-w-md [&>button:last-child]:hidden"
                onCloseAutoFocus={(event) => {
                  const trigger = contactInfoTriggerRef.current
                  if (trigger?.isConnected) {
                    event.preventDefault()
                    trigger.focus()
                  }
                }}
              >
                <SheetTitle className="sr-only">
                  {isGroup ? (activeConversation.group?.subject ?? t("chats.contactInfo")) : t("chats.contactInfo")}
                </SheetTitle>
                {panel}
              </SheetContent>
            </Sheet>
          )
        })()}
      </div>

      <BulkTagsConversationsDialog
        open={bulkTagsOpen}
        onOpenChange={setBulkTagsOpen}
        selectedIds={Array.from(selectedConversationIds)}
        onSuccess={() => {
          refreshConversations()
          handleExitSelectionMode()
        }}
      />

      <BulkAssignConversationsDialog
        open={bulkAssignOpen}
        onOpenChange={setBulkAssignOpen}
        selectedIds={Array.from(selectedConversationIds)}
        onSuccess={() => {
          refreshConversations()
          handleExitSelectionMode()
        }}
      />

      <BroadcastDialog
        open={broadcastOpen}
        onOpenChange={setBroadcastOpen}
        selectedIds={Array.from(selectedConversationIds)}
        channelId={selectedCommonChannelId}
        onSuccess={() => {
          refreshConversations()
          handleExitSelectionMode()
        }}
      />

      <NewGroupDialog
        open={newGroupOpen}
        onOpenChange={setNewGroupOpen}
        channels={channels}
        defaultChannelId={selectedChannelId ?? undefined}
        onCreated={async () => {
          await refreshConversations()
        }}
      />

      <InstagramPageSelectDialog
        pages={instagramPageOptions}
        onSelect={selectInstagramPage}
        onCancel={cancelInstagramPageSelection}
      />

      <MessengerPageSelectDialog
        pages={messengerPageOptions}
        onSelect={selectMessengerPage}
        onCancel={cancelMessengerPageSelection}
      />

      <MailConnectDialog open={isMailConnectOpen} onOpenChange={setMailConnectOpen} />

      <AlertDialog open={bulkDeleteOpen} onOpenChange={(open) => { if (!bulkSubmitting) setBulkDeleteOpen(open) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("chats.bulk.deleteConfirmTitle")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t(`chats.bulk.deleteConfirmDesc_${selectedConversationIds.size === 1 ? "one" : "other"}`, { count: selectedConversationIds.size })}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={bulkSubmitting}>
              {t("chats.bulk.dialog.cancel")}
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={(e) => { e.preventDefault(); handleBulkDelete() }}
              disabled={bulkSubmitting}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {bulkSubmitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {t("chats.bulk.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </SidebarLayout>
  )
}
