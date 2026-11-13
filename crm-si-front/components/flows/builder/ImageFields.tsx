"use client"

import { useRef, useState, type DragEvent, type ReactNode } from "react"
import { ImagePlus, Loader2, Plus, RefreshCw, Trash2, X } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { ALT_TEXT_MAX, CAROUSEL_IMAGES_MAX, IMAGE_MAX_BYTES } from "@/lib/flow-builder/limits"
import {
  BLOCK_IMAGE_MAX_SIDE,
  ImagePrepError,
  formatKb,
  imageBytes,
  imageDataUrl,
  isSupportedImage,
  prepareImage,
} from "@/lib/flow-builder/images"
import type { FlowBlock, ImageBlock, ImageCarouselBlock, ImageSize, ScaleType } from "@/lib/flow-builder/types"
import { cn } from "@/lib/utils"

import { BuilderField, FieldErrorText } from "./BuilderField"

type Translate = (key: string, params?: Record<string, string | number>) => string

const IMAGE_SIZES: ImageSize[] = ["small", "medium", "large"]
const SCALE_TYPES: ScaleType[] = ["contain", "cover"]
const ASPECT_RATIOS: ImageCarouselBlock["aspect_ratio"][] = ["4:3", "16:9"]

/** Elige un archivo (o lo recibe arrastrado), lo achica al límite y devuelve el base64. */
function useImageUpload(maxBytes: number, maxSide: number, onImage: (base64: string) => void, t: Translate) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const upload = async (file: File | undefined) => {
    if (!file) return
    setBusy(true)
    setError(null)
    try {
      onImage(await prepareImage(file, maxBytes, maxSide))
    } catch (cause) {
      const reason = cause instanceof ImagePrepError ? cause.reason : "unsupported"
      setError(t(`flows.builder.image.${reason}`, { max: Math.round(maxBytes / 1000) }))
    } finally {
      setBusy(false)
    }
  }

  const input = (
    <input
      ref={inputRef}
      type="file"
      accept="image/*"
      className="sr-only"
      tabIndex={-1}
      aria-hidden
      onChange={(event) => {
        void upload(event.target.files?.[0])
        event.target.value = ""
      }}
    />
  )

  return { input, busy, error, open: () => inputRef.current?.click(), upload }
}

interface ImagePickerProps {
  id: string
  value: string
  maxBytes: number
  maxSide: number
  invalid?: boolean
  onChange: (base64: string) => void
  t: Translate
}

