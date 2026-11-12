"use client"

import { useEffect, useMemo, useState } from "react"
import { Loader2, Megaphone, Search, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Drawer, DrawerContent, DrawerFooter, DrawerTitle } from "@/components/ui/drawer"
import { CheckBox } from "@/components/chat/StageFilterMenu"
import { useIsMobile } from "@/hooks/use-mobile"
import { useTranslation } from "@/hooks/useTranslation"
import { getConversationAds } from "@/lib/api/conversations"
import type { AdReferral, ConversationAd } from "@/data/types"
import { cn } from "@/lib/utils"

/** Clave para los chats que entraron por cualquier anuncio o publicación. */
export const ANY_AD_FILTER = "any"

/** Con más anuncios que esto el panel muestra un buscador. */
const SEARCH_THRESHOLD = 8

interface AdFilterMenuProps {
  /** Ids de anuncio, o sólo `ANY_AD_FILTER`. */
  selected: string[]
  onChange: (selected: string[]) => void
  /** Chats por clave de anuncio, sin contar el propio filtro de anuncios. */
  counts?: Map<string, number>
  /** Chats que quedan con el filtro aplicado (botón "Ver N chats" en mobile). */
  resultCount?: number
  /** Mobile: además de cerrar el drawer, llevar a la lista (p. ej. cerrar el panel de canales). */
  onShowResults?: () => void
}

/**
 * Filtro por anuncio de Meta. Un chat entra si llegó por alguno de los anuncios
 * elegidos en cualquier momento de su historial, no sólo por el último.
 */
