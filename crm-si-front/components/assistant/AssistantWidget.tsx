"use client"

import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
import { useChat } from "@ai-sdk/react"
import { DefaultChatTransport } from "ai"
import { Bot, History, Plus, Send, Square } from "lucide-react"
import { AssistantMessageBubble, isRunningTool, messageText } from "@/components/assistant/AssistantMessageBubble"
import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { ScrollArea } from "@/components/ui/scroll-area"
import { Sheet, SheetContent, SheetDescription, SheetTitle } from "@/components/ui/sheet"
import { Textarea } from "@/components/ui/textarea"
import { useTranslation } from "@/hooks/useTranslation"
import {
  createAssistantConversation,
  getAssistantThread,
  listAssistantConversations,
  sendAssistantFeedback,
  type AssistantConversation,
  type AssistantRating,
} from "@/lib/api/assistant"
import { workspaceHeaders } from "@/lib/api/auth-token"
import { hasPermission } from "@/lib/permissions"
import { cn } from "@/lib/utils"
import { useAuthStore } from "@/store/useAuthStore"

const MAX_LENGTH = 2000

const SUGGESTIONS = ["summary", "pipeline", "tasks"] as const

type AssistantError = { message?: string; code?: string }

/** Los errores previos al stream llegan como JSON {message, code} en el cuerpo de la respuesta. */
function parseChatError(error: Error): AssistantError {
  try {
    const parsed = JSON.parse(error.message)
    return { message: parsed?.message, code: parsed?.code }
  } catch {
    return {}
  }
}

/**
 * Asistente interno: botón flotante + panel lateral con chat en streaming
 * sobre el workspace activo. Solo para quien tiene assistant.use.
 */
export function AssistantWidget() {
  const permissions = useAuthStore((state) => state.permissions)
  const role = useAuthStore((state) => state.role)

  if (!role?.is_owner && !hasPermission("assistant.use", permissions)) return null

  return <AssistantPanel />
}

