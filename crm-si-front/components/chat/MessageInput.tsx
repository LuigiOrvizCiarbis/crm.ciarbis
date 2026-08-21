"use client"

import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useTranslation } from "@/hooks/useTranslation"
import { Paperclip, Smile, Send, X, Pencil, Check, Music2, Languages, Loader2, RotateCcw } from "lucide-react"
import { KeyboardEvent, SyntheticEvent, useMemo, useRef, useState, useEffect } from "react"
import dynamic from "next/dynamic"
import Image from "next/image"
import { Message, TranslationLanguage } from "@/data/types"
import { getMessageHotkeys, type MessageHotkey } from "@/lib/api/message-hotkeys"
import { pauseOtherAudios, canRecordAudio } from "@/lib/audio"
import { expandHotkey, parseSlashCommand, type HotkeyExpansionContext } from "@/lib/utils/hotkeys"
import { HotkeyAutocomplete } from "./HotkeyAutocomplete"
import { AudioRecorder } from "./AudioRecorder"
import type { AudioRecorderError } from "@/hooks/use-audio-recorder"
import { useToast } from "@/components/Toast"

const TemplatePicker = dynamic(
  () => import("./TemplatePicker").then(m => m.TemplatePicker),
  { ssr: false }
)

const EMOJI_GROUPS = [
  {
    key: "smileys",
    translationKey: "chats.emojiCategorySmileys",
    emojis: ["😀", "😂", "😊", "😍", "😎", "🤔", "🥲", "😴"],
  },
  {
    key: "gestures",
    translationKey: "chats.emojiCategoryGestures",
    emojis: ["👍", "👎", "👏", "🙌", "🙏", "🤝", "💪", "👌"],
  },
  {
    key: "hearts",
    translationKey: "chats.emojiCategoryHearts",
    emojis: ["❤️", "🧡", "💛", "💚", "💙", "💜", "🤍", "🖤"],
  },
  {
    key: "celebration",
    translationKey: "chats.emojiCategoryCelebration",
    emojis: ["🎉", "✨", "🔥", "🚀", "🏆", "🎯", "🥳", "💥"],
  },
  {
    key: "objects",
    translationKey: "chats.emojiCategoryObjects",
    emojis: ["📌", "📅", "💬", "📞", "✅", "⚠️", "💡", "📎"],
  },
]