/** Imagen de un bloque: vista previa grande, peso actual y reemplazo por clic o arrastre. */
export function ImagePicker({ id, value, maxBytes, maxSide, invalid, onChange, t }: ImagePickerProps) {
  const { input, busy, error, open, upload } = useImageUpload(maxBytes, maxSide, onChange, t)
  const [dragging, setDragging] = useState(false)
  const hasImage = isSupportedImage(value)

  const onDrop = (event: DragEvent) => {
    event.preventDefault()
    setDragging(false)
    void upload(event.dataTransfer.files[0])
  }

  return (
    <div className="space-y-1.5">
      {input}
      <button
        id={id}
        type="button"
        onClick={open}
        disabled={busy}
        onDragOver={(event) => {
          event.preventDefault()
          setDragging(true)
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={onDrop}
        aria-describedby={invalid ? `${id}-error` : undefined}
        aria-label={hasImage ? t("flows.builder.image.replace") : t("flows.builder.image.upload")}
        className={cn(
          "group relative flex w-full items-center justify-center overflow-hidden rounded-md border transition-colors",
          "outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50",
          hasImage ? "h-36 border-border bg-muted/40" : "h-28 border-dashed bg-muted/20 hover:bg-muted/40",
          dragging && "border-primary bg-primary/5",
          invalid && "border-destructive/60",
        )}
      >
        {hasImage ? (
          // eslint-disable-next-line @next/next/no-img-element -- base64 local, sin optimizar
          <img src={imageDataUrl(value)} alt="" className="h-full w-full object-contain" />
        ) : (
          <span className="flex flex-col items-center gap-1 px-4 text-center">
            <ImagePlus className="size-5 text-muted-foreground" aria-hidden />
            <span className="text-sm font-medium">{t("flows.builder.image.upload")}</span>
            <span className="text-xs text-muted-foreground">{t("flows.builder.image.uploadHint")}</span>
          </span>
        )}
        {busy ? (
          <span className="absolute inset-0 flex items-center justify-center bg-background/70">
            <Loader2 className="size-5 animate-spin text-muted-foreground" aria-label={t("flows.builder.image.processing")} />
          </span>
        ) : null}
      </button>
      {hasImage ? (
        <div className="flex items-center justify-between gap-2">
          <span className="font-mono text-[11px] tabular-nums text-muted-foreground/70">
            {formatKb(imageBytes(value))} / {formatKb(maxBytes)}
          </span>
          <Button type="button" variant="ghost" size="sm" onClick={open} disabled={busy}>
            <RefreshCw className="size-3.5" />
            {t("flows.builder.image.replace")}
          </Button>
        </div>
      ) : null}
      {error ? (
        <p className="text-xs text-destructive" role="alert">
          {error}
        </p>
      ) : null}
    </div>
  )
}

interface OptionImageButtonProps {
  value: string | null | undefined
  maxBytes: number
  maxSide: number
  label: string
  invalid?: boolean
  onChange: (base64: string | null) => void
  t: Translate
}

/** Imagen de una opción: miniatura compacta al lado del texto, con quitar. */
export function OptionImageButton({ value, maxBytes, maxSide, label, invalid, onChange, t }: OptionImageButtonProps) {
  const { input, busy, error, open } = useImageUpload(maxBytes, maxSide, onChange, t)
  const hasImage = isSupportedImage(value)

  return (
    <div className="relative shrink-0">
      {input}
      <button
        type="button"
        onClick={open}
        disabled={busy}
        aria-label={label}
        title={error ?? label}
        className={cn(
          "flex size-9 items-center justify-center overflow-hidden rounded-md border text-muted-foreground transition-colors",
          "outline-none hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50",
          hasImage ? "border-border" : "border-dashed",
          (invalid || error) && "border-destructive/60 text-destructive",
        )}
      >
        {busy ? (
          <Loader2 className="size-4 animate-spin" aria-hidden />
        ) : hasImage ? (
          // eslint-disable-next-line @next/next/no-img-element -- base64 local, sin optimizar
          <img src={imageDataUrl(value)} alt="" className="size-full object-cover" />
        ) : (
          <ImagePlus className="size-4" aria-hidden />
        )}
      </button>
      {hasImage && !busy ? (
        <button
          type="button"
          onClick={() => onChange(null)}
          aria-label={t("flows.builder.image.remove")}
          className="absolute -right-1.5 -top-1.5 flex size-4 items-center justify-center rounded-full border border-border bg-background text-muted-foreground shadow-sm hover:text-destructive"
        >
          <X className="size-3" aria-hidden />
        </button>
      ) : null}
    </div>
  )
}

function DisplaySelects({
  uid,
  scaleType,
  onScaleType,
  extra,
  t,
}: {
  uid: string
  scaleType: ScaleType
  onScaleType: (value: ScaleType) => void
  extra: ReactNode
  t: Translate
}) {
  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {extra}
      <BuilderField id={`${uid}-scale`} label={t("flows.builder.image.scaleType")}>
        <Select value={scaleType} onValueChange={(value) => onScaleType(value as ScaleType)}>
          <SelectTrigger id={`${uid}-scale`} className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {SCALE_TYPES.map((type) => (
              <SelectItem key={type} value={type}>
                {t(`flows.builder.image.scaleTypes.${type}`)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </BuilderField>
    </div>
  )
}

interface FieldsProps<B> {
  block: B
  uid: string
  errors: Record<string, string[]>
  onChange: (block: FlowBlock) => void
  t: Translate
}

export function ImageBlockFields({ block, uid, errors, onChange, t }: FieldsProps<ImageBlock>) {
  const altText = block.alt_text ?? ""

  return (
    <>
      <BuilderField id={`${uid}-src`} label={t("flows.builder.image.file")} errors={errors.src}>
        <ImagePicker
          id={`${uid}-src`}
          value={block.src}
          maxBytes={IMAGE_MAX_BYTES}
          maxSide={BLOCK_IMAGE_MAX_SIDE}
          invalid={!!errors.src}
          onChange={(src) => onChange({ ...block, src })}
          t={t}
        />
      </BuilderField>
      <BuilderField
        id={`${uid}-alt`}
        label={t("flows.builder.image.altText")}
        value={altText}
        max={ALT_TEXT_MAX}
        errors={errors.alt_text}
        hint={t("flows.builder.image.altTextHint")}
      >
        <Input
          id={`${uid}-alt`}
          value={altText}
          aria-invalid={!!errors.alt_text}
          onChange={(event) => onChange({ ...block, alt_text: event.target.value || null })}
        />
      </BuilderField>
      <DisplaySelects
        uid={uid}
        scaleType={block.scale_type}
        onScaleType={(scale_type) => onChange({ ...block, scale_type })}
        t={t}
        extra={
          <BuilderField id={`${uid}-size`} label={t("flows.builder.image.size")}>
            <Select value={block.size} onValueChange={(value) => onChange({ ...block, size: value as ImageSize })}>
              <SelectTrigger id={`${uid}-size`} className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {IMAGE_SIZES.map((size) => (
                  <SelectItem key={size} value={size}>
                    {t(`flows.builder.image.sizes.${size}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </BuilderField>
        }
      />
    </>
  )
}

export function CarouselFields({ block, uid, errors, onChange, t }: FieldsProps<ImageCarouselBlock>) {
  const setImage = (index: number, patch: Partial<ImageCarouselBlock["images"][number]>) =>
    onChange({ ...block, images: block.images.map((image, i) => (i === index ? { ...image, ...patch } : image)) })
  const full = block.images.length >= CAROUSEL_IMAGES_MAX
  const adder = useImageUpload(IMAGE_MAX_BYTES, BLOCK_IMAGE_MAX_SIDE, (src) => onChange({ ...block, images: [...block.images, { src, alt_text: "" }] }), t)

  return (
    <>
      <div className="space-y-2">
        <div className="flex items-baseline justify-between gap-2">
          <span className="text-xs font-medium text-muted-foreground">{t("flows.builder.image.carouselImages")}</span>
          <span className="font-mono text-[11px] tabular-nums text-muted-foreground/70">
            {block.images.length}/{CAROUSEL_IMAGES_MAX}
          </span>
        </div>
        <ol className="space-y-4">
          {block.images.map((image, index) => {
            const altPath = `images.${index}.alt_text`
            return (
              <li key={index} className="space-y-2 rounded-md border border-border p-3">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-xs font-medium text-muted-foreground">
                    {t("flows.builder.image.carouselItem", { n: index + 1 })}
                  </span>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7 text-muted-foreground hover:text-destructive"
                    onClick={() => onChange({ ...block, images: block.images.filter((_, i) => i !== index) })}
                    aria-label={t("flows.builder.image.removeFromCarousel", { n: index + 1 })}
                  >
                    <Trash2 className="size-3.5" />
                  </Button>
                </div>
                <ImagePicker
                  id={`${uid}-image-${index}`}
                  value={image.src}
                  maxBytes={IMAGE_MAX_BYTES}
                  maxSide={BLOCK_IMAGE_MAX_SIDE}
                  invalid={!!errors[`images.${index}.src`]}
                  onChange={(src) => setImage(index, { src })}
                  t={t}
                />
                <FieldErrorText errors={errors[`images.${index}.src`]} id={`${uid}-image-${index}-error`} />
                <BuilderField
                  id={`${uid}-image-${index}-alt`}
                  label={t("flows.builder.image.altTextRequired")}
                  value={image.alt_text}
                  max={ALT_TEXT_MAX}
                  errors={errors[altPath]}
                >
                  <Input
                    id={`${uid}-image-${index}-alt`}
                    value={image.alt_text}
                    aria-invalid={!!errors[altPath]}
                    onChange={(event) => setImage(index, { alt_text: event.target.value })}
                  />
                </BuilderField>
              </li>
            )
          })}
        </ol>
        {adder.input}
        <Button type="button" variant="outline" size="sm" className="w-full border-dashed" onClick={adder.open} disabled={full || adder.busy}>
          {adder.busy ? <Loader2 className="size-4 animate-spin" /> : <Plus className="size-4" />}
          {t("flows.builder.image.addToCarousel")}
        </Button>
        {adder.error ? (
          <p className="text-xs text-destructive" role="alert">
            {adder.error}
          </p>
        ) : null}
        <FieldErrorText errors={errors.images} />
      </div>
      <DisplaySelects
        uid={uid}
        scaleType={block.scale_type}
        onScaleType={(scale_type) => onChange({ ...block, scale_type })}
        t={t}
        extra={
          <BuilderField id={`${uid}-ratio`} label={t("flows.builder.image.aspectRatio")}>
            <Select
              value={block.aspect_ratio}
              onValueChange={(value) => onChange({ ...block, aspect_ratio: value as ImageCarouselBlock["aspect_ratio"] })}
            >
              <SelectTrigger id={`${uid}-ratio`} className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {ASPECT_RATIOS.map((ratio) => (
                  <SelectItem key={ratio} value={ratio}>
                    {ratio}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </BuilderField>
        }
      />
    </>
  )
}
