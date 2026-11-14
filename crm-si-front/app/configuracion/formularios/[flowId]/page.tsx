"use client"

import { Suspense, useEffect, useState } from "react"
import { useParams, useRouter, useSearchParams } from "next/navigation"

import { BrandPilot } from "@/components/brand/BrandPilot"
import { FLOWS_SETTINGS_PATH, FlowBuilder } from "@/components/flows/builder/FlowBuilder"
import type { FlowBuilderSnapshot } from "@/components/flows/builder/useFlowBuilderState"
import { SidebarLayout } from "@/components/SidebarLayout"
import { useToast } from "@/components/Toast"
import { Skeleton } from "@/components/ui/skeleton"
import type { WhatsAppFlow } from "@/data/types"
import { usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { getFlow } from "@/lib/api/whatsapp-flows"
import { isFlowPreset, presetCategories, presetDefinition, presetName } from "@/lib/flow-builder/presets"
import { useAuthStore } from "@/store/useAuthStore"

const NEW_FLOW_SEGMENT = "nuevo"

interface NewFlowDraft {
  channelId: number
  snapshot: FlowBuilderSnapshot
}

/** Constructor visual de formularios de WhatsApp: `/configuracion/formularios/nuevo` o `/configuracion/formularios/<id>`. */
export default function FlowBuilderPage() {
  return (
    <SidebarLayout>
      <BrandPilot />
      <Suspense fallback={<BuilderSkeleton />}>
        <FlowBuilderRoute />
      </Suspense>
    </SidebarLayout>
  )
}

function FlowBuilderRoute() {
  const hasHydrated = useAuthStore((state) => state._hasHydrated)
  const canManage = usePermission("flows.manage")

  if (!hasHydrated) return <BuilderSkeleton />
  if (!canManage) return <RestrictedNotice />
  return <FlowBuilderLoader />
}

function readNewDraft(searchParams: URLSearchParams): NewFlowDraft | null {
  const channelId = Number(searchParams.get("channel"))
  if (!Number.isInteger(channelId) || channelId <= 0) return null
  const presetParam = searchParams.get("preset")
  const preset = isFlowPreset(presetParam) ? presetParam : "blank"
  return {
    channelId,
    snapshot: { name: presetName(preset), categories: presetCategories(preset), definition: presetDefinition(preset) },
  }
}

function FlowBuilderLoader() {
  const { t } = useTranslation()
  const router = useRouter()
  const { addToast } = useToast()
  const { flowId } = useParams<{ flowId: string }>()
  const searchParams = useSearchParams()

  // El borrador nuevo se arma una sola vez: al guardarlo la URL pasa a `/<id>` sin query string,
  // y el mismo constructor sigue montado (no se recarga ni se pierde un diálogo abierto).
  const [newDraft] = useState(() =>
    flowId === NEW_FLOW_SEGMENT ? readNewDraft(new URLSearchParams(searchParams.toString())) : null,
  )
  const [createdId, setCreatedId] = useState<number | null>(null)
  const [loaded, setLoaded] = useState<{ id: string; flow: WhatsAppFlow } | null>(null)

  const editingNew = newDraft !== null && (flowId === NEW_FLOW_SEGMENT || flowId === String(createdId))
  const flowIdNumber = Number(flowId)

  useEffect(() => {
    if (flowId === NEW_FLOW_SEGMENT && newDraft === null) router.replace(FLOWS_SETTINGS_PATH)
  }, [flowId, newDraft, router])

  useEffect(() => {
    if (editingNew || flowId === NEW_FLOW_SEGMENT || loaded?.id === flowId) return
    if (!Number.isInteger(flowIdNumber) || flowIdNumber <= 0) {
      router.replace(FLOWS_SETTINGS_PATH)
      return
    }

    let cancelled = false
    getFlow(flowIdNumber)
      .then((flow) => {
        if (cancelled) return
        // Los formularios sin definición del constructor se editan como JSON desde la configuración.
        if (!flow.builder_definition) {
          router.replace(FLOWS_SETTINGS_PATH)
          return
        }
        setLoaded({ id: flowId, flow })
      })
      .catch((error: unknown) => {
        if (cancelled) return
        addToast({
          type: "error",
          title: t("flows.builder.toasts.loadError"),
          description: error instanceof Error ? error.message : undefined,
        })
        router.replace(FLOWS_SETTINGS_PATH)
      })
    return () => {
      cancelled = true
    }
  }, [editingNew, flowId, flowIdNumber, loaded?.id, router, addToast, t])

  if (editingNew && newDraft) {
    return (
      <FlowBuilder
        key="new"
        initialFlow={null}
        channelId={newDraft.channelId}
        initial={newDraft.snapshot}
        onCreated={(flow) => setCreatedId(flow.id)}
      />
    )
  }

  const definition = loaded?.flow.builder_definition
  if (!loaded || loaded.id !== flowId || !definition) return <BuilderSkeleton />

  const { flow } = loaded
  return (
    <FlowBuilder
      key={flow.id}
      initialFlow={flow}
      channelId={null}
      initial={{ name: flow.name, categories: flow.categories, definition }}
    />
  )
}

function RestrictedNotice() {
  const { t } = useTranslation()
  return (
    <div className="flex-1 overflow-y-auto">
      <div className="mx-auto w-full max-w-5xl px-4 py-12 md:px-8">
        <h1 className="text-base font-semibold text-foreground">{t("settings.page.restricted.title")}</h1>
        <p className="mt-1.5 max-w-lg text-sm text-muted-foreground">{t("settings.page.restricted.description")}</p>
      </div>
    </div>
  )
}

/** Esqueleto con la forma del diagrama: barra, tres cajas en recorrido y el panel lateral. */
function BuilderSkeleton() {
  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="border-b border-border px-4 py-3 md:px-5">
        <Skeleton className="h-9 w-full max-w-md" />
      </div>
      <div className="flex min-h-[480px] flex-1">
        <div className="flex flex-1 items-center justify-center gap-16 px-6">
          <Skeleton className="h-40 w-56" />
          <div className="flex flex-col gap-8">
            <Skeleton className="h-28 w-56" />
            <Skeleton className="h-28 w-56" />
          </div>
        </div>
        <div className="hidden w-[400px] border-l border-border p-5 lg:block xl:w-[440px]">
          <Skeleton className="h-6 w-40" />
        </div>
      </div>
    </div>
  )
}
