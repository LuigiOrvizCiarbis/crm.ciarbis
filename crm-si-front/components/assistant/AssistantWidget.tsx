"use client"

import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"
import { useChat } from "@ai-sdk/react"
import { DefaultChatTransport, isToolUIPart, lastAssistantMessageIsCompleteWithApprovalResponses } from "ai"
import { AlertCircle, Bot, Check, History, Plus, Send, Square } from "lucide-react"
import { hasPendingApproval } from "@/components/assistant/AssistantActionCard"
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
import { Skeleton } from "@/components/ui/skeleton"
import { Sheet, SheetContent, SheetDescription, SheetTitle } from "@/components/ui/sheet"
import { Textarea } from "@/components/ui/textarea"
import { VoiceRecorder } from "@/components/chat/VoiceRecorder"
import { useTranslation } from "@/hooks/useTranslation"
import {
  createAssistantConversation,
  getAssistantThread,
  listAssistantConversations,
  sendAssistantFeedback,
  transcribeAssistantAudio,
  type AssistantConversation,
  type AssistantRating,
} from "@/lib/api/assistant"
import { workspaceHeaders } from "@/lib/api/auth-token"
import { hasPermission } from "@/lib/permissions"
import { cn } from "@/lib/utils"
import { useAuthStore } from "@/store/useAuthStore"

const MAX_LENGTH = 2000
// El contador aparece recién cerca del tope: antes es ruido.
const COUNTER_THRESHOLD = MAX_LENGTH * 0.9
// Distancia al fondo dentro de la cual el panel sigue el streaming.
const STICK_THRESHOLD_PX = 64

const SUGGESTIONS = ["summary", "pipeline", "tasks"] as const

type AssistantError = { message?: string; code?: string }

/** Errores que se muestran traducidos en vez del texto del servidor. */
const ERROR_KEYS: Record<string, string> = {
  rate_limited: "assistant.errors.rateLimited",
  voice_rate_limited: "assistant.voice.rateLimited",
  network_error: "assistant.voice.networkError",
}

/** Los errores previos al stream llegan como JSON {message, code} en el cuerpo de la respuesta. */
function parseChatError(error: Error): AssistantError {
  try {
    const parsed = JSON.parse(error.message)
    return { message: parsed?.message, code: parsed?.code }
  } catch {
    return {}
  }
}

