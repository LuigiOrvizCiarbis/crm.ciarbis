"use client"

import { useCallback, useEffect, useState } from "react"
import { useRouter } from "next/navigation"
import {
  AlertTriangle,
  Archive,
  Code2,
  Copy,
  ExternalLink,
  FileUp,
  KeyRound,
  Link2,
  Loader2,
  MoreHorizontal,
  Pencil,
  Plus,
  RefreshCw,
  Send,
  ShieldCheck,
  Trash2,
} from "lucide-react"

import { SettingsBlock } from "@/components/config/SettingsBlock"
import { FlowFieldMappingDialog } from "@/components/config/flows/FlowFieldMappingDialog"
import { FlowJsonDialog } from "@/components/config/flows/FlowJsonDialog"
import { NewFlowDialog } from "@/components/config/flows/NewFlowDialog"
import { FLOW_STATUS_CLASS, isFlowEditable } from "@/components/config/flows/flow-labels"
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import { useToast } from "@/components/Toast"
import { ChannelType } from "@/data/enums"
import type { Channel, WhatsAppFlow, WhatsAppFlowEncryptionStatus } from "@/data/types"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { getChannels } from "@/lib/api/channels"
import {
  cloneFlow,
  deleteFlow,
  deprecateFlow,
  getFlowEncryption,
  getFlowPreview,
  getFlows,
  publishFlow,
  setupFlowEncryption,
  syncFlows,
} from "@/lib/api/whatsapp-flows"
import { cn } from "@/lib/utils"

type EditMode = "auto" | "json"

type Confirmation =
  | { kind: "edit"; flow: WhatsAppFlow; mode: EditMode }
  | { kind: "deprecate"; flow: WhatsAppFlow }
  | { kind: "delete"; flow: WhatsAppFlow }

/** `null` cerrado; `flowId: null` importa uno nuevo. */
type JsonDialogState = { flowId: number | null } | null

const CLONE_NAME_MAX = 200

