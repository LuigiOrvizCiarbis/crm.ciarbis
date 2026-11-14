"use client"

import { useCallback, useEffect, useMemo, useState, useSyncExternalStore, type ReactNode } from "react"
import { useRouter } from "next/navigation"
import { ReactFlowProvider } from "@xyflow/react"
import {
  ArrowLeft,
  CalendarClock,
  ChevronDown,
  ExternalLink,
  Flag,
  Loader2,
  MousePointerClick,
  Redo2,
  Save,
  Send,
  Trash2,
  TriangleAlert,
  Undo2,
  X,
} from "lucide-react"

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
import { Sheet, SheetContent, SheetTitle } from "@/components/ui/sheet"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
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
import { SCREEN_NODE_WIDTH, layoutScreens, nextFreePosition, questionCount } from "@/lib/flow-builder/layout"
import { MAX_SCREENS, NAME_MAX, isQuestionBlock } from "@/lib/flow-builder/limits"
import { DEFAULT_APPOINTMENT } from "@/lib/flow-builder/presets"
import {
  canBeEntry,
  newScreenId,
  portOptionId,
  screenTarget,
  usesEndpoint,
  type RoutePort,
} from "@/lib/flow-builder/routing"
import type { CanvasPosition, FlowBlock, FlowScreen, LongTextBlock } from "@/lib/flow-builder/types"
import { charCount, validateDefinition } from "@/lib/flow-builder/validate"
import { cn } from "@/lib/utils"
import { useContactFieldsStore } from "@/store/useContactFieldsStore"

import { AppointmentSettings } from "./AppointmentSettings"
import { FieldErrorText } from "./BuilderField"
import { FlowPhonePreview } from "./FlowPhonePreview"
import { ScreenEditor } from "./ScreenEditor"
import { FlowCanvas, type FocusRequest } from "./canvas/FlowCanvas"
import { APPOINTMENT_ID, END_ID } from "./canvas/graph"
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
/** Separación entre una pantalla y la que se crea a su derecha. */
const INSERT_OFFSET = { x: SCREEN_NODE_WIDTH + 104, y: 48 }

function subscribeDesktop(onChange: () => void) {
  const mql = window.matchMedia(DESKTOP_QUERY)
  mql.addEventListener("change", onChange)
  return () => mql.removeEventListener("change", onChange)
}

function isMac(): boolean {
  return typeof navigator !== "undefined" && /Mac|iPhone|iPad/.test(navigator.platform)
}

/** Si el foco está en un campo de texto o hay un diálogo abierto: los atajos del lienzo no aplican. */
function typingOrModal(target: EventTarget | null): boolean {
  const element = target as HTMLElement | null
  if (element?.closest("input, textarea, select, [contenteditable=true]")) return true
  return document.querySelector('[role="dialog"][data-state="open"], [role="alertdialog"][data-state="open"]') !== null
}

