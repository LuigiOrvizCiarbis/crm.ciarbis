"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import Link from "next/link"
import {
  ArrowUpRight,
  Instagram,
  Loader2,
  MessageCircle,
  Plus,
  RefreshCw,
  Search,
  Trash2,
  Users,
} from "lucide-react"

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Progress } from "@/components/ui/progress"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { useToast } from "@/components/Toast"
import { Channel, WhatsAppTemplate } from "@/data/types"
import { ChannelType } from "@/data/enums"
import { getChannels } from "@/lib/api/channels"
import { deleteTemplate, getManagedTemplates, syncTemplates } from "@/lib/api/templates"
import { BroadcastCampaign, getBroadcasts } from "@/lib/api/broadcasts"
import { getPipelineStages, PipelineStage } from "@/lib/api/pipeline"
import { getTags, Tag } from "@/lib/api/tags"
import { ContactField, getContactFields } from "@/lib/api/contact-fields"
import { cn } from "@/lib/utils"
import { usePermission } from "@/hooks/usePermission"
import { NewBroadcastDialog } from "./NewBroadcastDialog"
import { NewTemplateDialog } from "./NewTemplateDialog"

const templateStateLabel: Record<string, string> = {
  ALL: "Todos los estados",
  APPROVED: "Aprobadas",
  PENDING: "Pendientes",
  REJECTED: "Rechazadas",
  DISABLED: "Deshabilitadas",
}

const templateStatus: Record<string, { label: string; className: string }> = {
  APPROVED: { label: "Aprobada", className: "border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300" },
  PENDING: { label: "En revisión", className: "border-amber-500/25 bg-amber-500/10 text-amber-700 dark:text-amber-300" },
  REJECTED: { label: "Rechazada", className: "border-red-500/25 bg-red-500/10 text-red-700 dark:text-red-300" },
}

const campaignStatus: Record<string, { label: string; className: string }> = {
  scheduled: { label: "Programada", className: "bg-sky-500/10 text-sky-700 dark:text-sky-300" },
  processing: { label: "En curso", className: "bg-amber-500/10 text-amber-700 dark:text-amber-300" },
  completed: { label: "Completada", className: "bg-emerald-500/10 text-emerald-700 dark:text-emerald-300" },
  partial: { label: "Parcial", className: "bg-orange-500/10 text-orange-700 dark:text-orange-300" },
  failed: { label: "Con errores", className: "bg-red-500/10 text-red-700 dark:text-red-300" },
  cancelled: { label: "Cancelada", className: "bg-muted text-muted-foreground" },
}

const currency = new Intl.NumberFormat("es-AR", { style: "currency", currency: "USD", minimumFractionDigits: 2 })
const dateTime = new Intl.DateTimeFormat("es-AR", { dateStyle: "medium", timeStyle: "short" })

const isConnectedBroadcastChannel = (channel: Channel) =>
  channel.status === "active"
  && (
    (channel.type === ChannelType.WHATSAPP && Boolean(channel.whatsapp_config))
    || (channel.type === ChannelType.INSTAGRAM && Boolean(channel.instagram_config))
  )

const channelSubtitle = (channel: Channel) =>
  channel.type === ChannelType.WHATSAPP
    ? channel.whatsapp_config?.display_phone_number ?? "WhatsApp Business"
    : `@${channel.instagram_config?.username ?? "Instagram"}`