export function WhatsAppFlowsCard() {
  const { t } = useTranslation()
  const { addToast } = useToast()
  const router = useRouter()
  const canManage = usePermission("flows.manage")

  const [channels, setChannels] = useState<Channel[]>([])
  const [channelId, setChannelId] = useState<number | null>(null)
  const [loadingChannels, setLoadingChannels] = useState(true)
  const [flows, setFlows] = useState<WhatsAppFlow[]>([])
  const [loadingFlows, setLoadingFlows] = useState(false)
  const [encryption, setEncryption] = useState<WhatsAppFlowEncryptionStatus | null>(null)
  const [loadingEncryption, setLoadingEncryption] = useState(false)
  const [settingUpEncryption, setSettingUpEncryption] = useState(false)
  const [syncing, setSyncing] = useState(false)
  const [busyFlowId, setBusyFlowId] = useState<number | null>(null)

  const [newOpen, setNewOpen] = useState(false)
  const [jsonDialog, setJsonDialog] = useState<JsonDialogState>(null)
  const [mappingFlow, setMappingFlow] = useState<WhatsAppFlow | null>(null)
  const [cloneTarget, setCloneTarget] = useState<WhatsAppFlow | null>(null)
  const [cloneName, setCloneName] = useState("")
  const [confirm, setConfirm] = useState<Confirmation | null>(null)

  const toastError = useCallback(
    (title: string, error: unknown) => {
      addToast({ type: "error", title, description: error instanceof Error ? error.message : undefined })
    },
    [addToast],
  )

  useEffect(() => {
    let cancelled = false
    getChannels()
      .then((list) => {
        if (cancelled) return
        const whatsapp = list.filter((channel) => channel.type === ChannelType.WHATSAPP)
        setChannels(whatsapp)
        // Al volver del constructor llega ?channel=<id> para no perder el canal elegido.
        const requested = Number(new URLSearchParams(window.location.search).get("channel"))
        const initial = whatsapp.find((channel) => channel.id === requested) ?? whatsapp[0]
        setChannelId(initial ? initial.id : null)
      })
      .catch((error) => {
        if (!cancelled) toastError(t("flows.settings.channelsError"), error)
      })
      .finally(() => {
        if (!cancelled) setLoadingChannels(false)
      })
    return () => {
      cancelled = true
    }
  }, [t, toastError])

  const loadFlows = useCallback(
    async (id: number) => {
      setLoadingFlows(true)
      try {
        setFlows(await getFlows(id))
      } catch (error) {
        toastError(t("flows.settings.loadError"), error)
      } finally {
        setLoadingFlows(false)
      }
    },
    [t, toastError],
  )

  useEffect(() => {
    if (channelId === null) {
      setFlows([])
      setEncryption(null)
      return
    }
    void loadFlows(channelId)
    let cancelled = false
    setLoadingEncryption(true)
    setEncryption(null)
    getFlowEncryption(channelId)
      .then((status) => {
        if (!cancelled) setEncryption(status)
      })
      .catch((error) => {
        if (!cancelled) toastError(t("flows.settings.encryption.loadError"), error)
      })
      .finally(() => {
        if (!cancelled) setLoadingEncryption(false)
      })
    return () => {
      cancelled = true
    }
  }, [channelId, loadFlows, t, toastError])

  const upsertFlow = (flow: WhatsAppFlow) => {
    setFlows((current) => {
      const exists = current.some((item) => item.id === flow.id)
      const next = exists ? current.map((item) => (item.id === flow.id ? flow : item)) : [...current, flow]
      return next.sort((a, b) => a.name.localeCompare(b.name))
    })
  }

  const handleSetupEncryption = async () => {
    if (channelId === null) return
    setSettingUpEncryption(true)
    try {
      setEncryption(await setupFlowEncryption(channelId))
      addToast({ type: "success", title: t("flows.settings.encryption.setupDone") })
    } catch (error) {
      toastError(t("flows.settings.encryption.setupError"), error)
    } finally {
      setSettingUpEncryption(false)
    }
  }

  const handleSync = async () => {
    if (channelId === null) return
    setSyncing(true)
    try {
      const { count } = await syncFlows(channelId)
      addToast({ type: "success", title: t("flows.settings.synced", { count }) })
      await loadFlows(channelId)
    } catch (error) {
      toastError(t("flows.settings.syncError"), error)
    } finally {
      setSyncing(false)
    }
  }

  const openEditor = (flow: WhatsAppFlow, mode: EditMode) => {
    if (mode === "auto" && flow.has_builder_definition) {
      router.push(`/configuracion/formularios/${flow.id}`)
      return
    }
    setJsonDialog({ flowId: flow.id })
  }

  const startEdit = (flow: WhatsAppFlow, mode: EditMode) => {
    if (flow.status === "PUBLISHED") setConfirm({ kind: "edit", flow, mode })
    else openEditor(flow, mode)
  }

  const handlePreview = async (flow: WhatsAppFlow) => {
    // La pestaña se abre en el click para que el navegador no la bloquee.
    const tab = window.open("", "_blank")
    setBusyFlowId(flow.id)
    try {
      const { preview_url } = await getFlowPreview(flow.id)
      if (tab) {
        tab.opener = null
        tab.location.href = preview_url
      } else {
        window.open(preview_url, "_blank", "noopener,noreferrer")
      }
    } catch (error) {
      tab?.close()
      toastError(t("flows.settings.previewError"), error)
    } finally {
      setBusyFlowId(null)
    }
  }

  const handlePublish = async (flow: WhatsAppFlow) => {
    setBusyFlowId(flow.id)
    try {
      const published = await publishFlow(flow.id)
      upsertFlow(published)
      addToast({ type: "success", title: t("flows.settings.published", { name: flow.name }) })
    } catch (error) {
      toastError(t("flows.settings.publishError"), error)
    } finally {
      setBusyFlowId(null)
    }
  }

  const handleClone = async () => {
    if (!cloneTarget) return
    const target = cloneTarget
    setBusyFlowId(target.id)
    try {
      const cloned = await cloneFlow(target.id, cloneName.trim())
      upsertFlow(cloned)
      addToast({ type: "success", title: t("flows.settings.cloned", { name: cloned.name }) })
      setCloneTarget(null)
    } catch (error) {
      toastError(t("flows.settings.cloneError"), error)
    } finally {
      setBusyFlowId(null)
    }
  }

  const handleConfirm = async () => {
    if (!confirm) return
    const { flow } = confirm
    if (confirm.kind === "edit") {
      setConfirm(null)
      openEditor(flow, confirm.mode)
      return
    }
    setBusyFlowId(flow.id)
    try {
      if (confirm.kind === "deprecate") {
        upsertFlow(await deprecateFlow(flow.id))
        addToast({ type: "success", title: t("flows.settings.deprecated", { name: flow.name }) })
      } else {
        await deleteFlow(flow.id)
        setFlows((current) => current.filter((item) => item.id !== flow.id))
        addToast({ type: "success", title: t("flows.settings.deleted", { name: flow.name }) })
      }
      setConfirm(null)
    } catch (error) {
      toastError(
        confirm.kind === "deprecate" ? t("flows.settings.deprecateError") : t("flows.settings.deleteError"),
        error,
      )
    } finally {
      setBusyFlowId(null)
    }
  }

  const copyEndpoint = async (value: string) => {
    try {
      await navigator.clipboard.writeText(value)
      addToast({ type: "success", title: t("flows.settings.encryption.copied") })
    } catch (error) {
      toastError(t("flows.settings.encryption.copyError"), error)
    }
  }

  const confirmBusy = confirm !== null && busyFlowId === confirm.flow.id

  return (
    <SettingsBlock
      title={t("settings.pages.flows.title")}
      description={t("flows.settings.description")}
      action={
        canManage && channelId !== null ? (
          <Button type="button" onClick={() => setNewOpen(true)}>
            <Plus className="size-4" />
            {t("flows.settings.newFlow")}
          </Button>
        ) : undefined
      }
    >
      {loadingChannels ? (
        <div className="space-y-3">
          <Skeleton className="h-9 w-64" />
          <Skeleton className="h-24 w-full" />
        </div>
      ) : channels.length === 0 ? (
        <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
          {t("flows.settings.noChannels")}
        </p>
      ) : (
        <div className="space-y-8">
          <div className="space-y-2">
            <Label htmlFor="flows-channel">{t("flows.settings.channel")}</Label>
            <Select
              value={channelId !== null ? String(channelId) : undefined}
              onValueChange={(value) => setChannelId(Number(value))}
            >
              <SelectTrigger id="flows-channel" className="w-full sm:w-80">
                <SelectValue placeholder={t("flows.settings.selectChannel")} />
              </SelectTrigger>
              <SelectContent>
                {channels.map((channel) => (
                  <SelectItem key={channel.id} value={String(channel.id)}>
                    {channel.name}
                    {channel.whatsapp_config?.display_phone_number
                      ? ` · ${channel.whatsapp_config.display_phone_number}`
                      : ""}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <section className="space-y-3 rounded-lg border p-4" aria-labelledby="flows-encryption-title">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="min-w-0 space-y-1">
                <h3 id="flows-encryption-title" className="flex items-center gap-2 text-sm font-semibold">
                  <KeyRound className="size-4 text-muted-foreground" aria-hidden />
                  {t("flows.settings.encryption.title")}
                </h3>
                <p className="max-w-[68ch] text-sm text-muted-foreground">
                  {t("flows.settings.encryption.description")}
                </p>
              </div>
              {canManage && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => void handleSetupEncryption()}
                  disabled={settingUpEncryption || loadingEncryption}
                >
                  {settingUpEncryption ? (
                    <Loader2 className="size-4 animate-spin motion-reduce:animate-none" />
                  ) : (
                    <ShieldCheck className="size-4" />
                  )}
                  {encryption?.configured
                    ? t("flows.settings.encryption.reupload")
                    : t("flows.settings.encryption.setup")}
                </Button>
              )}
            </div>

            {loadingEncryption ? (
              <Skeleton className="h-6 w-48" />
            ) : encryption ? (
              <div className="space-y-3">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge
                    className={cn(
                      encryption.configured
                        ? "border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-400"
                        : "border-transparent bg-amber-500/15 text-amber-700 dark:text-amber-400",
                    )}
                  >
                    {encryption.configured
                      ? t("flows.settings.encryption.configured")
                      : t("flows.settings.encryption.notConfigured")}
                  </Badge>
                  {encryption.signature_status && (
                    <Badge
                      variant="outline"
                      className={cn(encryption.signature_status !== "VALID" && "border-destructive/50 text-destructive")}
                    >
                      {encryption.signature_status === "VALID"
                        ? t("flows.settings.encryption.signatureValid")
                        : t("flows.settings.encryption.signatureInvalid", {
                            status: encryption.signature_status,
                          })}
                    </Badge>
                  )}
                  {encryption.uploaded_at && (
                    <span className="text-xs text-muted-foreground">
                      {t("flows.settings.encryption.uploadedAt", {
                        date: new Date(encryption.uploaded_at).toLocaleString(),
                      })}
                    </span>
                  )}
                </div>
                {encryption.endpoint_url && (
                  <div className="space-y-1">
                    <Label htmlFor="flows-endpoint-url" className="text-xs text-muted-foreground">
                      {t("flows.settings.encryption.endpointUrl")}
                    </Label>
                    <div className="flex items-center gap-2">
                      <Input
                        id="flows-endpoint-url"
                        readOnly
                        value={encryption.endpoint_url}
                        className="font-mono text-xs"
                        onFocus={(event) => event.currentTarget.select()}
                      />
                      <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="shrink-0"
                        aria-label={t("flows.settings.encryption.copy")}
                        onClick={() => void copyEndpoint(encryption.endpoint_url as string)}
                      >
                        <Copy className="size-4" />
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            ) : null}
          </section>

          <section className="space-y-3" aria-labelledby="flows-list-title">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h3 id="flows-list-title" className="text-sm font-semibold">
                {t("flows.settings.listTitle")}
              </h3>
              {canManage && (
                <div className="flex flex-wrap gap-2">
                  <Button type="button" variant="outline" size="sm" onClick={() => setJsonDialog({ flowId: null })}>
                    <FileUp className="size-4" />
                    {t("flows.settings.importJson")}
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => void handleSync()}
                    disabled={syncing}
                  >
                    <RefreshCw className={cn("size-4", syncing && "animate-spin motion-reduce:animate-none")} />
                    {t("flows.settings.sync")}
                  </Button>
                </div>
              )}
            </div>

            {loadingFlows ? (
              <div className="space-y-2">
                <Skeleton className="h-14 w-full" />
                <Skeleton className="h-14 w-full" />
              </div>
            ) : flows.length === 0 ? (
              <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                {t("flows.settings.empty")}
              </p>
            ) : (
              <ul className="divide-y rounded-lg border">
                {flows.map((flow) => {
                  const busy = busyFlowId === flow.id
                  const editable = isFlowEditable(flow.status)
                  const canDelete = flow.status === "DRAFT" && !flow.has_published_version
                  const canDeprecate = flow.status === "PUBLISHED" || flow.status === "THROTTLED"
                  return (
                    <li key={flow.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                      <div className="min-w-0 flex-1 space-y-1">
                        <p className="truncate text-sm font-medium">{flow.name}</p>
                        <div className="flex flex-wrap items-center gap-1.5">
                          <Badge className={FLOW_STATUS_CLASS[flow.status]}>
                            {t(`flows.settings.status.${flow.status}`)}
                          </Badge>
                          {flow.status === "DRAFT" && flow.has_published_version && (
                            <Badge variant="outline">{t("flows.settings.hasPublishedVersion")}</Badge>
                          )}
                          {flow.uses_endpoint && (
                            <Badge variant="outline">
                              <Link2 aria-hidden />
                              {t("flows.settings.usesEndpoint")}
                            </Badge>
                          )}
                          {flow.has_builder_definition && (
                            <Badge variant="secondary">{t("flows.settings.builderBadge")}</Badge>
                          )}
                          {flow.validation_errors.length > 0 && (
                            <Badge variant="outline" className="border-destructive/50 text-destructive">
                              <AlertTriangle aria-hidden />
                              {t("flows.settings.validationErrorsCount", { count: flow.validation_errors.length })}
                            </Badge>
                          )}
                        </div>
                      </div>

                      <div className="flex items-center gap-1">
                        {busy && <Loader2 className="size-4 animate-spin text-muted-foreground motion-reduce:animate-none" />}
                        {canManage && editable && (
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={busy}
                            onClick={() => startEdit(flow, "auto")}
                          >
                            <Pencil className="size-4" />
                            {t("flows.settings.actions.edit")}
                          </Button>
                        )}
                        {/* No modal: varias acciones abren un diálogo y Radix dejaría el body bloqueado. */}
                        <DropdownMenu modal={false}>
                          <DropdownMenuTrigger asChild>
                            <Button
                              type="button"
                              variant="ghost"
                              size="icon"
                              disabled={busy}
                              aria-label={t("flows.settings.actions.more", { name: flow.name })}
                            >
                              <MoreHorizontal className="size-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem onSelect={() => void handlePreview(flow)}>
                              <ExternalLink className="size-4" />
                              {t("flows.settings.actions.preview")}
                            </DropdownMenuItem>
                            {canManage && (
                              <>
                                {flow.status === "DRAFT" && (
                                  <DropdownMenuItem onSelect={() => void handlePublish(flow)}>
                                    <Send className="size-4" />
                                    {t("flows.settings.actions.publish")}
                                  </DropdownMenuItem>
                                )}
                                {editable && flow.has_builder_definition && (
                                  <DropdownMenuItem onSelect={() => startEdit(flow, "json")}>
                                    <Code2 className="size-4" />
                                    {t("flows.settings.actions.editJson")}
                                  </DropdownMenuItem>
                                )}
                                {!flow.has_builder_definition && (
                                  <DropdownMenuItem onSelect={() => setMappingFlow(flow)}>
                                    <Link2 className="size-4" />
                                    {t("flows.settings.actions.mapping")}
                                  </DropdownMenuItem>
                                )}
                                <DropdownMenuItem
                                  onSelect={() => {
                                    setCloneName(
                                      t("flows.settings.clone.defaultName", { name: flow.name }).slice(0, CLONE_NAME_MAX),
                                    )
                                    setCloneTarget(flow)
                                  }}
                                >
                                  <Copy className="size-4" />
                                  {t("flows.settings.actions.clone")}
                                </DropdownMenuItem>
                                {(canDeprecate || canDelete) && <DropdownMenuSeparator />}
                                {canDeprecate && (
                                  <DropdownMenuItem onSelect={() => setConfirm({ kind: "deprecate", flow })}>
                                    <Archive className="size-4" />
                                    {t("flows.settings.actions.deprecate")}
                                  </DropdownMenuItem>
                                )}
                                {canDelete && (
                                  <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => setConfirm({ kind: "delete", flow })}
                                  >
                                    <Trash2 className="size-4" />
                                    {t("flows.settings.actions.delete")}
                                  </DropdownMenuItem>
                                )}
                              </>
                            )}
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
                    </li>
                  )
                })}
              </ul>
            )}
          </section>
        </div>
      )}

      {channelId !== null && (
        <>
          <NewFlowDialog open={newOpen} onOpenChange={setNewOpen} channelId={channelId} />
          <FlowJsonDialog
            open={jsonDialog !== null}
            onOpenChange={(open) => !open && setJsonDialog(null)}
            channelId={channelId}
            flowId={jsonDialog?.flowId ?? null}
            onSaved={(flow) => {
              upsertFlow(flow)
              // Un import con errores de Meta sigue abierto, ya como edición.
              setJsonDialog((current) => (current ? { flowId: flow.id } : current))
            }}
          />
        </>
      )}

      <FlowFieldMappingDialog
        flow={mappingFlow}
        onOpenChange={(open) => !open && setMappingFlow(null)}
        onSaved={upsertFlow}
      />

      <Dialog
        open={cloneTarget !== null}
        onOpenChange={(open) => !open && busyFlowId === null && setCloneTarget(null)}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("flows.settings.clone.title")}</DialogTitle>
            <DialogDescription>{t("flows.settings.clone.description")}</DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="flow-clone-name">{t("flows.settings.clone.name")}</Label>
            <Input
              id="flow-clone-name"
              value={cloneName}
              maxLength={CLONE_NAME_MAX}
              onChange={(event) => setCloneName(event.target.value)}
            />
          </div>
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setCloneTarget(null)}
              disabled={busyFlowId !== null}
            >
              {t("common.cancel")}
            </Button>
            <Button
              type="button"
              onClick={() => void handleClone()}
              disabled={busyFlowId !== null || !cloneName.trim()}
            >
              {cloneTarget !== null && busyFlowId === cloneTarget.id && (
                <Loader2 className="mr-2 size-4 animate-spin motion-reduce:animate-none" />
              )}
              {t("flows.settings.actions.clone")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={confirm !== null} onOpenChange={(open) => !open && !confirmBusy && setConfirm(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {confirm ? t(`flows.settings.confirm.${confirm.kind}.title`, { name: confirm.flow.name }) : ""}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {confirm ? t(`flows.settings.confirm.${confirm.kind}.description`, { name: confirm.flow.name }) : ""}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={confirmBusy}>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              className={cn(
                confirm?.kind === "delete" && "bg-destructive text-white hover:bg-destructive/90",
              )}
              disabled={confirmBusy}
              onClick={(event) => {
                event.preventDefault()
                void handleConfirm()
              }}
            >
              {confirmBusy && <Loader2 className="mr-2 size-4 animate-spin motion-reduce:animate-none" />}
              {confirm ? t(`flows.settings.confirm.${confirm.kind}.action`) : ""}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </SettingsBlock>
  )
}