type InspectorTab = "content" | "preview"

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
  const { state, dirty, canUndo, canRedo, actions } = useFlowBuilderState(initial)
  const { definition } = state

  const [flow, setFlow] = useState<WhatsAppFlow | null>(initialFlow)
  // En pantallas angostas el editor es una hoja a pantalla completa: arranca cerrado para ver el diagrama.
  const [selectedId, setSelectedId] = useState<string | null>(() =>
    typeof window !== "undefined" && window.matchMedia(DESKTOP_QUERY).matches ? (definition.screens[0]?.uid ?? null) : null,
  )
  const [pendingRemovalUid, setPendingRemovalUid] = useState<string | null>(null)
  const [focusRequest, setFocusRequest] = useState<FocusRequest | null>(null)
  const [inspectorTab, setInspectorTab] = useState<InspectorTab>("content")
  const [serverErrors, setServerErrors] = useState<GroupedErrors>(EMPTY_ERRORS)
  const [saving, setSaving] = useState(false)
  const [publishing, setPublishing] = useState(false)
  const [openingPreview, setOpeningPreview] = useState(false)
  const [publishTargetId, setPublishTargetId] = useState<number | null>(null)
  const [metaErrorsOpen, setMetaErrorsOpen] = useState(false)
  const [leaveOpen, setLeaveOpen] = useState(false)
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

  // Deshacer / rehacer y Escape en todo el constructor, salvo mientras se escribe o hay un diálogo.
  useEffect(() => {
    const handler = (event: KeyboardEvent) => {
      if (typingOrModal(event.target)) return
      const modifier = event.metaKey || event.ctrlKey
      const key = event.key.toLowerCase()
      if (modifier && key === "z") {
        event.preventDefault()
        if (event.shiftKey) actions.redo()
        else actions.undo()
      } else if (modifier && key === "y") {
        event.preventDefault()
        actions.redo()
      } else if (event.key === "Escape") {
        setPendingRemovalUid(null)
      }
    }
    window.addEventListener("keydown", handler)
    return () => window.removeEventListener("keydown", handler)
  }, [actions])

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
  const selectedIndex = screens.findIndex((screen) => screen.uid === selectedId)
  const selectedScreen = selectedIndex === -1 ? null : screens[selectedIndex]
  const appointmentSelected = selectedId === APPOINTMENT_ID && hasAppointment

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

  const select = useCallback((id: string | null) => {
    setSelectedId(id)
    setPendingRemovalUid((pending) => (pending === id ? pending : null))
  }, [])

  const focus = useCallback((id: string) => setFocusRequest({ id, nonce: Date.now() }), [])

  const selectAndFocus = useCallback(
    (id: string) => {
      select(id)
      focus(id)
    },
    [select, focus],
  )

  const newScreen = useCallback(
    (position: CanvasPosition): FlowScreen => ({
      id: newScreenId(screens.map((item) => item.id)),
      uid: newUid(),
      title: t("flows.builder.defaults.screenTitle"),
      footer_label: t("flows.builder.defaults.footerLabel"),
      blocks: [],
      position,
    }),
    [screens, t],
  )

  const addScreen = useCallback(() => {
    if (screens.length >= MAX_SCREENS) return
    const screen = newScreen(nextFreePosition(screens))
    actions.addScreen(screen)
    setInspectorTab("content")
    selectAndFocus(screen.uid as string)
  }, [actions, newScreen, screens, selectAndFocus])

  const insertScreen = useCallback(
    (from: number, port: RoutePort, position?: CanvasPosition) => {
      const source = screens[from]
      if (!source || screens.length >= MAX_SCREENS) return
      const origin = source.position ?? { x: 0, y: 0 }
      const screen = newScreen(position ?? { x: origin.x + INSERT_OFFSET.x, y: origin.y + INSERT_OFFSET.y })
      actions.insertScreen(screen, from, port)
      setInspectorTab("content")
      selectAndFocus(screen.uid as string)
    },
    [actions, newScreen, screens, selectAndFocus],
  )

  const removeScreen = useCallback(
    (uid: string) => {
      const index = screens.findIndex((screen) => screen.uid === uid)
      if (index === -1 || screens.length <= 1) return
      if (selectedId === uid) setSelectedId(null)
      setPendingRemovalUid(null)
      dropServerErrors("screens", uid)
      actions.removeScreen(index)
      addToast({
        type: "info",
        title: t("flows.builder.canvas.removed", { title: screens[index].title }),
        description: t("flows.builder.canvas.undoHint", { shortcut: isMac() ? "⌘Z" : "Ctrl+Z" }),
      })
    },
    [actions, addToast, dropServerErrors, screens, selectedId, t],
  )

  const requestRemoveScreen = useCallback(
    (uid: string) => {
      const screen = screens.find((item) => item.uid === uid)
      if (!screen || screens.length <= 1) return
      if (questionCount(screen) > 0) {
        setPendingRemovalUid(uid)
        if (!isDesktop) setSelectedId(null)
      } else {
        removeScreen(uid)
      }
    },
    [isDesktop, removeScreen, screens],
  )

  const addAppointment = useCallback(() => {
    actions.setAppointment({ ...DEFAULT_APPOINTMENT, reason_key: reasonCandidates[0]?.key ?? null })
    select(APPOINTMENT_ID)
  }, [actions, reasonCandidates, select])

  const clearRoute = useCallback(
    (from: number, port: RoutePort) => {
      const screen = screens[from]
      if (!screen) return
      // Sin flecha, una opción sigue al botón de la pantalla y el botón termina el formulario.
      actions.setRoute(from, port, portOptionId(port) !== null ? (screen.next ?? "end") : "end")
    },
    [actions, screens],
  )

  const canvasActions = useMemo(
    () => ({
      requestRemoveScreen,
      confirmRemoveScreen: removeScreen,
      cancelRemoveScreen: () => setPendingRemovalUid(null),
      insertScreen: (from: number, port: RoutePort) => insertScreen(from, port),
      addAppointment,
      clearRoute,
    }),
    [requestRemoveScreen, removeScreen, insertScreen, addAppointment, clearRoute],
  )

  const connectRoute = useCallback(
    (from: number, port: RoutePort, targetId: string) => {
      if (targetId === END_ID || targetId === APPOINTMENT_ID) {
        actions.setRoute(from, port, "end")
        return
      }
      const target = screens.find((screen) => screen.uid === targetId)
      if (target) actions.setRoute(from, port, screenTarget(target.id))
    },
    [actions, screens],
  )

  const arrange = useCallback(
    (heights: Record<string, number>) => {
      const positions = Object.fromEntries(
        layoutScreens(screens, heights).map((screen) => [screen.uid ?? "", screen.position ?? { x: 0, y: 0 }]),
      )
      actions.setPositions(positions)
    },
    [actions, screens],
  )

  const showServerErrors = (validationErrors: Record<string, string[]>, snapshot: FlowBuilderSnapshot) => {
    const grouped = groupErrors(definitionErrorsFromServer(validationErrors), snapshot.definition)
    setServerErrors(grouped)
    const index = firstScreenWithErrors(grouped, snapshot.definition)
    const uid = index !== null ? snapshot.definition.screens[index]?.uid : undefined
    if (uid) {
      setInspectorTab("content")
      selectAndFocus(uid)
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
    const uid = index !== null ? screens[index]?.uid : undefined
    if (uid) {
      setInspectorTab("content")
      selectAndFocus(uid)
    } else if (Object.keys(errors.appointment).length > 0 && hasAppointment) {
      selectAndFocus(APPOINTMENT_ID)
    }
  }

  const validationErrors = flow?.validation_errors ?? []
  const statusKey = flow?.status ?? "NEW"
  const undoShortcut = isMac() ? "⌘Z" : "Ctrl+Z"
  const redoShortcut = isMac() ? "⇧⌘Z" : "Ctrl+Y"

  const screenEditor = selectedScreen ? (
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

  const appointmentSettings = (
    <AppointmentSettings
      appointment={definition.appointment}
      reasonCandidates={reasonCandidates}
      errors={errors.appointment}
      onChange={(appointment) => {
        setServerErrors((previous) => ({ ...previous, appointment: {} }))
        actions.setAppointment(appointment)
        if (appointment === null && selectedId === APPOINTMENT_ID) select(null)
      }}
    />
  )

  let inspector: ReactNode
  if (selectedScreen) {
    inspector = (
      <InspectorScreen
        screen={selectedScreen}
        index={selectedIndex}
        tab={inspectorTab}
        onTabChange={setInspectorTab}
        canMakeEntry={selectedIndex > 0 && canBeEntry(screens, selectedIndex)}
        canRemove={screens.length > 1}
        onMakeEntry={() => actions.setEntry(selectedIndex)}
        onRemove={() => requestRemoveScreen(selectedScreen.uid as string)}
        onClose={() => select(null)}
        content={screenEditor}
        preview={
          <FlowPhonePreview
            definition={definition}
            selectedIndex={selectedIndex}
            contactFields={contactFields}
            onSelectScreen={(index) => select(screens[index]?.uid ?? null)}
          />
        }
      />
    )
  } else if (appointmentSelected) {
    inspector = (
      <InspectorShell
        title={t("flows.builder.canvas.appointment")}
        icon={<CalendarClock className="size-4 text-muted-foreground" aria-hidden />}
        onClose={() => select(null)}
      >
        <div className="p-4">{appointmentSettings}</div>
      </InspectorShell>
    )
  } else {
    inspector = (
      <div className="space-y-6 p-5">
        <div className="space-y-2 pt-6">
          <MousePointerClick className="size-5 text-muted-foreground" aria-hidden />
          <h2 className="text-sm font-semibold text-foreground">{t("flows.builder.canvas.emptyTitle")}</h2>
          <p className="max-w-[46ch] text-sm text-muted-foreground">{t("flows.builder.canvas.emptyHint")}</p>
        </div>
        {hasAppointment ? null : appointmentSettings}
      </div>
    )
  }

  const inspectorOpen = selectedScreen !== null || appointmentSelected

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <header className="z-20 shrink-0 border-b border-border bg-background">
        <div className="flex w-full flex-wrap items-start gap-3 px-4 py-3 md:px-5">
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
            <div className="flex items-center">
              <HeaderIconButton label={t("flows.builder.canvas.undo")} shortcut={undoShortcut} onClick={actions.undo} disabled={!canUndo}>
                <Undo2 className="size-4" />
              </HeaderIconButton>
              <HeaderIconButton label={t("flows.builder.canvas.redo")} shortcut={redoShortcut} onClick={actions.redo} disabled={!canRedo}>
                <Redo2 className="size-4" />
              </HeaderIconButton>
            </div>
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

        <div className="space-y-2 px-4 pb-3 empty:hidden md:px-5">
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
      </header>

      <div className="flex min-h-[480px] flex-1">
        <div className="flow-canvas relative min-w-0 flex-1 select-none" aria-label={t("flows.builder.canvas.label")} role="region">
          <ReactFlowProvider>
            <FlowCanvas
              definition={definition}
              errors={errors}
              selectedId={selectedId}
              pendingRemovalUid={pendingRemovalUid}
              interactive={isDesktop && editable}
              editable={editable}
              focusRequest={focusRequest}
              actions={canvasActions}
              onSelect={(id) => {
                if (id !== selectedId) setInspectorTab("content")
                select(id)
              }}
              onConnectRoute={connectRoute}
              onSetEntry={actions.setEntry}
              onDropNewScreen={(from, port, position) => insertScreen(from, port, position)}
              onMoveScreens={actions.setPositions}
              onAddScreen={addScreen}
              onArrange={arrange}
            />
          </ReactFlowProvider>
        </div>

        {isDesktop ? (
          <aside className="w-[400px] shrink-0 overflow-y-auto border-l border-border bg-background xl:w-[440px]">{inspector}</aside>
        ) : null}
      </div>

      {!isDesktop ? (
        <Sheet open={inspectorOpen} onOpenChange={(open) => !open && select(null)}>
          <SheetContent side="right" className="w-full gap-0 overflow-y-auto p-0 sm:max-w-full">
            <SheetTitle className="sr-only">
              {selectedScreen ? selectedScreen.title : t("flows.builder.canvas.appointment")}
            </SheetTitle>
            {inspector}
          </SheetContent>
        </Sheet>
      ) : null}

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
    </div>
  )
}

