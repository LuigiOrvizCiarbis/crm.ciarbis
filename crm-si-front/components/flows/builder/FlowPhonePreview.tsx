"use client"

import { useState, type ReactNode } from "react"
import { CalendarDays, ChevronDown, EllipsisVertical, X } from "lucide-react"

import { useTranslation } from "@/hooks/useTranslation"
import type { ContactField } from "@/lib/api/contact-fields"
import { isTextBlock } from "@/lib/flow-builder/limits"
import type { AppointmentConfig, FlowBlock, FlowDefinition } from "@/lib/flow-builder/types"
import { cn } from "@/lib/utils"

import { prefillLabel } from "./BlockEditor"

type PreviewView = "screen" | "date" | "slot"

interface FlowPhonePreviewProps {
  definition: FlowDefinition
  selectedIndex: number
  contactFields: ContactField[]
  onSelectScreen: (index: number) => void
}

/** Vista aproximada de la pantalla en WhatsApp: controles estáticos, sin interacción. */
export function FlowPhonePreview({ definition, selectedIndex, contactFields, onSelectScreen }: FlowPhonePreviewProps) {
  const { t } = useTranslation()
  // La vista de turno vale sólo para la pantalla desde la que se eligió: si cambia la selección, vuelve a la pantalla.
  const [extra, setExtra] = useState<{ view: PreviewView; forIndex: number }>({ view: "screen", forIndex: selectedIndex })
  const view = extra.forIndex === selectedIndex ? extra.view : "screen"
  const { appointment, screens } = definition
  const screen = screens[selectedIndex]

  const pills: Array<{ key: string; label: string; active: boolean; onClick: () => void }> = [
    ...screens.map((_, index) => ({
      key: `screen-${index}`,
      label: String(index + 1),
      active: view === "screen" && index === selectedIndex,
      onClick: () => {
        onSelectScreen(index)
        setExtra({ view: "screen", forIndex: index })
      },
    })),
    ...(appointment
      ? (["date", "slot"] as const).map((target) => ({
          key: target,
          label: t(`flows.builder.preview.${target}`),
          active: view === target,
          onClick: () => setExtra({ view: target, forIndex: selectedIndex }),
        }))
      : []),
  ]

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-center gap-1" role="group" aria-label={t("flows.builder.preview.screens")}>
        {pills.map((pill) => (
          <button
            key={pill.key}
            type="button"
            onClick={pill.onClick}
            aria-pressed={pill.active}
            className={cn(
              "min-w-7 rounded-full border px-2 py-0.5 text-xs transition-colors",
              pill.active
                ? "border-primary bg-primary text-primary-foreground"
                : "border-border text-muted-foreground hover:bg-muted",
            )}
          >
            {pill.label}
          </button>
        ))}
      </div>

      <PhoneFrame>
        {view !== "screen" && appointment ? (
          <AppointmentScreen config={appointment} view={view} />
        ) : screen ? (
          <PhoneScreen
            title={screen.title}
            footerLabel={screen.footer_label}
            body={screen.blocks.map((block, index) => (
              <PreviewBlock key={block.uid ?? index} block={block} contactFields={contactFields} />
            ))}
          />
        ) : null}
      </PhoneFrame>

      <p className="text-center text-xs text-muted-foreground">{t("flows.builder.preview.note")}</p>
    </div>
  )
}

function PhoneFrame({ children }: { children: ReactNode }) {
  return (
    <div className="mx-auto w-full max-w-[300px] rounded-[2.25rem] border-[6px] border-neutral-800 bg-neutral-800 shadow-lg">
      <div className="flex h-[560px] flex-col overflow-hidden rounded-[1.85rem] bg-white text-neutral-900">{children}</div>
    </div>
  )
}

function PhoneScreen({ title, footerLabel, body }: { title: string; footerLabel: string; body: ReactNode }) {
  const { t } = useTranslation()
  return (
    <>
      <div className="flex items-center gap-3 border-b border-neutral-200 px-4 pb-3 pt-5">
        <X className="size-5 shrink-0 text-neutral-600" aria-hidden />
        <p className="min-w-0 flex-1 truncate text-center text-[15px] font-semibold">{title}</p>
        <EllipsisVertical className="size-5 shrink-0 text-neutral-600" aria-hidden />
      </div>
      <div className="flex-1 space-y-4 overflow-y-auto px-4 py-4">{body}</div>
      <div className="space-y-2 border-t border-neutral-100 px-4 pb-4 pt-3">
        <div className="w-full truncate rounded-full bg-[#1DAA61] px-4 py-2.5 text-center text-sm font-semibold text-white">
          {footerLabel || "—"}
        </div>
        <p className="text-center text-[10px] text-neutral-500">{t("flows.builder.preview.managedBy")}</p>
      </div>
    </>
  )
}

