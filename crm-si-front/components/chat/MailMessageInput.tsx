"use client"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { useTranslation } from "@/hooks/useTranslation"
import type { SendMailMessageInput } from "@/lib/api/messages"
import {
  Bold,
  Italic,
  Link,
  List,
  ListOrdered,
  Loader2,
  Paperclip,
  Send,
  Underline,
  X,
} from "lucide-react"
import { useEffect, useRef, useState } from "react"

interface MailMessageInputProps {
  value: string
  onChange: (value: string) => void
  onSend: (input: SendMailMessageInput) => void | Promise<void>
  disabled?: boolean
  subject?: string | null
}

function parseAddresses(value: string): string[] {
  return value
    .split(/[;,]/)
    .map((address) => address.trim().toLowerCase())
    .filter(Boolean)
}

export function MailMessageInput({
  value,
  onChange,
  onSend,
  disabled = false,
  subject,
}: MailMessageInputProps) {
  const { t } = useTranslation()
  const editorRef = useRef<HTMLDivElement>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [showRecipients, setShowRecipients] = useState(false)
  const [cc, setCc] = useState("")
  const [bcc, setBcc] = useState("")
  const [attachments, setAttachments] = useState<File[]>([])
  const [isSending, setIsSending] = useState(false)

  useEffect(() => {
    const editor = editorRef.current
    if (!editor || document.activeElement === editor || editor.innerText === value) return
    editor.innerText = value
  }, [value])

  const runCommand = (command: string, argument?: string) => {
    editorRef.current?.focus()
    document.execCommand(command, false, argument)
    const editor = editorRef.current
    if (editor) onChange(editor.innerText)
  }

  const addLink = () => {
    const url = window.prompt(t("chats.mailLinkPrompt"), "https://")
    if (url && /^https?:\/\//i.test(url)) runCommand("createLink", url)
  }

  const addFiles = (files: FileList | null) => {
    if (!files) return
    const accepted = Array.from(files).filter((file) => file.size <= 10 * 1024 * 1024)
    setAttachments((current) => [...current, ...accepted].slice(0, 10))
    if (fileInputRef.current) fileInputRef.current.value = ""
  }

  const handleSend = async () => {
    const editor = editorRef.current
    const content = editor?.innerText.trim() || value.trim()
    if ((!content && attachments.length === 0) || disabled || isSending) return

    setIsSending(true)
    try {
      await onSend({
        content,
        contentHtml: editor?.innerHTML || undefined,
        cc: parseAddresses(cc),
        bcc: parseAddresses(bcc),
        attachments,
      })
      if (editor) editor.innerHTML = ""
      onChange("")
      setCc("")
      setBcc("")
      setAttachments([])
      setShowRecipients(false)
    } finally {
      setIsSending(false)
    }
  }

  const tools = [
    { icon: Bold, label: t("chats.mailFormatBold"), command: "bold" },
    { icon: Italic, label: t("chats.mailFormatItalic"), command: "italic" },
    { icon: Underline, label: t("chats.mailFormatUnderline"), command: "underline" },
    { icon: List, label: t("chats.mailFormatList"), command: "insertUnorderedList" },
    { icon: ListOrdered, label: t("chats.mailFormatNumberedList"), command: "insertOrderedList" },
  ]

  return (
    <section className="sticky bottom-0 border-t border-border bg-card px-3 py-3 md:relative md:px-4" aria-label={t("chats.mailReplyComposer")}>
      <div className="overflow-hidden rounded-lg border border-border bg-background shadow-sm focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/30 dark:shadow-none">
        <div className="flex min-h-10 flex-wrap items-center justify-between gap-2 border-b border-border px-3 py-1.5 text-xs">
          <div className="min-w-0">
            <span className="font-medium text-foreground">{t("chats.mailReply")}</span>
            {subject && <span className="ml-2 hidden truncate text-muted-foreground sm:inline">{subject}</span>}
          </div>
          <button
            type="button"
            className="min-h-7 rounded-sm px-1 font-medium text-primary outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
            onClick={() => setShowRecipients((show) => !show)}
            aria-expanded={showRecipients}
          >
            CC/BCC
          </button>
        </div>

        {showRecipients && (
          <div className="grid gap-2 border-b border-border bg-muted/35 p-3 sm:grid-cols-2">
            <label className="grid gap-1 text-xs font-medium text-muted-foreground">
              CC
              <Input
                value={cc}
                onChange={(event) => setCc(event.target.value)}
                placeholder={t("chats.mailCcPlaceholder")}
                type="text"
                disabled={disabled || isSending}
              />
            </label>
            <label className="grid gap-1 text-xs font-medium text-muted-foreground">
              BCC
              <Input
                value={bcc}
                onChange={(event) => setBcc(event.target.value)}
                placeholder={t("chats.mailBccPlaceholder")}
                type="text"
                disabled={disabled || isSending}
              />
            </label>
          </div>
        )}

        <div
          ref={editorRef}
          role="textbox"
          aria-multiline="true"
          aria-label={t("chats.mailReplyBody")}
          contentEditable={!disabled && !isSending}
          suppressContentEditableWarning
          data-placeholder={t("chats.mailReplyPlaceholder")}
          onInput={(event) => onChange(event.currentTarget.innerText)}
          onKeyDown={(event) => {
            if ((event.metaKey || event.ctrlKey) && event.key === "Enter") {
              event.preventDefault()
              void handleSend()
            }
          }}
          className="min-h-28 max-h-64 overflow-y-auto px-4 py-3 text-sm leading-6 text-foreground outline-none empty:before:pointer-events-none empty:before:text-muted-foreground empty:before:content-[attr(data-placeholder)] [&_a]:text-primary [&_a]:underline [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5"
        />

        {attachments.length > 0 && (
          <div className="flex flex-wrap gap-2 border-t border-border px-3 py-2">
            {attachments.map((file, index) => (
              <span key={`${file.name}-${index}`} className="inline-flex max-w-full items-center gap-1.5 rounded-md bg-muted px-2 py-1 text-xs">
                <Paperclip className="h-3 w-3 shrink-0" />
                <span className="max-w-48 truncate">{file.name}</span>
                <button
                  type="button"
                  className="rounded-sm outline-none hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring"
                  onClick={() => setAttachments((files) => files.filter((_, fileIndex) => fileIndex !== index))}
                  aria-label={t("chats.mailRemoveAttachment", { name: file.name })}
                >
                  <X className="h-3 w-3" />
                </button>
              </span>
            ))}
          </div>
        )}

        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border px-2 py-2">
          <div className="flex items-center gap-0.5">
            {tools.map(({ icon: Icon, label, command }) => (
              <Button
                key={command}
                type="button"
                variant="ghost"
                size="icon"
                className="h-9 w-9"
                aria-label={label}
                title={label}
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => runCommand(command)}
                disabled={disabled || isSending}
              >
                <Icon className="h-4 w-4" />
              </Button>
            ))}
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-9 w-9"
              aria-label={t("chats.mailFormatLink")}
              title={t("chats.mailFormatLink")}
              onMouseDown={(event) => event.preventDefault()}
              onClick={addLink}
              disabled={disabled || isSending}
            >
              <Link className="h-4 w-4" />
            </Button>
            <input
              ref={fileInputRef}
              type="file"
              multiple
              className="hidden"
              onChange={(event) => addFiles(event.target.files)}
            />
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-9 w-9"
              aria-label={t("chats.mailAddAttachment")}
              title={t("chats.mailAddAttachment")}
              onClick={() => fileInputRef.current?.click()}
              disabled={disabled || isSending || attachments.length >= 10}
            >
              <Paperclip className="h-4 w-4" />
            </Button>
          </div>

          <Button
            type="button"
            size="sm"
            className="min-w-24 gap-2"
            onClick={() => void handleSend()}
            disabled={disabled || isSending || (!value.trim() && attachments.length === 0)}
          >
            {isSending ? <Loader2 className="h-4 w-4 animate-spin motion-reduce:animate-none" /> : <Send className="h-4 w-4" />}
            {isSending ? t("chats.mailSending") : t("chats.mailSend")}
          </Button>
        </div>
      </div>
      <p className="mt-1.5 text-right text-[11px] text-muted-foreground">
        {t("chats.mailSendShortcut")}
      </p>
    </section>
  )
}