function HeaderIconButton({
  label,
  shortcut,
  onClick,
  disabled,
  children,
}: {
  label: string
  shortcut: string
  onClick: () => void
  disabled: boolean
  children: ReactNode
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span>
          <Button variant="ghost" size="icon" onClick={onClick} disabled={disabled} aria-label={label} aria-keyshortcuts={shortcut}>
            {children}
          </Button>
        </span>
      </TooltipTrigger>
      <TooltipContent>
        {label} <span className="ml-1 font-mono text-[11px] opacity-70">{shortcut}</span>
      </TooltipContent>
    </Tooltip>
  )
}

function InspectorShell({
  title,
  icon,
  actions,
  onClose,
  children,
}: {
  title: string
  icon: ReactNode
  actions?: ReactNode
  onClose: () => void
  children: ReactNode
}) {
  const { t } = useTranslation()
  return (
    <div className="flex min-h-full flex-col">
      <div className="sticky top-0 z-10 flex items-center gap-2 border-b border-border bg-background px-4 py-2.5">
        {icon}
        <h2 className="min-w-0 flex-1 truncate text-sm font-semibold text-foreground" title={title}>
          {title}
        </h2>
        {actions}
        <Button variant="ghost" size="icon" className="size-8" onClick={onClose} aria-label={t("flows.builder.close")}>
          <X className="size-4" />
        </Button>
      </div>
      {children}
    </div>
  )
}