export function AdFilterMenu({ selected, onChange, counts, resultCount, onShowResults }: AdFilterMenuProps) {
  const { t } = useTranslation()
  const isMobile = useIsMobile()
  const [open, setOpen] = useState(false)
  const [ads, setAds] = useState<ConversationAd[] | null>(null)
  const [loadFailed, setLoadFailed] = useState(false)

  // Se recargan en cada apertura: entran anuncios nuevos mientras la bandeja está abierta.
  // Con un filtro activo hacen falta también para los nombres de los chips.
  useEffect(() => {
    if (!open && (ads || selected.length === 0)) return
    if (loadFailed) return
    let cancelled = false
    getConversationAds()
      .then((loaded) => {
        if (!cancelled) setAds(loaded)
      })
      .catch(() => {
        if (!cancelled) setLoadFailed(true)
      })
    return () => {
      cancelled = true
    }
    // `ads` queda fuera a propósito: cargarlos no debe disparar otra carga.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, selected.length, loadFailed])

  const adById = useMemo(() => new Map((ads ?? []).map((ad) => [ad.ad_id, ad])), [ads])
  const adLabel = (ad: ConversationAd | undefined, adId: string) =>
    ad?.headline || t("chats.adIdLabel", { id: adId })

  const trigger = (
    <Button
      variant="outline"
      size="sm"
      className={cn("gap-2 bg-transparent", selected.length > 0 && "border-primary/40 bg-primary/10 text-foreground")}
      onClick={isMobile ? () => setOpen(true) : undefined}
      title={t("chats.adsFilterHint")}
    >
      <Megaphone className={cn("h-4 w-4", selected.length > 0 && "text-primary")} aria-hidden="true" />
      {t("chats.adsFilter")}
      {selected.length > 0 && !selected.includes(ANY_AD_FILTER) && (
        <span className="rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold text-primary-foreground">
          {selected.length}
        </span>
      )}
    </Button>
  )

  const panel = (
    <AdPanel
      ads={ads}
      loadFailed={loadFailed}
      onRetry={() => setLoadFailed(false)}
      selected={selected}
      counts={counts}
      onChange={onChange}
      adLabel={adLabel}
      touch={isMobile}
    />
  )

  return (
    <>
      {isMobile ? (
        <>
          {trigger}
          <Drawer open={open} onOpenChange={setOpen}>
            <DrawerContent className="max-h-[85vh]">
              <DrawerTitle className="sr-only">{t("chats.adFilter.title")}</DrawerTitle>
              <div className="min-h-0 flex-1 overflow-y-auto">{panel}</div>
              <DrawerFooter className="border-t border-border pt-3">
                <Button
                  onClick={() => {
                    setOpen(false)
                    onShowResults?.()
                  }}
                >
                  {resultCount === undefined
                    ? t("chats.stageFilter.done")
                    : t("chats.stageFilter.showResults", { count: resultCount })}
                </Button>
              </DrawerFooter>
            </DrawerContent>
          </Drawer>
        </>
      ) : (
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>{trigger}</PopoverTrigger>
          <PopoverContent align="start" className="w-[320px] p-0">{panel}</PopoverContent>
        </Popover>
      )}

      {/* Los anuncios elegidos quedan a la vista: se sacan de a uno sin abrir el panel. */}
      {selected.length > 0 && !selected.includes(ANY_AD_FILTER) && (
        <ul className="flex w-full flex-wrap gap-1.5" aria-label={t("chats.adFilter.active")}>
          {selected.map((adId) => {
            const label = adLabel(adById.get(adId), adId)
            return (
              <li key={adId}>
                <button
                  type="button"
                  onClick={() => onChange(selected.filter((item) => item !== adId))}
                  aria-label={t("chats.adFilter.remove", { ad: label })}
                  className="group flex max-w-[220px] items-center gap-1.5 rounded-full border border-border bg-background/60 py-0.5 pl-2 pr-1 text-xs outline-none transition-colors hover:border-foreground/30 focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                  <Megaphone className="h-3 w-3 shrink-0 text-primary" aria-hidden="true" />
                  <span className="truncate">{label}</span>
                  <X className="h-3 w-3 shrink-0 text-muted-foreground group-hover:text-foreground" aria-hidden="true" />
                </button>
              </li>
            )
          })}
          {selected.length > 1 && (
            <li>
              <button
                type="button"
                onClick={() => onChange([])}
                className="rounded-full px-2 py-0.5 text-xs text-muted-foreground underline-offset-2 outline-none hover:text-foreground hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50"
              >
                {t("chats.stageFilter.clear")}
              </button>
            </li>
          )}
        </ul>
      )}
    </>
  )
}

function AdPanel({
  ads,
  loadFailed,
  onRetry,
  selected,
  counts,
  onChange,
  adLabel,
  touch,
}: {
  ads: ConversationAd[] | null
  loadFailed: boolean
  onRetry: () => void
  selected: string[]
  counts?: Map<string, number>
  onChange: (selected: string[]) => void
  adLabel: (ad: ConversationAd | undefined, adId: string) => string
  touch: boolean
}) {
  const { t } = useTranslation()
  const [query, setQuery] = useState("")
  const selectedSet = new Set(selected)
  const anySelected = selectedSet.has(ANY_AD_FILTER)

  const rowClass = cn(
    "flex w-full items-center gap-2.5 rounded-md px-2 text-left text-sm transition-colors hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none",
    touch ? "py-3" : "py-1.5",
  )

  // "Cualquier anuncio" y los anuncios puntuales se excluyen: uno contiene a los otros.
  const toggleAd = (adId: string) => {
    const specific = selected.filter((item) => item !== ANY_AD_FILTER)
    onChange(selectedSet.has(adId) ? specific.filter((item) => item !== adId) : [...specific, adId])
  }

  const normalizedQuery = query.trim().toLowerCase()
  const visibleAds = (ads ?? []).filter((ad) => (
    !normalizedQuery
    || adLabel(ad, ad.ad_id).toLowerCase().includes(normalizedQuery)
    || ad.ad_id.includes(normalizedQuery)
  ))

  return (
    <div className="flex flex-col">
      <div className="flex items-center justify-between px-3 pb-2 pt-3">
        <p className="text-sm font-semibold">{t("chats.adFilter.title")}</p>
        {selected.length > 0 && (
          <button type="button" onClick={() => onChange([])} className="text-xs text-muted-foreground hover:text-foreground">
            {t("chats.stageFilter.clear")}
          </button>
        )}
      </div>

      <div className="border-t border-border p-1.5">
        <button
          type="button"
          role="checkbox"
          aria-checked={anySelected}
          onClick={() => onChange(anySelected ? [] : [ANY_AD_FILTER])}
          className={rowClass}
        >
          <CheckBox state={anySelected ? "on" : "off"} />
          <span className="flex-1 font-medium">{t("chats.adFilter.any")}</span>
          {counts && (
            <span className="text-xs tabular-nums text-muted-foreground">{counts.get(ANY_AD_FILTER) ?? 0}</span>
          )}
        </button>
      </div>

      <div className="border-t border-border p-1.5">
        {loadFailed ? (
          <div className="flex flex-col items-center gap-3 px-4 py-6 text-center text-sm text-muted-foreground">
            <p>{t("chats.adFilter.loadError")}</p>
            <Button variant="outline" size="sm" onClick={onRetry}>
              {t("chats.retry")}
            </Button>
          </div>
        ) : ads === null ? (
          <div role="status" className="flex items-center gap-2 px-3 py-6 text-sm text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            {t("chats.adFilter.loading")}
          </div>
        ) : ads.length === 0 ? (
          <p className="px-3 py-6 text-center text-sm text-muted-foreground">{t("chats.adFilter.empty")}</p>
        ) : (
          <>
            {ads.length > SEARCH_THRESHOLD && (
              <div className="relative px-1 pb-1.5">
                <Search className="pointer-events-none absolute left-3.5 top-1/2 h-3.5 w-3.5 -translate-y-[60%] text-muted-foreground" aria-hidden="true" />
                <Input
                  value={query}
                  onChange={(event) => setQuery(event.target.value)}
                  placeholder={t("chats.adFilter.search")}
                  aria-label={t("chats.adFilter.search")}
                  className="h-8 pl-8 text-sm"
                />
              </div>
            )}
            <div className="max-h-[280px] overflow-y-auto">
              {visibleAds.length === 0 && (
                <p className="px-3 py-4 text-center text-sm text-muted-foreground">{t("chats.adFilter.noMatches")}</p>
              )}
              {visibleAds.map((ad) => {
                const checked = selectedSet.has(ad.ad_id)
                const count = counts?.get(ad.ad_id) ?? 0
                return (
                  <button
                    key={ad.ad_id}
                    type="button"
                    role="checkbox"
                    aria-checked={checked}
                    onClick={() => toggleAd(ad.ad_id)}
                    className={cn(rowClass, checked && "bg-primary/10")}
                  >
                    <CheckBox state={checked ? "on" : "off"} />
                    <span className="flex min-w-0 flex-1 flex-col">
                      <span className="truncate">{adLabel(ad, ad.ad_id)}</span>
                      <span className="truncate text-xs text-muted-foreground">
                        {t(ad.source_type === "post" ? "chats.fromPost" : "chats.fromAd")} · {ad.ad_id}
                      </span>
                    </span>
                    {counts && (
                      <span className={cn("text-xs tabular-nums", count ? "text-muted-foreground" : "text-muted-foreground/40")}>
                        {count}
                      </span>
                    )}
                  </button>
                )
              })}
            </div>
          </>
        )}
      </div>
    </div>
  )
}

/** Claves de anuncio de un chat: todos los anuncios de su historial y, si tiene alguno, «cualquiera». */
export function adKeysOf(conversation: { adReferral?: AdReferral | null; adIds?: string[] }) {
  const adIds = new Set(conversation.adIds ?? [])
  if (conversation.adReferral?.ad_id) adIds.add(conversation.adReferral.ad_id)
  const hasAd = adIds.size > 0 || Boolean(conversation.adReferral)
  return hasAd ? [ANY_AD_FILTER, ...adIds] : []
}

/** Si el chat entra en el filtro de anuncios. Sin selección, entra todo. */
export function matchesAdFilter(
  conversation: { adReferral?: AdReferral | null; adIds?: string[] },
  selected: Set<string>,
) {
  if (selected.size === 0) return true
  return adKeysOf(conversation).some((key) => selected.has(key))
}
