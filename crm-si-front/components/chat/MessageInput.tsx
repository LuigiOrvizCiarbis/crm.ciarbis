"use client"

import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet"
import { EmojiPicker } from "./EmojiPicker"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useTranslation } from "@/hooks/useTranslation"
import { Paperclip, Smile, Send, X, Pencil, Check, Music2, Languages, Loader2, RotateCcw, Plus, FileText, Image as ImageIcon, Sparkles, UserRound } from "lucide-react"
import { KeyboardEvent, SyntheticEvent, useMemo, useRef, useState, useEffect } from "react"
import dynamic from "next/dynamic"
import Image from "next/image"
import { Message, TranslationLanguage } from "@/data/types"
import { getMessageHotkeys, type MessageHotkey } from "@/lib/api/message-hotkeys"
import { pauseOtherAudios } from "@/lib/audio"
import { expandHotkey, parseSlashCommand, type HotkeyExpansionContext } from "@/lib/utils/hotkeys"
import { HotkeyAutocomplete } from "./HotkeyAutocomplete"
import type { ManualAiDraft } from "@/lib/api/manual-ai-drafts"
import { VoiceRecorder } from "./VoiceRecorder"
import { ContactShareDialog } from "./ContactShareDialog"

const TemplatePicker = dynamic(
  () => import("./TemplatePicker").then(m => m.TemplatePicker),
  { ssr: false }
)

interface MessageInputProps {
  value: string
  onChange: (value: string) => void
  onSend: (content: string, media?: File, voice?: boolean) => void | Promise<void>
  disabled?: boolean
  placeholder?: string
  channelId?: number | null
  conversationId?: number | null
  onSendTemplate?: (content: string) => void
  /** Las plantillas son exclusivas de WhatsApp. En Instagram se oculta el picker. */
  supportsTemplates?: boolean
  editingMessage?: Message | null
  onCancelEdit?: () => void
  expansionContext?: HotkeyExpansionContext
  contactLanguage: TranslationLanguage
  onContactLanguageChange: (language: TranslationLanguage) => void | Promise<void>
  onTranslateDraft: (content: string, targetLanguage: TranslationLanguage) => Promise<string>
  aiDraft?: ManualAiDraft | null
  onRequestAiDraft?: () => void
  onCancelAiDraft?: () => void
  onUseAiDraft?: () => void
  supportsVoice?: boolean
  supportsContactSharing?: boolean
  onShareContacts?: (ids: number[]) => void | Promise<void>
}

interface SlashState {
  query: string
  start: number
  end: number
}

