"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import { ChevronDown, Copy, ExternalLink, ShoppingCart } from "lucide-react"
import { Button } from "@/components/ui/button"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { getWhatsAppCheckout } from "@/lib/api/whatsapp-checkouts"
import type { WhatsAppCheckoutSummary } from "@/data/types"

interface WhatsAppCheckoutCardProps {
  conversationId: number
  /** Los avisos del ciclo de vida son mensajes de sistema: cada uno sube esta clave y recarga. */
  refreshKey?: number
}

export function WhatsAppCheckoutCard({ conversationId, refreshKey }: WhatsAppCheckoutCardProps) {
  const { addToast } = useToast()
  const { t, language } = useTranslation()
  const [checkout, setCheckout] = useState<WhatsAppCheckoutSummary | null>(null)
  const [expanded, setExpanded] = useState(false)
  const loadGeneration = useRef(0)

  const load = useCallback(async () => {
    const generation = ++loadGeneration.current
    try {
      const data = await getWhatsAppCheckout(conversationId)
      if (generation === loadGeneration.current) setCheckout(data)
    } catch {
      if (generation === loadGeneration.current) setCheckout(null)
    }
  }, [conversationId])

  // El padre la monta con `key` por conversación: cambiar de chat la resetea.

  useEffect(() => {
    void load()
    return () => {
      loadGeneration.current += 1
    }
  }, [load, refreshKey])

  if (!checkout) return null

  const locale = language === "en" ? "en-US" : "es-AR"
  const time = (value: string | null) =>
    value ? new Intl.DateTimeFormat(locale, { hour: "2-digit", minute: "2-digit", hour12: false }).format(new Date(value)) : ""

  let total: string | null = null
  if (checkout.total != null) {
    total = checkout.currency
      ? new Intl.NumberFormat(locale, { style: "currency", currency: checkout.currency }).format(checkout.total)
      : new Intl.NumberFormat(locale).format(checkout.total)
  }

  const statusLabel = (() => {
    switch (checkout.status) {
      case "cart":
        return t("chats.checkout.status.cart")
      case "placing":
        return t("chats.checkout.status.placing")
      case "awaiting_payment":
        return t("chats.checkout.status.awaitingPayment", { time: time(checkout.payment_expires_at) })
      case "paid":
        if (!checkout.shipped_notified_at) return t("chats.checkout.status.paid")
        return checkout.tracking_number
          ? `${t("chats.checkout.status.shipped")} · ${checkout.tracking_number}`
          : t("chats.checkout.status.shipped")
      case "expired":
        return t("chats.checkout.status.expired")
      case "cancelled":
        return t("chats.checkout.status.cancelled")
    }
  })()

  const title = checkout.order_number
    ? t("chats.checkout.order", { number: checkout.order_number })
    : t("chats.checkout.cart")

  const copyPaymentLink = async () => {
    if (!checkout.payment_url) return
    try {
      await navigator.clipboard.writeText(checkout.payment_url)
      addToast({ type: "success", title: t("chats.checkout.linkCopied") })
    } catch {
      addToast({ type: "error", title: t("common.error") })
    }
  }

  return (
    <div className="shrink-0 border-b border-border px-4 py-1.5 text-xs">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <button
          type="button"
          className="flex min-w-0 items-center gap-1.5 text-left text-muted-foreground hover:text-foreground"
          onClick={() => setExpanded((value) => !value)}
          aria-expanded={expanded}
        >
          <ShoppingCart className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
          <span className="shrink-0 font-medium text-foreground">{title}</span>
          <span aria-hidden="true">·</span>
          <span className="truncate">{statusLabel}</span>
          {total && (
            <>
              <span aria-hidden="true">·</span>
              <span className="shrink-0 tabular-nums text-foreground">{total}</span>
            </>
          )}
          <ChevronDown className={`h-3.5 w-3.5 shrink-0 transition-transform ${expanded ? "rotate-180" : ""}`} aria-hidden="true" />
        </button>
        <div className="flex items-center gap-1">
          {checkout.status === "awaiting_payment" && checkout.payment_url && (
            <Button type="button" size="sm" variant="ghost" className="h-7 gap-1 px-2 text-xs" onClick={() => void copyPaymentLink()}>
              <Copy className="h-3.5 w-3.5" />
              {t("chats.checkout.copyPaymentLink")}
            </Button>
          )}
          {checkout.order_admin_url && (
            <Button asChild size="sm" variant="ghost" className="h-7 gap-1 px-2 text-xs">
              <a href={checkout.order_admin_url} target="_blank" rel="noopener noreferrer">
                <ExternalLink className="h-3.5 w-3.5" />
                {t("chats.checkout.openInWooCommerce")}
              </a>
            </Button>
          )}
        </div>
      </div>
      {expanded && checkout.items.length > 0 && (
        <ul className="mt-1 space-y-0.5 pl-5 text-muted-foreground">
          {checkout.items.map((item, index) => (
            <li key={index}>
              {item.quantity} × {item.name}
              {item.variant_label ? ` (${item.variant_label})` : ""}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
