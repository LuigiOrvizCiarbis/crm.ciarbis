"use client"

import { useCallback, useEffect, useMemo, useState, useSyncExternalStore, type ReactNode } from "react"
import { useRouter } from "next/navigation"
import { ArrowLeft, ChevronDown, ExternalLink, Loader2, Save, Send, TriangleAlert } from "lucide-react"

import { useToast } from "@/components/Toast"
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
import { Checkbox } from "@/components/ui/checkbox"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import type { WhatsAppFlow, WhatsAppFlowCategory, WhatsAppFlowValidationError } from "@/data/types"
import { useTranslation } from "@/hooks/useTranslation"
import { getChannels } from "@/lib/api/channels"
import {
  ApiValidationError,
  createFlow,
  getFlowEncryption,
  getFlowPreview,
  publishFlow,
  updateFlow,
} from "@/lib/api/whatsapp-flows"
import {
  EMPTY_ERRORS,
  definitionErrorsFromServer,
  firstScreenWithErrors,
  groupErrors,
  hasErrors,
  mergeErrors,
  type GroupedErrors,
} from "@/lib/flow-builder/errors"
import { NAME_MAX, isQuestionBlock } from "@/lib/flow-builder/limits"
import { newScreenId, usesEndpoint } from "@/lib/flow-builder/routing"
import type { FlowBlock, FlowScreen, LongTextBlock } from "@/lib/flow-builder/types"
import { charCount, validateDefinition } from "@/lib/flow-builder/validate"
import { cn } from "@/lib/utils"
import { useContactFieldsStore } from "@/store/useContactFieldsStore"

import { AppointmentSettings } from "./AppointmentSettings"
import { FieldErrorText } from "./BuilderField"
import { FlowPhonePreview } from "./FlowPhonePreview"
import { ScreenEditor } from "./ScreenEditor"
import { ScreenList } from "./ScreenList"
import { newUid, useFlowBuilderState, type FlowBuilderSnapshot } from "./useFlowBuilderState"

export const FLOWS_SETTINGS_PATH = "/configuracion/formularios"

const CATEGORIES: WhatsAppFlowCategory[] = [
  "SIGN_UP",
  "SIGN_IN",
  "APPOINTMENT_BOOKING",
  "LEAD_GENERATION",
  "CONTACT_US",
  "CUSTOMER_SUPPORT",
  "SURVEY",
  "OTHER",
]

const DESKTOP_QUERY = "(min-width: 1024px)"

function subscribeDesktop(onChange: () => void) {
  const mql = window.matchMedia(DESKTOP_QUERY)
  mql.addEventListener("change", onChange)
  return () => mql.removeEventListener("change", onChange)
}

type MobileTab = "screens" | "edit" | "preview"

interface FlowBuilderProps {
  /** null mientras el formulario no se guardó por primera vez. */
  initialFlow: WhatsAppFlow | null
  /** Canal de WhatsApp del formulario; obligatorio para crearlo. Si falta, se busca por la cuenta del formulario. */
  channelId: number | null
  initial: FlowBuilderSnapshot
  /** Se llama al crear el formulario, antes de cambiar la URL a la del formulario guardado. */
  onCreated?: (flow: WhatsAppFlow) => void
}

