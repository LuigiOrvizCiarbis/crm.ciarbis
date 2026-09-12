"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { AlertTriangle, CheckCircle2, Clock, Loader2, Lock, Send, Wallet } from "lucide-react"

import { SettingsBlock } from "@/components/config/SettingsBlock"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Checkbox } from "@/components/ui/checkbox"
import { Label } from "@/components/ui/label"
import { Skeleton } from "@/components/ui/skeleton"
import { Textarea } from "@/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useToast } from "@/components/Toast"
import { ChannelType } from "@/data/enums"
import type { Channel } from "@/data/types"
import { getChannels } from "@/lib/api/channels"
import { createTemplate } from "@/lib/api/templates"
import {
  bodyTextOf,
  getBillingTemplateDrafts,
  variablesOf,
  withBodyText,
  type BillingTemplateDraft,
} from "@/lib/api/billing"
import { usePermission } from "@/hooks/usePermission"
import { cn } from "@/lib/utils"

/** Estados de Meta que significan "todavía no se puede usar". */
const PENDING_STATUSES = new Set(["PENDING", "IN_APPEAL"])

/**
 * El aviso de fin de prueba sólo tiene sentido para negocios que ofrecen un
 * período de prueba. Las otras dos son el mínimo para que el módulo funcione.
 */
const OPTIONAL_KEYS = new Set<BillingTemplateDraft["key"]>(["trial"])

type DraftState = BillingTemplateDraft & { bodyText: string; selected: boolean }