function InspectorScreen({
  screen,
  index,
  tab,
  onTabChange,
  canMakeEntry,
  canRemove,
  onMakeEntry,
  onRemove,
  onClose,
  content,
  preview,
}: {
  screen: FlowScreen
  index: number
  tab: InspectorTab
  onTabChange: (tab: InspectorTab) => void
  canMakeEntry: boolean
  canRemove: boolean
  onMakeEntry: () => void
  onRemove: () => void
  onClose: () => void
  content: ReactNode
  preview: ReactNode
}) {
  const { t } = useTranslation()
  return (
    <InspectorShell
      title={screen.title || t("flows.builder.screens.untitled")}
      icon={
        <span className="flex size-5 shrink-0 items-center justify-center rounded bg-muted font-mono text-[11px] text-muted-foreground">
          {index + 1}
        </span>
      }
      onClose={onClose}
      actions={
        <>
          {canMakeEntry ? (
            <Button variant="ghost" size="sm" className="h-8" onClick={onMakeEntry}>
              <Flag className="size-4" />
              {t("flows.builder.canvas.makeEntry")}
            </Button>
          ) : null}
          {canRemove ? (
            <Button
              variant="ghost"
              size="icon"
              className="size-8 text-muted-foreground hover:text-destructive"
              onClick={onRemove}
              aria-label={t("flows.builder.screens.remove")}
            >
              <Trash2 className="size-4" />
            </Button>
          ) : null}
        </>
      }
    >
      <Tabs value={tab} onValueChange={(value) => onTabChange(value as InspectorTab)} className="gap-0">
        <div className="px-4 pt-3">
          <TabsList className="w-full">
            <TabsTrigger value="content">{t("flows.builder.canvas.tabContent")}</TabsTrigger>
            <TabsTrigger value="preview">{t("flows.builder.tabs.preview")}</TabsTrigger>
          </TabsList>
        </div>
        <TabsContent value="content" className="px-4 pb-10 pt-4">
          {content}
        </TabsContent>
        <TabsContent value="preview" className="px-4 pb-10 pt-4">
          {preview}
        </TabsContent>
      </Tabs>
    </InspectorShell>
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