export function FlowBuilder({ initialFlow, channelId: channelIdProp, initial, onCreated }: FlowBuilderProps) {
  const { t } = useTranslation()
  const router = useRouter()
  const { addToast } = useToast()
  const { state, dirty, actions } = useFlowBuilderState(initial)
  const { definition } = state

  const [flow, setFlow] = useState<WhatsAppFlow | null>(initialFlow)
  const [selectedUid, setSelectedUid] = useState<string | null>(() => definition.screens[0]?.uid ?? null)
  const [serverErrors, setServerErrors] = useState<GroupedErrors>(EMPTY_ERRORS)
  const [saving, setSaving] = useState(false)
  const [publishing, setPublishing] = useState(false)
  const [openingPreview, setOpeningPreview] = useState(false)
  const [publishTargetId, setPublishTargetId] = useState<number | null>(null)
  const [metaErrorsOpen, setMetaErrorsOpen] = useState(false)
  const [leaveOpen, setLeaveOpen] = useState(false)
  const [screenToRemove, setScreenToRemove] = useState<number | null>(null)
  const [mobileTab, setMobileTab] = useState<MobileTab>("edit")
  const [channelId, setChannelId] = useState<number | null>(channelIdProp)
  const [encryptionConfigured, setEncryptionConfigured] = useState<boolean | null>(null)

  const isDesktop = useSyncExternalStore(subscribeDesktop, () => window.matchMedia(DESKTOP_QUERY).matches, () => true)

  const contactFieldsStore = useContactFieldsStore()
  const { fetch: fetchContactFields } = contactFieldsStore
  useEffect(() => {
    void fetchContactFields()
  }, [fetchContactFields])
  // Si no se pudieron cargar, las referencias a campos personalizados las valida el backend.
  const knownFields = contactFieldsStore.loaded ? contactFieldsStore.fields : null
  const contactFields = knownFields ?? []

  const hasAppointment = definition.appointment !== null

  // El canal de un formulario existente se deduce de su cuenta de WhatsApp.
  useEffect(() => {
    if (channelId !== null || !flow) return
    let cancelled = false
    getChannels()
      .then((channels) => {
        const channel = channels.find((item) => item.whatsapp_config?.id === flow.whatsapp_config_id)
        if (!cancelled && channel) setChannelId(channel.id)
      })
      .catch(() => undefined)
    return () => {
      cancelled = true
    }
  }, [channelId, flow])

  // Todo formulario del constructor usa el endpoint cifrado: se avisa si el canal todavía no lo tiene.
  useEffect(() => {
    if (channelId === null || encryptionConfigured !== null) return
    let cancelled = false
    getFlowEncryption(channelId)
      .then((status) => {
        if (!cancelled) setEncryptionConfigured(status.configured)
      })
      .catch(() => undefined)
    return () => {
      cancelled = true
    }
  }, [channelId, encryptionConfigured])

  useEffect(() => {
    if (!dirty) return
    const handler = (event: BeforeUnloadEvent) => {
      event.preventDefault()
      event.returnValue = ""
    }
    window.addEventListener("beforeunload", handler)
    return () => window.removeEventListener("beforeunload", handler)
  }, [dirty])

  const clientErrors = useMemo(
    () => groupErrors(validateDefinition(definition, knownFields, t), definition),
    [definition, knownFields, t],
  )
  const errors = useMemo(() => mergeErrors(clientErrors, serverErrors), [clientErrors, serverErrors])
  const clientErrorCount = useMemo(
    () =>
      clientErrors.global.length +
      [...Object.values(clientErrors.screens), ...Object.values(clientErrors.blocks), clientErrors.appointment]
        .flatMap((fields) => Object.values(fields))
        .reduce((total, messages) => total + messages.length, 0),
    [clientErrors],
  )

  const nameError =
    state.name.trim() === ""
      ? t("flows.builder.errors.required")
      : charCount(state.name) > NAME_MAX
        ? t("flows.builder.errors.tooLong", { max: NAME_MAX })
        : null
  const categoriesError = state.categories.length === 0 ? t("flows.builder.errors.categoriesRequired") : null

  const editable = !flow || flow.status === "DRAFT" || flow.status === "PUBLISHED"
  const blockedByErrors = hasErrors(clientErrors) || nameError !== null || categoriesError !== null
  const busy = saving || publishing
  const canSave = editable && !blockedByErrors && !busy && (dirty || !flow) && (flow !== null || channelId !== null)
  const canPublish =
    editable && !blockedByErrors && !busy && (!flow || dirty || flow.status === "DRAFT") && (flow !== null || channelId !== null)

  const screens = definition.screens
  const selectedIndex = Math.max(0, screens.findIndex((screen) => screen.uid === selectedUid))
  const selectedScreen = screens[selectedIndex]

  const questionKeys = useMemo(
    () =>
      screens.flatMap((screen) =>
        screen.blocks.filter(isQuestionBlock).map((block) => ({ uid: block.uid, key: block.key })),
      ),
    [screens],
  )
  const reasonCandidates = useMemo(() => {
    const byKey = new Map<string, LongTextBlock>()
    for (const block of screens.flatMap((screen) => screen.blocks)) {
      if (block.type === "long_text" && block.key !== "" && !byKey.has(block.key)) byKey.set(block.key, block)
    }
    return [...byKey.values()]
  }, [screens])

  const dropServerErrors = useCallback((kind: "screens" | "blocks", uid: string | undefined) => {
    setServerErrors((previous) => {
      if (!uid || !previous[kind][uid]) return previous
      const rest = { ...previous[kind] }
      delete rest[uid]
      return { ...previous, [kind]: rest }
    })
  }, [])

  const selectScreen = (index: number) => {
    setSelectedUid(screens[index]?.uid ?? null)
    if (!isDesktop) setMobileTab("edit")
  }

  const addScreen = () => {
    const screen: FlowScreen = {
      id: newScreenId(screens.map((item) => item.id)),
      uid: newUid(),
      title: t("flows.builder.defaults.screenTitle"),
      footer_label: t("flows.builder.defaults.footerLabel"),
      blocks: [],
    }
    actions.addScreen(screen)
    setSelectedUid(screen.uid ?? null)
    if (!isDesktop) setMobileTab("edit")
  }

  const removeScreen = (index: number) => {
    const removed = screens[index]
    if (removed?.uid === selectedUid) {
      setSelectedUid(screens[index === 0 ? 1 : index - 1]?.uid ?? null)
    }
    dropServerErrors("screens", removed?.uid)
    actions.removeScreen(index)
  }

  const requestRemoveScreen = (index: number) => {
    if ((screens[index]?.blocks.length ?? 0) > 0) setScreenToRemove(index)
    else removeScreen(index)
  }

  const showServerErrors = (validationErrors: Record<string, string[]>, snapshot: FlowBuilderSnapshot) => {
    const grouped = groupErrors(definitionErrorsFromServer(validationErrors), snapshot.definition)
    setServerErrors(grouped)
    const index = firstScreenWithErrors(grouped, snapshot.definition)
    if (index !== null) {
      setSelectedUid(snapshot.definition.screens[index]?.uid ?? null)
      if (!isDesktop) setMobileTab("edit")
    }
  }

  const save = async (): Promise<WhatsAppFlow | null> => {
    const snapshot: FlowBuilderSnapshot = { name: state.name, categories: state.categories, definition }
    const payload = { name: snapshot.name.trim(), categories: snapshot.categories, builder_definition: snapshot.definition }

    setSaving(true)
    try {
      let saved: WhatsAppFlow
      if (flow) {
        saved = await updateFlow(flow.id, payload)
      } else if (channelId !== null) {
        saved = await createFlow(channelId, payload)
      } else {
        return null
      }

      setFlow(saved)
      setServerErrors(EMPTY_ERRORS)
      actions.markSaved(snapshot)
      if (saved.validation_errors.length > 0) {
        addToast({
          type: "warning",
          title: t("flows.builder.toasts.savedWithMetaErrors", { count: saved.validation_errors.length }),
        })
      } else {
        addToast({ type: "success", title: t("flows.builder.toasts.saved") })
      }
      if (!flow) {
        onCreated?.(saved)
        router.replace(`${FLOWS_SETTINGS_PATH}/${saved.id}`)
      }
      return saved
    } catch (error) {
      if (error instanceof ApiValidationError) showServerErrors(error.errors, snapshot)
      addToast({
        type: "error",
        title: t("flows.builder.toasts.saveError"),
        description: error instanceof Error ? error.message : undefined,
      })
      return null
    } finally {
      setSaving(false)
    }
  }

  const startPublish = async () => {
    const saved = !flow || dirty ? await save() : flow
    if (!saved) return
    if (saved.validation_errors.length > 0) {
      setMetaErrorsOpen(true)
      return
    }
    setPublishTargetId(saved.id)
  }

  const confirmPublish = async () => {
    if (publishTargetId === null) return
    setPublishing(true)
    try {
      const published = await publishFlow(publishTargetId)
      setFlow(published)
      setPublishTargetId(null)
      addToast({ type: "success", title: t("flows.builder.toasts.published") })
    } catch (error) {
      addToast({
        type: "error",
        title: t("flows.builder.toasts.publishError"),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      setPublishing(false)
    }
  }

  const openMetaPreview = async () => {
    if (!flow) return
    // La pestaña se abre en el clic: si se abriera después del await, el navegador la bloquearía.
    const previewWindow = window.open("", "_blank")
    setOpeningPreview(true)
    try {
      const { preview_url } = await getFlowPreview(flow.id)
      if (previewWindow) {
        previewWindow.opener = null
        previewWindow.location.href = preview_url
      } else {
        window.open(preview_url, "_blank", "noopener,noreferrer")
      }
    } catch (error) {
      previewWindow?.close()
      addToast({
        type: "error",
        title: t("flows.builder.toasts.previewError"),
        description: error instanceof Error ? error.message : undefined,
      })
    } finally {
      setOpeningPreview(false)
    }
  }

  const goBack = () => {
    if (dirty) setLeaveOpen(true)
    else router.push(FLOWS_SETTINGS_PATH)
  }

  const toggleCategory = (category: WhatsAppFlowCategory, checked: boolean) => {
    actions.setMeta({
      categories: checked
        ? CATEGORIES.filter((item) => item === category || state.categories.includes(item))
        : state.categories.filter((item) => item !== category),
    })
  }

  const goToFirstError = () => {
    const index = firstScreenWithErrors(errors, definition)
    if (index !== null) selectScreen(index)
    else if (!isDesktop) setMobileTab("screens")
  }

  const validationErrors = flow?.validation_errors ?? []
  const statusKey = flow?.status ?? "NEW"

  const screenListColumn = (
    <div className="space-y-6">
      <ScreenList
        screens={screens}
        selectedIndex={selectedIndex}
        hasAppointment={hasAppointment}
        errors={errors}
        onSelect={selectScreen}
        onAdd={addScreen}
        onRemove={requestRemoveScreen}
        onMove={actions.moveScreen}
      />
      <AppointmentSettings
        appointment={definition.appointment}
        reasonCandidates={reasonCandidates}
        errors={errors.appointment}
        onChange={(appointment) => {
          setServerErrors((previous) => ({ ...previous, appointment: {} }))
          actions.setAppointment(appointment)
        }}
      />
    </div>
  )

  const editorColumn = selectedScreen ? (
    <ScreenEditor
      key={selectedScreen.uid}
      screen={selectedScreen}
      index={selectedIndex}
      screens={screens}
      hasAppointment={hasAppointment}
      errors={errors}
      contactFields={contactFields}
      questionKeys={questionKeys}
      onUpdateScreen={(patch) => {
        dropServerErrors("screens", selectedScreen.uid)
        actions.updateScreen(selectedIndex, patch)
      }}
      onAddBlock={(block: FlowBlock) => {
        dropServerErrors("screens", selectedScreen.uid)
        actions.addBlock(selectedIndex, block)
      }}
      onRemoveBlock={(index) => {
        dropServerErrors("blocks", selectedScreen.blocks[index]?.uid)
        actions.removeBlock(selectedIndex, index)
      }}
      onMoveBlock={(from, to) => actions.moveBlock(selectedIndex, from, to)}
      onUpdateBlock={(index, block) => {
        dropServerErrors("blocks", block.uid)
        actions.updateBlock(selectedIndex, index, block)
      }}
    />
  ) : null

  const previewColumn = (
    <FlowPhonePreview
      definition={definition}
      selectedIndex={selectedIndex}
      contactFields={contactFields}
      onSelectScreen={(index) => setSelectedUid(screens[index]?.uid ?? null)}
    />
  )

  return (
    <div className="flex-1 overflow-y-auto">
      <header className="sticky top-0 z-20 border-b border-border bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
        <div className="mx-auto flex w-full max-w-[1400px] flex-wrap items-start gap-3 px-4 py-3 md:px-6">
          <Button variant="ghost" size="icon" onClick={goBack} aria-label={t("flows.builder.back")}>
            <ArrowLeft className="size-4" />
          </Button>

          <div className="min-w-0 flex-1 basis-56 space-y-1">
            <Label htmlFor="flow-name" className="sr-only">
              {t("flows.builder.name")}
            </Label>
            <Input
              id="flow-name"
              value={state.name}
              placeholder={t("flows.builder.namePlaceholder")}
              className="h-9 font-medium"
              aria-invalid={nameError !== null}
              onChange={(event) => {
                setServerErrors((previous) => ({ ...previous, global: [] }))
                actions.setMeta({ name: event.target.value })
              }}
            />
            <FieldErrorText errors={nameError ? [nameError] : undefined} />
          </div>

          <div className="space-y-1">
            <Popover>
              <PopoverTrigger asChild>
                <Button variant="outline" className="h-9" aria-invalid={categoriesError !== null}>
                  <span className="max-w-48 truncate">
                    {state.categories.length > 0
                      ? state.categories.map((category) => t(`flows.builder.categories.${category}`)).join(", ")
                      : t("flows.builder.categoriesLabel")}
                  </span>
                  <ChevronDown className="size-4 text-muted-foreground" />
                </Button>
              </PopoverTrigger>
              <PopoverContent align="start" className="w-64 space-y-2">
                <p className="text-xs font-medium text-muted-foreground">{t("flows.builder.categoriesLabel")}</p>
                {CATEGORIES.map((category) => (
                  <label key={category} className="flex cursor-pointer items-center gap-2 text-sm">
                    <Checkbox
                      checked={state.categories.includes(category)}
                      onCheckedChange={(checked) => toggleCategory(category, checked === true)}
                    />
                    {t(`flows.builder.categories.${category}`)}
                  </label>
                ))}
              </PopoverContent>
            </Popover>
            <FieldErrorText errors={categoriesError ? [categoriesError] : undefined} />
          </div>

          <Badge variant={flow?.status === "PUBLISHED" ? "default" : "outline"} className="mt-2">
            {t(`flows.builder.status.${statusKey}`)}
          </Badge>
          {dirty ? <span className="mt-2 text-xs text-muted-foreground">{t("flows.builder.unsaved")}</span> : null}

          <div className="flex flex-wrap items-center gap-2">
            {flow ? (
              <Button
                variant="ghost"
                onClick={openMetaPreview}
                disabled={openingPreview}
                title={dirty ? t("flows.builder.metaPreviewSavedHint") : undefined}
              >
                {openingPreview ? <Loader2 className="size-4 animate-spin" /> : <ExternalLink className="size-4" />}
                {t("flows.builder.metaPreview")}
              </Button>
            ) : null}
            <Button variant="outline" onClick={() => void save()} disabled={!canSave}>
              {saving ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              {t("flows.builder.saveDraft")}
            </Button>
            <Button onClick={() => void startPublish()} disabled={!canPublish}>
              {publishing ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
              {t("flows.builder.publish")}
            </Button>
          </div>
        </div>
      </header>

      <div className="mx-auto w-full max-w-[1400px] px-4 pb-24 pt-4 md:px-6">
        <div className="mb-4 space-y-2 empty:hidden">
          {!editable && flow ? (
            <Notice tone="warning">{t("flows.builder.notEditable", { status: t(`flows.builder.status.${flow.status}`) })}</Notice>
          ) : null}
          {editable && flow?.status === "PUBLISHED" ? <Notice>{t("flows.builder.publishedEditHint")}</Notice> : null}
          {editable && encryptionConfigured === false && usesEndpoint(definition) ? <Notice>{t("flows.builder.encryptionHint")}</Notice> : null}
          {errors.global.length > 0 ? <Notice tone="error">{[...new Set(errors.global)].join(" ")}</Notice> : null}
          {clientErrorCount > 0 ? (
            <Notice tone="error" action={{ label: t("flows.builder.goToError"), onClick: goToFirstError }}>
              {t("flows.builder.clientErrors", { count: clientErrorCount })}
            </Notice>
          ) : null}
          {validationErrors.length > 0 && !dirty ? (
            <Notice tone="warning" action={{ label: t("flows.builder.viewDetail"), onClick: () => setMetaErrorsOpen(true) }}>
              {t("flows.builder.metaErrorsBanner", { count: validationErrors.length })}
            </Notice>
          ) : null}
        </div>

        {isDesktop ? (
          <div className="grid grid-cols-[260px_minmax(0,1fr)_320px] gap-6 xl:grid-cols-[280px_minmax(0,1fr)_340px] xl:gap-8">
            <aside className="sticky top-24 max-h-[calc(100dvh-7rem)] self-start overflow-y-auto pr-1">{screenListColumn}</aside>
            <main className="min-w-0">{editorColumn}</main>
            <aside className="sticky top-24 self-start">{previewColumn}</aside>
          </div>
        ) : (
          <Tabs value={mobileTab} onValueChange={(value) => setMobileTab(value as MobileTab)}>
            <TabsList className="w-full">
              <TabsTrigger value="screens">{t("flows.builder.tabs.screens")}</TabsTrigger>
              <TabsTrigger value="edit">{t("flows.builder.tabs.edit")}</TabsTrigger>
              <TabsTrigger value="preview">{t("flows.builder.tabs.preview")}</TabsTrigger>
            </TabsList>
            <TabsContent value="screens" className="pt-4">
              {screenListColumn}
            </TabsContent>
            <TabsContent value="edit" className="pt-4">
              {editorColumn}
            </TabsContent>
            <TabsContent value="preview" className="pt-4">
              {previewColumn}
            </TabsContent>
          </Tabs>
        )}
      </div>

      <MetaErrorsDialog open={metaErrorsOpen} onOpenChange={setMetaErrorsOpen} errors={validationErrors} />

      <AlertDialog open={publishTargetId !== null} onOpenChange={(open) => !open && !publishing && setPublishTargetId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("flows.builder.publishConfirm.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("flows.builder.publishConfirm.description")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={publishing}>{t("flows.builder.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              disabled={publishing}
              onClick={(event) => {
                event.preventDefault()
                void confirmPublish()
              }}
            >
              {publishing ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("flows.builder.publish")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={leaveOpen} onOpenChange={setLeaveOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("flows.builder.leaveConfirm.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("flows.builder.leaveConfirm.description")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("flows.builder.leaveConfirm.stay")}</AlertDialogCancel>
            <AlertDialogAction onClick={() => router.push(FLOWS_SETTINGS_PATH)}>
              {t("flows.builder.leaveConfirm.leave")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={screenToRemove !== null} onOpenChange={(open) => !open && setScreenToRemove(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("flows.builder.removeScreenConfirm.title")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("flows.builder.removeScreenConfirm.description", {
                title: screenToRemove !== null ? (screens[screenToRemove]?.title ?? "") : "",
              })}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("flows.builder.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-white hover:bg-destructive/90"
              onClick={() => {
                if (screenToRemove !== null) removeScreen(screenToRemove)
                setScreenToRemove(null)
              }}
            >
              {t("flows.builder.removeScreenConfirm.confirm")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

function Notice({
  tone = "info",
  action,
  children,
}: {
  tone?: "info" | "warning" | "error"
  action?: { label: string; onClick: () => void }
  children: ReactNode
}) {
  return (
    <div
      role={tone === "error" ? "alert" : "status"}
      className={cn(
        "flex items-start gap-2 rounded-md border px-3 py-2 text-sm",
        tone === "error" && "border-destructive/40 bg-destructive/5 text-destructive",
        tone === "warning" && "border-amber-500/40 bg-amber-500/5 text-amber-800 dark:text-amber-300",
        tone === "info" && "border-border bg-muted/50 text-muted-foreground",
      )}
    >
      {tone !== "info" ? <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden /> : null}
      <p className="min-w-0 flex-1">{children}</p>
      {action ? (
        <button type="button" className="shrink-0 font-medium underline underline-offset-2" onClick={action.onClick}>
          {action.label}
        </button>
      ) : null}
    </div>
  )
}

function MetaErrorsDialog({
  open,
  onOpenChange,
  errors,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  errors: WhatsAppFlowValidationError[]
}) {
  const { t } = useTranslation()
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{t("flows.builder.metaErrors.title")}</DialogTitle>
          <DialogDescription>{t("flows.builder.metaErrors.description")}</DialogDescription>
        </DialogHeader>
        <ul className="max-h-80 space-y-2 overflow-y-auto">
          {errors.map((error, index) => (
            <li key={index} className="rounded-md border border-border px-3 py-2 text-sm">
              <p className="text-foreground">{error.message}</p>
              {error.line_start !== undefined || error.error_type ? (
                <p className="mt-0.5 font-mono text-xs text-muted-foreground">
                  {[
                    error.error_type,
                    error.line_start !== undefined ? t("flows.builder.metaErrors.line", { line: error.line_start }) : null,
                  ]
                    .filter(Boolean)
                    .join(" · ")}
                </p>
              ) : null}
            </li>
          ))}
        </ul>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t("flows.builder.close")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