export function BillingTemplatesCard() {
  const { addToast } = useToast()
  const canManage = usePermission("billing.manage")
  // Crear las plantillas es crear plantillas de WhatsApp: el backend lo exige
  // en CreateWhatsAppTemplateRequest::authorize(). Son permisos
  // independientes, así que un rol con billing.manage pero sin
  // templates.create vería el botón y se comería un 403 sin salida.
  const canCreateTemplates = usePermission("templates.create")

  const [drafts, setDrafts] = useState<DraftState[]>([])
  const [channels, setChannels] = useState<Channel[]>([])
  const [channelId, setChannelId] = useState<string>("")
  const [loading, setLoading] = useState(true)
  const [sending, setSending] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [draftList, channelList] = await Promise.all([
        getBillingTemplateDrafts(),
        getChannels(),
      ])

      setDrafts(
        draftList.map((draft) => ({
          ...draft,
          bodyText: bodyTextOf(draft),
          // Las obligatorias van siempre; la opcional arranca desmarcada para
          // que quien no ofrece prueba no tenga que desmarcarla ni termine
          // creando una plantilla que no va a usar.
          selected: !OPTIONAL_KEYS.has(draft.key),
        })),
      )

      const whatsapp = channelList.filter((channel) => channel.type === ChannelType.WHATSAPP)
      setChannels(whatsapp)
      setChannelId((current) => current || (whatsapp[0] ? String(whatsapp[0].id) : ""))
    } catch (error) {
      addToast({
        type: "error",
        title: "No se pudieron cargar las plantillas de cobranza",
        description: error instanceof Error ? error.message : "Error desconocido",
      })
    } finally {
      setLoading(false)
    }
  }, [addToast])

  useEffect(() => {
    if (canManage) void load()
    else setLoading(false)
  }, [canManage, load])

  // Las dos primeras son obligatorias para que el módulo funcione; la de
  // trial es opcional y sólo se manda si el usuario la deja marcada.
  const required = useMemo(() => drafts.filter((d) => !OPTIONAL_KEYS.has(d.key)), [drafts])
  const pending = useMemo(
    () => drafts.filter((d) => d.template && PENDING_STATUSES.has(d.template.status)),
    [drafts],
  )
  const approvedRequired = useMemo(
    () => required.filter((d) => d.template?.status === "APPROVED"),
    [required],
  )
  const rejected = useMemo(
    () => drafts.filter((d) => d.template?.status === "REJECTED"),
    [drafts],
  )
  // Lo que el botón va a mandar: pendientes de pedir y marcadas. Si el usuario
  // desmarca todo lo que queda, el botón no tiene nada que hacer.
  const selectedPending = useMemo(
    () => drafts.filter((d) => d.template === null && d.selected),
    [drafts],
  )

  const allRequiredApproved = required.length > 0 && approvedRequired.length === required.length
  // Hay trabajo pendiente Y el usuario puede hacerlo: sin el permiso de
  // plantillas no se ofrece el envío, se explica por qué.
  const canSubmit = selectedPending.length > 0 && canCreateTemplates
  const blockedByPermission = selectedPending.length > 0 && !canCreateTemplates

  const toggleSelected = (key: string, selected: boolean) => {
    setDrafts((current) =>
      current.map((draft) => (draft.key === key ? { ...draft, selected } : draft)),
    )
  }

  const updateBody = (key: string, text: string) => {
    setDrafts((current) =>
      current.map((draft) => (draft.key === key ? { ...draft, bodyText: text } : draft)),
    )
  }

  const send = async () => {
    // El backend lo rechaza igual (CreateWhatsAppTemplateRequest), pero acá
    // se corta antes para no disparar un 403 por cada plantilla.
    if (!channelId || !canCreateTemplates) return

    // Sólo las que todavía no se pidieron (reenviar una existente hace que
    // Meta rechace por nombre duplicado) y que el usuario dejó marcadas: las
    // opcionales no se crean sin que las pida.
    const toSend = drafts.filter((draft) => draft.template === null && draft.selected)
    if (toSend.length === 0) return

    setSending(true)
    let created = 0
    const failures: string[] = []

    for (const draft of toSend) {
      try {
        await createTemplate(Number(channelId), {
          name: draft.name,
          language: draft.language,
          category: draft.category,
          parameter_format: draft.parameter_format,
          components: withBodyText(draft, draft.bodyText),
        })
        created++
      } catch (error) {
        // Cada plantilla es independiente: si Meta rechaza una, las otras ya
        // creadas siguen en pie y se informa cuál falló.
        failures.push(`${draft.label}: ${error instanceof Error ? error.message : "error"}`)
      }
    }

    setSending(false)

    if (created > 0) {
      addToast({
        type: "success",
        title: `${created} plantilla(s) enviada(s) a Meta`,
        description: "Meta las revisa y avisa cuando estén aprobadas. Puede tardar unos minutos.",
      })
    }

    if (failures.length > 0) {
      addToast({
        type: "error",
        title: "Algunas plantillas no se pudieron crear",
        description: failures.join(" · "),
      })
    }

    await load()
  }

  if (!canManage) return null

  return (
    <SettingsBlock
      title="Plantillas de cobranza"
      description="Los mensajes que el CRM envía para avisar vencimientos y reclamar pagos. Meta los revisa antes de habilitarlos."
      icon={Wallet}
      measure="prose"
    >
      {loading ? (
        <div className="space-y-4">
          <Skeleton className="h-9 w-64" />
          <Skeleton className="h-28 w-full" />
          <Skeleton className="h-28 w-full" />
        </div>
      ) : channels.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          Conectá un canal de WhatsApp antes de crear las plantillas de cobranza.
        </p>
      ) : (
        <div className="space-y-6">
          {allRequiredApproved && (
            <div className="flex items-start gap-3 rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-4">
              <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-500" aria-hidden />
              <div className="space-y-1 text-sm">
                <p className="font-medium">Plantillas aprobadas</p>
                <p className="text-muted-foreground">
                  El módulo de cobranzas quedó configurado. Las automatizaciones se crearon en
                  borrador: revisalas y activalas cuando quieras que empiecen a enviarse.
                </p>
              </div>
            </div>
          )}

          {pending.length > 0 && (
            <div className="flex items-start gap-3 rounded-lg border border-amber-500/30 bg-amber-500/5 p-4">
              <Clock className="mt-0.5 h-5 w-5 shrink-0 text-amber-500" aria-hidden />
              <div className="space-y-1 text-sm">
                <p className="font-medium">Meta está revisando tus plantillas</p>
                <p className="text-muted-foreground">
                  Suele tardar unos minutos. Cuando las apruebe, el módulo se configura solo.
                </p>
              </div>
            </div>
          )}

          {rejected.length > 0 && (
            <div className="flex items-start gap-3 rounded-lg border border-red-500/30 bg-red-500/5 p-4">
              <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-red-500" aria-hidden />
              <div className="space-y-1 text-sm">
                <p className="font-medium">Meta rechazó una plantilla</p>
                <ul className="space-y-0.5 text-muted-foreground">
                  {rejected.map((draft) => (
                    <li key={draft.key}>
                      <span className="font-medium">{draft.label}:</span>{" "}
                      {draft.template?.rejected_reason ?? "sin motivo informado"}
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          )}

          {blockedByPermission && (
            <div className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-4">
              <Lock className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" aria-hidden />
              <div className="space-y-1 text-sm">
                <p className="font-medium">Necesitás permiso para crear plantillas</p>
                <p className="text-muted-foreground">
                  Estos mensajes se crean como plantillas de WhatsApp, y eso requiere el permiso
                  «Crear plantillas». Pedile a un administrador que te lo asigne o que complete
                  este paso.
                </p>
              </div>
            </div>
          )}

          {canSubmit && (
            <div className="space-y-1.5">
              <Label htmlFor="billing-template-channel">Canal de WhatsApp</Label>
              <Select value={channelId} onValueChange={setChannelId}>
                <SelectTrigger id="billing-template-channel" className="w-full sm:w-80">
                  <SelectValue placeholder="Elegí un canal" />
                </SelectTrigger>
                <SelectContent>
                  {channels.map((channel) => (
                    <SelectItem key={channel.id} value={String(channel.id)}>
                      {channel.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}

          <div className="space-y-5">
            {drafts.map((draft) => {
              const variables = variablesOf(draft.bodyText)
              const alreadyRequested = draft.template !== null
              const isOptional = OPTIONAL_KEYS.has(draft.key)
              // Una opcional desmarcada no se envía: se muestra en gris para
              // que se entienda que está ahí pero no va a crearse.
              const skipped = isOptional && !draft.selected && !alreadyRequested
              // Sin permiso para crear plantillas los textos se muestran, pero
              // no se pueden tocar: editarlos no llevaría a ningún lado.
              const editable = !alreadyRequested && !skipped && canCreateTemplates
              const selectable = isOptional && !alreadyRequested && canCreateTemplates

              return (
                <div key={draft.key} className="space-y-2 border-t border-border pt-5 first:border-0 first:pt-0">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-start gap-2.5">
                      {selectable && (
                        <Checkbox
                          id={`billing-draft-${draft.key}`}
                          checked={draft.selected}
                          onCheckedChange={(checked) => toggleSelected(draft.key, checked === true)}
                          className="mt-0.5"
                        />
                      )}
                      <div className="space-y-0.5">
                        <label
                          htmlFor={selectable ? `billing-draft-${draft.key}` : undefined}
                          className={cn(
                            "text-sm font-medium",
                            selectable && "cursor-pointer",
                            skipped && "text-muted-foreground",
                          )}
                        >
                          {draft.label}
                          {isOptional && (
                            <span className="ml-2 text-xs font-normal text-muted-foreground">
                              Opcional
                            </span>
                          )}
                        </label>
                        <p className="text-xs text-muted-foreground">{draft.description}</p>
                      </div>
                    </div>
                    {draft.template && (
                      <Badge
                        variant={
                          draft.template.status === "APPROVED"
                            ? "default"
                            : draft.template.status === "REJECTED"
                              ? "destructive"
                              : "secondary"
                        }
                      >
                        {draft.template.status_label}
                      </Badge>
                    )}
                  </div>

                  <Textarea
                    value={draft.bodyText}
                    onChange={(event) => updateBody(draft.key, event.target.value)}
                    disabled={!editable}
                    rows={3}
                    className="resize-none text-sm"
                    aria-label={`Texto de ${draft.label}`}
                  />

                  <p className="text-xs text-muted-foreground">
                    {alreadyRequested ? (
                      // Meta no permite editar una plantilla ya enviada: hay
                      // que crear otra distinta y borrar la vieja.
                      <>Ya enviada a Meta. Para cambiar el texto hay que crear una plantilla nueva.</>
                    ) : skipped ? (
                      <>No se va a crear. Marcala si tu negocio ofrece período de prueba.</>
                    ) : variables.length > 0 ? (
                      <>
                        Variables: {variables.map((name) => `{{${name}}}`).join(", ")}. Se completan
                        con los datos de cada contacto al enviar.
                      </>
                    ) : (
                      <>Sin variables: el mensaje se envía igual a todos los contactos.</>
                    )}
                  </p>
                </div>
              )
            })}
          </div>

          {canSubmit && (
            <div className="flex flex-wrap items-center gap-3">
              <Button onClick={send} disabled={sending || !channelId || selectedPending.length === 0}>
                {sending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />
                ) : (
                  <Send className="mr-2 h-4 w-4" aria-hidden />
                )}
                Enviar {selectedPending.length} plantilla{selectedPending.length === 1 ? "" : "s"} a Meta
              </Button>
              <p className="text-xs text-muted-foreground">
                Revisá los textos antes de enviarlos: una vez aprobados no se pueden editar.
              </p>
            </div>
          )}
        </div>
      )}
    </SettingsBlock>
  )
}
