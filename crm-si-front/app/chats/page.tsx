"use client"

import dynamic from "next/dynamic"
import { SidebarLayout } from "@/components/SidebarLayout"
import { ChatFilters } from "@/components/chat/ChatFilters"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"

const ContactInfoPanel = dynamic(
  () => import("@/components/ContactInfoPanel").then(m => m.ContactInfoPanel),
  { loading: () => null }
)
import { useChatState } from "@/hooks/useChatState"
import { useToast } from "@/components/Toast"
import { ChannelError, ChannelErrorDetail } from "@/lib/channel-error"
import { FilterType, Channel, Conversation, Message, TranslationLanguage } from "@/data/types"
import { ChannelType, filterTypeToChannelType } from "@/data/enums"
import { useState, useEffect, useCallback, useRef, useMemo } from "react"
import { useSearchParams, useRouter } from "next/navigation"
import { ChatQuickBar } from "@/components/ChatQuickBar"
import {
  archiveConversation,
  setAiAutoreply,
  bulkArchiveConversations,
  bulkAiAutoreplyConversations,
  bulkDeleteConversations,
  bulkMarkReadConversations,
  getChannelConversations,
  getConversationMessages,
  getConversations,
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

import { sendMessage, sendMailMessage, editMessage, deleteMessage, translateMessage, type SendMailMessageInput } from "@/lib/api/messages"
import { ConversationHeader } from "@/components/chat/ConversationHeader"
import { MessageList } from "@/components/chat/MessageList"
import { MessageInput } from "@/components/chat/MessageInput"
import { MailMessageInput } from "@/components/chat/MailMessageInput"
import { ConversationList } from "@/components/chat/ConversationList"
import { FilteredConversationsHeader } from "@/components/chat/FilteredConversationsHeader"
import { ChannelsList } from "@/components/chat/ChannelsList"
import { getChannels, updateChannelName, syncMailChannel } from "@/lib/api/channels"
import { updateContact } from "@/lib/api/contacts"
import { isExpectedBusinessErrorMessage } from "@/lib/observability/sentry"
import { ChannelHeader } from "@/components/chat/AccountHeader"
import { useConversationFilters } from "@/hooks/useConversationFilters"
import { TagFilterMenu } from "@/components/tags/TagFilterMenu"
import { Sheet, SheetContent, SheetTitle } from "@/components/ui/sheet"
import { useSSEMessages, type MessageStatusUpdate } from "@/hooks/useSSEMessages"
import { useTenantSSE } from "@/hooks/useTenantSSE"
import { getPusher } from "@/lib/pusher"
import { cancelManualAiDraft, getManualAiDraft, requestManualAiDraft, type ManualAiDraft } from "@/lib/api/manual-ai-drafts"
import { useTranslation } from "@/hooks/useTranslation"
import { useAuthStore } from "@/store/useAuthStore"
import { useFacebookSDK } from "@/hooks/useFacebookSDK"
import { useInstagramLogin } from "@/hooks/useInstagramLogin"
import { InstagramPageSelectDialog } from "@/components/chat/InstagramPageSelectDialog"
import { useMessengerLogin } from "@/hooks/useMessengerLogin"
import { MessengerPageSelectDialog } from "@/components/chat/MessengerPageSelectDialog"
import { MailConnectDialog } from "@/components/chat/MailConnectDialog"
import { MailReviewInbox } from "@/components/chat/MailReviewInbox"
import { getMailIntakeCount } from "@/lib/api/mail-intakes"
import {
  Archive,
  ArchiveRestore,
  Bot,
  CheckSquare,
  Facebook,
  Instagram,
  Loader2,
  Mail,
  MessageCircle,
  MailOpen,
  Megaphone,
  Menu,
  MoreHorizontal,
  Plus,
  Search,
  Sparkles,
  Tags,
  Trash2,
  UserPlus,
  X,
  Zap,
} from "lucide-react"

type ConversationView = "inbox" | "unread" | "archived" | "review"

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
  { key: "whatsapp", label: "WhatsApp", Icon: MessageCircle, iconClassName: "text-green-600" },
  { key: "instagram", label: "Instagram", Icon: Instagram, iconClassName: "text-pink-600" },
  { key: "messenger", label: "Messenger", Icon: Facebook, iconClassName: "text-blue-600" },
  { key: "mail", label: "Email", Icon: Mail, iconClassName: "text-blue-600" },
]

