"use client"

import { Suspense } from "react"
import { useSearchParams } from "next/navigation"
import { CheckCircle2, Clock, XCircle } from "lucide-react"
import { useTranslation } from "@/hooks/useTranslation"

// Retorno de Mercado Pago (back_urls) de los pedidos de compra por WhatsApp.
function WhatsAppPurchaseContent() {
  const { t } = useTranslation()
  const searchParams = useSearchParams()
  const status = searchParams.get("collection_status") ?? searchParams.get("status") ?? ""
  const order = searchParams.get("pedido") ?? ""
  const wa = searchParams.get("wa") ?? ""
  const waLink = /^\d+$/.test(wa) ? `https://wa.me/${wa}` : null

  const approved = status === "approved"
  const pending = status === "pending" || status === "in_process"
  const Icon = approved ? CheckCircle2 : pending ? Clock : XCircle
  const message = approved
    ? order
      ? t("whatsappPurchase.approved", { pedido: order })
      : t("whatsappPurchase.approvedNoOrder")
    : pending
      ? t("whatsappPurchase.pending")
      : t("whatsappPurchase.failed")

  return (
    <div className="min-h-screen bg-background text-foreground flex items-center justify-center">
      <div className="max-w-md mx-auto px-6 text-center">
        <div className="bg-card border border-border rounded-xl p-8 space-y-6">
          <Icon
            className={`mx-auto h-12 w-12 ${
              approved ? "text-success" : pending ? "text-warning" : "text-destructive"
            }`}
            aria-hidden="true"
          />
          <p className="text-lg font-medium">{message}</p>
          {waLink && (
            <a
              href={waLink}
              className="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
            >
              {t("whatsappPurchase.backToWhatsApp")}
            </a>
          )}
        </div>
      </div>
    </div>
  )
}

export default function WhatsAppPurchasePage() {
  return (
    <Suspense fallback={null}>
      <WhatsAppPurchaseContent />
    </Suspense>
  )
}
