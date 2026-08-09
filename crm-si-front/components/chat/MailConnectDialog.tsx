"use client"

import { useState } from "react"
import { Loader2, Mail } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { connectMailChannel, type MailEncryption } from "@/lib/api/channels"
import { ChannelError } from "@/lib/channel-error"
import { useTranslation } from "@/hooks/useTranslation"

interface MailConnectDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

const ENCRYPTIONS: MailEncryption[] = ["ssl", "tls", "none"]

/**
 * Diálogo de conexión de una casilla de email genérica (IMAP/SMTP) como canal
 * MAIL. El backend valida las credenciales probando la conexión antes de crear
 * el canal, así que el submit puede tardar unos segundos.
 */
export function MailConnectDialog({ open, onOpenChange }: MailConnectDialogProps) {
  const { t } = useTranslation()

  const [emailAddress, setEmailAddress] = useState("")
  const [fromName, setFromName] = useState("")
  const [password, setPassword] = useState("")
  const [imapHost, setImapHost] = useState("")
  const [imapPort, setImapPort] = useState("993")
  const [imapEncryption, setImapEncryption] = useState<MailEncryption>("ssl")
  const [smtpHost, setSmtpHost] = useState("")
  const [smtpPort, setSmtpPort] = useState("465")
  const [smtpEncryption, setSmtpEncryption] = useState<MailEncryption>("ssl")
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const resetForm = () => {
    setEmailAddress("")
    setFromName("")
    setPassword("")
    setImapHost("")
    setImapPort("993")
    setImapEncryption("ssl")
    setSmtpHost("")
    setSmtpPort("465")
    setSmtpEncryption("ssl")
    setError(null)
  }

  const handleOpenChange = (next: boolean) => {
    if (isSubmitting) return
    if (!next) resetForm()
    onOpenChange(next)
  }

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    if (isSubmitting) return

    setIsSubmitting(true)
    setError(null)

    try {
      await connectMailChannel({
        email_address: emailAddress.trim(),
        password,
        from_name: fromName.trim() || undefined,
        imap_host: imapHost.trim(),
        imap_port: parseInt(imapPort, 10) || 993,
        imap_encryption: imapEncryption,
        smtp_host: smtpHost.trim(),
        smtp_port: parseInt(smtpPort, 10) || 465,
        smtp_encryption: smtpEncryption,
      })
      handleOpenChange(false)
      window.dispatchEvent(new Event("channel-connected"))
    } catch (err) {
      console.error("Error conectando canal de email:", err)
      // `message` es texto redactado por el backend; `code` se traduce acá.
      if (err instanceof ChannelError) {
        setError(err.detail.message || (err.detail.code ? t(`chats.${err.detail.code}`) : t("chats.channelErrorMail")))
      } else {
        setError(t("chats.channelErrorMail"))
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  const encryptionSelect = (
    value: MailEncryption,
    onChange: (value: MailEncryption) => void,
    id: string
  ) => (
    <Select value={value} onValueChange={(next) => onChange(next as MailEncryption)}>
      <SelectTrigger id={id}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {ENCRYPTIONS.map((encryption) => (
          <SelectItem key={encryption} value={encryption}>
            {t(`chats.mailEncryption${encryption === "tls" ? "Tls" : encryption === "ssl" ? "Ssl" : "None"}`)}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Mail className="h-5 w-5 text-primary" />
            {t("chats.mailDialogTitle")}
          </DialogTitle>
          <DialogDescription>{t("chats.mailDialogDesc")}</DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="flex flex-col gap-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="mail-email">{t("chats.mailEmail")}</Label>
            <Input
              id="mail-email"
              type="email"
              required
              autoComplete="email"
              value={emailAddress}
              onChange={(event) => setEmailAddress(event.target.value)}
            />
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="mail-from-name">{t("chats.mailFromName")}</Label>
            <Input
              id="mail-from-name"
              type="text"
              value={fromName}
              onChange={(event) => setFromName(event.target.value)}
            />
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="mail-password">{t("chats.mailPassword")}</Label>
            <Input
              id="mail-password"
              type="password"
              required
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
            <p className="text-xs text-muted-foreground">{t("chats.mailPasswordHint")}</p>
          </div>

          <div className="flex flex-col gap-3 rounded-md border border-border p-3">
            <p className="text-sm font-medium text-foreground">{t("chats.mailImapSection")}</p>
            <div className="flex flex-col gap-2">
              <Label htmlFor="mail-imap-host">{t("chats.mailHost")}</Label>
              <Input
                id="mail-imap-host"
                type="text"
                required
                placeholder="imap.example.com"
                value={imapHost}
                onChange={(event) => setImapHost(event.target.value)}
              />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div className="flex flex-col gap-2">
                <Label htmlFor="mail-imap-port">{t("chats.mailPort")}</Label>
                <Input
                  id="mail-imap-port"
                  type="number"
                  required
                  min={1}
                  max={65535}
                  value={imapPort}
                  onChange={(event) => setImapPort(event.target.value)}
                />
              </div>
              <div className="flex flex-col gap-2">
                <Label htmlFor="mail-imap-encryption">{t("chats.mailEncryption")}</Label>
                {encryptionSelect(imapEncryption, setImapEncryption, "mail-imap-encryption")}
              </div>
            </div>
          </div>

          <div className="flex flex-col gap-3 rounded-md border border-border p-3">
            <p className="text-sm font-medium text-foreground">{t("chats.mailSmtpSection")}</p>
            <div className="flex flex-col gap-2">
              <Label htmlFor="mail-smtp-host">{t("chats.mailHost")}</Label>
              <Input
                id="mail-smtp-host"
                type="text"
                required
                placeholder="smtp.example.com"
                value={smtpHost}
                onChange={(event) => setSmtpHost(event.target.value)}
              />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div className="flex flex-col gap-2">
                <Label htmlFor="mail-smtp-port">{t("chats.mailPort")}</Label>
                <Input
                  id="mail-smtp-port"
                  type="number"
                  required
                  min={1}
                  max={65535}
                  value={smtpPort}
                  onChange={(event) => setSmtpPort(event.target.value)}
                />
              </div>
              <div className="flex flex-col gap-2">
                <Label htmlFor="mail-smtp-encryption">{t("chats.mailEncryption")}</Label>
                {encryptionSelect(smtpEncryption, setSmtpEncryption, "mail-smtp-encryption")}
              </div>
            </div>
          </div>

          {error && <p className="text-sm text-destructive">{error}</p>}

          <div className="flex justify-end gap-2">
            <Button
              type="button"
              variant="ghost"
              onClick={() => handleOpenChange(false)}
              disabled={isSubmitting}
            >
              {t("chats.mailCancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {isSubmitting ? t("chats.mailConnecting") : t("chats.mailConnect")}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