interface ChatsCompactHeaderProps {
  searchQuery: string
  onSearchChange: (query: string) => void
  onNewConversation: () => void
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
  onNewConversation,
  onConnectChannel,
  onOpenChannels,
  hideOnMobile = false,
}: ChatsCompactHeaderProps) {
  const { t } = useTranslation()
  return (
    <div
      className={`sticky top-0 z-40 border-b border-border bg-background/95 backdrop-blur-xl supports-[backdrop-filter]:bg-background/90 ${
        hideOnMobile ? "hidden md:block" : ""
      }`}
    >
      <div className="flex h-18.75 items-center gap-3 px-4 md:px-6">
        <Button
          variant="ghost"
          size="icon"
          onClick={onOpenChannels}
          aria-label="Abrir canales"
          className="md:hidden"
        >
          <Menu className="h-5 w-5" />
        </Button>
        <div className="flex shrink-0 items-center gap-3">
          <div className="flex h-9 w-9 items-center justify-center rounded-md bg-primary text-primary-foreground shadow-sm">
            <Sparkles className="h-4 w-4" />
          </div>
          <div>
            <h1 className="text-lg font-semibold tracking-tight text-foreground">Chats</h1>
            <p className="hidden text-xs text-muted-foreground sm:block">Bandeja omnicanal</p>
          </div>
        </div>

        <div className="relative hidden max-w-md flex-1 md:block">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={searchQuery}
            placeholder="Buscar conversaciones..."
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
                  Conectar canal
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
              <Button variant="ghost" size="icon" aria-label="Abrir acciones de chats">
                <MoreHorizontal className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {CONNECTABLE_CHANNELS.map(({ key, label, Icon, iconClassName }) => (
                <DropdownMenuItem key={key} onClick={() => onConnectChannel(key)}>
                  <Icon className={`mr-2 h-4 w-4 ${iconClassName}`} />
                  Conectar {label}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>

          <Button size="sm" onClick={onNewConversation} className="gap-2">
            <Plus className="h-4 w-4" />
            <span className="hidden sm:inline">Nueva conversación</span>
          </Button>
        </div>
      </div>

      <div className="border-t border-border px-4 pb-3 md:hidden">
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={searchQuery}
            placeholder="Buscar conversaciones..."
            className="h-9 border-border bg-muted/40 pl-9 shadow-none"
            onChange={(event) => onSearchChange(event.target.value)}
          />
        </div>
      </div>
    </div>
  )
}

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
  const canUpdateChannels = (permissions ?? []).includes("channels.update")

  const chatIdFromUrl = searchParams.get("chat")



  const chatIdNumber = chatIdFromUrl ? parseInt(chatIdFromUrl, 10) : null


  const [selectedChannel, setSelectedChannel] = useState<number | null>(null)
  const [selectedChannelId, setSelectedChannelId] = useState<number | null>(null)
  const [isContactInfoOpen, setIsContactInfoOpen] = useState(false)
  const [isChannelsSheetOpen, setIsChannelsSheetOpen] = useState(false)
  const [isMailConnectOpen, setMailConnectOpen] = useState(false)
  const [activeFilter, setActiveFilter] = useState<FilterType>("todos")
  const [tagFilterSlugs, setTagFilterSlugs] = useState<string[]>([])
  const [viewType, setViewType] = useState<ConversationView>("inbox")
  const [mailReviewCount, setMailReviewCount] = useState(0)
  const [searchQuery, setSearchQuery] = useState("")
  const [messageSearchResults, setMessageSearchResults] = useState<Conversation[]>([])
  const [isSearchingMessages, setIsSearchingMessages] = useState(false)
  const [isLoading, setIsLoading] = useState(false)
  const [isChannelsLoading, setIsChannelsLoading] = useState(false)
  const [message, setMessage] = useState("")
  const [channels, setChannels] = useState<Channel[]>([])
  const [conversations, setConversations] = useState<Conversation[]>([])
  const [currentConversation, setCurrentConversation] = useState<Conversation | null>(null)
  const [selectedConversationId, setSelectedConversationId] = useState<number | null>(chatIdNumber)

  const activeConversation = useMemo(() => conversations.find((c) => c.id === selectedConversationId), [conversations, selectedConversationId])

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
      belongsToContact(c) ? { ...c, contact: { ...c.contact, name } } : c
    )))
    setCurrentConversation((prev) => (
      prev && belongsToContact(prev) ? { ...prev, contact: { ...prev.contact, name } } : prev
    ))

    try {
      await updateContact(contactId, { name })
      addToast({ type: "success", title: t("chats.renameContactSuccess") })
    } catch (error) {
      setConversations((prev) => prev.map((c) => (
        previousNames.has(c.id) ? { ...c, contact: { ...c.contact, name: previousNames.get(c.id)! } } : c
      )))
      setCurrentConversation((prev) => (
        prev && previousNames.has(prev.id)
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

  const activeChannel = useMemo(() => channels.find((channel) => channel.id === selectedChannelId), [channels, selectedChannelId])

  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(true)
  const [isLoadingMore, setIsLoadingMore] = useState(false)
  const [editingMessage, setEditingMessage] = useState<Message | null>(null)
  const [aiDraft, setAiDraft] = useState<ManualAiDraft | null>(null)

  const [selectionMode, setSelectionMode] = useState(false)
  const [selectedConversationIds, setSelectedConversationIds] = useState<Set<number>>(new Set())
  const [bulkTagsOpen, setBulkTagsOpen] = useState(false)
  const [bulkAssignOpen, setBulkAssignOpen] = useState(false)
  const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false)
  const [broadcastOpen, setBroadcastOpen] = useState(false)
  const [bulkSubmitting, setBulkSubmitting] = useState(false)

  const conversationsRef = useRef(conversations);
  useEffect(() => {
    conversationsRef.current = conversations;
  }, [conversations]);

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


  const handleRealTimeMessage = useCallback((newMessage: Message) => {
    if (newMessage.direction === "inbound" && selectedConversationId === newMessage.conversation_id) {
      setAiDraft(null)
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
  }, [getRealtimeMessagePreview, normalizeIncomingTemplateContent, selectedConversationId]);

  const handleRealTimeEdit = useCallback((updatedMsg: Message) => {
    setCurrentConversation((prev) => {
      if (!prev) return prev;
      const messages = prev.messages || [];
      return {
        ...prev,
        messages: messages.map((m: Message) =>
          m.id === updatedMsg.id
            ? {
                ...m,
                content: updatedMsg.content,
                edited_at: updatedMsg.edited_at,
                original_content: updatedMsg.original_content ?? m.original_content,
              }
            : m
        ),
      };
    });
  }, []);

  const handleRealTimeDelete = useCallback((data: { id: number; conversation_id: number }) => {
    setCurrentConversation((prev) => {
      if (!prev) return prev;
      const messages = prev.messages || [];
      return {
        ...prev,
        messages: messages.map((m: Message) =>
          m.id === data.id ? { ...m, deleted_at: new Date().toISOString() } : m
        ),
      };
    });
  }, []);

  const handleRealTimeStatus = useCallback((status: MessageStatusUpdate) => {
    setCurrentConversation((prev) => {
      if (!prev) return prev;
      const messages = prev.messages || [];
      return {
        ...prev,
        messages: messages.map((m: Message) =>
          m.id === status.id
            ? {
                ...m,
                delivered_at: status.delivered_at ?? m.delivered_at,
                read_at: status.read_at ?? m.read_at,
                played_at: status.played_at ?? m.played_at,
                failed_at: status.failed_at ?? m.failed_at,
                error_message: status.error_message ?? m.error_message,
              }
            : m
        ),
      };
    });
  }, []);

  useSSEMessages({
    conversationId: selectedConversationId,
    onMessage: handleRealTimeMessage,
    onEdited: handleRealTimeEdit,
    onDeleted: handleRealTimeDelete,
    onStatus: handleRealTimeStatus,
  });

  const selectedChannelIdRef = useRef(selectedChannelId);
  useEffect(() => { selectedChannelIdRef.current = selectedChannelId; }, [selectedChannelId]);

  const tenantRefreshVersionRef = useRef(0);

  const handleTenantMessage = useCallback((message: Message) => {
    setConversations(prev => {
      const idx = prev.findIndex(c => c.id === message.conversation_id);
      if (idx !== -1) {
        const updated = {
          ...prev[idx],
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
      const fetcher = channelId ? getChannelConversations(channelId) : getConversations();
      fetcher.then(data => {
        if (version !== tenantRefreshVersionRef.current) return;
        if (selectedChannelIdRef.current !== channelId) return;
        setConversations(data);
      }).catch(() => {});
      // Refresh channel list so conversations_count stays in sync after a new chat lands
      getChannels().then(setChannels).catch(() => {});
      return prev;
    });
  }, [getRealtimeMessagePreview]);

  useTenantSSE({
    onMessage: handleTenantMessage,
  });

  const handleLoadMoreMessages = async () => {
    if (!selectedConversationId || isLoadingMore || !hasMore) return

    setIsLoadingMore(true)
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

          const existingIds = new Set((prev.messages || []).map((m: any) => m.id));
          const uniqueNewMessages = newMessages.filter((m: any) => !existingIds.has(m.id));
          const combinedMessages = [...uniqueNewMessages, ...(prev.messages || [])].sort((a: any, b: any) => a.id - b.id);

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
    } finally {
      setIsLoadingMore(false)
    }
  }


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
    if (tagFilterSlugs.length === 0) return filteredConversations

    const selected = new Set(tagFilterSlugs)
    return filteredConversations.filter((conversation) => (
      conversation.tags?.some((tag) => selected.has(tag.slug))
    ))
  }, [filteredConversations, tagFilterSlugs])

  const conversationViewCounts = useMemo(() => ({
    inbox: tagFilteredConversations.filter((conversation) => !conversation.archived).length,
    unread: tagFilteredConversations.filter((conversation) => conversation.unread && !conversation.archived).length,
    archived: tagFilteredConversations.filter((conversation) => conversation.archived).length,
    review: mailReviewCount,
  }), [tagFilteredConversations, mailReviewCount])

  const visibleConversations = useMemo(() => {
    const normalizedQuery = searchQuery.trim().toLowerCase()

    return tagFilteredConversations.filter((conversation) => {
      const matchesView =
        viewType === "archived"
          ? conversation.archived
          : viewType === "unread"
            ? conversation.unread && !conversation.archived
            : viewType === "review" ? false : !conversation.archived

      if (!matchesView) return false
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
    const targetType = activeFilter !== "todos" && activeFilter !== "no-leidos"
      ? filterTypeToChannelType(activeFilter)
      : null
    const matchingChannelIds = targetType
      ? new Set(channels.filter((ch) => ch.type === targetType).map((ch) => ch.id))
      : null

    return messageSearchResults.filter((conversation) => {
      if (visibleIds.has(conversation.id)) return false
      if (matchingChannelIds && !matchingChannelIds.has(conversation.channelId)) return false
      if (activeFilter === "no-leidos" && !((conversation.unread_count ?? 0) > 0 || conversation.unread)) return false
      if (tagSet.size > 0 && !conversation.tags?.some((tag) => tagSet.has(tag.slug))) return false
      if (viewType === "archived") return Boolean(conversation.archived)
      if (conversation.archived) return false
      if (viewType === "unread" && !conversation.unread) return false
      return true
    })
  }, [messageSearchResults, visibleConversations, searchQuery, activeFilter, channels, tagFilterSlugs, viewType])

  // Parallel initial fetch: channels + conversations (independent — partial success OK)
  useEffect(() => {
    let cancelled = false;
    (async () => {
      setIsLoading(true)
      setIsChannelsLoading(true)
      const [channelsResult, conversationsResult] = await Promise.allSettled([
        getChannels(),
        getConversations(),
      ])
      if (cancelled) return

      if (channelsResult.status === "fulfilled") {
        setChannels(channelsResult.value)
      } else {
        addToast({ type: "error", title: t("chats.loadAccountsError"), description: t("chats.loadAccountsErrorDesc") })
      }

      if (conversationsResult.status === "fulfilled") {
        setConversations(conversationsResult.value)
      } else {
        addToast({ type: "error", title: t("chats.loadConversationsError") })
      }

      setIsLoading(false)
      setIsChannelsLoading(false)
    })()
    return () => { cancelled = true }
  }, [])

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

  // Fetch conversations when channel selection changes (or clears to "all")
  const isInitialMountRef = useRef(true);
  useEffect(() => {
    if (isInitialMountRef.current) {
      isInitialMountRef.current = false;
      return;
    }

    let cancelled = false;
    (async () => {
      try {
        setIsLoading(true);
        const data = selectedChannelId
          ? await getChannelConversations(selectedChannelId)
          : await getConversations();
        if (!cancelled) setConversations(data);
      } catch (e) {
        addToast({ type: "error", title: t("chats.loadConversationsError") });
        if (!cancelled) setConversations([]);
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    })();
    return () => { cancelled = true };
  }, [selectedChannelId]);

  // Single effect: fetch conversation with messages when selected
  useEffect(() => {
    if (!selectedConversationId) {
      setCurrentConversation(null);
      setAiDraft(null);
      return;
    }

    // Clear stale data immediately so we never show conversation A under conversation B's header
    setCurrentConversation(null);

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
        addToast({
          type: "error",
          title: t("chats.loadConversationError"),
          description: error instanceof Error ? error.message : t("chats.unknownError"),
        });
      }
    })();
    return () => { cancelled = true };
  }, [selectedConversationId]);

  useEffect(() => {
    const userId = user?.id;
    if (!userId) return;
    const pusher = getPusher();
    const channel = pusher.subscribe(`private-App.Models.User.${userId}`);
    const onDraft = (event: { draft: ManualAiDraft }) => {
      if (event.draft.conversation_id === selectedConversationId) setAiDraft(event.draft.status === "cancelled" ? null : event.draft);
    };
    channel.bind("manual-ai-draft.updated", onDraft);
    return () => { channel.unbind("manual-ai-draft.updated", onDraft); pusher.unsubscribe(`private-App.Models.User.${userId}`); };
  }, [user?.id, selectedConversationId]);

  useEffect(() => {
    if (!selectedConversationId || aiDraft?.status !== "pending") return;
    const timer = window.setInterval(() => {
      getManualAiDraft(selectedConversationId).then(setAiDraft).catch(() => {});
    }, 2500);
    return () => window.clearInterval(timer);
  }, [selectedConversationId, aiDraft?.status]);

  const latestInboundMessage = useMemo(() => {
    const latest = [...(currentConversation?.messages ?? [])].reverse()[0]
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
    if (aiDraft?.content && !message.trim()) setMessage(aiDraft.content);
  }, [aiDraft?.content, message]);


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
        title: "Preparando Facebook",
        description: "El onboarding se está cargando. Intentá nuevamente en unos segundos.",
      })
      return
    }

    if (channelType === "instagram") {
      launchInstagramLogin()
    } else {
      launchWhatsAppSignup()
    }
  }

  const handleNewConversation = () => {
    addToast({
      type: "info",
      title: "Nueva conversación",
      description: "Selecciona un canal conectado para iniciar o continuar un chat.",
    })
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

  const handleSendTemplate = (content: string) => {
    if (!selectedConversationId) return;

    const tempId = `temp-${Date.now()}-${Math.random()}`;
    const optimisticMessage: any = {
      id: tempId,
      content,
      type: "text",
      created_at: new Date().toISOString(),
      status: "sending",
      conversation_id: selectedConversationId,
      direction: "outbound",
      sender_type: "user",
    };

    setCurrentConversation((prev) => {
      if (!prev) return prev;
      // Handoff: enviar una plantilla también apaga la auto-respuesta IA en el backend.
      return { ...prev, aiAutoreplyEnabled: false, messages: [...(prev.messages || []), optimisticMessage] };
    });

    setConversations((prevConversations) => {
      const index = prevConversations.findIndex((c) => c.id === selectedConversationId);
      if (index === -1) return prevConversations;
      const updated = { ...prevConversations[index], aiAutoreplyEnabled: false, last_message: content, updated_at: new Date().toISOString() };
      const next = [...prevConversations];
      next.splice(index, 1);
      return [updated, ...next];
    });
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
        title: t("chats.archiveError") || "Error al archivar",
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

  const handleDeleteMessage = async (msg: Message) => {
    try {
      await deleteMessage(msg.id);
      setCurrentConversation((prev) => {
        if (!prev) return prev;
        return {
          ...prev,
          messages: (prev.messages || []).map((m: Message) =>
            m.id === msg.id ? { ...m, deleted_at: new Date().toISOString() } : m
          ),
        };
      });
      addToast({ type: "success", title: t("chats.messageDeletedSuccess") });
    } catch (error) {
      console.error('[ChatsPage] Error deleting message:', error);
      addToast({
        type: "error",
        title: t("chats.deleteMessageError"),
        description: error instanceof Error ? error.message : t("chats.unknownError"),
      });
    }
  };

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
    try {
      const data = selectedChannelId
        ? await getChannelConversations(selectedChannelId)
        : await getConversations()
      setConversations(data)
    } catch {
      addToast({ type: "error", title: t("chats.loadConversationsError") })
    }
  }, [selectedChannelId, addToast, t])

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

  const renderChannelsSidebar = (
    onChannelSelect: (channelId: number) => void,
    onConnectChannelClick: (channelType?: ConnectableChannel) => void,
  ) => (
    <>
      <div
        role="tablist"
        aria-label={t("chats.views.label")}
        className="flex h-[70px] items-stretch border-b border-border bg-card/95"
      >
        {([
          { key: "inbox", label: t("chats.views.inbox"), count: conversationViewCounts.inbox },
          { key: "unread", label: t("chats.views.unread"), count: conversationViewCounts.unread },
          { key: "review", label: t("chats.views.review"), count: conversationViewCounts.review },
          { key: "archived", label: t("chats.views.archived"), count: conversationViewCounts.archived },
        ] as const).map((item) => {
          const isActive = viewType === item.key
          const hasUnreadCount = item.key === "unread" && item.count > 0
          return (
            <button
              key={item.key}
              type="button"
              role="tab"
              aria-selected={isActive}
              onClick={() => setViewType(item.key)}
              title={item.label}
              className={`group relative flex min-w-0 flex-1 items-center justify-center gap-1.5 px-2 text-sm font-medium tracking-[-0.01em] transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 ${
                isActive
                  ? "text-primary"
                  : "text-muted-foreground hover:text-foreground"
              }`}
            >
              <span className="truncate">{item.label}</span>
              <span
                aria-hidden={item.count === 0}
                className={`inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full border px-1.5 text-xs font-medium tabular-nums transition-colors ${
                  isActive
                    ? "border-primary/35 bg-primary/10 text-primary"
                    : hasUnreadCount
                      ? "border-primary/25 bg-primary/10 text-primary"
                      : "border-border bg-background/80 text-muted-foreground"
                }`}
              >
                {item.count > 99 ? "99+" : item.count}
              </span>
              <span
                className={`absolute inset-x-0 bottom-0 h-0.5 origin-left transition-transform duration-200 ease-out ${
                  isActive ? "scale-x-100 bg-primary" : "scale-x-0 bg-transparent"
                }`}
              />
            </button>
          )
        })}
      </div>

      <ChatFilters
        activeFilter={activeFilter}
        onFilterChange={handleFilterChange}
        availableChannelTypes={["whatsapp", "instagram", "facebook"]}
      />

      <div className="border-b border-border bg-card/80 px-4 py-3">
        <TagFilterMenu
          selectedSlugs={tagFilterSlugs}
          onChange={setTagFilterSlugs}
          label="Tags"
          align="start"
        />
      </div>

      <div className="flex-1 overflow-y-auto">
        <ChannelsList
          channels={connectedChannels.concat(disconnectedChannels)}
          selectedChannel={selectedChannel}
          activeFilter={activeFilter}
          isLoading={isChannelsLoading}
          error={null}
          onChannelSelect={onChannelSelect}
          onRenameChannel={canUpdateChannels ? handleRenameChannel : undefined}
          onSyncMailChannel={canUpdateChannels ? handleSyncMailChannel : undefined}
        />
      </div>

      <div className="border-t border-border p-4">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="outline" className="w-full justify-center gap-2 bg-transparent">
              <Zap className="h-4 w-4 text-primary" />
              + Connect chat
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

  const handleMobileConnectChannel = (channelType?: ConnectableChannel) => {
    setIsChannelsSheetOpen(false)
    handleConnectChannel(channelType)
  }

  return (
    <SidebarLayout>
      <ChatsCompactHeader
        searchQuery={searchQuery}
        onSearchChange={setSearchQuery}
        onNewConversation={handleNewConversation}
        onConnectChannel={handleConnectChannel}
        onOpenChannels={() => setIsChannelsSheetOpen(true)}
        hideOnMobile={Boolean(selectedConversationId)}
      />

      <Sheet open={isChannelsSheetOpen} onOpenChange={setIsChannelsSheetOpen}>
        <SheetContent
          side="left"
          className="flex w-[320px] flex-col gap-0 p-0 sm:max-w-[360px] md:hidden"
        >
          <SheetTitle className="sr-only">Canales</SheetTitle>
          {renderChannelsSidebar(handleMobileChannelSelect, handleMobileConnectChannel)}
        </SheetContent>
      </Sheet>

      {/* `flex-1 min-h-0` en vez de `h-[calc(100vh-75px)]`: el 75px era un
          número mágico que sólo valía para el encabezado de escritorio. En
          mobile el encabezado mide 125px (trae el buscador) y desaparece del
          todo con un hilo abierto, así que ninguna constante sirve para los
          tres casos. El flex mide lo que sobra, siempre. */}
      <div className="flex min-h-0 flex-1 overflow-hidden bg-background">
        <div className="hidden w-[360px] flex-col border-r border-border bg-card md:flex lg:w-[380px]">
          {renderChannelsSidebar(handleChannelSelect, handleConnectChannel)}
        </div>

        {/* Panel principal */}
        <div className="flex-1 bg-background flex flex-col overflow-hidden min-h-0">

          {!selectedChannelId && !selectedConversationId && (
            <FilteredConversationsHeader activeFilter={activeFilter} />
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
              <div className="flex items-center justify-between gap-2 border-b border-border bg-card/60 px-4 py-2">
                <div className="flex items-center gap-2">
                  <Button
                    variant={selectionMode ? "default" : "outline"}
                    size="sm"
                    onClick={handleToggleSelectionMode}
                    className="gap-2"
                  >
                    <CheckSquare className="h-4 w-4" />
                    {selectionMode ? t("chats.bulk.exitSelection") : t("chats.bulk.selectMode")}
                  </Button>
                  {selectionMode && visibleConversations.length > 0 && (
                    <Button variant="ghost" size="sm" onClick={handleSelectAllVisible}>
                      {allVisibleSelected ? t("chats.bulk.deselectAll") : t("chats.bulk.selectAll")}
                    </Button>
                  )}
                </div>
                {selectionMode && selectedConversationIds.size > 0 && (
                  <span className="text-xs text-muted-foreground">
                    {t("chats.bulk.selectedCount", { count: selectedConversationIds.size })}
                  </span>
                )}
              </div>

              {selectionMode && selectedConversationIds.size > 0 && (
                <div className="flex flex-wrap items-center gap-2 border-b border-primary/20 bg-primary/5 px-4 py-2">
                  <Button
                    size="sm"
                    onClick={() => setBulkTagsOpen(true)}
                    disabled={bulkSubmitting}
                    className="gap-2"
                  >
                    <Tags className="h-3.5 w-3.5" />
                    {t("chats.bulk.editTags")}
                  </Button>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => setBulkAssignOpen(true)}
                    disabled={bulkSubmitting}
                    className="gap-2"
                  >
                    <UserPlus className="h-3.5 w-3.5" />
                    {t("chats.bulk.assign")}
                  </Button>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={handleBroadcastClick}
                    disabled={bulkSubmitting}
                    className="gap-2"
                  >
                    <Megaphone className="h-3.5 w-3.5" />
                    {t("chats.bulk.broadcast")}
                  </Button>
                  {viewType === "archived" ? (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => handleBulkArchive(false)}
                      disabled={bulkSubmitting}
                      className="gap-2"
                    >
                      {bulkSubmitting ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <ArchiveRestore className="h-3.5 w-3.5" />}
                      {t("chats.bulk.unarchive")}
                    </Button>
                  ) : (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => handleBulkArchive(true)}
                      disabled={bulkSubmitting}
                      className="gap-2"
                    >
                      {bulkSubmitting ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Archive className="h-3.5 w-3.5" />}
                      {t("chats.bulk.archive")}
                    </Button>
                  )}
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => handleBulkMarkRead(true)}
                    disabled={bulkSubmitting}
                    className="gap-2"
                  >
                    {bulkSubmitting ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <MailOpen className="h-3.5 w-3.5" />}
                    {t("chats.bulk.markRead")}
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => handleBulkMarkRead(false)}
                    disabled={bulkSubmitting}
                    className="gap-2"
                  >
                    {bulkSubmitting ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Mail className="h-3.5 w-3.5" />}
                    {t("chats.bulk.markUnread")}
                  </Button>
                  <div
                    role="group"
                    aria-label={t("chats.bulk.aiAssistant")}
                    className="inline-flex h-8 items-center gap-1 rounded-md border border-border bg-background pl-2 pr-1"
                  >
                    {bulkSubmitting ? (
                      <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                    ) : (
                      <Bot className="h-3.5 w-3.5 text-muted-foreground" />
                    )}
                    <span className="hidden text-xs font-medium text-muted-foreground lg:inline">
                      {t("chats.bulk.aiAssistant")}
                    </span>
                    <div className="ml-1 flex items-center gap-0.5">
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => handleBulkAiAutoreply(true)}
                        disabled={bulkSubmitting}
                        className="h-6 px-2 text-xs text-emerald-600 hover:bg-emerald-500/10 hover:text-emerald-600 dark:text-emerald-400 dark:hover:text-emerald-400"
                      >
                        {t("chats.bulk.enableAi")}
                      </Button>
                      <span aria-hidden className="text-border">|</span>
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => handleBulkAiAutoreply(false)}
                        disabled={bulkSubmitting}
                        className="h-6 px-2 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
                      >
                        {t("chats.bulk.disableAi")}
                      </Button>
                    </div>
                  </div>
                  <Button
                    size="sm"
                    variant="destructive"
                    onClick={() => setBulkDeleteOpen(true)}
                    disabled={bulkSubmitting}
                    className="gap-2"
                  >
                    <Trash2 className="h-3.5 w-3.5" />
                    {t("chats.bulk.delete")}
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={handleClearSelection}
                    disabled={bulkSubmitting}
                    className="gap-2"
                  >
                    <X className="h-3.5 w-3.5" />
                    {t("chats.bulk.clear")}
                  </Button>
                </div>
              )}

              <ConversationList
                conversations={visibleConversations}
                channels={channels}
                isLoading={isLoading}
                selectedConversationId={selectedConversationId}
                onConversationClick={handleConversationClick}
                selectionMode={selectionMode}
                selectedIds={selectedConversationIds}
                onToggleSelect={handleToggleSelectConversation}
                messageResults={messageOnlyResults}
                isSearchingMessages={isSearchingMessages}
                emptyState={{
                  title: searchQuery ? t("chats.noSearchResults") : t("chats.noConversations"),
                  description: searchQuery
                    ? t("chats.noSearchResultsDesc")
                    : selectedChannelId
                    ? `${activeChannel?.name}`
                    : t("chats.noConversationsDesc"),
                }}
              />
            </>
          )}

          {selectedConversationId && currentConversation && activeConversation && selectedConversationId && (

            <>
              <ConversationHeader
                conversation={activeConversation}
                isContactInfoOpen={isContactInfoOpen}
                onBack={handleBackToConversations}
                onToggleContactInfo={() => setIsContactInfoOpen(!isContactInfoOpen)}
                onRenameContact={handleRenameContact}
                onToggleAiAutoreply={handleToggleAiAutoreply}
              />
              {/* Quick Bar */}
              <ChatQuickBar
                chatId={selectedConversationId}
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
                key={selectedConversationId}
                messages={currentConversation?.messages || []}
                onLoadMore={handleLoadMoreMessages}
                hasMore={hasMore}
                isLoadingMore={isLoadingMore}
                onEditMessage={handleEditMessage}
                onDeleteMessage={handleDeleteMessage}
                currentUserId={currentUserId}
                isAdmin={isAdmin}
                translationLanguage={language}
                onTranslateMessage={handleTranslateMessage}
                channelType={activeConversation?.channel?.type}
              />
              {activeConversation?.channel?.type === ChannelType.MAIL ? (
                <MailMessageInput
                  value={message}
                  onChange={setMessage}
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
                <MessageInput
                  value={message}
                  onChange={setMessage}
                  onSend={editingMessage ? handleSaveEdit : handleSendMessage}
                  disabled={isLoading}
                  channelId={activeConversation?.channel?.id ?? activeConversation?.channelId}
                  conversationId={selectedConversationId}
                  onSendTemplate={handleSendTemplate}
                  supportsTemplates={activeConversation?.channel?.type === ChannelType.WHATSAPP}
                  supportsVoice={activeConversation?.channel?.type === ChannelType.WHATSAPP}
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
              )}
            </>


          )}

        </div>

        {/* Panel de información de contacto */}
        {selectedConversationId && activeConversation && (() => {
          const contactId = activeConversation.contact_id ?? Number(activeConversation.contact?.id)
          if (!contactId || Number.isNaN(contactId)) return null
          return (
            <ContactInfoPanel
              contactId={contactId}
              isOpen={isContactInfoOpen}
              onClose={() => setIsContactInfoOpen(false)}
            />
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
              {t("chats.bulk.deleteConfirmDesc", { count: selectedConversationIds.size })}
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