function formatConversationDate(value: string, language: string): string {
  const date = new Date(value)
  const sameYear = date.getFullYear() === new Date().getFullYear()
  return date.toLocaleDateString(language, { day: "numeric", month: "short", year: sameYear ? undefined : "numeric" })
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
  // Grabando o transcribiendo un dictado: el grabador ocupa la fila del campo.
  const [dictating, setDictating] = useState(false)
  const [activeConversationId, setActiveConversationId] = useState<string | null>(null)
  const conversationIdRef = useRef<string | null>(null)
  const lastSentRef = useRef("")
  const listLoadedRef = useRef(false)
  // Guardas síncronas: el estado de React no alcanza contra un doble Enter.
  const sendingRef = useRef(false)
  const chatActiveRef = useRef(false)
  const endRef = useRef<HTMLDivElement>(null)
  const logRef = useRef<HTMLDivElement>(null)
  const textareaRef = useRef<HTMLTextAreaElement>(null)
  const wasDictatingRef = useRef(false)
  // Si el usuario sube a releer, el streaming no lo arrastra al fondo.
  const stickToBottomRef = useRef(true)

  const transport = useMemo(
    () =>
      new DefaultChatTransport({
        api: "/api/assistant/chat",
        headers: () => workspaceHeaders(),
        // El historial lo carga el backend desde el hilo: solo viaja el último
        // mensaje, junto al conversation_id que manda cada sendMessage. Al
        // reanudar una confirmación, de ese mensaje del asistente solo importan
        // las partes de herramientas (el texto y el razonamiento ya están guardados).
        prepareSendMessagesRequest: ({ messages, body }) => {
          const last = messages.at(-1)
          const outgoing =
            last?.role === "assistant" ? { ...last, parts: last.parts.filter((part) => isToolUIPart(part)) } : last
          return { body: { ...body, messages: outgoing ? [outgoing] : [] } }
        },
      }),
    [],
  )

  const { messages, sendMessage, status, error, stop, setMessages, clearError, addToolApprovalResponse } = useChat({
    transport,
    // Al confirmar o cancelar una acción, el turno se reanuda solo.
    sendAutomaticallyWhen: lastAssistantMessageIsCompleteWithApprovalResponses,
    onFinish: () => {
      // useChat ya pasó a "ready", pero el ref se actualiza recién tras el render:
      // sin esto, la recarga del hilo podría descartarse por "turno en curso".
      chatActiveRef.current = false
      void refreshAfterReply()
    },
    onError: () => {
      // Un turno nuevo que falló no se guardó: se saca de la vista y el texto
      // vuelve al campo. Si falló una reanudación (el último mensaje es del
      // asistente) no se quitó nada y el borrador actual queda intacto.
      // setMessages de useChat aplica el updater en el momento.
      let removedUserMessage = false
      setMessages((current) => {
        if (current.at(-1)?.role !== "user") return current
        removedUserMessage = true
        return current.slice(0, -1)
      })
      if (removedUserMessage) setInput(lastSentRef.current)
    },
  })

  const chatActive = status === "submitted" || status === "streaming"
  const busy = chatActive || sending
  const lastMessage = messages.at(-1)
  // Con una acción esperando confirmación no se puede escribir otra cosa:
  // el turno pausado tiene que resolverse primero.
  const awaitingApproval = lastMessage?.role === "assistant" && hasPendingApproval(lastMessage)
  const inputLocked = busy || loadingThread || awaitingApproval
  // Los modelos que razonan (p. ej. qwen3) emiten solo razonamiento al principio:
  // los puntos siguen hasta que aparece texto visible o una herramienta en curso.
  const showTyping =
    busy &&
    !(lastMessage?.role === "assistant" && (messageText(lastMessage) !== "" || isRunningTool(lastMessage)))
  const visibleError = requestError ?? (error ? parseChatError(error) : null)
  const errorKey = visibleError?.code ? ERROR_KEYS[visibleError.code] : undefined
  const errorText = errorKey ? t(errorKey) : visibleError?.message || t("assistant.errors.generic")
  const canConfigureAi = Boolean(role?.is_owner) || hasPermission("ai_config.manage", permissions)

  useEffect(() => {
    if (!open || listLoadedRef.current) return
    listLoadedRef.current = true
    void listAssistantConversations().then(setConversations)
  }, [open])

  useEffect(() => {
    chatActiveRef.current = chatActive
  }, [chatActive])

  useEffect(() => {
    // Depende de busy y no solo de chatActive: el stream termina mientras
    // sending sigue en true, con Confirmar todavía deshabilitado.
    if (busy || !open) return
    // Al terminar un turno el foco vuelve al campo; si quedó una acción por
    // confirmar, va al botón Confirmar (el campo está bloqueado).
    const confirm = awaitingApproval ? logRef.current?.querySelector<HTMLButtonElement>("[data-assistant-confirm]:not(:disabled)") : null
    if (confirm) confirm.focus()
    else textareaRef.current?.focus()
  }, [busy, open, awaitingApproval])

  useEffect(() => {
    // Al terminar un dictado el campo se vuelve a montar con el texto
    // transcripto: el foco va ahí recién cuando existe.
    if (wasDictatingRef.current && !dictating) textareaRef.current?.focus()
    wasDictatingRef.current = dictating
  }, [dictating])

  useEffect(() => {
    if (stickToBottomRef.current) endRef.current?.scrollIntoView({ block: "end" })
  }, [messages, status])

  function selectConversation(conversationId: string | null) {
    conversationIdRef.current = conversationId
    setActiveConversationId(conversationId)
    stickToBottomRef.current = true
  }

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
    if (!content || sendingRef.current || chatActiveRef.current || loadingThread || awaitingApproval || dictating) return
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
        selectConversation(conversationId)
        setConversations((current) => [created.data!, ...current])
      }

      lastSentRef.current = content
      setInput("")
      stickToBottomRef.current = true
      await sendMessage({ text: content }, { body: { conversation_id: conversationId } })
    } finally {
      sendingRef.current = false
      setSending(false)
    }
  }

  /** Confirma o cancela la acción pendiente; sendAutomaticallyWhen reanuda el turno. */
  function decide(approvalId: string, approved: boolean) {
    if (sendingRef.current || chatActiveRef.current) return
    resetErrors()
    void addToolApprovalResponse({
      id: approvalId,
      approved,
      options: { body: { conversation_id: conversationIdRef.current } },
    })
  }

  /**
   * El dictado no se envía solo: el texto se suma al campo para revisarlo,
   * así un error de transcripción no termina, p. ej., en una tarea mal creada.
   */
  async function dictate(audio: File) {
    resetErrors()
    const result = await transcribeAssistantAudio(audio)
    if (result.text === undefined) {
      setRequestError({ message: result.error, code: result.code })
      return
    }
    const text = result.text
    setInput((current) => (current.trim() ? `${current.trim()} ${text}` : text).slice(0, MAX_LENGTH))
  }

  function startNewConversation() {
    if (busy) void stop()
    resetErrors()
    selectConversation(null)
    setMessages([])
    setFeedback({})
    setStoredIds(new Set())
  }

  async function openConversation(conversationId: string) {
    if (busy) void stop()
    resetErrors()
    selectConversation(conversationId)
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
          "fixed right-4 z-40 size-12 rounded-full shadow-lg md:right-6 dark:shadow-none",
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
          {/* Alto alineado con el cierre del Sheet (top-3, size-8; size-10 en táctil). */}
          <header className="flex h-14 shrink-0 items-center gap-1 border-b pr-12 pl-4 pointer-coarse:h-16 pointer-coarse:pr-14">
            <SheetTitle className="mr-auto text-base">{t("assistant.title")}</SheetTitle>
            <SheetDescription className="sr-only">{t("assistant.empty")}</SheetDescription>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="text-muted-foreground hover:text-foreground size-8 pointer-coarse:size-10"
                  aria-label={t("assistant.conversations")}
                  title={t("assistant.conversations")}
                >
                  <History className="size-4" aria-hidden="true" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="max-h-80 w-72 overflow-y-auto">
                <DropdownMenuLabel>{t("assistant.conversations")}</DropdownMenuLabel>
                {conversations.length === 0 ? (
                  <p className="text-muted-foreground px-2 py-1.5 text-sm">{t("assistant.noConversations")}</p>
                ) : (
                  conversations.map((conversation) => {
                    const current = conversation.id === activeConversationId
                    return (
                      <DropdownMenuItem
                        key={conversation.id}
                        onSelect={() => void openConversation(conversation.id)}
                        aria-current={current || undefined}
                        className="grid grid-cols-[1fr_auto] items-center gap-x-2 gap-y-0"
                      >
                        <span className={cn("truncate", current && "font-medium")}>{conversation.title}</span>
                        {current ? (
                          <Check className="text-primary row-span-2 size-4" aria-hidden="true" />
                        ) : (
                          <span className="row-span-2" />
                        )}
                        {conversation.updated_at ? (
                          <span className="text-muted-foreground text-xs">
                            {formatConversationDate(conversation.updated_at, language)}
                          </span>
                        ) : null}
                      </DropdownMenuItem>
                    )
                  })
                )}
              </DropdownMenuContent>
            </DropdownMenu>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="text-muted-foreground hover:text-foreground size-8 pointer-coarse:size-10"
              aria-label={t("assistant.newConversation")}
              title={t("assistant.newConversation")}
              onClick={startNewConversation}
            >
              <Plus className="size-4" aria-hidden="true" />
            </Button>
          </header>

          <ScrollArea
            className="min-h-0 flex-1"
            onScrollCapture={(event) => {
              const viewport = event.target as HTMLElement
              if (viewport.dataset.slot !== "scroll-area-viewport") return
              const distance = viewport.scrollHeight - viewport.scrollTop - viewport.clientHeight
              stickToBottomRef.current = distance < STICK_THRESHOLD_PX
            }}
          >
            <div ref={logRef} className="flex flex-col gap-2 px-4 py-5" role="log" aria-live="polite" aria-busy={busy || loadingThread}>
              {messages.length === 0 && !busy && !loadingThread ? (
                <div className="flex flex-col gap-4 py-2">
                  <p className="text-muted-foreground text-sm">{t("assistant.empty")}</p>
                  <div className="flex flex-col gap-2">
                    {SUGGESTIONS.map((key) => (
                      <Button
                        key={key}
                        type="button"
                        variant="outline"
                        className="h-auto justify-start py-2 text-left font-normal whitespace-normal pointer-coarse:min-h-10"
                        // Un turno lanzado mientras se graba quedaría sin botón Detener.
                        disabled={dictating}
                        onClick={() => void send(t(`assistant.suggestions.${key}`))}
                      >
                        {t(`assistant.suggestions.${key}`)}
                      </Button>
                    ))}
                  </div>
                </div>
              ) : null}

              {loadingThread && messages.length === 0 ? (
                <div className="flex flex-col gap-2" aria-hidden="true">
                  <Skeleton className="ml-auto h-9 w-2/3 motion-reduce:animate-none" />
                  <Skeleton className="h-4 w-full motion-reduce:animate-none" />
                  <Skeleton className="h-4 w-5/6 motion-reduce:animate-none" />
                  <Skeleton className="h-4 w-2/3 motion-reduce:animate-none" />
                </div>
              ) : null}

              {messages.map((message) => (
                <AssistantMessageBubble
                  key={message.id}
                  message={message}
                  rating={feedback[message.id]}
                  showActions={message === lastMessage}
                  ratingDisabled={busy}
                  onRate={
                    message.role === "assistant" && storedIds.has(message.id)
                      ? (rating) => void rate(message.id, rating)
                      : undefined
                  }
                  onDecide={!busy && message === lastMessage ? decide : undefined}
                />
              ))}

              {showTyping ? (
                <div className="flex h-5 items-center gap-1" aria-hidden="true">
                  {[0, 0.2, 0.4].map((delay) => (
                    <span
                      key={delay}
                      className="bg-muted-foreground/70 size-1.5 animate-pulse rounded-full motion-reduce:animate-none"
                      style={{ animationDelay: `${delay}s` }}
                    />
                  ))}
                </div>
              ) : null}
              <div ref={endRef} />
            </div>
          </ScrollArea>

          <form
            className="flex shrink-0 flex-col gap-2 border-t p-3"
            onSubmit={(event) => {
              event.preventDefault()
              void send(input)
            }}
          >
            {visibleError ? (
              <p role="alert" className="text-destructive flex items-start gap-2 text-sm">
                <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>
                  {errorText}
                  {visibleError.code === "ai_not_configured" && canConfigureAi ? (
                    <>
                      {" "}
                      <Link
                        href="/configuracion/integraciones#ai-assistant"
                        className="font-medium underline underline-offset-2"
                        onClick={() => setOpen(false)}
                      >
                        {t("assistant.configureAi")}
                      </Link>
                    </>
                  ) : null}
                </span>
              </p>
            ) : null}
            <div className="flex items-end gap-2">
              {/* Mientras se graba, el grabador ocupa la fila (contador, cancelar, transcribir). */}
              {dictating ? null : (
                <Textarea
                  ref={textareaRef}
                  value={input}
                  onChange={(event) => setInput(event.target.value)}
                  onKeyDown={handleKeyDown}
                  placeholder={t(awaitingApproval ? "assistant.approval.pending" : "assistant.placeholder")}
                  aria-label={t("assistant.placeholder")}
                  maxLength={MAX_LENGTH}
                  rows={1}
                  className="max-h-40 min-h-10 resize-none"
                  // readOnly y no disabled: el foco se queda en el campo entre turnos.
                  readOnly={inputLocked}
                />
              )}
              <VoiceRecorder
                onSend={dictate}
                disabled={inputLocked}
                onActiveChange={setDictating}
                labels={{
                  start: t("assistant.voice.start"),
                  send: t("assistant.voice.send"),
                  sending: t("assistant.voice.sending"),
                }}
              />
              {dictating ? null : busy ? (
                <Button
                  type="button"
                  size="icon"
                  variant="outline"
                  className="size-10 shrink-0"
                  aria-label={t("assistant.stop")}
                  title={t("assistant.stop")}
                  onClick={() => void stop()}
                >
                  <Square className="size-3.5 fill-current" aria-hidden="true" />
                </Button>
              ) : (
                <Button
                  type="submit"
                  size="icon"
                  className="size-10 shrink-0"
                  aria-label={t("assistant.send")}
                  title={t("assistant.send")}
                  disabled={!input.trim() || inputLocked}
                >
                  <Send className="size-4" aria-hidden="true" />
                </Button>
              )}
            </div>
            {input.length >= COUNTER_THRESHOLD ? (
              <span className="text-muted-foreground self-end text-xs tabular-nums" aria-live="polite">
                {input.length}/{MAX_LENGTH}
              </span>
            ) : null}
          </form>
        </SheetContent>
      </Sheet>
    </>
  )
}
