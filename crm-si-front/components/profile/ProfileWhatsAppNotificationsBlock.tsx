"use client"

import { useState } from "react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import { useAuthStore } from "@/store/useAuthStore"
import { useToast } from "@/components/Toast"
import { confirmWhatsAppNotificationVerification, requestWhatsAppNotificationVerification, revokeWhatsAppNotifications } from "@/lib/api/profile"

export function ProfileWhatsAppNotificationsBlock() {
  const user = useAuthStore((state) => state.user)
  const updateUser = useAuthStore((state) => state.updateUser)
  const { addToast } = useToast()
  const [phone, setPhone] = useState(user?.whatsapp_notification?.phone ?? "")
  const [code, setCode] = useState("")
  const [sent, setSent] = useState(false)
  const [busy, setBusy] = useState(false)
  const verified = Boolean(user?.whatsapp_notification?.verified_at)

  async function sendCode() {
    setBusy(true); const result = await requestWhatsAppNotificationVerification(phone); setBusy(false)
    if (result.error) addToast({ type: "error", title: result.error }); else { setSent(true); addToast({ type: "success", title: "Código enviado por WhatsApp" }) }
  }
  async function confirm() {
    setBusy(true); const result = await confirmWhatsAppNotificationVerification(code); setBusy(false)
    if (result.error) addToast({ type: "error", title: result.error }); else { updateUser(result.data!); setSent(false); setCode(""); addToast({ type: "success", title: "WhatsApp verificado" }) }
  }
  async function revoke() { const result = await revokeWhatsAppNotifications(); if (!result.error) { updateUser({ whatsapp_notification: undefined }); setPhone("") } }

  return <Card><CardHeader><CardTitle>Alertas de derivación por WhatsApp</CardTitle></CardHeader><CardContent className="space-y-3">
    <p className="text-sm text-muted-foreground">Recibí un aviso cuando un cliente pida hablar con una persona.</p>
    <div className="flex gap-2"><Input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="+54 9 11..." disabled={verified} /><Button onClick={sendCode} disabled={busy || !phone || verified}>Verificar</Button></div>
    {sent && <div className="flex gap-2"><Input value={code} onChange={(e) => setCode(e.target.value)} placeholder="Código de 6 dígitos" maxLength={6} /><Button onClick={confirm} disabled={busy || code.length !== 6}>Confirmar</Button></div>}
    {verified && <Button variant="outline" onClick={revoke}>Desactivar alertas</Button>}
  </CardContent></Card>
}
