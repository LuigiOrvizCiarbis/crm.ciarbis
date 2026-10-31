"use client"

import { Message, MailAddress } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"
import {
  AlertCircle,
  ChevronDown,
  ChevronUp,
  Download,
  FileText,
  ImageOff,
  Mail,
  Paperclip,
} from "lucide-react"
import { useMemo, useState } from "react"

interface MailMessageBlockProps {
  message: Message
}

function addressLabel(address?: MailAddress | null): string {
  if (!address) return ""
  return address.name?.trim() || address.email
}

function addressList(addresses?: MailAddress[] | null): string {
  return (addresses ?? []).map(addressLabel).filter(Boolean).join(", ")
}

function legacyParts(content: string): { subject: string; body: string } {
  const [first = "", ...rest] = content.split("\n")
  return { subject: first.trim(), body: rest.join("\n").trim() || first.trim() }
}

function prepareHtml(html: string, loadRemoteImages: boolean, showQuoted: boolean): string {
  if (typeof DOMParser === "undefined") return html
  const document = new DOMParser().parseFromString(html, "text/html")

  document.querySelectorAll<HTMLElement>("[data-mail-quoted='true']").forEach((node) => {
    if (!showQuoted) node.remove()
  })

  document.querySelectorAll<HTMLImageElement>("img[data-remote-src]").forEach((image) => {
    const source = image.dataset.remoteSrc
    if (loadRemoteImages && source && /^https?:\/\//i.test(source)) {
      image.src = source
      image.referrerPolicy = "no-referrer"
    }
  })

  return document.body.innerHTML
}