interface MessageInputProps {
  value: string
  onChange: (value: string) => void
  onSend: (content: string, media?: File) => void
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
}: MessageInputProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const resolvedPlaceholder = placeholder ?? t("chats.messagePlaceholder")
  const fileInputRef = useRef<HTMLInputElement>(null)
  const inputRef = useRef<HTMLTextAreaElement>(null)
  const [selectedMedia, setSelectedMedia] = useState<File | null>(null)
  const [mediaPreview, setMediaPreview] = useState<string | null>(null)
  const [isRecordingAudio, setIsRecordingAudio] = useState(false)
  const [isEmojiPickerOpen, setIsEmojiPickerOpen] = useState(false)
  const [hotkeys, setHotkeys] = useState<MessageHotkey[]>([])
  const [slashState, setSlashState] = useState<SlashState | null>(null)
  const [activeHotkeyIndex, setActiveHotkeyIndex] = useState(0)
  const [isTranslating, setIsTranslating] = useState(false)
  const [translationError, setTranslationError] = useState<string | null>(null)
  const [originalDraft, setOriginalDraft] = useState<string | null>(null)
  const [translatedLanguage, setTranslatedLanguage] = useState<TranslationLanguage | null>(null)

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

  // El audio grabado va a la misma preview que un adjunto: nunca se envía
  // solo al soltar, siempre hay que confirmar (botón enviar) antes.
  const handleAudioRecorded = (file: File) => {
    acceptMediaFile(file)
  }

  const handleAudioRecorderError = (error: AudioRecorderError) => {
    if (error === "not-allowed") {
      addToast({
        type: "error",
        title: t("chats.micPermissionDenied"),
      })
      return
    }

    addToast({
      type: "error",
      title: t("chats.audioFormatUnsupported"),
    })
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
  const emojiPickerDisabled = disabled || isAudio || isRecordingAudio
  // El micrófono reemplaza al botón de enviar cuando no hay nada que mandar
  // todavía. En cuanto hay texto o media seleccionada, vuelve el avión de
  // papel. Sin MediaRecorder o sin contexto seguro (http), se oculta el
  // botón y sólo queda el clip de adjuntar. Mientras graba, se mantiene
  // aunque cambien value/selectedMedia por debajo (no debería, están
  // deshabilitados, pero evita desmontar el grabador a mitad de gesto).
  const showMicButton = !isEditing && (isRecordingAudio || (!value.trim() && !selectedMedia)) && canRecordAudio()

  const handleEmojiSelect = (emoji: string) => {
    const input = inputRef.current
    const selectionStart = input?.selectionStart ?? value.length
    const selectionEnd = input?.selectionEnd ?? value.length
    const nextValue = `${value.slice(0, selectionStart)}${emoji}${value.slice(selectionEnd)}`
    const nextCursorPosition = selectionStart + emoji.length

    onChange(nextValue)
    setIsEmojiPickerOpen(false)

    requestAnimationFrame(() => {
      inputRef.current?.focus()
      inputRef.current?.setSelectionRange(nextCursorPosition, nextCursorPosition)
    })
  }

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
      {!isEditing && (
        <div className="flex min-h-9 flex-wrap items-center justify-between gap-x-3 gap-y-1 px-4 py-1.5 text-xs text-muted-foreground">
          <div className="flex min-w-0 items-center gap-2">
            <Languages className="h-3.5 w-3.5 shrink-0" />
            <span className="hidden sm:inline">{t("chats.contactLanguage")}</span>
            <Select
              value={contactLanguage}
              onValueChange={(next) => void onContactLanguageChange(next as TranslationLanguage)}
              disabled={disabled || isTranslating}
            >
              <SelectTrigger
                className="h-7 w-[128px] border-0 bg-transparent px-2 text-xs font-medium shadow-none focus:ring-2"
                aria-label={t("chats.contactLanguage")}
              >
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

          <div className="flex min-w-0 items-center gap-2" aria-live="polite">
            {translatedLanguage && originalDraft !== null && (
              <span className="hidden truncate text-[11px] sm:inline">
                {t("chats.draftTranslatedTo", { language: t(`chats.language.${translatedLanguage}`) })}
              </span>
            )}
            {originalDraft !== null ? (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-7 gap-1.5 px-2 text-xs"
                onClick={restoreOriginalDraft}
                disabled={disabled || isTranslating}
              >
                <RotateCcw className="h-3.5 w-3.5" />
                {t("chats.restoreOriginal")}
              </Button>
            ) : (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-7 gap-1.5 px-2 text-xs"
                onClick={() => void handleTranslateDraft()}
                disabled={disabled || isTranslating || isAudio || !value.trim()}
              >
                {isTranslating ? (
                  <Loader2 className="h-3.5 w-3.5 animate-spin motion-reduce:animate-none" />
                ) : (
                  <Languages className="h-3.5 w-3.5" />
                )}
                <span className="hidden sm:inline">
                  {isTranslating ? t("chats.translating") : t("chats.translateDraft")}
                </span>
                <span className="sm:hidden">{t("chats.translateShort")}</span>
              </Button>
            )}
          </div>
          {translationError && (
            <div className="w-full text-right text-[11px] text-destructive" role="status">
              {translationError}
            </div>
          )}
        </div>
      )}
      <div className="p-4">
        <div className="flex items-end gap-2">
          {!isEditing && (
            <>
              <input
                ref={fileInputRef}
                type="file"
                accept="image/*,audio/*"
                className="hidden"
                onChange={handleFileSelect}
              />
              <Button
                variant="ghost"
                size="sm"
                disabled={disabled || isRecordingAudio}
                onClick={() => fileInputRef.current?.click()}
              >
                <Paperclip className="w-4 h-4" />
              </Button>
              {supportsTemplates && channelId && conversationId && onSendTemplate && (
                <TemplatePicker
                  channelId={channelId}
                  conversationId={conversationId}
                  onSend={onSendTemplate}
                  disabled={disabled || isRecordingAudio}
                />
              )}
            </>
          )}
          <div className="relative flex-1">
            <Textarea
              ref={inputRef}
              rows={1}
              placeholder={
                isEditing
                  ? t("chats.editingMessage")
                  : isRecordingAudio
                    ? (t("chats.recording") || "Grabando...")
                    : isAudio
                      ? (t("chats.audioPlaceholder") || "Audio listo para enviar")
                      : (selectedMedia ? (t("chats.captionPlaceholder") || "Agregar caption...") : resolvedPlaceholder)
              }
              className="w-full min-h-9 max-h-32 resize-none py-2"
              value={value}
              onChange={handleInputChange}
              onSelect={handleInputSelect}
              onPaste={handlePaste}
              onBlur={() => requestAnimationFrame(closeHotkeyDropdown)}
              onKeyDown={handleKeyDown}
              onKeyUp={stopPropagation}
              disabled={disabled || isAudio || isRecordingAudio}
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
          </div>
          {!isEditing && (
            <Popover open={isEmojiPickerOpen} onOpenChange={setIsEmojiPickerOpen}>
              <PopoverTrigger asChild>
                <Button
                  variant="ghost"
                  size="sm"
                  disabled={emojiPickerDisabled}
                  aria-label={t("chats.openEmojiPicker")}
                >
                  <Smile className="w-4 h-4" />
                </Button>
              </PopoverTrigger>
              <PopoverContent align="end" className="w-80 p-3">
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <p className="text-sm font-medium">{t("chats.emojiPickerTitle")}</p>
                    <Smile className="h-4 w-4 text-muted-foreground" />
                  </div>
                  {EMOJI_GROUPS.map((group) => (
                    <div key={group.key} className="space-y-2">
                      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        {t(group.translationKey)}
                      </p>
                      <div className="grid grid-cols-8 gap-1">
                        {group.emojis.map((emoji) => (
                          <button
                            key={`${group.key}-${emoji}`}
                            type="button"
                            className="flex h-9 w-9 items-center justify-center rounded-md text-lg transition-colors hover:bg-accent focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] outline-none"
                            onClick={() => handleEmojiSelect(emoji)}
                            aria-label={`${t(group.translationKey)} ${emoji}`}
                          >
                            {emoji}
                          </button>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              </PopoverContent>
            </Popover>
          )}
          {showMicButton ? (
            <AudioRecorder
              disabled={disabled}
              onRecorded={handleAudioRecorded}
              onError={handleAudioRecorderError}
              onRecordingChange={setIsRecordingAudio}
            />
          ) : (
            <Button size="sm" onClick={handleSend} disabled={disabled || !canSend}>
              {isEditing ? <Check className="w-4 h-4" /> : <Send className="w-4 h-4" />}
            </Button>
          )}
        </div>
      </div>
    </div>
  )
}
