"use client"

import { useState, type CSSProperties, type ReactNode } from "react"
import { CalendarDays, ChevronDown, EllipsisVertical, Image as ImageIcon, X } from "lucide-react"

import { useTranslation } from "@/hooks/useTranslation"
import type { ContactField } from "@/lib/api/contact-fields"
import { imageDataUrl, isSupportedImage } from "@/lib/flow-builder/images"
import { IMAGE_HEIGHTS, isTextBlock } from "@/lib/flow-builder/limits"
import { branchingBlock, optionTarget, resolveTarget } from "@/lib/flow-builder/routing"
import { groupScreens, isRepeaterScreen } from "@/lib/flow-builder/repeater"
import type { AppointmentConfig, ContentScreen, FlowBlock, FlowDefinition, ScaleType } from "@/lib/flow-builder/types"
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
  const step = screens[selectedIndex]
  // Un grupo se ve fila por fila, con las pantallas que genera.
  const [groupPage, setGroupPage] = useState<{ forIndex: number; at: number }>({ forIndex: selectedIndex, at: 0 })
  const pages = isRepeaterScreen(step) ? groupScreens(step, step.next ?? "end") : null
  const pageIndex = pages ? Math.min(groupPage.forIndex === selectedIndex ? groupPage.at : 0, pages.length - 1) : 0
  const screen: ContentScreen | undefined = pages ? pages[pageIndex] : isRepeaterScreen(step) ? undefined : step
  // Respuesta simulada de la pregunta que cambia el camino, para ver a dónde lleva el botón.
  const [picked, setPicked] = useState<{ forIndex: number; optionId: string } | null>(null)
  const branching = screen && !pages ? branchingBlock(screen) : null
  const pickedOption =
    branching?.options.find((option) => picked?.forIndex === selectedIndex && option.id === picked.optionId) ??
    branching?.options[0]
  const target = screen && !pages ? (branching && pickedOption ? optionTarget(branching, pickedOption, screen) : (screen.next ?? "following")) : null
  const resolved = target ? resolveTarget(target, selectedIndex, screens) : null
  const destination =
    resolved === null
      ? null
      : resolved === "end"
        ? appointment
          ? t("flows.builder.routes.appointmentShort")
          : t("flows.builder.routes.endShort")
        : String(resolved + 1)

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
            footerLabel={destination ? `${screen.footer_label || "—"} → ${destination}` : screen.footer_label}
            body={screen.blocks.map((block, index) => (
              <PreviewBlock
                key={block.uid ?? index}
                block={block}
                contactFields={contactFields}
                selectedOptionId={block === branching ? pickedOption?.id : undefined}
              />
            ))}
          />
        ) : null}
      </PhoneFrame>

      {view === "screen" && branching ? (
        <div className="flex flex-wrap items-center justify-center gap-1" role="group" aria-label={t("flows.builder.routes.simulate")}>
          <span className="text-xs text-muted-foreground">{t("flows.builder.routes.simulate")}</span>
          {branching.options.map((option) => (
            <button
              key={option.id}
              type="button"
              aria-pressed={option.id === pickedOption?.id}
              onClick={() => setPicked({ forIndex: selectedIndex, optionId: option.id })}
              className={cn(
                "rounded-full border px-2 py-0.5 text-xs transition-colors",
                option.id === pickedOption?.id
                  ? "border-primary bg-primary text-primary-foreground"
                  : "border-border text-muted-foreground hover:bg-muted",
              )}
            >
              {option.title || option.id}
            </button>
          ))}
        </div>
      ) : null}

      {view === "screen" && pages && pages.length > 1 ? (
        <div className="flex flex-wrap items-center justify-center gap-1" role="group" aria-label={t("flows.builder.repeater.previewRows")}>
          {pages.map((page, index) => (
            <button
              key={page.id}
              type="button"
              aria-pressed={index === pageIndex}
              onClick={() => setGroupPage({ forIndex: selectedIndex, at: index })}
              className={cn(
                "rounded-full border px-2 py-0.5 text-xs transition-colors",
                index === pageIndex
                  ? "border-primary bg-primary text-primary-foreground"
                  : "border-border text-muted-foreground hover:bg-muted",
              )}
            >
              {page.title}
            </button>
          ))}
        </div>
      ) : null}

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

