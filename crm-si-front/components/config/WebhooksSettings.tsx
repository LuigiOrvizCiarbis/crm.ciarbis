"use client"

import { useEffect, useMemo, useRef, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"
import { Skeleton } from "@/components/ui/skeleton"
import { Badge } from "@/components/ui/badge"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog"
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import {
  Braces,
  CheckCircle2,
  Clock,
  Inbox,
  Loader2,
  Plus,
  Copy,
  KeyRound,
  Trash2,
  ListChecks,
  ShieldCheck,
  TriangleAlert,
  XCircle,
  type LucideIcon,
} from "lucide-react"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { usePermission } from "@/hooks/usePermission"
import {
  listWebhookEndpoints,
  getWebhookContactFields,
  createWebhookEndpoint,
  updateWebhookEndpoint,
  deleteWebhookEndpoint,
  rotateWebhookKey,
  listWebhookDeliveries,
  getWebhookDelivery,
  type WebhookEndpoint,
  type WebhookContactField,
  type WebhookDelivery,
  type WebhookDeliveryDetail,
} from "@/lib/api/webhooks"

function sampleValue(field: WebhookContactField): unknown {
  const firstChoice = field.options?.choices?.[0]

  switch (field.type) {
    case "number":
      return 100
    // El payload lleva el importe pelado: la divisa del campo no viaja en el
    // valor, vive en su definición.
    case "currency":
      return 1250000.5
    case "date":
      return "2026-07-15"
    case "boolean":
      return true
    case "select":
      return firstChoice ?? "opcion"
    case "multi_select":
      return firstChoice ? [firstChoice] : []
    case "email":
      return "dato@example.com"
    case "url":
      return "https://example.com"
    case "phone":
      return "+5491123456789"
    case "repeater":
      return [(field.options?.fields ?? []).filter((nested) => nested.is_active !== false).reduce<Record<string, unknown>>((row, nested) => {
        row[nested.key ?? nested.label] = nested.type === "number" ? 1 : nested.type === "currency" ? 1250000.5 : nested.type === "boolean" ? true : nested.type === "select" ? nested.options?.choices?.[0] ?? "opcion" : "ejemplo"
        return row
      }, {})]
    default:
      return "ejemplo"
  }
}

function buildContactsPayload(fields: WebhookContactField[]): string {
  const customData = Object.fromEntries(
    fields.map((field) => [field.key, sampleValue(field)]),
  )

  return JSON.stringify(
    {
    contacts: [
      {
        external_id: "cliente-123",
        name: "Ana Pérez",
        email: "ana@example.com",
        phone: "+5491123456789",
        ...(fields.length > 0 ? { custom_data: customData } : {}),
      },
    ],
    },
    null,
    2,
  )
}

// Estado codificado con ícono + etiqueta traducida, nunca solo color.
const STATUS_META: Record<
  WebhookDelivery["status"],
  {
    variant: "default" | "secondary" | "destructive" | "outline"
    icon: LucideIcon
    spin?: boolean
  }
> = {
  received: { variant: "secondary", icon: Inbox },
  queued: { variant: "secondary", icon: Clock },
  processing: { variant: "secondary", icon: Loader2, spin: true },
  processed: { variant: "default", icon: CheckCircle2 },
  partial: { variant: "outline", icon: TriangleAlert },
  failed: { variant: "destructive", icon: XCircle },
  rejected: { variant: "destructive", icon: XCircle },
}

// Estados no terminales de un delivery bulk: mientras haya uno, se refresca solo.
const ACTIVE_STATUSES: ReadonlyArray<WebhookDelivery["status"]> = [
  "queued",
  "processing",
]

function isActiveDelivery(status: WebhookDelivery["status"]): boolean {
  return ACTIVE_STATUSES.includes(status)
}

function DeliveryStatusBadge({ status }: { status: WebhookDelivery["status"] }) {
  const { t } = useTranslation()
  const meta = STATUS_META[status]
  const Icon = meta.icon

  return (
    <Badge variant={meta.variant} className="gap-1">
      <Icon
        className={`size-3 ${meta.spin ? "animate-spin motion-reduce:animate-none" : ""}`}
        aria-hidden="true"
      />
      {t(`settings.webhooks.statusLabels.${status}`)}
    </Badge>
  )
}

export function WebhooksSettings() {
  const { addToast } = useToast()
  const { t } = useTranslation()
  const canView = usePermission(["webhooks.view", "webhooks.manage"])
  const canManage = usePermission("webhooks.manage")

  const [loading, setLoading] = useState(true)
  const [endpoints, setEndpoints] = useState<WebhookEndpoint[]>([])
  const [customFields, setCustomFields] = useState<WebhookContactField[]>([])
  const payloadExample = useMemo(
    () => buildContactsPayload(customFields),
    [customFields],
  )

  // Modal de creación.
  const [createOpen, setCreateOpen] = useState(false)
  const [newName, setNewName] = useState("")
  const [newSecret, setNewSecret] = useState("")
  const [saving, setSaving] = useState(false)

  // Modal "una sola vez" con la key en plano (create + rotate).
  const [plainKey, setPlainKey] = useState<string | null>(null)

  // Drawer de deliveries.
  const [deliveriesFor, setDeliveriesFor] = useState<WebhookEndpoint | null>(null)

  // Confirmaciones destructivas (AlertDialog transversal de la app).
  const [deleteTarget, setDeleteTarget] = useState<WebhookEndpoint | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [rotateTarget, setRotateTarget] = useState<WebhookEndpoint | null>(null)
  const [rotating, setRotating] = useState(false)

  useEffect(() => {
    if (canView) void load()
    else setLoading(false)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canView])

  const load = async () => {
    setLoading(true)
    const [loadedEndpoints, loadedFields] = await Promise.all([
      listWebhookEndpoints(),
      getWebhookContactFields(),
    ])
    setEndpoints(loadedEndpoints)
    setCustomFields(loadedFields)
    setLoading(false)
  }

  const copy = async (value: string) => {
    try {
      await navigator.clipboard.writeText(value)
      addToast({ type: "success", title: t("settings.webhooks.copied") })
    } catch {
      addToast({ type: "error", title: t("common.error") })
    }
  }

  const handleCreate = async () => {
    if (!newName.trim()) return
    setSaving(true)
    const result = await createWebhookEndpoint({
      name: newName.trim(),
      ...(newSecret.trim() ? { signing_secret: newSecret.trim() } : {}),
    })
    setSaving(false)

    if (result.error || !result.data) {
      addToast({
        type: "error",
        title: t("common.error"),
        description: result.error,
      })
      return
    }

    setCreateOpen(false)
    setNewName("")
    setNewSecret("")
    setPlainKey(result.data.api_key ?? null)
    await load()
  }

  const handleToggle = async (endpoint: WebhookEndpoint, enabled: boolean) => {
    setEndpoints((prev) =>
      prev.map((e) => (e.id === endpoint.id ? { ...e, enabled } : e)),
    )
    const result = await updateWebhookEndpoint(endpoint.id, { enabled })
    if (result.error) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      await load()
    }
  }

  const handleRotate = async () => {
    if (!rotateTarget) return
    setRotating(true)
    const result = await rotateWebhookKey(rotateTarget.id)
    setRotating(false)
    setRotateTarget(null)
    if (result.error || !result.data) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }
    setPlainKey(result.data.api_key ?? null)
    await load()
  }

  const handleDelete = async () => {
    if (!deleteTarget) return
    setDeleting(true)
    const result = await deleteWebhookEndpoint(deleteTarget.id)
    setDeleting(false)
    setDeleteTarget(null)
    if (!result.ok) {
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }
    addToast({ type: "success", title: t("settings.webhooks.deleted") })
    await load()
  }

  if (!canView) return null

  return (
    <div className="space-y-4">
        {canManage && endpoints.length > 0 && (
          <div className="flex justify-end">
            <Button type="button" size="sm" onClick={() => setCreateOpen(true)}>
              <Plus className="size-4 mr-2" />
              {t("settings.webhooks.create")}
            </Button>
          </div>
        )}

        {loading ? (
          <div className="space-y-2">
            <Skeleton className="h-10 w-full rounded-md" />
            <Skeleton className="h-10 w-full rounded-md" />
            <Skeleton className="h-10 w-full rounded-md" />
          </div>
        ) : endpoints.length === 0 ? (
          <div className="rounded-lg border border-dashed py-10 px-6 text-center">
            <p className="text-sm font-medium">
              {t("settings.webhooks.emptyTitle")}
            </p>
            <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
              {t("settings.webhooks.empty")}
            </p>
            {canManage && (
              <Button
                type="button"
                size="sm"
                variant="outline"
                className="mt-4"
                onClick={() => setCreateOpen(true)}
              >
                <Plus className="size-4 mr-1" />
                {t("settings.webhooks.create")}
              </Button>
            )}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t("settings.webhooks.colName")}</TableHead>
                  <TableHead>{t("settings.webhooks.colUrl")}</TableHead>
                  <TableHead>{t("settings.webhooks.colKey")}</TableHead>
                  <TableHead>{t("settings.webhooks.colLastReceived")}</TableHead>
                  <TableHead>{t("settings.webhooks.colEnabled")}</TableHead>
                  <TableHead className="text-right">
                    {t("settings.webhooks.colActions")}
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {endpoints.map((endpoint) => (
                  <TableRow key={endpoint.id} className="group">
                    <TableCell className="font-medium">
                      <div className="flex items-center gap-2">
                        <span className="truncate">{endpoint.name}</span>
                        {endpoint.has_signing_secret && (
                          <Badge variant="secondary" className="shrink-0 gap-1">
                            <ShieldCheck className="size-3" aria-hidden="true" />
                            HMAC
                          </Badge>
                        )}
                      </div>
                    </TableCell>
                    <TableCell>
                      <button
                        type="button"
                        onClick={() => copy(endpoint.public_url)}
                        className="flex items-center gap-1 rounded-sm text-xs text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        aria-label={t("settings.webhooks.payload.copyUrl")}
                        title={endpoint.public_url}
                      >
                        <span className="max-w-[220px] truncate">
                          {endpoint.public_url}
                        </span>
                        <Copy className="size-3 shrink-0" aria-hidden="true" />
                      </button>
                    </TableCell>
                    <TableCell>
                      <code className="font-mono text-xs text-muted-foreground">
                        {endpoint.api_key_prefix}…
                      </code>
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground">
                      {endpoint.last_received_at
                        ? new Date(endpoint.last_received_at).toLocaleString()
                        : "—"}
                    </TableCell>
                    <TableCell>
                      <Switch
                        checked={endpoint.enabled}
                        disabled={!canManage}
                        onCheckedChange={(v) => handleToggle(endpoint, v)}
                      />
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center justify-end gap-0.5 opacity-60 transition-opacity group-hover:opacity-100 focus-within:opacity-100">
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          className="size-8"
                          aria-label={t("settings.webhooks.viewDeliveries")}
                          onClick={() => setDeliveriesFor(endpoint)}
                        >
                          <ListChecks className="size-4" />
                        </Button>
                        {canManage && (
                          <>
                            <Button
                              type="button"
                              variant="ghost"
                              size="icon"
                              className="size-8"
                              aria-label={t("settings.webhooks.rotateKey")}
                              onClick={() => setRotateTarget(endpoint)}
                            >
                              <KeyRound className="size-4" />
                            </Button>
                            <Button
                              type="button"
                              variant="ghost"
                              size="icon"
                              className="size-8 text-muted-foreground hover:text-destructive"
                              aria-label={t("settings.webhooks.delete")}
                              onClick={() => setDeleteTarget(endpoint)}
                            >
                              <Trash2 className="size-4" />
                            </Button>
                          </>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}

        <PayloadGuide
          payload={payloadExample}
          customFields={customFields}
          endpointUrl={endpoints[0]?.public_url ?? null}
          onCopyText={copy}
        />

      {/* Modal de creación */}
      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("settings.webhooks.createTitle")}</DialogTitle>
            <DialogDescription>
              {t("settings.webhooks.createHint")}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>{t("settings.webhooks.name")}</Label>
              <Input
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                placeholder={t("settings.webhooks.namePlaceholder")}
              />
            </div>
            <div className="space-y-2">
              <Label>{t("settings.webhooks.signingSecret")}</Label>
              <Input
                type="password"
                value={newSecret}
                onChange={(e) => setNewSecret(e.target.value)}
                placeholder={t("settings.webhooks.signingSecretPlaceholder")}
                autoComplete="off"
              />
              <p className="text-xs text-muted-foreground">
                {t("settings.webhooks.signingSecretHint")}
              </p>
            </div>
          </div>
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setCreateOpen(false)}
            >
              {t("common.cancel")}
            </Button>
            <Button
              type="button"
              onClick={handleCreate}
              disabled={saving || !newName.trim()}
            >
              {saving && (
                <Loader2 className="mr-2 size-4 animate-spin motion-reduce:animate-none" />
              )}
              {t("settings.webhooks.create")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Modal "una sola vez" con la key */}
      <Dialog open={plainKey !== null} onOpenChange={(o) => !o && setPlainKey(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("settings.webhooks.keyTitle")}</DialogTitle>
            <DialogDescription>
              {t("settings.webhooks.keyWarning")}
            </DialogDescription>
          </DialogHeader>
          <div className="flex items-center gap-2">
            <code className="min-w-0 flex-1 break-all rounded-md bg-muted px-3 py-2 font-mono text-sm">
              {plainKey}
            </code>
            <Button
              type="button"
              variant="outline"
              size="icon"
              className="shrink-0"
              aria-label={t("settings.webhooks.payload.copy")}
              onClick={() => plainKey && copy(plainKey)}
            >
              <Copy className="size-4" />
            </Button>
          </div>
          <DialogFooter>
            <Button type="button" onClick={() => setPlainKey(null)}>
              {t("settings.webhooks.keyDone")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {deliveriesFor && (
        <DeliveriesDialog
          endpoint={deliveriesFor}
          onClose={() => setDeliveriesFor(null)}
        />
      )}

      <AlertDialog
        open={rotateTarget !== null}
        onOpenChange={(open) => !open && !rotating && setRotateTarget(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t("settings.webhooks.rotateTitle")}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t("settings.webhooks.rotateConfirm", {
                name: rotateTarget?.name ?? "",
              })}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={rotating}>
              {t("common.cancel")}
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault()
                void handleRotate()
              }}
              disabled={rotating}
            >
              {rotating && (
                <Loader2 className="mr-2 size-4 animate-spin motion-reduce:animate-none" />
              )}
              {t("settings.webhooks.rotateKey")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && !deleting && setDeleteTarget(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t("settings.webhooks.deleteTitle")}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t("settings.webhooks.deleteConfirm", {
                name: deleteTarget?.name ?? "",
              })}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleting}>
              {t("common.cancel")}
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault()
                void handleDelete()
              }}
              disabled={deleting}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deleting && (
                <Loader2 className="mr-2 size-4 animate-spin motion-reduce:animate-none" />
              )}
              {t("settings.webhooks.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

function CodeBlock({
  label,
  meta,
  code,
  copyTitle,
  onCopy,
}: {
  label: string
  meta?: string
  code: string
  copyTitle: string
  onCopy: () => void
}) {
  return (
    <div className="overflow-hidden rounded-lg border border-border bg-muted/40">
      <div className="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
        <span className="font-mono text-xs font-medium text-foreground">
          {label}
        </span>
        <span className="flex min-w-0 items-center gap-2">
          {meta && (
            <span className="truncate font-mono text-[11px] text-muted-foreground">
              {meta}
            </span>
          )}
          <button
            type="button"
            title={copyTitle}
            onClick={onCopy}
            className="shrink-0 rounded-sm p-0.5 text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <Copy className="size-3.5" aria-hidden="true" />
            <span className="sr-only">{copyTitle}</span>
          </button>
        </span>
      </div>
      <pre className="overflow-x-auto p-3 font-mono text-xs leading-5 text-foreground">
        <code>{code}</code>
      </pre>
    </div>
  )
}

function VariantRow({
  name,
  url,
  detail,
  copyLabel,
  onCopy,
}: {
  name: string
  url: string
  detail: string
  copyLabel: string
  onCopy: () => void
}) {
  return (
    <div className="grid gap-1 py-3 sm:grid-cols-[12rem_minmax(0,1fr)] sm:gap-4">
      <dt className="text-sm font-medium text-foreground">{name}</dt>
      <dd className="min-w-0 space-y-1">
        <button
          type="button"
          onClick={onCopy}
          aria-label={copyLabel}
          title={url}
          className="flex max-w-full items-center gap-1.5 rounded-sm font-mono text-xs text-foreground transition-colors hover:text-foreground/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <span className="font-medium">POST</span>
          <span className="truncate text-muted-foreground">{url}</span>
          <Copy
            className="size-3 shrink-0 text-muted-foreground"
            aria-hidden="true"
          />
        </button>
        <p className="text-xs leading-5 text-muted-foreground">{detail}</p>
      </dd>
    </div>
  )
}

function PayloadGuide({
  payload,
  customFields,
  endpointUrl,
  onCopyText,
}: {
  payload: string
  customFields: WebhookContactField[]
  endpointUrl: string | null
  onCopyText: (value: string) => void
}) {
  const { t } = useTranslation()

  const baseUrl =
    endpointUrl ?? "https://TU-DOMINIO/api/incoming-webhooks/{slug}"
  const compactPayload = JSON.stringify(JSON.parse(payload))

  const curlSync = [
    `curl -X POST ${baseUrl} \\`,
    `  -H "X-Api-Key: whk_TU_API_KEY" \\`,
    `  -H "Content-Type: application/json" \\`,
    `  -d '${compactPayload}'`,
  ].join("\n")

  const responseSync = JSON.stringify(
    { delivery_id: 12, created: 1, updated: 0, failed: 0, errors: [] },
    null,
    2,
  )

  const curlBulk = [
    `curl -X POST ${baseUrl}/bulk \\`,
    `  -H "X-Api-Key: whk_TU_API_KEY" \\`,
    `  -H "Content-Type: application/json" \\`,
    `  -d '${compactPayload}'`,
  ].join("\n")

  const responseBulk = JSON.stringify(
    {
      delivery_id: 13,
      status: "queued",
      contacts_received: 1,
      status_url: `${baseUrl}/deliveries/13`,
    },
    null,
    2,
  )

  const curlStatus = [
    `curl ${baseUrl}/deliveries/13 \\`,
    `  -H "X-Api-Key: whk_TU_API_KEY"`,
  ].join("\n")

  const responseStatus = JSON.stringify(
    {
      delivery_id: 13,
      status: "processed",
      result: { created: 1, updated: 0, failed: 0, errors: [] },
      error: null,
    },
    null,
    2,
  )

  const fields = [
    {
      name: "contacts",
      description: t("settings.webhooks.payload.fields.contacts"),
      required: true,
    },
    {
      name: "external_id",
      description: t("settings.webhooks.payload.fields.externalId"),
      required: true,
    },
    {
      name: "name",
      description: t("settings.webhooks.payload.fields.name"),
      required: true,
    },
    {
      name: "email",
      description: t("settings.webhooks.payload.fields.email"),
      required: false,
    },
    {
      name: "phone",
      description: t("settings.webhooks.payload.fields.phone"),
      required: false,
    },
    {
      name: "custom_data",
      description: t("settings.webhooks.payload.fields.customData"),
      required: false,
    },
  ]

  return (
    <section
      aria-labelledby="webhook-payload-title"
      className="mt-8 border-t border-border pt-6"
    >
      <div className="space-y-1">
        <h4
          id="webhook-payload-title"
          className="text-base font-semibold text-foreground"
        >
          {t("settings.webhooks.payload.title")}
        </h4>
        <p className="max-w-[68ch] text-sm leading-6 text-muted-foreground">
          {t("settings.webhooks.payload.description")}
        </p>
      </div>

      <div className="mt-5">
        <h5 className="text-sm font-semibold text-foreground">
          {t("settings.webhooks.payload.variantsTitle")}
        </h5>
        <dl className="mt-3 divide-y divide-border border-y border-border">
          <VariantRow
            name={t("settings.webhooks.payload.variantSyncName")}
            url={baseUrl}
            detail={t("settings.webhooks.payload.variantSyncDetail")}
            copyLabel={t("settings.webhooks.payload.copyUrl")}
            onCopy={() => onCopyText(baseUrl)}
          />
          <VariantRow
            name={t("settings.webhooks.payload.variantBulkName")}
            url={`${baseUrl}/bulk`}
            detail={t("settings.webhooks.payload.variantBulkDetail")}
            copyLabel={t("settings.webhooks.payload.copyUrl")}
            onCopy={() => onCopyText(`${baseUrl}/bulk`)}
          />
        </dl>
      </div>

      <div className="mt-5">
        <h5 className="text-sm font-semibold text-foreground">
          {t("settings.webhooks.payload.authenticationTitle")}
        </h5>
        <p className="mt-1 max-w-[68ch] text-xs leading-5 text-muted-foreground">
          {t("settings.webhooks.payload.authenticationDescription")}
        </p>
        <dl className="mt-3 divide-y divide-border border-y border-border">
          <div className="grid gap-1 py-3 sm:grid-cols-[12rem_minmax(0,1fr)] sm:gap-4">
            <dt>
              <code className="inline-flex items-center gap-2 font-mono text-xs font-medium text-foreground">
                <KeyRound className="size-3.5" aria-hidden="true" />
                X-Api-Key
              </code>
            </dt>
            <dd className="text-xs leading-5 text-muted-foreground">
              {t("settings.webhooks.payload.apiKeyDescription")}
              <code className="ml-1 font-mono text-foreground">
                X-Api-Key: whk_TU_API_KEY
              </code>
            </dd>
          </div>
          <div className="grid gap-1 py-3 sm:grid-cols-[12rem_minmax(0,1fr)] sm:gap-4">
            <dt>
              <code className="inline-flex items-center gap-2 font-mono text-xs font-medium text-foreground">
                <Braces className="size-3.5" aria-hidden="true" />
                Content-Type
              </code>
            </dt>
            <dd className="text-xs leading-5 text-muted-foreground">
              {t("settings.webhooks.payload.contentTypeDescription")}
              <code className="ml-1 font-mono text-foreground">
                application/json
              </code>
            </dd>
          </div>
          <div className="grid gap-1 py-3 sm:grid-cols-[12rem_minmax(0,1fr)] sm:gap-4">
            <dt>
              <code className="inline-flex items-center gap-2 font-mono text-xs font-medium text-foreground">
                <ShieldCheck className="size-3.5" aria-hidden="true" />
                X-Signature-256
              </code>
            </dt>
            <dd className="text-xs leading-5 text-muted-foreground">
              {t("settings.webhooks.payload.signatureDescription")}
            </dd>
          </div>
        </dl>
        <p className="mt-2 text-xs leading-5 text-muted-foreground">
          {t("settings.webhooks.payload.apiKeyWarning")}
        </p>
      </div>

      <div className="mt-5 grid gap-6 lg:grid-cols-[minmax(0,1.25fr)_minmax(16rem,0.75fr)]">
        <CodeBlock
          label="JSON"
          meta="application/json"
          code={payload}
          copyTitle={t("settings.webhooks.payload.copy")}
          onCopy={() => onCopyText(payload)}
        />

        <div>
          <h5 className="text-sm font-semibold text-foreground">
            {t("settings.webhooks.payload.fieldsTitle")}
          </h5>
          <dl className="mt-2 divide-y divide-border border-y border-border">
            {fields.map((field) => (
              <div key={field.name} className="py-3">
                <dt className="flex items-center gap-2">
                  <code className="font-mono text-xs font-medium text-foreground">
                    {field.name}
                  </code>
                  <span className="text-[11px] text-muted-foreground">
                    {field.required
                      ? t("settings.webhooks.payload.required")
                      : t("settings.webhooks.payload.optional")}
                  </span>
                </dt>
                <dd className="mt-1 text-xs leading-5 text-muted-foreground">
                  {field.description}
                </dd>
              </div>
            ))}
          </dl>

          <h5 className="mt-6 text-sm font-semibold text-foreground">
            {t("settings.webhooks.payload.customFieldsTitle")}
          </h5>
          {customFields.length === 0 ? (
            <p className="mt-2 border-y border-border py-3 text-xs leading-5 text-muted-foreground">
              {t("settings.webhooks.payload.noCustomFields")}
            </p>
          ) : (
            <dl className="mt-2 divide-y divide-border border-y border-border">
              {customFields.map((field) => (
                <div key={field.key} className="py-3">
                  <dt className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <code className="font-mono text-xs font-medium text-foreground">
                      {field.key}
                    </code>
                    <span className="text-xs text-muted-foreground">
                      {field.label}
                    </span>
                    <span className="text-[11px] text-muted-foreground">
                      {t(`fields.typeLabels.${field.type}`)}
                      {" · "}
                      {field.is_required
                        ? t("settings.webhooks.payload.required")
                        : t("settings.webhooks.payload.optional")}
                    </span>
                  </dt>
                  {(field.options?.choices?.length ?? 0) > 0 && (
                    <dd className="mt-1 text-xs leading-5 text-muted-foreground">
                      {t("settings.webhooks.payload.allowedValues", {
                        values: field.options?.choices?.join(", ") ?? "",
                      })}
                    </dd>
                  )}
                </div>
              ))}
            </dl>
          )}
        </div>
      </div>

      <div className="mt-8">
        <h5 className="text-sm font-semibold text-foreground">
          {t("settings.webhooks.payload.exampleSyncTitle")}
        </h5>
        <p className="mt-1 max-w-[68ch] text-xs leading-5 text-muted-foreground">
          {t("settings.webhooks.payload.exampleSyncDescription")}
        </p>
        <div className="mt-3 grid gap-4 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,0.75fr)]">
          <CodeBlock
            label="POST"
            meta={baseUrl}
            code={curlSync}
            copyTitle={t("settings.webhooks.payload.copyCurl")}
            onCopy={() => onCopyText(curlSync)}
          />
          <CodeBlock
            label="200 OK"
            code={responseSync}
            copyTitle={t("settings.webhooks.payload.copyCurl")}
            onCopy={() => onCopyText(responseSync)}
          />
        </div>
      </div>

      <div className="mt-8">
        <h5 className="text-sm font-semibold text-foreground">
          {t("settings.webhooks.payload.exampleBulkTitle")}
        </h5>
        <p className="mt-1 max-w-[68ch] text-xs leading-5 text-muted-foreground">
          {t("settings.webhooks.payload.exampleBulkDescription")}
        </p>
        <div className="mt-3 grid gap-6 lg:grid-cols-2">
          <div className="space-y-2">
            <p className="text-xs font-medium text-foreground">
              {t("settings.webhooks.payload.exampleBulkSend")}
            </p>
            <CodeBlock
              label="POST"
              meta="/bulk"
              code={curlBulk}
              copyTitle={t("settings.webhooks.payload.copyCurl")}
              onCopy={() => onCopyText(curlBulk)}
            />
            <CodeBlock
              label="202 Accepted"
              code={responseBulk}
              copyTitle={t("settings.webhooks.payload.copyCurl")}
              onCopy={() => onCopyText(responseBulk)}
            />
          </div>
          <div className="space-y-2">
            <p className="text-xs font-medium text-foreground">
              {t("settings.webhooks.payload.exampleBulkPoll")}
            </p>
            <CodeBlock
              label="GET"
              meta="/deliveries/{id}"
              code={curlStatus}
              copyTitle={t("settings.webhooks.payload.copyCurl")}
              onCopy={() => onCopyText(curlStatus)}
            />
            <CodeBlock
              label="200 OK"
              code={responseStatus}
              copyTitle={t("settings.webhooks.payload.copyCurl")}
              onCopy={() => onCopyText(responseStatus)}
            />
          </div>
        </div>
      </div>

      <p className="mt-5 rounded-md border border-border bg-muted/30 px-3 py-2.5 text-xs leading-5 text-muted-foreground">
        <strong className="font-medium text-foreground">
          {t("settings.webhooks.payload.upsertTitle")}
        </strong>{" "}
        {t("settings.webhooks.payload.upsertDescription")}
      </p>
    </section>
  )
}

function DeliveriesDialog({
  endpoint,
  onClose,
}: {
  endpoint: WebhookEndpoint
  onClose: () => void
}) {
  const { t } = useTranslation()
  const [loading, setLoading] = useState(true)
  const [deliveries, setDeliveries] = useState<WebhookDelivery[]>([])
  const [detail, setDetail] = useState<WebhookDeliveryDetail | null>(null)

  useEffect(() => {
    void (async () => {
      setLoading(true)
      const page = await listWebhookDeliveries(endpoint.id)
      setDeliveries(page?.data ?? [])
      setLoading(false)
    })()
  }, [endpoint.id])

  // Mientras haya deliveries bulk sin terminar (queued/processing), la lista y
  // el detalle abierto se refrescan solos. Se corta cuando todo es terminal.
  const hasActive =
    deliveries.some((d) => isActiveDelivery(d.status)) ||
    (detail !== null && isActiveDelivery(detail.status))

  // El detalle se lee por ref dentro del intervalo: tenerlo como dependencia
  // recrearía el timer en cada refresco (setDetail cambia la referencia) y el
  // ciclo de 5s nunca llegaría a completarse.
  const detailRef = useRef(detail)
  detailRef.current = detail

  useEffect(() => {
    if (!hasActive) return
    const interval = setInterval(() => {
      void (async () => {
        const page = await listWebhookDeliveries(endpoint.id)
        if (page) setDeliveries(page.data)
        const current = detailRef.current
        if (current && isActiveDelivery(current.status)) {
          const fresh = await getWebhookDelivery(endpoint.id, current.id)
          if (fresh) setDetail(fresh)
        }
      })()
    }, 5000)
    return () => clearInterval(interval)
  }, [hasActive, endpoint.id])

  const openDetail = async (deliveryId: number) => {
    setDetail(await getWebhookDelivery(endpoint.id, deliveryId))
  }

  // Cuántos contactos trae un delivery bulk en curso: el payload completo tiene
  // contacts[]; si ya fue truncado por el job, queda contacts_count.
  const detailContactsCount = (() => {
    if (!detail || typeof detail.payload !== "object" || detail.payload === null)
      return null
    const payload = detail.payload as {
      contacts?: unknown
      contacts_count?: unknown
    }
    if (Array.isArray(payload.contacts)) return payload.contacts.length
    if (typeof payload.contacts_count === "number") return payload.contacts_count
    return null
  })()

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {t("settings.webhooks.deliveriesTitle", { name: endpoint.name })}
          </DialogTitle>
        </DialogHeader>
        {loading ? (
          <div className="flex justify-center py-6">
            <Loader2
              className="size-5 animate-spin motion-reduce:animate-none text-muted-foreground"
              aria-hidden="true"
            />
          </div>
        ) : deliveries.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">
            {t("settings.webhooks.deliveriesEmpty")}
          </p>
        ) : (
          <div className="max-h-[50vh] overflow-y-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t("settings.webhooks.colStatus")}</TableHead>
                  <TableHead>{t("settings.webhooks.colResult")}</TableHead>
                  <TableHead>{t("settings.webhooks.colDate")}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {deliveries.map((d) => (
                  <TableRow
                    key={d.id}
                    tabIndex={0}
                    aria-selected={detail?.id === d.id}
                    data-state={detail?.id === d.id ? "selected" : undefined}
                    className="cursor-pointer transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                    onClick={() => openDetail(d.id)}
                    onKeyDown={(e) => {
                      if (e.key === "Enter" || e.key === " ") {
                        e.preventDefault()
                        void openDetail(d.id)
                      }
                    }}
                  >
                    <TableCell>
                      <DeliveryStatusBadge status={d.status} />
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground">
                      {d.created === null && d.updated === null && d.failed === null
                        ? "—"
                        : `+${d.created ?? 0} / ~${d.updated ?? 0} / !${d.failed ?? 0}`}
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground">
                      {d.created_at
                        ? new Date(d.created_at).toLocaleString()
                        : "—"}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}

        {detail && (
          <div className="space-y-2 border-t pt-4">
            <Label>{t("settings.webhooks.detailPayload")}</Label>
            {isActiveDelivery(detail.status) ? (
              <p className="flex items-center gap-2 py-2 text-sm text-muted-foreground">
                <Loader2
                  className="size-4 shrink-0 animate-spin motion-reduce:animate-none"
                  aria-hidden="true"
                />
                {detailContactsCount !== null
                  ? t("settings.webhooks.processingDetailCount", {
                      count: detailContactsCount,
                    })
                  : t("settings.webhooks.processingDetail")}
              </p>
            ) : (
              <pre className="max-h-[30vh] overflow-auto rounded-md bg-muted p-3 text-xs">
                {JSON.stringify(
                  { result: detail.result, error: detail.error, payload: detail.payload },
                  null,
                  2,
                )}
              </pre>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
