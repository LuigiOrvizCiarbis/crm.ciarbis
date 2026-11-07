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
import { StatStrip } from "@/components/ui/stat-strip"
import { DataTable, type DataTableColumn } from "@/components/ui/data-table"
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

/**
 * Estados de plantilla de Meta. Solo APPROVED se puede enviar: PAUSED y
 * DISABLED los aplica Meta por feedback negativo o baja tasa de lectura, y
 * bloquean el envío hasta que se resuelvan.
 *
 * `hint` explica qué hacer cuando el estado no es autoexplicativo.
 *
 * @see https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/overview
 */
const templateStatus: Record<string, { label: string; className: string; hint?: string }> = {
  APPROVED: { label: "Aprobada", className: "border-success/30 bg-success/10 text-success" },
  PENDING: { label: "En revisión", className: "border-warning/30 bg-warning/10 text-warning" },
  REJECTED: { label: "Rechazada", className: "border-destructive/30 bg-destructive/10 text-destructive" },
  PAUSED: {
    label: "Pausada",
    className: "border-warning/30 bg-warning/10 text-warning",
    hint: "Meta la pausó por feedback negativo. Se reactiva sola con el tiempo.",
  },
  DISABLED: {
    label: "Deshabilitada",
    className: "border-destructive/30 bg-destructive/10 text-destructive",
    hint: "Meta la deshabilitó de forma permanente. Creá una nueva.",
  },
  LIMIT_EXCEEDED: {
    label: "Límite excedido",
    className: "border-warning/30 bg-warning/10 text-warning",
    hint: "Se alcanzó el máximo de envíos de esta plantilla.",
  },
  IN_APPEAL: {
    label: "En apelación",
    className: "border-info/30 bg-info/10 text-info",
    hint: "Meta está revisando la apelación.",
  },
  PENDING_DELETION: { label: "Eliminación pendiente", className: "bg-muted text-muted-foreground" },
  DELETED: { label: "Eliminada", className: "bg-muted text-muted-foreground" },
  UNKNOWN: { label: "Estado desconocido", className: "bg-muted text-muted-foreground" },
}