function PreviewBlock({
  block,
  contactFields,
  selectedOptionId,
}: {
  block: FlowBlock
  contactFields: ContactField[]
  /** Opción elegida en la simulación del camino. */
  selectedOptionId?: string
}) {
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

  if (block.type === "image") {
    // La vista mide ~80% de un teléfono real: los altos de Meta se escalan igual.
    return (
      <PreviewImage
        src={block.src}
        alt={block.alt_text ?? ""}
        fit={block.scale_type}
        style={{ height: Math.round(IMAGE_HEIGHTS[block.size] * PREVIEW_SCALE) }}
      />
    )
  }

  if (block.type === "image_carousel") {
    const ratio = block.aspect_ratio === "16:9" ? "16 / 9" : "4 / 3"
    if (block.images.length === 0) return <PreviewImage src="" alt="" fit="cover" style={{ aspectRatio: ratio }} />
    return (
      <div className="space-y-1.5">
        <div className="flex snap-x snap-mandatory gap-2 overflow-x-auto rounded-lg [scrollbar-width:none]">
          {block.images.map((image, index) => (
            <PreviewImage
              key={index}
              src={image.src}
              alt={image.alt_text}
              fit={block.scale_type}
              className="w-full shrink-0 snap-center"
              style={{ aspectRatio: ratio }}
            />
          ))}
        </div>
        {block.images.length > 1 ? (
          <div className="flex justify-center gap-1" aria-hidden>
            {block.images.map((_, index) => (
              <span key={index} className={cn("size-1.5 rounded-full", index === 0 ? "bg-neutral-700" : "bg-neutral-300")} />
            ))}
          </div>
        ) : null}
      </div>
    )
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
    case "dropdown": {
      const selected = block.options.find((option) => option.id === selectedOptionId)
      return <FakeBox label={label} value={selected?.title} trailing={<ChevronDown className="size-4" />} />
    }
    case "single_choice":
    case "multiple_choice":
      return (
        <fieldset className="space-y-2">
          <legend className="mb-1 text-sm font-medium">{label}</legend>
          {block.options.map((option, index) => (
            <div key={`${option.id}-${index}`} className="flex items-center justify-between gap-3 text-sm">
              <span className="flex min-w-0 items-center gap-2">
                {isSupportedImage(option.image) ? (
                  // eslint-disable-next-line @next/next/no-img-element -- base64 local, sin optimizar
                  <img
                    src={imageDataUrl(option.image)}
                    alt={option.alt_text ?? ""}
                    className={cn("shrink-0 rounded object-cover", block.media_size === "large" ? "size-14" : "size-8")}
                  />
                ) : null}
                <span className="min-w-0 break-words">{option.title}</span>
              </span>
              <span
                className={cn(
                  "size-4 shrink-0 border-2 border-neutral-400",
                  block.type === "single_choice" ? "rounded-full" : "rounded-sm",
                  option.id === selectedOptionId && "border-[#1DAA61] bg-[#1DAA61]",
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

const PREVIEW_SCALE = 0.8

function PreviewImage({
  src,
  alt,
  fit,
  className,
  style,
}: {
  src: string
  alt: string
  fit: ScaleType
  className?: string
  style: CSSProperties
}) {
  if (!isSupportedImage(src)) {
    return (
      <div
        className={cn("flex items-center justify-center rounded-lg border border-dashed border-neutral-300 text-neutral-400", className)}
        style={style}
      >
        <ImageIcon className="size-6" aria-hidden />
      </div>
    )
  }
  return (
    // eslint-disable-next-line @next/next/no-img-element -- base64 local, sin optimizar
    <img
      src={imageDataUrl(src)}
      alt={alt}
      className={cn("w-full rounded-lg", fit === "cover" ? "object-cover" : "object-contain", className)}
      style={style}
    />
  )
}