const SAMPLE_DATES = ["lun 5 de ene", "mar 6 de ene", "mié 7 de ene"]
const SAMPLE_SLOTS = ["09:00", "09:30", "10:00"]

function AppointmentScreen({ config, view }: { config: AppointmentConfig; view: Exclude<PreviewView, "screen"> }) {
  const { t } = useTranslation()
  const isDate = view === "date"
  const samples = isDate ? SAMPLE_DATES : SAMPLE_SLOTS

  return (
    <PhoneScreen
      title={isDate ? config.date_screen_title : config.slot_screen_title}
      footerLabel={isDate ? t("flows.builder.preview.continue") : config.confirm_label}
      body={
        <>
          <FakeBox label={isDate ? config.date_label : config.slot_label} trailing={<ChevronDown className="size-4" />} />
          <ul className="space-y-1 rounded-lg border border-neutral-200 p-2 text-sm text-neutral-600">
            {samples.map((sample) => (
              <li key={sample} className="px-1 py-0.5">
                {sample}
              </li>
            ))}
          </ul>
          <p className="text-xs text-neutral-500">{t("flows.builder.preview.sampleOptions")}</p>
        </>
      }
    />
  )
}

function FakeBox({ label, value, trailing, tall }: { label: string; value?: ReactNode; trailing?: ReactNode; tall?: boolean }) {
  return (
    <div
      className={cn(
        "flex gap-2 rounded-lg border border-neutral-300 px-3 py-2 text-neutral-500",
        tall ? "min-h-20 items-start" : "items-center",
      )}
    >
      <div className="min-w-0 flex-1">
        <p className={cn("truncate", value ? "text-[11px]" : "text-sm")}>{label}</p>
        {value ? <p className="truncate text-sm italic text-neutral-700">{value}</p> : null}
      </div>
      {trailing ? <span className="shrink-0 text-neutral-500">{trailing}</span> : null}
    </div>
  )
}

function PreviewBlock({ block, contactFields }: { block: FlowBlock; contactFields: ContactField[] }) {
  const { t } = useTranslation()

  if (isTextBlock(block)) {
    const textClass = {
      heading: "text-xl font-bold leading-tight",
      subheading: "text-base font-semibold",
      body: "text-sm",
      caption: "text-xs text-neutral-500",
    }[block.type]
    return <p className={cn("whitespace-pre-wrap break-words", textClass)}>{block.text}</p>
  }

  const label = block.required ? block.label : `${block.label} ${t("flows.builder.preview.optional")}`

  switch (block.type) {
    case "short_text":
    case "long_text": {
      const prefill = block.prefill ? `{${prefillLabel(block.prefill, contactFields, t)}}` : undefined
      return (
        <div className="space-y-1">
          <FakeBox label={label} value={prefill} tall={block.type === "long_text"} />
          {block.helper_text ? <p className="px-1 text-xs text-neutral-500">{block.helper_text}</p> : null}
        </div>
      )
    }
    case "date":
      return (
        <div className="space-y-1">
          <FakeBox label={label} trailing={<CalendarDays className="size-4" />} />
          {block.helper_text ? <p className="px-1 text-xs text-neutral-500">{block.helper_text}</p> : null}
        </div>
      )
    case "dropdown":
      return <FakeBox label={label} trailing={<ChevronDown className="size-4" />} />
    case "single_choice":
    case "multiple_choice":
      return (
        <fieldset className="space-y-2">
          <legend className="mb-1 text-sm font-medium">{label}</legend>
          {block.options.map((option, index) => (
            <div key={`${option.id}-${index}`} className="flex items-center justify-between gap-3 text-sm">
              <span className="min-w-0 break-words">{option.title}</span>
              <span
                className={cn(
                  "size-4 shrink-0 border-2 border-neutral-400",
                  block.type === "single_choice" ? "rounded-full" : "rounded-sm",
                )}
                aria-hidden
              />
            </div>
          ))}
        </fieldset>
      )
    case "opt_in":
      return (
        <div className="flex items-start gap-2 text-sm">
          <span className="mt-0.5 size-4 shrink-0 rounded-sm border-2 border-neutral-400" aria-hidden />
          <span className="min-w-0 break-words">{label}</span>
        </div>
      )
  }
}
