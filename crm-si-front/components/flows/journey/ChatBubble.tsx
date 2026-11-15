"use client"

import { ExternalLink, FileText, Image as ImageIcon, ListChecks, Phone, Reply, Video } from "lucide-react"

import type { WhatsAppTemplate } from "@/data/types"
import { cn } from "@/lib/utils"

export type BubbleHeader =
  | { kind: "text"; text: string }
  | { kind: "image"; url: string | null }
  | { kind: "video" }
  | { kind: "document" }

export interface BubbleButton {
  text: string
  kind: "flow" | "link" | "reply" | "phone"
}

export interface BubbleContent {
  header: BubbleHeader | null
  body: string
  footer: string | null
  buttons: BubbleButton[]
}

const BUTTON_ICON = { flow: ListChecks, link: ExternalLink, reply: Reply, phone: Phone }

/** Encabezado, pie y botones de una plantilla; `body` ya viene con las variables resueltas. */
export function templateBubble(template: WhatsAppTemplate, body: string): BubbleContent {
  const part = (type: string) => template.components.find((component) => component.type.toUpperCase() === type)
  const header = part("HEADER")
  const format = header?.format?.toUpperCase()
  const example = header?.example?.header_handle?.[0]
  return {
    header:
      format === "TEXT" && header?.text
        ? { kind: "text", text: header.text }
        : format === "IMAGE"
          ? { kind: "image", url: example?.startsWith("http") ? example : null }
          : format === "VIDEO"
            ? { kind: "video" }
            : format === "DOCUMENT"
              ? { kind: "document" }
              : null,
    body,
    footer: part("FOOTER")?.text ?? null,
    buttons: (part("BUTTONS")?.buttons ?? []).map((button) => {
      const type = button.type.toUpperCase()
      return {
        text: button.text,
        kind: type === "FLOW" ? "flow" : type === "URL" ? "link" : type === "PHONE_NUMBER" ? "phone" : "reply",
      }
    }),
  }
}

/** Burbuja de un mensaje de la empresa en WhatsApp, con colores fijos de la app. */
export function ChatBubble({
  content,
  onButtonClick,
  className,
}: {
  content: BubbleContent
  /** Si está, los botones se pueden tocar (por ejemplo, para abrir el formulario en la vista previa). */
  onButtonClick?: (button: BubbleButton) => void
  className?: string
}) {
  const { header } = content
  return (
    <div className={cn("max-w-[88%] overflow-hidden rounded-lg rounded-tl-none bg-white text-[13px] text-neutral-900 shadow-sm", className)}>
      {header?.kind === "image" ? (
        header.url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={header.url} alt="" className="aspect-[1.91/1] w-full object-cover" />
        ) : (
          <div className="flex aspect-[1.91/1] w-full items-center justify-center bg-neutral-200 text-neutral-500">
            <ImageIcon className="size-6" aria-hidden />
          </div>
        )
      ) : null}
      {header?.kind === "video" ? (
        <div className="flex aspect-video w-full items-center justify-center bg-neutral-800 text-white/80">
          <Video className="size-6" aria-hidden />
        </div>
      ) : null}
      {header?.kind === "document" ? (
        <div className="flex items-center gap-2 bg-neutral-100 px-3 py-2 text-xs text-neutral-600">
          <FileText className="size-4 shrink-0" aria-hidden /> PDF
        </div>
      ) : null}
      <div className="space-y-1 px-2.5 pb-1.5 pt-2">
        {header?.kind === "text" ? <p className="font-semibold">{header.text}</p> : null}
        <p className="whitespace-pre-wrap break-words">{content.body || "—"}</p>
        {content.footer ? <p className="text-xs text-neutral-500">{content.footer}</p> : null}
        <p className="text-right text-[10px] text-neutral-400">10:30</p>
      </div>
      {content.buttons.map((button, index) => {
        const Icon = BUTTON_ICON[button.kind]
        const inner = (
          <>
            <Icon className="size-4 shrink-0" aria-hidden />
            <span className="truncate">{button.text}</span>
          </>
        )
        const classes = "flex w-full items-center justify-center gap-1.5 border-t border-neutral-200 py-2 text-[13px] font-medium text-[#027eb5]"
        return onButtonClick ? (
          <button key={index} type="button" onClick={() => onButtonClick(button)} className={cn(classes, "hover:bg-neutral-50")}>
            {inner}
          </button>
        ) : (
          <div key={index} className={classes}>
            {inner}
          </div>
        )
      })}
    </div>
  )
}