export function MailMessageBlock({ message }: MailMessageBlockProps) {
  const { t, language } = useTranslation()
  const details = message.mail_details
  const legacy = legacyParts(message.content || "")
  const [detailsOpen, setDetailsOpen] = useState(false)
  const [quotedOpen, setQuotedOpen] = useState(false)
  const [remoteImagesLoaded, setRemoteImagesLoaded] = useState(false)
  const isOutbound = message.direction === "outbound"
  const subject = details?.subject?.trim() || legacy.subject || t("chats.mailNoSubject")
  const bodyText = details?.body_text?.trim() || legacy.body
  const bodyHtml = details?.body_html?.trim()
  const hasQuoted = Boolean(bodyHtml?.includes("data-mail-quoted"))
  const renderedHtml = useMemo(
    () => bodyHtml ? prepareHtml(bodyHtml, remoteImagesLoaded, quotedOpen) : "",
    [bodyHtml, quotedOpen, remoteImagesLoaded],
  )
  const legacyPrimaryAttachment = message.media_url || message.media_full_url
    ? [message]
    : []
  const attachments = [...legacyPrimaryAttachment, ...(message.mail_attachments ?? [])]
  const timestamp = message.delivered_at || message.created_at
  const sender = addressLabel(details?.from) || (isOutbound ? t("chats.mailTeamSender") : t("chats.mailUnknownSender"))
  const senderEmail = details?.from?.email
  const recipients = addressList(details?.to)

  return (
    <article
      className={`group/mail w-full max-w-[min(85%,72rem)] overflow-hidden rounded-lg border shadow-sm dark:shadow-none ${
        isOutbound
          ? "ml-auto border-primary/20 bg-primary/[0.045]"
          : "mr-auto border-border bg-card"
      }`}
      aria-label={`${t("chats.mailEmailLabel")}: ${subject}`}
    >
      <header className="flex items-start gap-3 px-4 pb-2 pt-3 sm:px-5 sm:pt-4">
        <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-border bg-background text-muted-foreground">
          <Mail className="h-4 w-4" aria-hidden="true" />
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <p className="min-w-0 text-sm font-medium text-foreground">
              <span>{sender}</span>
              {senderEmail && senderEmail !== sender && (
                <span className="ml-1 font-normal text-muted-foreground">&lt;{senderEmail}&gt;</span>
              )}
            </p>
            <time className="shrink-0 text-xs tabular-nums text-muted-foreground" dateTime={timestamp}>
              {new Date(timestamp).toLocaleString(language, {
                day: "2-digit",
                month: "short",
                hour: "2-digit",
                minute: "2-digit",
              })}
            </time>
          </div>
          <div className="mt-0.5 flex min-w-0 flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
            {recipients && <span className="truncate">{t("chats.mailTo")}: {recipients}</span>}
            <button
              type="button"
              className="inline-flex min-h-7 items-center gap-1 rounded-sm font-medium text-primary outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
              onClick={() => setDetailsOpen((open) => !open)}
              aria-expanded={detailsOpen}
            >
              {detailsOpen ? t("chats.mailHideDetails") : t("chats.mailViewDetails")}
              {detailsOpen ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />}
            </button>
          </div>
          {detailsOpen && (
            <dl className="mt-2 grid gap-x-3 gap-y-1 rounded-md bg-muted/60 px-3 py-2 text-xs sm:grid-cols-[auto_1fr]">
              <dt className="font-medium text-muted-foreground">{t("chats.mailFrom")}</dt>
              <dd className="break-all">{senderEmail || sender}</dd>
              <dt className="font-medium text-muted-foreground">{t("chats.mailTo")}</dt>
              <dd className="break-all">{addressList(details?.to) || t("chats.mailNotAvailable")}</dd>
              {!!details?.cc?.length && <><dt className="font-medium text-muted-foreground">CC</dt><dd className="break-all">{addressList(details.cc)}</dd></>}
              {!!details?.reply_to && <><dt className="font-medium text-muted-foreground">Reply-To</dt><dd className="break-all">{addressLabel(details.reply_to)}</dd></>}
            </dl>
          )}
        </div>
      </header>

      <div className="px-4 pb-4 sm:px-5 sm:pb-5">
        <h3 className="mb-3 text-base font-semibold leading-6 text-foreground sm:text-lg">{subject}</h3>

        {details?.has_remote_images && !remoteImagesLoaded && (
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-md bg-muted/70 px-3 py-2 text-xs text-muted-foreground">
            <span className="inline-flex items-center gap-2">
              <ImageOff className="h-4 w-4" />
              {t("chats.mailRemoteImagesBlocked")}
            </span>
            <button
              type="button"
              className="min-h-7 rounded-sm font-medium text-primary outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
              onClick={() => setRemoteImagesLoaded(true)}
            >
              {t("chats.mailLoadImages")}
            </button>
          </div>
        )}

        {bodyHtml ? (
          <div
            className="max-w-[70ch] break-words text-sm leading-6 text-foreground [&_a]:font-medium [&_a]:text-primary [&_a]:underline [&_blockquote]:border-l [&_blockquote]:border-border [&_blockquote]:pl-3 [&_code]:rounded-sm [&_code]:bg-muted [&_code]:px-1 [&_h1]:mb-3 [&_h1]:text-xl [&_h2]:mb-2 [&_h2]:text-lg [&_img]:my-3 [&_img]:max-w-full [&_li]:my-1 [&_ol]:my-3 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-3 [&_pre]:my-3 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-muted [&_pre]:p-3 [&_table]:my-3 [&_table]:w-full [&_table]:border-collapse [&_td]:border [&_td]:border-border [&_td]:p-2 [&_th]:border [&_th]:border-border [&_th]:bg-muted [&_th]:p-2 [&_ul]:my-3 [&_ul]:list-disc [&_ul]:pl-5"
            dangerouslySetInnerHTML={{ __html: renderedHtml }}
          />
        ) : (
          <p className="max-w-[70ch] whitespace-pre-wrap break-words text-sm leading-6 text-foreground">
            {bodyText}
          </p>
        )}

        {hasQuoted && (
          <button
            type="button"
            className="mt-2 inline-flex min-h-8 items-center gap-1 rounded-sm text-xs font-medium text-primary outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
            onClick={() => setQuotedOpen((open) => !open)}
            aria-expanded={quotedOpen}
          >
            {quotedOpen ? t("chats.mailHidePrevious") : t("chats.mailShowPrevious")}
            {quotedOpen ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />}
          </button>
        )}

        {attachments.length > 0 && (
          <div className="mt-4 border-t border-border/70 pt-3">
            <p className="mb-2 flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
              <Paperclip className="h-3.5 w-3.5" />
              {t("chats.mailAttachments", { count: attachments.length })}
            </p>
            <div className="flex flex-wrap gap-2">
              {attachments.map((attachment) => (
                attachment.media_full_url || attachment.media_url ? (
                  <a
                    key={attachment.id}
                    href={attachment.media_full_url || attachment.media_url || undefined}
                    download={attachment.media_filename || undefined}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex min-h-11 min-w-0 max-w-full items-center gap-2 rounded-md border border-border bg-background px-3 py-2 text-sm outline-none transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span className="max-w-56 truncate">{attachment.media_filename || t("chats.mailAttachment")}</span>
                    <Download className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  </a>
                ) : (
                  <span key={attachment.id} className="flex min-h-11 min-w-0 max-w-full items-center gap-2 rounded-md border border-border bg-background px-3 py-2 text-sm text-muted-foreground">
                    <FileText className="h-4 w-4 shrink-0" />
                    <span className="max-w-56 truncate">{attachment.media_filename || t("chats.mailAttachment")}</span>
                  </span>
                )
              ))}
            </div>
          </div>
        )}

        {message.failed_at && (
          <p className="mt-3 flex items-center gap-1.5 text-xs text-destructive" role="status">
            <AlertCircle className="h-3.5 w-3.5" />
            {t("chats.messageFailed")}
          </p>
        )}
      </div>
    </article>
  )
}