function AssistantPanel() {
  const { t, language } = useTranslation()
  const pathname = usePathname()
  const permissions = useAuthStore((state) => state.permissions)
  const role = useAuthStore((state) => state.role)
  const [open, setOpen] = useState(false)
  const [input, setInput] = useState("")
  const [conversations, setConversations] = useState<AssistantConversation[]>([])
  const [feedback, setFeedback] = useState<Record<string, AssistantRating>>({})
  // Ids ya guardados en el backend: solo esos admiten valoración.
  const [storedIds, setStoredIds] = useState<Set<string>>(new Set())
  const [requestError, setRequestError] = useState<AssistantError | null>(null)
  // Turno en preparación (creando el hilo) antes de que useChat cambie de estado.
  const [sending, setSending] = useState(false)
  const [loadingThread, setLoadingThread] = useState(false)
  const conversationIdRef = useRef<string | null>(null)
  const lastSentRef = useRef("")
  const listLoadedRef = useRef(false)
  // Guardas síncronas: el estado de React no alcanza contra un doble Enter.
  const sendingRef = useRef(false)
  const chatActiveRef = useRef(false)
  const endRef = useRef<HTMLDivElement>(null)
  const textareaRef = useRef<HTMLTextAreaElement>(null)

  const transport = useMemo(
    () =>
      new DefaultChatTransport({
        api: "/api/assistant/chat",
        headers: () => workspaceHeaders(),
        // El historial lo carga el backend desde el hilo: solo viaja el último
        // mensaje, junto al conversation_id que manda cada sendMessage.
        prepareSendMessagesRequest: ({ messages, body }) => ({
          body: { ...body, messages: messages.slice(-1) },
        }),
      }),
    [],
  )

  const { messages, sendMessage, status, error, stop, setMessages, clearError } = useChat({
    transport,
    onFinish: () => {
      // useChat ya pasó a "ready", pero el ref se actualiza recién tras el render:
      // sin esto, la recarga del hilo podría descartarse por "turno en curso".
      chatActiveRef.current = false
      void refreshAfterReply()
    },
    onError: () => {
      // El turno no se guardó: se saca de la vista y el texto vuelve al input.
      setMessages((current) => (current.at(-1)?.role === "user" ? current.slice(0, -1) : current))
      setInput(lastSentRef.current)
    },
  })

  const chatActive = status === "submitted" || status === "streaming"
  const busy = chatActive || sending
  const inputLocked = busy || loadingThread
  // Los modelos que razonan (p. ej. qwen3) emiten solo razonamiento al principio:
  // los puntos siguen hasta que aparece texto visible o una herramienta en curso.
  const lastMessage = messages.at(-1)
  const showTyping =
    busy &&
    !(lastMessage?.role === "assistant" && (messageText(lastMessage) !== "" || isRunningTool(lastMessage)))
  const visibleError = requestError ?? (error ? parseChatError(error) : null)
  const errorText =
    visibleError?.code === "rate_limited"
      ? t("assistant.errors.rateLimited")
      : visibleError?.message || t("assistant.errors.generic")
  const canConfigureAi = Boolean(role?.is_owner) || hasPermission("ai_config.manage", permissions)

  useEffect(() => {
    if (!open || listLoadedRef.current) return
    listLoadedRef.current = true
    void listAssistantConversations().then(setConversations)
  }, [open])

  useEffect(() => {
    chatActiveRef.current = chatActive
    // Al terminar un turno el textarea vuelve a estar disponible: el foco sigue ahí.
    if (!chatActive && open) textareaRef.current?.focus()
  }, [chatActive, open])

  useEffect(() => {
    endRef.current?.scrollIntoView({ block: "end" })
  }, [messages, status])

  async function loadThread(conversationId: string) {
    const thread = await getAssistantThread(conversationId)
    if (!thread || conversationIdRef.current !== conversationId) return
    // Si ya salió otro turno, reemplazar los mensajes borraría el que está en curso.
    if (sendingRef.current || chatActiveRef.current) return
    setMessages(thread.messages)
    setFeedback(thread.feedback)
    setStoredIds(new Set(thread.messages.map((message) => message.id)))
  }

  async function refreshAfterReply() {
    const conversationId = conversationIdRef.current
    if (conversationId) await loadThread(conversationId)
    setConversations(await listAssistantConversations())
  }

  function resetErrors() {
    setRequestError(null)
    clearError()
  }

  async function send(text: string) {
    const content = text.trim()
    if (!content || sendingRef.current || chatActiveRef.current || loadingThread) return
    sendingRef.current = true
    setSending(true)
    resetErrors()

    try {
      let conversationId = conversationIdRef.current
      if (!conversationId) {
        const created = await createAssistantConversation(content)
        if (!created.data) {
          setRequestError({ message: created.error })
          return
        }
        conversationId = created.data.id
        conversationIdRef.current = conversationId
        setConversations((current) => [created.data!, ...current])
      }

      lastSentRef.current = content
      setInput("")
      await sendMessage({ text: content }, { body: { conversation_id: conversationId } })
    } finally {
      sendingRef.current = false
      setSending(false)
    }
  }

  function startNewConversation() {
    if (busy) void stop()
    resetErrors()
    conversationIdRef.current = null
    setMessages([])
    setFeedback({})
    setStoredIds(new Set())
  }

  async function openConversation(conversationId: string) {
    if (busy) void stop()
    resetErrors()
    conversationIdRef.current = conversationId
    setMessages([])
    setLoadingThread(true)
    try {
      await loadThread(conversationId)
    } finally {
      setLoadingThread(false)
    }
  }

  async function rate(messageId: string, rating: AssistantRating) {
    const previous = feedback[messageId]
    setFeedback((current) => ({ ...current, [messageId]: rating }))
    if (!(await sendAssistantFeedback(messageId, rating))) {
      setFeedback((current) => {
        const next = { ...current }
        if (previous) next[messageId] = previous
        else delete next[messageId]
        return next
      })
    }
  }

  function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key !== "Enter" || event.shiftKey || event.nativeEvent.isComposing) return
    event.preventDefault()
    void send(input)
  }

  return (
    <>
      <Button
        type="button"
        size="icon"
        aria-label={t("assistant.open")}
        title={t("assistant.open")}
        onClick={() => setOpen(true)}
        className={cn(
          "fixed right-4 z-40 size-12 rounded-full shadow-lg md:right-6",
          // Por encima de la barra inferior de mobile, contando el área segura del iPhone.
          pathname?.startsWith("/chats")
            ? "bottom-[calc(9rem+env(safe-area-inset-bottom))] md:bottom-28"
            : "bottom-[calc(5rem+env(safe-area-inset-bottom))] md:bottom-6",
        )}
      >
        <Bot className="size-5" aria-hidden="true" />
      </Button>

      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent
          side="right"
          className="w-full gap-0 p-0 sm:max-w-md"
          onOpenAutoFocus={(event) => {
            event.preventDefault()
            textareaRef.current?.focus()
          }}
        >
          <header className="flex items-center gap-1 border-b py-3 pr-12 pl-4">
            <SheetTitle className="mr-auto text-base">{t("assistant.title")}</SheetTitle>
            <SheetDescription className="sr-only">{t("assistant.empty")}</SheetDescription>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button type="button" variant="ghost" size="icon" aria-label={t("assistant.conversations")} title={t("assistant.conversations")}>
                  <History className="size-4" aria-hidden="true" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-72">
                <DropdownMenuLabel>{t("assistant.conversations")}</DropdownMenuLabel>
                {conversations.length === 0 ? (
                  <p className="text-muted-foreground px-2 py-1.5 text-sm">{t("assistant.noConversations")}</p>
                ) : (
                  conversations.map((conversation) => (
                    <DropdownMenuItem
                      key={conversation.id}
                      onSelect={() => void openConversation(conversation.id)}
                      className="flex flex-col items-start gap-0"
                    >
                      <span className="w-full truncate">{conversation.title}</span>
                      {conversation.updated_at ? (
                        <span className="text-muted-foreground text-xs">
                          {new Date(conversation.updated_at).toLocaleDateString(language)}
                        </span>
                      ) : null}
                    </DropdownMenuItem>
                  ))
                )}
              </DropdownMenuContent>
            </DropdownMenu>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              aria-label={t("assistant.newConversation")}
              title={t("assistant.newConversation")}
              onClick={startNewConversation}
            >
              <Plus className="size-4" aria-hidden="true" />
            </Button>
          </header>

          <ScrollArea className="min-h-0 flex-1">
            <div className="flex flex-col gap-3 p-4" role="log" aria-live="polite" aria-busy={busy}>
              {messages.length === 0 && !busy && !loadingThread ? (
                <div className="flex flex-col items-start gap-3 py-6">
                  <p className="text-muted-foreground text-sm">{t("assistant.empty")}</p>
                  <div className="flex flex-wrap gap-2">
                    {SUGGESTIONS.map((key) => (
                      <Button
                        key={key}
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => void send(t(`assistant.suggestions.${key}`))}
                      >
                        {t(`assistant.suggestions.${key}`)}
                      </Button>
                    ))}
                  </div>
                </div>
              ) : null}

              {messages.map((message) => (
                <AssistantMessageBubble
                  key={message.id}
                  message={message}
                  rating={feedback[message.id]}
                  onRate={
                    !busy && message.role === "assistant" && storedIds.has(message.id)
                      ? (rating) => void rate(message.id, rating)
                      : undefined
                  }
                />
              ))}

              {showTyping ? (
                <div className="bg-muted flex w-fit gap-1 rounded-2xl rounded-bl-sm px-3 py-3" aria-hidden="true">
                  {[0, 0.1, 0.2].map((delay) => (
                    <span
                      key={delay}
                      className="bg-muted-foreground/60 size-1.5 animate-bounce rounded-full motion-reduce:animate-none"
                      style={{ animationDelay: `${delay}s` }}
                    />
                  ))}
                </div>
              ) : null}
              <div ref={endRef} />
            </div>
          </ScrollArea>

          <form
            className="flex flex-col gap-2 border-t p-3"
            onSubmit={(event) => {
              event.preventDefault()
              void send(input)
            }}
          >
            {visibleError ? (
              <p role="alert" className="text-destructive text-sm">
                {errorText}
                {visibleError.code === "ai_not_configured" && canConfigureAi ? (
                  <>
                    {" "}
                    <Link
                      href="/configuracion/integraciones#ai-assistant"
                      className="font-medium underline"
                      onClick={() => setOpen(false)}
                    >
                      {t("assistant.configureAi")}
                    </Link>
                  </>
                ) : null}
              </p>
            ) : null}
            <div className="flex items-end gap-2">
              <Textarea
                ref={textareaRef}
                value={input}
                onChange={(event) => setInput(event.target.value)}
                onKeyDown={handleKeyDown}
                placeholder={t("assistant.placeholder")}
                aria-label={t("assistant.placeholder")}
                maxLength={MAX_LENGTH}
                rows={1}
                className="max-h-40 min-h-10 resize-none"
                // readOnly y no disabled: el foco se queda en el campo entre turnos.
                readOnly={inputLocked}
              />
              {busy ? (
                <Button type="button" size="icon" variant="outline" aria-label={t("assistant.stop")} title={t("assistant.stop")} onClick={() => void stop()}>
                  <Square className="size-4" aria-hidden="true" />
                </Button>
              ) : (
                <Button type="submit" size="icon" aria-label={t("assistant.send")} title={t("assistant.send")} disabled={!input.trim() || loadingThread}>
                  <Send className="size-4" aria-hidden="true" />
                </Button>
              )}
            </div>
          </form>
        </SheetContent>
      </Sheet>
    </>
  )
}