export function BroadcastsDashboard() {
  const { addToast } = useToast()
  const canSend = usePermission("templates.send")
  const canCreateTemplate = usePermission("templates.create")
  const canDeleteTemplate = usePermission("templates.delete")
  const canSyncTemplate = usePermission("templates.sync")
  const [channels, setChannels] = useState<Channel[]>([])
  const [campaigns, setCampaigns] = useState<BroadcastCampaign[]>([])
  const [templates, setTemplates] = useState<WhatsAppTemplate[]>([])
  const [stages, setStages] = useState<PipelineStage[]>([])
  const [tags, setTags] = useState<Tag[]>([])
  const [fields, setFields] = useState<Array<Pick<ContactField, "key" | "label" | "type">>>([])
  const [channelId, setChannelId] = useState<number | null>(null)
  const [loading, setLoading] = useState(true)
  const [templatesLoading, setTemplatesLoading] = useState(false)
  const [syncing, setSyncing] = useState(false)
  const [query, setQuery] = useState("")
  const [stateFilter, setStateFilter] = useState("ALL")
  const [dialogOpen, setDialogOpen] = useState(false)
  const [initialTemplateId, setInitialTemplateId] = useState<number | null>(null)
  const [newTemplateOpen, setNewTemplateOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState<WhatsAppTemplate | null>(null)
  const [deletingTemplateId, setDeletingTemplateId] = useState<number | null>(null)

  const loadCampaigns = useCallback(async () => {
    setCampaigns(await getBroadcasts())
  }, [])

  useEffect(() => {
    let cancelled = false
    Promise.all([getChannels(), getBroadcasts(), getPipelineStages(), getTags(), getContactFields()])
      .then(([loadedChannels, loadedCampaigns, loadedStages, loadedTags, loadedFields]) => {
        if (cancelled) return
        setChannels(loadedChannels)
        setCampaigns(loadedCampaigns)
        setStages(loadedStages)
        setTags(loadedTags)
        setFields([...loadedFields.standard, ...loadedFields.data].filter((field) => !field.is_system))
        const first = loadedChannels.find((channel) => channel.type === ChannelType.WHATSAPP && isConnectedBroadcastChannel(channel))
          ?? loadedChannels.find((channel) => channel.type === ChannelType.INSTAGRAM && isConnectedBroadcastChannel(channel))
        setChannelId(first?.id ?? null)
      })
      .catch((error) => addToast({ type: "error", title: error instanceof Error ? error.message : "No se pudo cargar Difusiones" }))
      .finally(() => !cancelled && setLoading(false))
    return () => { cancelled = true }
  }, [addToast])

  const selectedChannel = channels.find((channel) => channel.id === channelId) ?? null

  useEffect(() => {
    if (!selectedChannel || selectedChannel.type !== ChannelType.WHATSAPP || !selectedChannel.whatsapp_config) {
      setTemplates([])
      return
    }
    let cancelled = false
    setTemplatesLoading(true)
    getManagedTemplates(selectedChannel.id)
      .then((items) => !cancelled && setTemplates(items))
      .catch((error) => addToast({ type: "error", title: error instanceof Error ? error.message : "No se pudieron cargar las plantillas" }))
      .finally(() => !cancelled && setTemplatesLoading(false))
    return () => { cancelled = true }
  }, [addToast, selectedChannel])

  const connectedChannels = useMemo(
    () => channels.filter(isConnectedBroadcastChannel),
    [channels],
  )

  const visibleTemplates = useMemo(() => {
    const normalized = query.trim().toLowerCase()
    return templates.filter((template) => {
      const matchesQuery = !normalized || template.name.toLowerCase().includes(normalized)
      const matchesState = stateFilter === "ALL" || template.status === stateFilter
      return matchesQuery && matchesState
    })
  }, [query, stateFilter, templates])

  const totals = useMemo(() => ({
    scheduled: campaigns.filter((campaign) => campaign.status === "scheduled").length,
    active: campaigns.filter((campaign) => campaign.status === "processing").length,
    sent: campaigns.reduce((sum, campaign) => sum + campaign.sent_count, 0),
    spent: campaigns.reduce((sum, campaign) => sum + campaign.actual_cost_usd, 0),
  }), [campaigns])

  const openDialog = (templateId?: number) => {
    setInitialTemplateId(templateId ?? null)
    setDialogOpen(true)
  }

  const handleSync = async () => {
    if (!selectedChannel || selectedChannel.type !== ChannelType.WHATSAPP || !selectedChannel.whatsapp_config) return
    setSyncing(true)
    try {
      await syncTemplates(selectedChannel.id)
      setTemplates(await getManagedTemplates(selectedChannel.id))
      addToast({ type: "success", title: "Plantillas sincronizadas" })
    } catch (error) {
      addToast({ type: "error", title: error instanceof Error ? error.message : "No se pudieron sincronizar" })
    } finally {
      setSyncing(false)
    }
  }

  const handleDeleteTemplate = async () => {
    if (!selectedChannel || !deleteTarget || deletingTemplateId !== null) return
    const templateId = deleteTarget.id
    setDeletingTemplateId(templateId)
    try {
      await deleteTemplate(selectedChannel.id, templateId)
      setTemplates((current) => current.filter((template) => template.id !== templateId))
      setDeleteTarget(null)
      addToast({ type: "success", title: "Plantilla eliminada de Meta y del CRM." })
    } catch (error) {
      addToast({ type: "error", title: error instanceof Error ? error.message : "No se pudo eliminar la plantilla" })
    } finally {
      setDeletingTemplateId(null)
    }
  }

  if (loading) {
    return (
      <>
        <div className="sticky top-0 z-40 flex h-[75px] items-center gap-4 border-b border-border bg-background px-4 md:px-6 lg:px-8">
          <Skeleton className="h-6 w-32" />
          <Skeleton className="h-9 w-56" />
          <Skeleton className="ml-auto h-9 w-40" />
        </div>
        <div className="px-4 py-6 md:px-6 lg:px-8">
          <Skeleton className="h-14 w-full max-w-2xl" />
          <Skeleton className="mt-8 h-72 w-full" />
        </div>
      </>
    )
  }

  const stats = [
    { label: "Programadas", value: totals.scheduled.toLocaleString("es-AR") },
    { label: "En curso", value: totals.active.toLocaleString("es-AR") },
    { label: "Mensajes enviados", value: totals.sent.toLocaleString("es-AR") },
    { label: "Inversión registrada", value: currency.format(totals.spent) },
  ]

  return (
    <>
      <header className="sticky top-0 z-40 flex h-[75px] items-center gap-3 border-b border-border bg-background/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/60 md:px-6 lg:px-8">
        <h1 className="min-w-fit text-xl font-semibold tracking-tight">Difusiones</h1>

        {connectedChannels.length > 0 && (
          <Select
            value={channelId ? String(channelId) : undefined}
            onValueChange={(value) => setChannelId(Number(value))}
          >
            <SelectTrigger className="h-9 w-[210px]">
              <SelectValue placeholder="Elegí un canal" />
            </SelectTrigger>
            <SelectContent>
              {connectedChannels.map((channel) => (
                <SelectItem key={channel.id} value={String(channel.id)}>
                  <span className="flex items-center gap-2">
                    {channel.type === ChannelType.WHATSAPP
                      ? <MessageCircle className="h-4 w-4 text-muted-foreground" />
                      : <Instagram className="h-4 w-4 text-muted-foreground" />}
                    <span className="truncate">{channel.name}</span>
                    <span className="truncate text-xs text-muted-foreground">{channelSubtitle(channel)}</span>
                  </span>
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}

        <div className="ml-auto flex items-center gap-2">
          <Button
            size="sm"
            className="h-9"
            onClick={() => openDialog()}
            disabled={!canSend || !templates.some((template) => template.status === "APPROVED")}
          >
            <Plus className="mr-2 h-4 w-4" />
            Nueva difusión
          </Button>
        </div>
      </header>

      <div className="flex-1 px-4 py-6 md:px-6 lg:px-8">
        <dl className="flex flex-wrap items-stretch gap-y-4 divide-border sm:divide-x">
          {stats.map((stat) => (
            <div key={stat.label} className="min-w-32 flex-1 pr-6 sm:not-first:pl-6">
              <dt className="text-xs font-medium tracking-wide text-muted-foreground">{stat.label}</dt>
              <dd className="mt-0.5 text-2xl font-semibold tabular-nums tracking-tight">{stat.value}</dd>
            </div>
          ))}
        </dl>

        <section className="mt-10">
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <h2 className="text-base font-semibold">Historial de difusiones</h2>
            <p className="text-sm text-muted-foreground">Campañas enviadas y programadas desde el CRM.</p>
          </div>

          <div className="mt-3 overflow-x-auto rounded-lg border border-border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Nombre difusión</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead>Contactos</TableHead>
                  <TableHead className="text-right">Enviado</TableHead>
                  <TableHead className="text-right">Error</TableHead>
                  <TableHead className="text-right">Gasto</TableHead>
                  <TableHead>Fecha / hora</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {campaigns.map((campaign) => {
                  const state = campaignStatus[campaign.status]
                  const progress = campaign.audience_count ? ((campaign.sent_count + campaign.error_count) / campaign.audience_count) * 100 : 0
                  return (
                    <TableRow key={campaign.id}>
                      <TableCell>
                        <p className="font-medium">{campaign.name}</p>
                        <p className="text-xs text-muted-foreground">{campaign.template.name} · {campaign.channel.name}</p>
                      </TableCell>
                      <TableCell><Badge className={cn("border-0", state.className)}>{state.label}</Badge></TableCell>
                      <TableCell>
                        <div className="flex min-w-28 items-center gap-2">
                          <Users className="h-3.5 w-3.5 text-muted-foreground" />
                          <span className="tabular-nums">{campaign.audience_count.toLocaleString("es-AR")}</span>
                          <Progress value={progress} className="h-1.5 w-12" />
                        </div>
                      </TableCell>
                      <TableCell className="text-right font-medium tabular-nums text-emerald-600">{campaign.sent_count.toLocaleString("es-AR")}</TableCell>
                      <TableCell className={cn("text-right tabular-nums", campaign.error_count ? "font-medium text-red-600" : "text-muted-foreground")}>{campaign.error_count.toLocaleString("es-AR")}</TableCell>
                      <TableCell className="text-right tabular-nums">{currency.format(["scheduled", "processing"].includes(campaign.status) ? campaign.estimated_cost_usd : campaign.actual_cost_usd)}</TableCell>
                      <TableCell className="text-sm text-muted-foreground">{dateTime.format(new Date(campaign.scheduled_at))}</TableCell>
                    </TableRow>
                  )
                })}
                {campaigns.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={7} className="h-32 text-center text-muted-foreground">
                      Todavía no hay difusiones. La primera puede salir hoy.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>
        </section>

        <section className="mt-12">
          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div className="flex flex-wrap items-baseline gap-x-3">
              <h2 className="text-base font-semibold">Plantillas de Meta</h2>
              <p className="text-sm text-muted-foreground">Estado de aprobación para este canal.</p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <div className="relative min-w-52 flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar plantilla" className="h-9 pl-9" />
              </div>
              <select
                value={stateFilter}
                onChange={(event) => setStateFilter(event.target.value)}
                className="h-9 rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring"
              >
                {Object.entries(templateStateLabel).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
              {canSyncTemplate && (
                <Button variant="outline" size="sm" className="h-9" onClick={handleSync} disabled={syncing || selectedChannel?.type !== ChannelType.WHATSAPP}>
                  <RefreshCw className={cn("mr-2 h-4 w-4", syncing && "animate-spin")} />
                  Sincronizar
                </Button>
              )}
              {canCreateTemplate && (
                <Button size="sm" className="h-9" onClick={() => setNewTemplateOpen(true)} disabled={selectedChannel?.type !== ChannelType.WHATSAPP}>
                  <Plus className="mr-2 h-4 w-4" />
                  Crear plantilla
                </Button>
              )}
            </div>
          </div>

          <div className="mt-3 overflow-hidden rounded-lg border border-border">
            {connectedChannels.length === 0 ? (
              <div className="px-6 py-14 text-center">
                <h3 className="font-semibold">Todavía no hay canales conectados</h3>
                <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                  Conectá WhatsApp o Instagram desde Configuración para empezar a difundir.
                </p>
                <Button variant="outline" size="sm" className="mt-4 h-9" asChild>
                  <Link href="/configuracion#integrations">
                    Conectar un canal
                    <ArrowUpRight className="ml-2 h-4 w-4" />
                  </Link>
                </Button>
              </div>
            ) : selectedChannel?.type === ChannelType.INSTAGRAM ? (
              <div className="px-6 py-14 text-center">
                <Instagram className="mx-auto mb-3 h-8 w-8 text-muted-foreground" />
                <h3 className="font-semibold">Difusiones de Instagram en preparación</h3>
                <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                  El canal ya está visible; el envío masivo se habilita cuando Meta lo admita en este flujo.
                </p>
              </div>
            ) : templatesLoading ? (
              <div className="flex items-center justify-center gap-2 py-14 text-sm text-muted-foreground">
                <Loader2 className="h-4 w-4 animate-spin" />
                Cargando plantillas…
              </div>
            ) : (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Referencia</TableHead>
                      <TableHead>Nombre</TableHead>
                      <TableHead>Estado</TableHead>
                      <TableHead>Creación</TableHead>
                      <TableHead className="text-right" />
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {visibleTemplates.map((template) => {
                      const state = templateStatus[template.status] ?? { label: template.status, className: "bg-muted text-muted-foreground" }
                      return (
                        <TableRow key={template.id}>
                          <TableCell className="font-mono text-xs text-muted-foreground">#{template.external_id || template.id}</TableCell>
                          <TableCell>
                            <p className="font-medium">{template.name}</p>
                            <p className="text-xs text-muted-foreground">{template.category} · {template.language}</p>
                          </TableCell>
                          <TableCell><Badge variant="outline" className={state.className}>{state.label}</Badge></TableCell>
                          <TableCell className="text-sm text-muted-foreground">{dateTime.format(new Date(template.created_at))}</TableCell>
                          <TableCell className="text-right">
                            <div className="flex items-center justify-end gap-1">
                              <Button size="sm" variant="ghost" disabled={!canSend || template.status !== "APPROVED"} onClick={() => openDialog(template.id)}>
                                Usar <ArrowUpRight className="ml-1 h-3.5 w-3.5" />
                              </Button>
                              {canDeleteTemplate && (
                                <Button
                                  variant="ghost"
                                  size="icon"
                                  className="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                  onClick={() => setDeleteTarget(template)}
                                  disabled={deletingTemplateId === template.id}
                                  aria-label={`Eliminar plantilla ${template.name} (${template.language})`}
                                  title="Eliminar plantilla"
                                >
                                  {deletingTemplateId === template.id ? <Loader2 className="size-4 animate-spin" /> : <Trash2 className="size-4" />}
                                </Button>
                              )}
                            </div>
                          </TableCell>
                        </TableRow>
                      )
                    })}
                    {visibleTemplates.length === 0 && (
                      <TableRow>
                        <TableCell colSpan={5} className="h-28 text-center text-muted-foreground">
                          {query.trim() ? "Ninguna plantilla coincide con la búsqueda." : "No hay plantillas para mostrar."}
                        </TableCell>
                      </TableRow>
                    )}
                  </TableBody>
                </Table>
              </div>
            )}
          </div>
        </section>
      </div>

      <AlertDialog
        open={deleteTarget !== null}
        onOpenChange={(openState) => !openState && deletingTemplateId === null && setDeleteTarget(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>¿Eliminar esta plantilla permanentemente?</AlertDialogTitle>
            <AlertDialogDescription>
              Se eliminará <strong className="font-medium text-foreground">{deleteTarget?.name}</strong> ({deleteTarget?.language}) de Meta y del CRM. Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deletingTemplateId !== null}>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              onClick={(event) => {
                event.preventDefault()
                void handleDeleteTemplate()
              }}
              disabled={deletingTemplateId !== null}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deletingTemplateId !== null && <Loader2 className="mr-2 size-4 animate-spin" />}
              Eliminar de Meta y del CRM
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {canCreateTemplate && selectedChannel?.type === ChannelType.WHATSAPP && (
        <NewTemplateDialog
          open={newTemplateOpen}
          onOpenChange={setNewTemplateOpen}
          channelId={selectedChannel.id}
          onCreated={async () => {
            setTemplates(await getManagedTemplates(selectedChannel.id))
          }}
        />
      )}

      {canSend && selectedChannel?.type === ChannelType.WHATSAPP && (
        <NewBroadcastDialog
          open={dialogOpen}
          onOpenChange={setDialogOpen}
          channel={selectedChannel}
          templates={templates}
          initialTemplateId={initialTemplateId}
          stages={stages}
          tags={tags}
          fields={fields}
          onCreated={async () => { await loadCampaigns(); setDialogOpen(false) }}
        />
      )}
    </>
  )
}
