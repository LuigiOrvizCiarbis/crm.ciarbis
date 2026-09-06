"use client"

import { Globe } from "lucide-react"
import type { LinkPreview } from "@/data/types"
import { AspectRatio } from "@/components/ui/aspect-ratio"
import { Skeleton } from "@/components/ui/skeleton"

/**
 * Tarjeta de preview Open Graph bajo el texto del mensaje. "failed" no
 * renderiza nada: el link ya quedó clickeable por el autolink de MessageText,
 * así que una preview rota no aporta y sólo ensucia la burbuja.
 */
export function LinkPreviewCard({ preview, isUser }: { preview: LinkPreview; isUser: boolean }) {
  if (preview.status === "failed") return null

  if (preview.status === "pending") {
    return (
      <div
        className={`mt-2 overflow-hidden rounded-lg border ${isUser ? "border-primary-foreground/20" : "border-current/15"}`}
      >
        <Skeleton className="h-24 w-full rounded-none" />
      </div>
    )
  }

  return (
    <a
      href={preview.url}
      target="_blank"
      rel="noopener noreferrer"
      onClick={(e) => e.stopPropagation()}
      className={`mt-2 block overflow-hidden rounded-lg border transition-opacity hover:opacity-90 ${
        isUser ? "border-primary-foreground/20" : "border-current/15"
      }`}
    >
      {preview.image_url && (
        <AspectRatio ratio={1.91}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={preview.image_url} alt="" className="h-full w-full object-cover" loading="lazy" />
        </AspectRatio>
      )}
      <div className="space-y-0.5 bg-background/40 p-2.5">
        {preview.site_name && (
          <div className="flex items-center gap-1 text-[11px] opacity-70">
            <Globe className="h-3 w-3 shrink-0" aria-hidden />
            <span className="truncate">{preview.site_name}</span>
          </div>
        )}
        {preview.title && <p className="line-clamp-2 text-sm font-medium">{preview.title}</p>}
        {preview.description && <p className="line-clamp-2 text-xs opacity-70">{preview.description}</p>}
      </div>
    </a>
  )
}
