"use client"

import { useEffect, useState } from "react"
import { Button } from "@/components/ui/button"
import { ContactAvatar } from "@/components/contact-avatar"
import { Badge } from "@/components/ui/badge"
import { X, Phone, MessageSquare, Loader2 } from "lucide-react"
import { cn } from "@/lib/utils"
import { getContact, type Contact, type ContactAvatar as ContactAvatarPayload } from "@/lib/api/contacts"
import { ContactAvatarActions } from "@/components/contact-avatar-actions"
import { PlatformIcon } from "@/components/chat/PlatformIcon"
import { useTranslation } from "@/hooks/useTranslation"

interface ContactInfoPanelProps {
  contactId: number
  isOpen: boolean
  onClose: () => void
  /** Para que la bandeja y el encabezado muestren la foto nueva sin recargar. */
  onAvatarChange?: (contactId: number, avatarUrl: string | null) => void
  className?: string
}

const HIDDEN_CUSTOM_KEYS = new Set(["tags"])

function humanizeKey(key: string): string {
  return key
    .replace(/[_-]+/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase())
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined || value === "") return "—"
  if (Array.isArray(value)) return value.join(", ")
  if (typeof value === "boolean") return value ? "Sí" : "No"
  return String(value)
}

export function ContactInfoPanel({ contactId, isOpen, onClose, onAvatarChange, className }: ContactInfoPanelProps) {
  const { t } = useTranslation()
  const [contact, setContact] = useState<Contact | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    if (!isOpen || !contactId) return

    let cancelled = false
    setIsLoading(true)
    setError(null)

    getContact(contactId)
      .then((data) => {
        if (!cancelled) setContact(data)
      })
      .catch(() => {
        // Sin esto quedaría visible el contacto anterior debajo del error.
        if (!cancelled) {
          setContact(null)
          setError(t("contactPanel.loadError"))
        }
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [contactId, isOpen, t, reloadKey])

  const handleAvatarChange = (avatar: ContactAvatarPayload) => {
    setContact((prev) => (prev ? { ...prev, ...avatar } : prev))
    onAvatarChange?.(contactId, avatar.avatar_url)
  }

  if (!isOpen) return null

  const customEntries = Object.entries(contact?.custom_data ?? {}).filter(
    ([key, value]) => !HIDDEN_CUSTOM_KEYS.has(key) && value !== null && value !== "",
  )

  const phoneDigits = (contact?.phone ?? "").replace(/[^0-9]/g, "")

  return (
    <div
      className={cn(
        "w-96 border-l border-border bg-card flex flex-col h-full overflow-hidden transition-all duration-300",
        className,
      )}
    >
      <div className="p-4 border-b border-border flex items-center justify-between sticky top-0 bg-card z-10">
        <h2 className="font-semibold text-lg">{t("contactPanel.title")}</h2>
        <Button variant="ghost" size="sm" onClick={onClose} className="size-10 p-0 lg:size-8" aria-label={t("contactPanel.close")}>
          <X className="w-4 h-4" aria-hidden="true" />
        </Button>
      </div>

      <div className="flex-1 overflow-y-auto p-6 pb-[max(1.5rem,env(safe-area-inset-bottom))] space-y-6">
        {isLoading && (
          <div role="status" aria-label={t("chats.loading")} className="flex items-center justify-center py-12 text-muted-foreground">
            <Loader2 className="w-5 h-5 animate-spin" aria-hidden="true" />
          </div>
        )}

        {error && !isLoading && (
          <div className="flex flex-col items-center gap-3 py-8 text-center">
            <p className="text-sm text-destructive">{error}</p>
            <Button size="sm" variant="outline" onClick={() => setReloadKey((key) => key + 1)}>
              {t("chats.retry")}
            </Button>
          </div>
        )}

        {contact && !isLoading && (
          <>
            <div className="flex flex-col items-center space-y-3">
              <ContactAvatar
                contactId={contact.id}
                name={contact.name}
                imageUrl={contact.avatar_url}
                className="w-24 h-24"
                fallbackClassName="text-2xl"
              />
              <div className="text-center">
                <h3 className="text-xl font-semibold">{contact.name}</h3>
                {contact.phone && <p className="text-sm text-muted-foreground">{contact.phone}</p>}
              </div>
              <ContactAvatarActions
                contactId={contact.id}
                avatarUrl={contact.avatar_url}
                avatarSource={contact.avatar_source}
                onChange={handleAvatarChange}
                className="items-center text-center"
              />
            </div>

            <div className="space-y-4">
              <Field label={t("contactPanel.email")} value={formatValue(contact.email)} />
              <Field label={t("contactPanel.phone")} value={formatValue(contact.phone)} />
              <Field
                label={t("contactPanel.stage")}
                value={contact.pipeline_stage?.name ?? "—"}
                badge={Boolean(contact.pipeline_stage)}
              />
              <Field label={t("contactPanel.assignee")} value={contact.assigned_user?.name ?? "—"} />
              <Field label={t("contactPanel.source")} value={formatValue(contact.source)} />
              <Field
                label={t("contactPanel.createdAt")}
                value={contact.created_at ? new Date(contact.created_at).toLocaleDateString() : "—"}
              />

              {contact.tags && contact.tags.length > 0 && (
                <div className="space-y-2">
                  <p className="text-xs text-muted-foreground">{t("contactPanel.tags")}</p>
                  <div className="flex flex-wrap gap-2">
                    {contact.tags.map((tag) => (
                      <Badge key={tag.id} variant="outline">
                        {tag.name}
                      </Badge>
                    ))}
                  </div>
                </div>
              )}
            </div>

            {contact.ad_referrals && contact.ad_referrals.length > 0 && (
              <div className="space-y-3 pt-4 border-t border-border">
                <h3 className="font-medium text-sm">{t("contactPanel.adReferrals")}</h3>
                <ul className="space-y-3">
                  {contact.ad_referrals.map((referral) => (
                    <li key={referral.id} className="flex items-start gap-2">
                      <PlatformIcon type={referral.platform} className="w-4 h-4 mt-0.5 shrink-0" aria-hidden="true" />
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium break-words">
                          {referral.headline ?? t("contactPanel.adFallbackTitle", { id: referral.ad_id ?? "—" })}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {new Date(referral.referred_at).toLocaleDateString()}
                        </p>
                        {referral.source_url && (
                          <a
                            href={referral.source_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-xs text-primary underline-offset-4 hover:underline"
                          >
                            {t("contactPanel.viewAd")}
                          </a>
                        )}
                      </div>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {customEntries.length > 0 && (
              <div className="space-y-3 pt-4 border-t border-border">
                <h3 className="font-medium text-sm">{t("contactPanel.additionalFields")}</h3>
                <div className="space-y-3">
                  {customEntries.map(([key, value]) => (
                    <Field key={key} label={humanizeKey(key)} value={formatValue(value)} />
                  ))}
                </div>
              </div>
            )}

            <div className="flex gap-2 pt-4 border-t border-border">
              {phoneDigits && (
                <Button variant="outline" className="h-11 flex-1 gap-2 bg-transparent lg:h-9" asChild>
                  <a href={`https://wa.me/${phoneDigits}`} target="_blank" rel="noopener noreferrer">
                    <MessageSquare className="w-4 h-4" />
                    {t("contactPanel.openWhatsApp")}
                  </a>
                </Button>
              )}
              {contact.phone && (
                <Button variant="outline" className="h-11 flex-1 gap-2 bg-transparent lg:h-9" asChild>
                  <a href={`tel:${contact.phone}`}>
                    <Phone className="w-4 h-4" />
                    {t("contactPanel.call")}
                  </a>
                </Button>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  )
}

function Field({ label, value, badge = false }: { label: string; value: string; badge?: boolean }) {
  return (
    <div className="space-y-1">
      <p className="text-xs text-muted-foreground">{label}</p>
      {badge ? (
        <Badge variant="outline">{value}</Badge>
      ) : (
        <p className="text-sm font-medium break-words">{value}</p>
      )}
    </div>
  )
}