const campaignStatus: Record<string, { label: string; className: string }> = {
  scheduled: { label: "Programada", className: "bg-info/10 text-info" },
  processing: { label: "En curso", className: "bg-warning/10 text-warning" },
  completed: { label: "Completada", className: "bg-success/10 text-success" },
  partial: { label: "Parcial", className: "bg-warning/10 text-warning" },
  failed: { label: "Con errores", className: "bg-destructive/10 text-destructive" },
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

const campaignProgress = (campaign: BroadcastCampaign) =>
  campaign.audience_count ? ((campaign.sent_count + campaign.error_count) / campaign.audience_count) * 100 : 0

// Mientras la campaña no terminó se muestra el costo estimado; después, el real.
const campaignCost = (campaign: BroadcastCampaign) =>
  currency.format(["scheduled", "processing"].includes(campaign.status) ? campaign.estimated_cost_usd : campaign.actual_cost_usd)

const historyColumns: DataTableColumn<BroadcastCampaign>[] = [
  {
    id: "name",
    header: "Nombre de la difusión",
    rowHeader: true,
    cell: (campaign) => {
      const summary = (
        <>
          <p className="font-medium">{campaign.name}</p>
          <p className="text-xs text-muted-foreground">{campaign.template.name} · {campaign.channel.name}</p>
        </>
      )
      return campaign.results_tracking_version === 1 ? (
        <Link href={`/difusiones/${campaign.id}`} className="block rounded-sm outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50">
          {summary}
        </Link>
      ) : (
        summary
      )
    },
  },
  {
    id: "status",
    header: "Estado",
    cell: (campaign) => {
      const state = campaignStatus[campaign.status]
      return <Badge className={cn("border-0", state.className)}>{state.label}</Badge>
    },
  },
  {
    id: "audience",
    header: "Contactos",
    cell: (campaign) => (
      <div className="flex min-w-28 items-center gap-2">
        <Users className="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
        <span className="tabular-nums">{campaign.audience_count.toLocaleString("es-AR")}</span>
        <Progress value={campaignProgress(campaign)} className="h-1.5 w-12" aria-label="Avance del envío" />
      </div>
    ),
  },
  {
    id: "sent",
    header: "Enviado",
    align: "right",
    className: "font-medium tabular-nums text-success",
    cell: (campaign) => campaign.sent_count.toLocaleString("es-AR"),
  },
  {
    id: "errors",
    header: "Error",
    align: "right",
    className: (campaign) => cn("tabular-nums", campaign.error_count ? "font-medium text-destructive" : "text-muted-foreground"),
    cell: (campaign) => campaign.error_count.toLocaleString("es-AR"),
  },
  { id: "cost", header: "Gasto", align: "right", className: "tabular-nums", cell: campaignCost },
  {
    id: "scheduled_at",
    header: "Fecha y hora",
    className: "text-sm tabular-nums text-muted-foreground",
    cell: (campaign) => dateTime.format(new Date(campaign.scheduled_at)),
  },
]

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
  const [loadError, setLoadError] = useState<string | null>(null)
  const [reloadKey, setReloadKey] = useState(0)
  const [templatesError, setTemplatesError] = useState<string | null>(null)
  const [templatesReloadKey, setTemplatesReloadKey] = useState(0)

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
      .catch((error) => !cancelled && setLoadError(error instanceof Error ? error.message : "Revisá tu conexión e intentá de nuevo."))
      .finally(() => !cancelled && setLoading(false))
    return () => { cancelled = true }
  }, [reloadKey])

  const retryLoad = () => {
    setLoadError(null)
    setLoading(true)
    setReloadKey((key) => key + 1)
  }

  const selectedChannel = channels.find((channel) => channel.id === channelId) ?? null

  useEffect(() => {
    if (!selectedChannel || selectedChannel.type !== ChannelType.WHATSAPP || !selectedChannel.whatsapp_config) {
      setTemplates([])
      setTemplatesError(null)
      return
    }
    let cancelled = false
    setTemplatesLoading(true)
    setTemplatesError(null)
    getManagedTemplates(selectedChannel.id)
      .then((items) => !cancelled && setTemplates(items))
      .catch((error) => !cancelled && setTemplatesError(error instanceof Error ? error.message : "Revisá tu conexión e intentá de nuevo."))
      .finally(() => !cancelled && setTemplatesLoading(false))
    return () => { cancelled = true }
  }, [selectedChannel, templatesReloadKey])

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

  // Mismas acciones en la tabla (desktop) y en la lista de teléfonos; en táctil crecen a 40px.
  const renderTemplateActions = (template: WhatsAppTemplate) => (
    <div className="flex items-center justify-end gap-1">
      <Button size="sm" variant="ghost" disabled={!canSend || template.status !== "APPROVED"} onClick={() => openDialog(template.id)}>
        Usar <ArrowUpRight className="ml-1 h-3.5 w-3.5" />
      </Button>
      {canDeleteTemplate && (
        <Button
          variant="ghost"
          size="icon"
          className="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive pointer-coarse:size-10"
          onClick={() => setDeleteTarget(template)}
          disabled={deletingTemplateId === template.id}
          aria-label={`Eliminar plantilla ${template.name} (${template.language})`}
          title="Eliminar plantilla"
        >
          {deletingTemplateId === template.id ? <Loader2 className="size-4 animate-spin" /> : <Trash2 className="size-4" />}
        </Button>
      )}
    </div>
  )

  const templateColumns: DataTableColumn<WhatsAppTemplate>[] = [
    {
      id: "reference",
      header: "Referencia",
      className: "font-mono text-xs text-muted-foreground",
      cell: (template) => `#${template.external_id || template.id}`,
    },
    {
      id: "name",
      header: "Nombre",
      rowHeader: true,
      cell: (template) => (
        <>
          <p className="font-medium">{template.name}</p>
          <p className="text-xs text-muted-foreground">{template.category} · {template.language}</p>
        </>
      ),
    },
    {
      id: "status",
      header: "Estado",
      cell: (template) => {
        const state = templateStatus[template.status] ?? { label: template.status, className: "bg-muted text-muted-foreground" }
        return (
          <>
            <Badge variant="outline" className={state.className}>{state.label}</Badge>
            {state.hint && <p className="mt-1 max-w-56 text-xs leading-4 text-muted-foreground">{state.hint}</p>}
          </>
        )
      },
    },
    {
      id: "created_at",
      header: "Creación",
      className: "text-sm tabular-nums text-muted-foreground",
      cell: (template) => dateTime.format(new Date(template.created_at)),
    },
    { id: "actions", header: "", headerLabel: "Acciones", align: "right", cell: renderTemplateActions },
  ]

  if (loading) {
    return (
      <>
        <div className="sticky top-0 z-40 flex h-[75px] shrink-0 items-center gap-4 border-b border-border bg-background px-4 md:px-6 lg:px-8">
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

  if (loadError) {
    return (
      <>
        <header className="sticky top-0 z-40 flex h-[75px] shrink-0 items-center border-b border-border bg-background px-4 md:px-6 lg:px-8">
          <h1 className="text-xl font-semibold tracking-tight">Difusiones</h1>
        </header>
        <div role="alert" className="flex flex-col items-center gap-3 px-4 py-20 text-center">
          <p className="font-medium text-destructive">No se pudieron cargar las difusiones.</p>
          <p className="max-w-md text-sm text-muted-foreground">{loadError}</p>
          <Button variant="outline" size="sm" onClick={retryLoad}>
            <RefreshCw className="mr-2 h-4 w-4" />
            Reintentar
          </Button>
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

  const templatesEmptyMessage = query.trim() || stateFilter !== "ALL"
    ? "Ninguna plantilla coincide con los filtros."
    : canCreateTemplate || canSyncTemplate
      ? "Todavía no hay plantillas en este canal. Creá una o sincronizá las de Meta."
      : "Todavía no hay plantillas en este canal."

  return (
    <>
      <header className="sticky top-0 z-40 flex h-[75px] shrink-0 items-center gap-3 border-b border-border bg-background/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/60 md:px-6 lg:px-8">
        <h1 className="shrink-0 text-xl font-semibold tracking-tight">Difusiones</h1>

        {connectedChannels.length > 0 && (
          <Select
            value={channelId ? String(channelId) : undefined}
            onValueChange={(value) => setChannelId(Number(value))}
          >
            <SelectTrigger className="h-10 min-w-0 flex-1 md:h-9 md:w-[210px] md:flex-initial" aria-label="Canal de difusión">
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

        <div className="ml-auto flex shrink-0 items-center gap-2">
          {/* En teléfonos el botón queda en ícono para que el selector de canal no se corte. */}
          <Button
            size="sm"
            className="h-9 max-sm:size-10 max-sm:p-0"
            onClick={() => openDialog()}
            disabled={!canSend || !templates.some((template) => template.status === "APPROVED")}
            aria-label="Nueva difusión"
          >
            <Plus className="h-4 w-4 sm:mr-2" />
            <span className="hidden sm:inline">Nueva difusión</span>
          </Button>
        </div>
      </header>

      <div className="flex-1 px-4 py-6 md:px-6 lg:px-8">
        <StatStrip stats={stats} />

        <section className="mt-10">
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <h2 className="text-base font-semibold">Historial de difusiones</h2>
            <p className="text-sm text-muted-foreground">Campañas enviadas y programadas desde el CRM.</p>
          </div>

          {/* Teléfonos: lista de tarjetas; la tabla de siete columnas no entra en 390px. */}
          <ul className="mt-3 divide-y divide-border rounded-lg border border-border md:hidden">
            {campaigns.map((campaign) => {
              const state = campaignStatus[campaign.status]
              const body = (
                <>
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="font-medium [overflow-wrap:anywhere]">{campaign.name}</p>
                      <p className="text-xs text-muted-foreground [overflow-wrap:anywhere]">{campaign.template.name} · {campaign.channel.name}</p>
                    </div>
                    <Badge className={cn("shrink-0 border-0", state.className)}>{state.label}</Badge>
                  </div>
                  <div className="mt-3 flex items-center gap-2 text-sm">
                    <Users className="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span className="tabular-nums">{campaign.audience_count.toLocaleString("es-AR")} contactos</span>
                    <Progress value={campaignProgress(campaign)} className="ml-auto h-1.5 w-20" aria-label="Avance del envío" />
                  </div>
                  <dl className="mt-3 grid grid-cols-3 gap-2 text-sm">
                    <div>
                      <dt className="text-xs text-muted-foreground">Enviado</dt>
                      <dd className="font-medium tabular-nums text-success">{campaign.sent_count.toLocaleString("es-AR")}</dd>
                    </div>
                    <div>
                      <dt className="text-xs text-muted-foreground">Error</dt>
                      <dd className={cn("tabular-nums", campaign.error_count ? "font-medium text-destructive" : "text-muted-foreground")}>{campaign.error_count.toLocaleString("es-AR")}</dd>
                    </div>
                    <div>
                      <dt className="text-xs text-muted-foreground">Gasto</dt>
                      <dd className="tabular-nums">{campaignCost(campaign)}</dd>
                    </div>
                  </dl>
                  <p className="mt-2 text-xs tabular-nums text-muted-foreground">{dateTime.format(new Date(campaign.scheduled_at))}</p>
                </>
              )
              return (
                <li key={campaign.id}>
                  {campaign.results_tracking_version === 1 ? (
                    <Link
                      href={`/difusiones/${campaign.id}`}
                      className="block px-4 py-3 outline-none transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 active:bg-accent"
                    >
                      {body}
                    </Link>
                  ) : (
                    <div className="px-4 py-3">{body}</div>
                  )}
                </li>
              )
            })}
            {campaigns.length === 0 && (
              <li className="px-4 py-10 text-center text-sm text-muted-foreground">
                Todavía no hay difusiones. La primera puede salir hoy.
              </li>
            )}
          </ul>

          <DataTable
            className="mt-3 hidden md:block"
            columns={historyColumns}
            data={campaigns}
            getRowId={(campaign) => String(campaign.id)}
            emptyLabel="Todavía no hay difusiones. La primera puede salir hoy."
          />
        </section>

        <section className="mt-12">
          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div className="flex flex-wrap items-baseline gap-x-3">
              <h2 className="text-base font-semibold">Plantillas de Meta</h2>
              <p className="text-sm text-muted-foreground">Estado de aprobación para este canal.</p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <div className="relative min-w-52 flex-1 max-md:basis-full">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar plantilla" aria-label="Buscar plantilla" className="h-9 pl-9 max-md:h-11" />
              </div>
              <select
                value={stateFilter}
                onChange={(event) => setStateFilter(event.target.value)}
                aria-label="Estado de la plantilla"
                className="h-9 rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring max-md:h-10 max-md:flex-1 max-md:text-base"
              >
                {Object.entries(templateStateLabel).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
              {canSyncTemplate && (
                <Button variant="outline" size="sm" className="h-9 max-md:h-10" onClick={handleSync} disabled={syncing || selectedChannel?.type !== ChannelType.WHATSAPP}>
                  <RefreshCw className={cn("mr-2 h-4 w-4", syncing && "animate-spin")} />
                  Sincronizar
                </Button>
              )}
              {canCreateTemplate && (
                <Button size="sm" className="h-9 max-md:h-10" onClick={() => setNewTemplateOpen(true)} disabled={selectedChannel?.type !== ChannelType.WHATSAPP}>
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
            ) : templatesError ? (
              <div role="alert" className="flex flex-col items-center gap-3 px-6 py-14 text-center">
                <p className="text-sm font-medium text-destructive">No se pudieron cargar las plantillas.</p>
                <p className="max-w-md text-sm text-muted-foreground">{templatesError}</p>
                <Button variant="outline" size="sm" onClick={() => setTemplatesReloadKey((key) => key + 1)}>
                  <RefreshCw className="mr-2 h-4 w-4" />
                  Reintentar
                </Button>
              </div>
            ) : (
              <>
              <ul className="divide-y divide-border md:hidden">
                {visibleTemplates.map((template) => {
                  const state = templateStatus[template.status] ?? { label: template.status, className: "bg-muted text-muted-foreground" }
                  return (
                    <li key={template.id} className="px-4 py-3">
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                          <p className="font-medium [overflow-wrap:anywhere]">{template.name}</p>
                          <p className="text-xs text-muted-foreground">
                            {template.category} · {template.language} · <span className="font-mono">#{template.external_id || template.id}</span>
                          </p>
                        </div>
                        <Badge variant="outline" className={cn("shrink-0", state.className)}>{state.label}</Badge>
                      </div>
                      {state.hint && <p className="mt-1 text-xs leading-4 text-muted-foreground">{state.hint}</p>}
                      <div className="mt-2 flex items-center justify-between gap-2">
                        <span className="text-xs tabular-nums text-muted-foreground">{dateTime.format(new Date(template.created_at))}</span>
                        {renderTemplateActions(template)}
                      </div>
                    </li>
                  )
                })}
                {visibleTemplates.length === 0 && (
                  <li className="px-4 py-10 text-center text-sm text-muted-foreground">
                    {templatesEmptyMessage}
                  </li>
                )}
              </ul>
              <DataTable
                className="hidden md:block"
                variant="plain"
                columns={templateColumns}
                data={visibleTemplates}
                getRowId={(template) => String(template.id)}
                emptyLabel={templatesEmptyMessage}
              />
              </>
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
          // Recarga con el estado de carga/error de la tabla: un fallo acá no debe leerse como fallo al crear.
          onCreated={async () => setTemplatesReloadKey((key) => key + 1)}
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
          onCreated={async () => {
            // La difusión ya se creó: se cierra antes de refrescar para que un fallo del historial no invite a reenviarla.
            setDialogOpen(false)
            try {
              await loadCampaigns()
            } catch {
              addToast({ type: "error", title: "La difusión se creó, pero no se pudo actualizar el historial." })
            }
          }}
        />
      )}
    </>
  )
}
