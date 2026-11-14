"use client"

import type { Message, MessageReplyPreview } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"
import { parseTemplateContent } from "./messageThreadUtils"

type TFunction = (key: string) => string

/** Recorta un mensaje a los campos que necesita la cita (mismo shape que `reply_to` del back). */
export function toReplyPreview(message: Message): MessageReplyPreview {
  return {
    id: message.id,
    conversation_id: message.conversation_id,
    content: message.content,
    message_type: message.message_type,
    sender_type: message.sender_type,
    sender_id: message.sender_id,
    sender: message.sender,
    direction: message.direction,
    media_url: message.media_url,
    media_full_url: message.media_full_url,
    media_mime_type: message.media_mime_type,
    media_filename: message.media_filename,
    deleted_at: message.deleted_at,
  }
}

function quoteAuthor(quoted: MessageReplyPreview, contactName: string | null | undefined, t: TFunction): string {
  if (quoted.direction === "outbound") {
    return quoted.sender_type === "system" ? t("chats.aiBadge") : t("chats.replyAuthorYou")
  }
  return quoted.sender?.name || contactName || t("chats.replyAuthorContact")
}

function quoteSnippet(quoted: MessageReplyPreview, t: TFunction): string {
  const content = quoted.content
  if (quoted.deleted_at) return t("chats.messageDeleted")
  switch (quoted.message_type) {
    case "image":
      return `📷 ${content || t("chats.quotedImage")}`
    case "sticker":
      return `🏷️ ${t("chats.quotedSticker")}`
    case "video":
      return `🎥 ${content || t("chats.quotedVideo")}`
    case "audio":
      return `🎵 ${t("chats.quotedAudio")}`
    case "document":
      return `📄 ${quoted.media_filename || content || t("chats.quotedDocument")}`
    case "contacts":
      return `👤 ${content || t("chats.quotedContacts")}`
    case "flow":
    case "flow_response":
      return `📋 ${content || t("chats.quotedFlow")}`
  }
  const template = parseTemplateContent(content || "")
  if (template.isTemplate) return template.title || template.body
  return content || t("chats.messageWithoutText")
}

interface QuotedMessageProps {
  quoted: MessageReplyPreview
  contactName?: string | null
  onAccent?: boolean
  onClick?: () => void
  className?: string
}

/** Cita estilo WhatsApp: autor + extracto, con miniatura si es imagen/sticker. */
export function QuotedMessage({ quoted, contactName, onAccent = false, onClick, className }: QuotedMessageProps) {
  const { t } = useTranslation()
  const thumbnail =
    (quoted.message_type === "image" || quoted.message_type === "sticker") && !quoted.deleted_at
      ? quoted.media_full_url || quoted.media_url
      : null
  const colors = onAccent ? "border-primary-foreground/60 bg-primary-foreground/15" : "border-primary bg-background/60"
  const classes = `flex w-full min-w-[8rem] items-stretch gap-2 rounded-md border-l-4 px-2 py-1 text-left ${colors} ${className ?? ""}`

  const inner = (
    <>
      <span className="flex min-w-0 flex-1 flex-col">
        <span className="text-[11px] font-semibold">{quoteAuthor(quoted, contactName, t)}</span>
        <span className="text-xs opacity-80 line-clamp-2 whitespace-pre-wrap [overflow-wrap:anywhere]">
          {quoteSnippet(quoted, t)}
        </span>
      </span>
      {thumbnail && (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={thumbnail} alt="" className="h-10 w-10 shrink-0 rounded object-cover" />
      )}
    </>
  )

  if (onClick) {
    return (
      <button type="button" onClick={onClick} aria-label={t("chats.goToQuotedMessage")} className={classes}>
        {inner}
      </button>
    )
  }
  return <div className={classes}>{inner}</div>
}