export function MessageInput({
  value,
  onChange,
  onSend,
  disabled = false,
  placeholder,
  channelId,
  conversationId,
  onSendTemplate,
  supportsTemplates = true,
  editingMessage,
  onCancelEdit,
  expansionContext,
  contactLanguage,
  onContactLanguageChange,
  onTranslateDraft,
  aiDraft,
  onRequestAiDraft,
  onCancelAiDraft,
  onUseAiDraft,
  supportsVoice = false,
  supportsContactSharing = false,
  onShareContacts,
}: MessageInputProps) {
  const { t } = useTranslation()
  const resolvedPlaceholder = placeholder ?? t("chats.messagePlaceholder")
  const fileInputRef = useRef<HTMLInputElement>(null)
  const inputRef = useRef<HTMLTextAreaElement>(null)
  const [selectedMedia, setSelectedMedia] = useState<File | null>(null)
  const [mediaPreview, setMediaPreview] = useState<string | null>(null)
  const [isEmojiPickerOpen, setIsEmojiPickerOpen] = useState(false)
  const [hotkeys, setHotkeys] = useState<MessageHotkey[]>([])
  const [slashState, setSlashState] = useState<SlashState | null>(null)
  const [activeHotkeyIndex, setActiveHotkeyIndex] = useState(0)
  const [isTranslating, setIsTranslating] = useState(false)
  const [translationError, setTranslationError] = useState<string | null>(null)
  const [originalDraft, setOriginalDraft] = useState<string | null>(null)
  const [translatedLanguage, setTranslatedLanguage] = useState<TranslationLanguage | null>(null)
  const [isTranslationOpen, setIsTranslationOpen] = useState(false)
  const [isActionsOpen, setIsActionsOpen] = useState(false)
  const [isEmojiSheetOpen, setIsEmojiSheetOpen] = useState(false)
  const [isTranslationSheetOpen, setIsTranslationSheetOpen] = useState(false)
  const [isTemplateDialogOpen, setIsTemplateDialogOpen] = useState(false)
  const [isVoiceComposerActive, setIsVoiceComposerActive] = useState(false)
  const [isContactShareOpen, setIsContactShareOpen] = useState(false)

  const isEditing = !!editingMessage
  const isAudio = !!selectedMedia?.type.startsWith("audio/")

  useEffect(() => {
    let cancelled = false
    getMessageHotkeys().then((data) => {
      if (!cancelled) setHotkeys(data)
    })
    return () => {
      cancelled = true
    }
  }, [])

  const matchingHotkeys = useMemo(() => {
    if (!slashState) return []
    const q = slashState.query
    return hotkeys.filter((h) => h.trigger.startsWith(q))
  }, [hotkeys, slashState])

  useEffect(() => {
    setActiveHotkeyIndex(0)
  }, [slashState?.query])

  const isHotkeyDropdownOpen = !!slashState && !isEditing

  const closeHotkeyDropdown = () => setSlashState(null)

  const detectSlashFromInput = (nextValue: string, cursorPos: number) => {
    const match = parseSlashCommand(nextValue, cursorPos)
    setSlashState(match)
  }

  const applyHotkey = (hotkey: MessageHotkey) => {
    if (!slashState) return
    const expanded = expandHotkey(hotkey.content, expansionContext ?? {})
    const next = `${value.slice(0, slashState.start)}${expanded}${value.slice(slashState.end)}`
    const cursor = slashState.start + expanded.length

    onChange(next)
    setSlashState(null)

    requestAnimationFrame(() => {
      inputRef.current?.focus()
      inputRef.current?.setSelectionRange(cursor, cursor)
    })
  }
  const isImage = !!selectedMedia?.type.startsWith("image/")

  useEffect(() => {
    if (selectedMedia) {
      const url = URL.createObjectURL(selectedMedia)
      setMediaPreview(url)
      return () => URL.revokeObjectURL(url)
    }
    setMediaPreview(null)
  }, [selectedMedia])

  useEffect(() => {
    if (editingMessage) {
      inputRef.current?.focus()
    }
  }, [editingMessage])

  useEffect(() => {
    setOriginalDraft(null)
    setTranslatedLanguage(null)
    setTranslationError(null)
  }, [conversationId])

  useEffect(() => {
    if (!value && !isTranslating) {
      setOriginalDraft(null)
      setTranslatedLanguage(null)
      setTranslationError(null)
    }
  }, [value, isTranslating])

  const stopPropagation = (e: SyntheticEvent) => {
    e.stopPropagation()
  }

  const handleKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    e.stopPropagation()

    if (isHotkeyDropdownOpen) {
      if (e.key === "ArrowDown") {
        e.preventDefault()
        if (matchingHotkeys.length > 0) {
          setActiveHotkeyIndex((i) => (i + 1) % matchingHotkeys.length)
        }
        return
      }
      if (e.key === "ArrowUp") {
        e.preventDefault()
        if (matchingHotkeys.length > 0) {
          setActiveHotkeyIndex((i) => (i - 1 + matchingHotkeys.length) % matchingHotkeys.length)
        }
        return
      }
      if (e.key === "Enter" || e.key === "Tab") {
        const target = matchingHotkeys[activeHotkeyIndex]
        if (target) {
          e.preventDefault()
          applyHotkey(target)
          return
        }
      }
      if (e.key === "Escape") {
        e.preventDefault()
        closeHotkeyDropdown()
        return
      }
    }

    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault()
      handleSend()
    }
    if (e.key === "Escape" && isEditing && onCancelEdit) {
      e.preventDefault()
      onCancelEdit()
    }
  }

  const handleInputChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
    const nextValue = e.target.value
    onChange(nextValue)
    const cursorPos = e.target.selectionStart ?? nextValue.length
    detectSlashFromInput(nextValue, cursorPos)
  }

  const handleInputSelect = (e: SyntheticEvent<HTMLTextAreaElement>) => {
    const input = e.currentTarget
    const cursorPos = input.selectionStart ?? value.length
    detectSlashFromInput(value, cursorPos)
  }

  const handleSend = () => {
    if (!value.trim() && !selectedMedia) return
    onSend(value.trim(), selectedMedia || undefined)
    setSelectedMedia(null)
    setIsEmojiPickerOpen(false)
    setOriginalDraft(null)
    setTranslatedLanguage(null)
    setTranslationError(null)
  }

  const handleTranslateDraft = async () => {
    const source = value
    if (!source.trim() || isEditing || isAudio || isTranslating) return

    setIsTranslating(true)
    setTranslationError(null)
    try {
      const translated = await onTranslateDraft(source, contactLanguage)
      setOriginalDraft((current) => current ?? source)
      setTranslatedLanguage(contactLanguage)
      onChange(translated)
      closeTranslationSurfaces()
      requestAnimationFrame(() => inputRef.current?.focus())
    } catch (error) {
      setTranslationError(error instanceof Error ? error.message : t("chats.translationError"))
    } finally {
      setIsTranslating(false)
    }
  }

  const restoreOriginalDraft = () => {
    if (originalDraft === null) return
    onChange(originalDraft)
    setOriginalDraft(null)
    setTranslatedLanguage(null)
    setTranslationError(null)
    requestAnimationFrame(() => inputRef.current?.focus())
  }

  const acceptMediaFile = (file: File) => {
    if (file.size > 10 * 1024 * 1024) {
      alert(t("chats.fileTooLarge") || "El archivo es demasiado grande (máx. 10MB)")
      return false
    }

    if (!file.type.startsWith("image/") && !file.type.startsWith("audio/")) {
      alert(t("chats.onlyImagesOrAudio") || "Solo se permiten imágenes o audios")
      return false
    }

    setSelectedMedia(file)
    return true
  }

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return

    acceptMediaFile(file)
    if (fileInputRef.current) fileInputRef.current.value = ""
  }

  const handlePaste = (e: React.ClipboardEvent<HTMLTextAreaElement>) => {
    if (isEditing || isAudio) return

    const imageItem = Array.from(e.clipboardData.items).find((item) =>
      item.type.startsWith("image/")
    )
    if (!imageItem) return

    const file = imageItem.getAsFile()
    if (!file) return

    e.preventDefault()
    acceptMediaFile(file)
  }

  const canSend = isAudio ? selectedMedia : value.trim() || selectedMedia
  const emojiPickerDisabled = disabled || isAudio

  const handleEmojiSelect = (emoji: string) => {
    const input = inputRef.current
    const selectionStart = input?.selectionStart ?? value.length
    const selectionEnd = input?.selectionEnd ?? value.length
    const nextValue = `${value.slice(0, selectionStart)}${emoji}${value.slice(selectionEnd)}`
    const nextCursorPosition = selectionStart + emoji.length

    onChange(nextValue)
    setIsEmojiPickerOpen(false)
    setIsEmojiSheetOpen(false)

    requestAnimationFrame(() => {
      inputRef.current?.focus()
      inputRef.current?.setSelectionRange(nextCursorPosition, nextCursorPosition)
    })
  }

  /** El traductor vive en un popover (escritorio) y una hoja (móvil): cerramos ambos. */
  const closeTranslationSurfaces = () => {
    setIsTranslationOpen(false)
    setIsTranslationSheetOpen(false)
  }

  /** Mismo cuerpo para el popover de escritorio y la hoja móvil. */
  const translationPanel = (
    <div className="space-y-3">
      <div className="space-y-1.5">
        <p className="text-xs font-medium text-muted-foreground">
          {t("chats.contactLanguage")}
        </p>
        <Select
          value={contactLanguage}
          onValueChange={(next) => void onContactLanguageChange(next as TranslationLanguage)}
          disabled={disabled || isTranslating}
        >
          <SelectTrigger className="h-10 w-full text-sm sm:h-9" aria-label={t("chats.contactLanguage")}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {(["es", "en", "pt", "fr", "it", "de", "zh"] as TranslationLanguage[]).map((lang) => (
              <SelectItem key={lang} value={lang}>
                {t(`chats.language.${lang}`)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {originalDraft !== null ? (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-10 w-full gap-2 sm:h-9"
          onClick={() => {
            restoreOriginalDraft()
            closeTranslationSurfaces()
          }}
          disabled={disabled || isTranslating}
        >
          <RotateCcw className="h-3.5 w-3.5" />
          {t("chats.restoreOriginal")}
        </Button>
      ) : (
        <Button
          type="button"
          size="sm"
          className="h-10 w-full gap-2 sm:h-9"
          onClick={() => void handleTranslateDraft()}
          disabled={disabled || isTranslating || isAudio || !value.trim()}
        >
          {isTranslating ? (
            <Loader2 className="h-3.5 w-3.5 animate-spin motion-reduce:animate-none" />
          ) : (
            <Languages className="h-3.5 w-3.5" />
          )}
          {isTranslating ? t("chats.translating") : t("chats.translateDraft")}
        </Button>
      )}

      {translationError && (
        <p className="text-[11px] text-destructive" role="status">
          {translationError}
        </p>
      )}
    </div>
  )

  /** Acciones de la hoja inferior, en grilla. Sólo móvil. */
  const composerActions = [
    {
      key: "gallery",
      label: t("chats.attachImage"),
      Icon: ImageIcon,
      tone: "bg-primary/10 text-primary",
      disabled: disabled,
      onClick: () => {
        setIsActionsOpen(false)
        fileInputRef.current?.click()
      },
    },
    ...(supportsTemplates && channelId && conversationId && onSendTemplate
      ? [{
          key: "template",
          label: t("chats.templates"),
          Icon: FileText,
          tone: "bg-muted text-foreground",
          disabled: disabled,
          onClick: () => {
            setIsActionsOpen(false)
            setIsTemplateDialogOpen(true)
          },
        }]
      : []),
    ...(supportsContactSharing && onShareContacts ? [{
      key: "contacts",
      label: "Compartir contactos",
      Icon: UserRound,
      tone: "bg-primary/10 text-primary",
      disabled,
      onClick: () => { setIsActionsOpen(false); setIsContactShareOpen(true) },
    }] : []),
    {
      key: "emoji",
      label: t("chats.emojiPickerTitle"),
      Icon: Smile,
      tone: "bg-muted text-foreground",
      disabled: emojiPickerDisabled,
      onClick: () => {
        setIsActionsOpen(false)
        setIsEmojiSheetOpen(true)
      },
    },
    {
      key: "translate",
      label: t("chats.translateAction"),
      Icon: Languages,
      tone: translatedLanguage ? "bg-primary/10 text-primary" : "bg-muted text-foreground",
      disabled: disabled,
      onClick: () => {
        setIsActionsOpen(false)
        setIsTranslationSheetOpen(true)
      },
    },
    ...(onRequestAiDraft ? [{
      key: "ai-draft",
      label: t("chats.aiDraftGenerate"),
      Icon: Sparkles,
      tone: "bg-primary/10 text-primary",
      disabled,
      onClick: () => {
        setIsActionsOpen(false)
        if (aiDraft?.status === "pending") onCancelAiDraft?.()
        else if (aiDraft?.content) onUseAiDraft?.()
        else onRequestAiDraft()
      },
    }] : []),
  ]

  return (
    <div className="border-t border-border bg-card sticky bottom-0 md:relative">
      {/* Edit mode banner */}
      {isEditing && (
        <div className="px-4 pt-3 flex items-center gap-2 text-sm text-muted-foreground">
          <Pencil className="h-4 w-4 shrink-0" />
          <span className="truncate flex-1">
            {t("chats.editingMessage")}: {editingMessage.content}
          </span>
          <button
            onClick={onCancelEdit}
            className="shrink-0 hover:text-foreground transition-colors"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {mediaPreview && !isEditing && (
        <div className="px-4 pt-3 flex items-end gap-2">
          <div className="relative inline-block">
            {isImage ? (
              <Image
                src={mediaPreview}
                alt="Preview"
                width={120}
                height={120}
                className="rounded-lg object-cover max-h-[120px] w-auto"
              />
            ) : (
              <div className="min-w-[240px] max-w-[320px] rounded-lg border border-border bg-background p-3">
                <div className="mb-2 flex items-center gap-2 text-sm">
                  <Music2 className="h-4 w-4 shrink-0" />
                  <span className="truncate">{selectedMedia?.name}</span>
                </div>
                <audio controls src={mediaPreview} className="w-full" onPlay={pauseOtherAudios} />
              </div>
            )}
            <button
              onClick={() => setSelectedMedia(null)}
              className="absolute -top-2 -right-2 bg-destructive text-destructive-foreground rounded-full p-0.5 hover:opacity-80"
            >
              <X className="w-3 h-3" />
            </button>
          </div>
        </div>
      )}
      {/* Estado de traducción fuera del popover: el chip resume qué pasó cuando
          el panel ya se cerró. Con el popover abierto no se duplica. */}
      {!isEditing && !isTranslationOpen && (translatedLanguage || translationError) && (
        <div
          className="flex items-center gap-2 px-4 pt-2 text-[11px] text-muted-foreground"
          aria-live="polite"
        >
          {translationError ? (
            <span className="truncate text-destructive" role="status">
              {translationError}
            </span>
          ) : (
            <>
              <span className="inline-flex items-center gap-1.5 rounded-full bg-muted px-2 py-0.5 font-medium">
                <Languages className="h-3 w-3 shrink-0" />
                {t(`chats.language.${translatedLanguage}`)}
              </span>
              {originalDraft !== null && (
                <button
                  type="button"
                  onClick={restoreOriginalDraft}
                  disabled={disabled || isTranslating}
                  className="shrink-0 underline underline-offset-2 transition-colors hover:text-foreground disabled:opacity-50"
                >
                  {t("chats.restoreOriginal")}
                </button>
              )}
            </>
          )}
        </div>
      )}
      <div className="px-3 py-2.5 sm:p-4">
        <div className="flex items-end gap-1 sm:gap-2">
          {!isEditing && !isVoiceComposerActive && (
            <>
              <input
                ref={fileInputRef}
                type="file"
                accept="image/*,audio/*"
                className="hidden"
                onChange={handleFileSelect}
              />
              {/* En móvil las acciones secundarias suben desde el borde inferior,
                  al alcance del pulgar. Con cinco botones inline al textarea le
                  quedaban 150px de 366. */}
              <Sheet open={isActionsOpen} onOpenChange={setIsActionsOpen}>
                <SheetTrigger asChild>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-10 w-10 shrink-0 p-0 sm:hidden"
                    disabled={disabled}
                    aria-label={t("chats.moreActions")}
                  >
                    <Plus className="w-4 h-4" />
                  </Button>
                </SheetTrigger>
                <SheetContent side="bottom" className="gap-0 rounded-t-2xl pb-[env(safe-area-inset-bottom)]">
                  <SheetHeader className="pb-2">
                    <SheetTitle className="text-base">{t("chats.moreActions")}</SheetTitle>
                  </SheetHeader>
                  <div className="grid grid-cols-4 gap-2 px-4 pb-6">
                    {composerActions.map((action) => (
                      <button
                        key={action.key}
                        type="button"
                        disabled={action.disabled}
                        onClick={action.onClick}
                        className="flex flex-col items-center gap-1.5 rounded-lg py-2 transition-colors hover:bg-accent disabled:pointer-events-none disabled:opacity-40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                      >
                        <span className={`flex h-12 w-12 items-center justify-center rounded-full ${action.tone}`}>
                          <action.Icon className="h-5 w-5" />
                        </span>
                        <span className="text-[11px] leading-tight text-muted-foreground">
                          {action.label}
                        </span>
                      </button>
                    ))}
                  </div>
                </SheetContent>
              </Sheet>

              {/* Hoja de emojis: en móvil sube desde abajo, en escritorio es popover. */}
              <Sheet open={isEmojiSheetOpen} onOpenChange={setIsEmojiSheetOpen}>
                <SheetContent side="bottom" className="gap-0 rounded-t-2xl pb-[env(safe-area-inset-bottom)]">
                  <SheetHeader className="pb-2">
                    <SheetTitle className="text-base">{t("chats.emojiPickerTitle")}</SheetTitle>
                  </SheetHeader>
                  <div className="px-4 pb-6">
                    <EmojiPicker size="touch" onSelect={handleEmojiSelect} />
                  </div>
                </SheetContent>
              </Sheet>

              {/* Hoja del traductor en móvil. */}
              <Sheet open={isTranslationSheetOpen} onOpenChange={setIsTranslationSheetOpen}>
                <SheetContent side="bottom" className="gap-0 rounded-t-2xl pb-[env(safe-area-inset-bottom)]">
                  <SheetHeader className="pb-2">
                    <SheetTitle className="text-base">{t("chats.translateDraft")}</SheetTitle>
                  </SheetHeader>
                  <div className="px-4 pb-6">
                    {translationPanel}
                  </div>
                </SheetContent>
              </Sheet>

              <Button
                variant="ghost"
                size="sm"
                className="hidden shrink-0 sm:inline-flex sm:h-8 sm:w-auto sm:px-2.5"
                disabled={disabled}
                onClick={() => fileInputRef.current?.click()}
                aria-label={t("chats.attachFile")}
              >
                <Paperclip className="w-4 h-4" />
              </Button>
              {supportsTemplates && channelId && conversationId && onSendTemplate && (
                <>
                  {/* Botón propio sólo en escritorio; en móvil se abre desde la hoja. */}
                  <span className="hidden shrink-0 sm:contents">
                    <TemplatePicker
                      channelId={channelId}
                      conversationId={conversationId}
                      onSend={onSendTemplate}
                      disabled={disabled}
                      open={isTemplateDialogOpen}
                      onOpenChange={setIsTemplateDialogOpen}
                    />
                  </span>
                  <span className="contents sm:hidden">
                    <TemplatePicker
                      channelId={channelId}
                      conversationId={conversationId}
                      onSend={onSendTemplate}
                      disabled={disabled}
                      open={isTemplateDialogOpen}
                      onOpenChange={setIsTemplateDialogOpen}
                      hideTrigger
                    />
                  </span>
                </>
              )}
              <Popover open={isTranslationOpen} onOpenChange={setIsTranslationOpen}>
                <PopoverTrigger asChild>
                  <Button
                    variant="ghost"
                    size="sm"
                    disabled={disabled}
                    aria-label={t("chats.translateDraft")}
                    title={t("chats.translateDraft")}
                    className={`h-10 w-10 shrink-0 p-0 sm:inline-flex sm:h-8 sm:w-auto sm:px-2.5 ${
                      value.trim() || translatedLanguage ? "inline-flex" : "hidden"
                    } ${translatedLanguage ? "text-primary" : ""}`}
                  >
                    {isTranslating ? (
                      <Loader2 className="w-4 h-4 animate-spin motion-reduce:animate-none" />
                    ) : (
                      <Languages className="w-4 h-4" />
                    )}
                  </Button>
                </PopoverTrigger>
                <PopoverContent align="start" className="w-64 p-3">
                  {translationPanel}
                </PopoverContent>
              </Popover>
              {!isEditing && onRequestAiDraft && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className={`h-10 w-10 shrink-0 p-0 sm:h-8 sm:w-8 ${aiDraft?.content ? "bg-primary/10 text-primary hover:bg-primary/15" : ""}`}
                  disabled={disabled}
                  onClick={() => {
                    if (aiDraft?.status === "pending") onCancelAiDraft?.()
                    else if (aiDraft?.content) onUseAiDraft?.()
                    else onRequestAiDraft()
                  }}
                  aria-label={aiDraft?.status === "pending" ? t("chats.aiDraftCancel") : aiDraft?.content ? t("chats.aiDraftUse") : t("chats.aiDraftGenerate")}
                  title={aiDraft?.status === "pending" ? t("chats.aiDraftCancel") : aiDraft?.content ? t("chats.aiDraftUse") : t("chats.aiDraftGenerate")}
                  aria-busy={aiDraft?.status === "pending"}
                >
                  {aiDraft?.status === "pending" ? <Loader2 className="h-4 w-4 animate-spin motion-reduce:animate-none" /> : <Sparkles className="h-4 w-4" />}
                </Button>
              )}
            </>
          )}
          {!isVoiceComposerActive && <div className="relative flex-1">
            <Textarea
              ref={inputRef}
              rows={1}
              placeholder={
                isEditing
                  ? t("chats.editingMessage")
                  : isAudio
                    ? (t("chats.audioPlaceholder") || "Audio listo para enviar")
                    : (selectedMedia ? (t("chats.captionPlaceholder") || "Agregar caption...") : resolvedPlaceholder)
              }
              className="w-full min-h-9 max-h-32 resize-none py-2 [field-sizing:content]"
              value={value}
              onChange={handleInputChange}
              onSelect={handleInputSelect}
              onPaste={handlePaste}
              onBlur={() => requestAnimationFrame(closeHotkeyDropdown)}
              onKeyDown={handleKeyDown}
              onKeyUp={stopPropagation}
              disabled={disabled || isAudio}
            />
            {isHotkeyDropdownOpen && (
              <HotkeyAutocomplete
                hotkeys={matchingHotkeys}
                activeIndex={activeHotkeyIndex}
                onActiveIndexChange={setActiveHotkeyIndex}
                onSelect={applyHotkey}
                anchorRef={inputRef}
              />
            )}
          </div>}
          {!isEditing && !isVoiceComposerActive && (
            <Popover open={isEmojiPickerOpen} onOpenChange={setIsEmojiPickerOpen}>
              <PopoverTrigger asChild>
                <Button
                  variant="ghost"
                  size="sm"
                  className="hidden shrink-0 sm:inline-flex sm:h-8 sm:w-auto sm:px-2.5"
                  disabled={emojiPickerDisabled}
                  aria-label={t("chats.openEmojiPicker")}
                >
                  <Smile className="w-4 h-4" />
                </Button>
              </PopoverTrigger>
              <PopoverContent align="end" className="w-80 p-3">
                <EmojiPicker onSelect={handleEmojiSelect} />
              </PopoverContent>
            </Popover>
          )}
          {supportsVoice && !isEditing && !selectedMedia && (
            <VoiceRecorder
              conversationId={conversationId}
              disabled={disabled}
              canRecord={!value.trim()}
              onActiveChange={setIsVoiceComposerActive}
              onSend={(file) => onSend("", file, true)}
            />
          )}
          {!isVoiceComposerActive && <Button
            size="sm"
            className="h-10 w-10 shrink-0 p-0 sm:h-8 sm:w-auto sm:px-3"
            onClick={handleSend}
            disabled={disabled || !canSend}
            aria-label={isEditing ? t("chats.save") : t("chats.send")}
          >
            {isEditing ? <Check className="w-4 h-4" /> : <Send className="w-4 h-4" />}
          </Button>}
        </div>
      </div>
      {supportsContactSharing && onShareContacts && <ContactShareDialog open={isContactShareOpen} onOpenChange={setIsContactShareOpen} onSend={onShareContacts} disabled={disabled} />}
    </div>
  )
}
