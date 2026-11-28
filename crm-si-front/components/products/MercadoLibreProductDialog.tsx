"use client"

import Link from "next/link"
import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { ExternalLink, Loader2, Search } from "lucide-react"
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
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Skeleton } from "@/components/ui/skeleton"
import { Switch } from "@/components/ui/switch"
import { useToast } from "@/components/Toast"
import { MercadoLibreAttributeInput } from "@/components/products/MercadoLibreAttributeInput"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"
import {
  getMercadoLibreCategoryAttributes,
  getProductMercadoLibre,
  searchMercadoLibreCategories,
  updateProductMercadoLibre,
  type FieldErrors,
  type MercadoLibreAttribute,
  type MercadoLibreAttributeValue,
  type MercadoLibreCategory,
  type MercadoLibreListing,
  type MercadoLibreListingStatus,
  type ProductMercadoLibre,
} from "@/lib/api/mercadolibre"

const SEARCH_DEBOUNCE_MS = 300
const SEARCH_MIN_CHARS = 2

const STATUS_VARIANT: Record<MercadoLibreListingStatus, "default" | "secondary" | "destructive" | "outline"> = {
  synced: "default",
  pending: "secondary",
  paused: "outline",
  error: "destructive",
  needs_attributes: "destructive",
}

interface MercadoLibreProductDialogProps {
  productId: number | string
  open: boolean
  onOpenChange: (open: boolean) => void
  onSaved?: (data: ProductMercadoLibre) => void
}

type AttributeValues = Record<string, MercadoLibreAttributeValue>

interface SaveErrors {
  fields: FieldErrors
  /** Error del 422 por id de atributo. */
  byAttribute: Record<string, string>
}

const NO_SAVE_ERRORS: SaveErrors = { fields: {}, byAttribute: {} }

export function MercadoLibreProductDialog({
  productId,
  open,
  onOpenChange,
  onSaved,
}: MercadoLibreProductDialogProps) {
  const { t } = useTranslation()
  const { addToast } = useToast()

  const [loading, setLoading] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [data, setData] = useState<ProductMercadoLibre | null>(null)

  const [enabled, setEnabled] = useState(true)
  const [category, setCategory] = useState<{ id: string; name: string | null; predicted: boolean } | null>(null)
  const [values, setValues] = useState<AttributeValues>({})

  const [attributes, setAttributes] = useState<MercadoLibreAttribute[]>([])
  const [attributesLoading, setAttributesLoading] = useState(false)
  const [attributesError, setAttributesError] = useState<string | null>(null)
  const [showOptional, setShowOptional] = useState(false)

  const [saving, setSaving] = useState(false)
  // Los 422 vienen como `attributes.N.*`: se traducen al id del atributo al recibirlos.
  const [saveErrors, setSaveErrors] = useState<SaveErrors>(NO_SAVE_ERRORS)
  const fieldErrors = saveErrors.fields
  const attributeErrors = saveErrors.byAttribute

  const applyData = useCallback((next: ProductMercadoLibre) => {
    setData(next)
    setEnabled(next.enabled)
    setCategory(
      next.category_id
        ? { id: next.category_id, name: next.category_name, predicted: next.category_predicted }
        : null,
    )
    setValues(Object.fromEntries(next.attributes.map((value) => [value.id, value])))
  }, [])

  useEffect(() => {
    if (!open) return
    let cancelled = false
    setLoading(true)
    setLoadError(null)
    setSaveErrors(NO_SAVE_ERRORS)
    setShowOptional(false)
    void getProductMercadoLibre(productId).then((result) => {
      if (cancelled) return
      if (result.data) applyData(result.data)
      else setLoadError(result.error ?? t("products.mercadolibre.loadError"))
      setLoading(false)
    })
    return () => {
      cancelled = true
    }
  }, [open, productId, applyData, t])

  const categoryId = category?.id ?? null
  // Al cambiar de categoría a mano se descartan los atributos que la nueva no conoce;
  // al abrir el diálogo se conservan los guardados tal cual.
  const pruneOnAttributesLoadRef = useRef(false)
  useEffect(() => {
    if (!open || !categoryId) {
      setAttributes([])
      return
    }
    let cancelled = false
    setAttributesLoading(true)
    setAttributesError(null)
    void getMercadoLibreCategoryAttributes(categoryId).then((result) => {
      if (cancelled) return
      if (result.data) {
        setAttributes(result.data)
        if (pruneOnAttributesLoadRef.current) {
          pruneOnAttributesLoadRef.current = false
          const known = new Set(result.data.map((attribute) => attribute.id))
          setValues((prev) => Object.fromEntries(Object.entries(prev).filter(([id]) => known.has(id))))
        }
      } else {
        setAttributes([])
        setAttributesError(result.error ?? t("products.mercadolibre.attributesError"))
      }
      setAttributesLoading(false)
    })
    return () => {
      cancelled = true
    }
  }, [open, categoryId, t])

  const handleCategoryPick = (picked: MercadoLibreCategory) => {
    if (picked.category_id === categoryId) return
    pruneOnAttributesLoadRef.current = true
    setCategory({ id: picked.category_id, name: picked.category_name, predicted: false })
    setSaveErrors(NO_SAVE_ERRORS)
  }

  const setAttributeValue = (id: string, value: MercadoLibreAttributeValue | null) => {
    setValues((prev) => {
      const next = { ...prev }
      if (value) next[id] = value
      else delete next[id]
      return next
    })
  }

  const missingIds = useMemo(
    () => new Set((data?.listings ?? []).flatMap((listing) => listing.missing.map((item) => item.id))),
    [data],
  )

  const generalErrors = useMemo(
    () =>
      Object.entries(fieldErrors)
        .filter(([key]) => key !== "category_id" && !/^attributes\.\d+/.test(key))
        .flatMap(([, messages]) => messages),
    [fieldErrors],
  )

  const requiredAttributes = attributes.filter((attribute) => attribute.required)
  const optionalAttributes = attributes.filter((attribute) => !attribute.required)
  const isEmpty = (id: string) => !values[id]?.value_name?.trim()

  const handleSave = async () => {
    const payload = Object.values(values).filter((value) => value.value_name.trim() !== "")
    setSaving(true)
    setSaveErrors(NO_SAVE_ERRORS)
    const result = await updateProductMercadoLibre(productId, {
      enabled,
      category_id: categoryId,
      attributes: payload,
    })
    setSaving(false)

    if (!result.data) {
      if (result.fieldErrors) {
        const byAttribute: Record<string, string> = {}
        for (const [key, messages] of Object.entries(result.fieldErrors)) {
          const match = key.match(/^attributes\.(\d+)/)
          const sent = match ? payload[Number(match[1])] : undefined
          if (sent && messages[0]) byAttribute[sent.id] = messages[0]
        }
        setSaveErrors({ fields: result.fieldErrors, byAttribute })
      }
      addToast({ type: "error", title: t("common.error"), description: result.error })
      return
    }

    applyData(result.data)
    addToast({ type: "success", title: t("products.mercadolibre.saved") })
    onSaved?.(result.data)
  }

  const renderBody = () => {
    if (loading) {
      return (
        <div className="space-y-3">
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-24 w-full" />
        </div>
      )
    }
    if (loadError || !data) {
      return <p className="text-sm text-destructive">{loadError ?? t("products.mercadolibre.loadError")}</p>
    }
    if (!data.connected) {
      return (
        <p className="text-sm text-muted-foreground">
          {t("products.mercadolibre.notConnected")}{" "}
          <Link
            href="/configuracion/integraciones#mercadolibre"
            className="font-medium text-foreground underline underline-offset-4"
          >
            {t("products.mercadolibre.notConnectedLink")}
          </Link>
        </p>
      )
    }

    return (
      <div className="space-y-6">
        <div className="flex items-start justify-between gap-4 rounded-md border border-border p-3">
          <div className="space-y-0.5">
            <Label htmlFor="ml-enabled">{t("products.mercadolibre.enabled")}</Label>
            <p className="text-xs text-muted-foreground">{t("products.mercadolibre.enabledHint")}</p>
          </div>
          <Switch id="ml-enabled" checked={enabled} onCheckedChange={setEnabled} />
        </div>

        {generalErrors.length > 0 && (
          <ul className="space-y-1 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
            {generalErrors.map((message, index) => (
              <li key={index}>{message}</li>
            ))}
          </ul>
        )}

        <CategoryPicker
          current={category}
          invalid={missingIds.has("CATEGORY") || Boolean(fieldErrors.category_id)}
          error={fieldErrors.category_id?.[0]}
          onPick={handleCategoryPick}
        />

        {categoryId && (
          <section className="space-y-3">
            <h3 className="text-sm font-semibold">{t("products.mercadolibre.attributes")}</h3>
            {attributesLoading ? (
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                {t("common.loading")}
              </div>
            ) : attributesError ? (
              <p className="text-sm text-destructive">{attributesError}</p>
            ) : attributes.length === 0 ? (
              <p className="text-sm text-muted-foreground">{t("products.mercadolibre.noAttributes")}</p>
            ) : (
              <>
                <div className="grid gap-4 sm:grid-cols-2">
                  {requiredAttributes.map((attribute) => (
                    <MercadoLibreAttributeInput
                      key={attribute.id}
                      attribute={attribute}
                      value={values[attribute.id]}
                      onChange={(value) => setAttributeValue(attribute.id, value)}
                      invalid={isEmpty(attribute.id) || Boolean(attributeErrors[attribute.id])}
                      error={attributeErrors[attribute.id]}
                    />
                  ))}
                </div>
                {optionalAttributes.length > 0 && (
                  <>
                    <Button
                      type="button"
                      variant="link"
                      size="sm"
                      className="h-auto px-0"
                      onClick={() => setShowOptional((prev) => !prev)}
                    >
                      {showOptional
                        ? t("products.mercadolibre.hideMoreAttributes")
                        : t("products.mercadolibre.showMoreAttributes", { count: optionalAttributes.length })}
                    </Button>
                    {showOptional && (
                      <div className="grid gap-4 sm:grid-cols-2">
                        {optionalAttributes.map((attribute) => (
                          <MercadoLibreAttributeInput
                            key={attribute.id}
                            attribute={attribute}
                            value={values[attribute.id]}
                            onChange={(value) => setAttributeValue(attribute.id, value)}
                            invalid={
                              (missingIds.has(attribute.id) && isEmpty(attribute.id)) ||
                              Boolean(attributeErrors[attribute.id])
                            }
                            error={attributeErrors[attribute.id]}
                          />
                        ))}
                      </div>
                    )}
                  </>
                )}
              </>
            )}
          </section>
        )}

        <ListingsSection listings={data.listings} />
      </div>
    )
  }

  const editable = Boolean(data?.connected) && !loading && !loadError

  return (
    <Dialog open={open} onOpenChange={(next) => !saving && onOpenChange(next)}>
      <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{t("products.mercadolibre.title")}</DialogTitle>
          <DialogDescription>{t("products.mercadolibre.description")}</DialogDescription>
        </DialogHeader>

        {renderBody()}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            {editable ? t("products.mercadolibre.cancel") : t("products.mercadolibre.close")}
          </Button>
          {editable && (
            <Button onClick={() => void handleSave()} disabled={saving || attributesLoading}>
              {saving && <Loader2 className="mr-2 size-4 animate-spin" />}
              {saving ? t("products.mercadolibre.saving") : t("products.mercadolibre.save")}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

interface CategoryPickerProps {
  current: { id: string; name: string | null; predicted: boolean } | null
  invalid: boolean
  error?: string
  onPick: (category: MercadoLibreCategory) => void
}

function CategoryPicker({ current, invalid, error, onPick }: CategoryPickerProps) {
  const { t } = useTranslation()
  const [query, setQuery] = useState("")
  const [results, setResults] = useState<MercadoLibreCategory[]>([])
  const [searching, setSearching] = useState(false)
  const [searchError, setSearchError] = useState<string | null>(null)

  const trimmed = query.trim()

  useEffect(() => {
    if (trimmed.length < SEARCH_MIN_CHARS) {
      setResults([])
      setSearching(false)
      setSearchError(null)
      return
    }
    const controller = new AbortController()
    setSearching(true)
    const timer = setTimeout(() => {
      searchMercadoLibreCategories(trimmed, controller.signal)
        .then((result) => {
          setResults(result.data ?? [])
          setSearchError(result.error ?? null)
          setSearching(false)
        })
        .catch(() => {
          // Abortada por una búsqueda más nueva: esa actualiza el estado.
        })
    }, SEARCH_DEBOUNCE_MS)
    return () => {
      clearTimeout(timer)
      controller.abort()
    }
  }, [trimmed])

  return (
    <section className="space-y-2">
      <Label htmlFor="ml-category-search" className={cn(invalid && "text-destructive")}>
        {t("products.mercadolibre.category")}
      </Label>
      <div className="flex flex-wrap items-center gap-2 text-sm">
        {current ? (
          <>
            <span className="font-medium">{current.name ?? current.id}</span>
            {current.predicted && <Badge variant="secondary">{t("products.mercadolibre.suggested")}</Badge>}
          </>
        ) : (
          <span className={cn("text-muted-foreground", invalid && "text-destructive")}>
            {t("products.mercadolibre.noCategory")}
          </span>
        )}
      </div>
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          id="ml-category-search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder={t("products.mercadolibre.searchCategory")}
          className={cn("pl-9", invalid && "border-destructive")}
          autoComplete="off"
        />
      </div>
      {error && <p className="text-xs text-destructive">{error}</p>}
      {trimmed.length > 0 && trimmed.length < SEARCH_MIN_CHARS && (
        <p className="text-xs text-muted-foreground">{t("products.mercadolibre.searchMinChars")}</p>
      )}
      {trimmed.length >= SEARCH_MIN_CHARS && (
        <div className="rounded-md border border-border">
          {searching ? (
            <p className="flex items-center gap-2 p-3 text-sm text-muted-foreground">
              <Loader2 className="size-4 animate-spin" />
              {t("products.mercadolibre.searching")}
            </p>
          ) : searchError ? (
            <p className="p-3 text-sm text-destructive">{searchError}</p>
          ) : results.length === 0 ? (
            <p className="p-3 text-sm text-muted-foreground">{t("products.mercadolibre.searchNoResults")}</p>
          ) : (
            <ul className="divide-y divide-border">
              {results.map((result) => (
                <li key={result.category_id}>
                  <button
                    type="button"
                    className={cn(
                      "w-full px-3 py-2 text-left text-sm hover:bg-muted",
                      result.category_id === current?.id && "bg-muted",
                    )}
                    onClick={() => {
                      onPick(result)
                      setQuery("")
                    }}
                  >
                    <span className="block font-medium">{result.category_name}</span>
                    {result.domain_name && (
                      <span className="block text-xs text-muted-foreground">{result.domain_name}</span>
                    )}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </section>
  )
}

function ListingsSection({ listings }: { listings: MercadoLibreListing[] }) {
  const { t } = useTranslation()

  return (
    <section className="space-y-3">
      <h3 className="text-sm font-semibold">{t("products.mercadolibre.listings")}</h3>
      {listings.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t("products.mercadolibre.noListings")}</p>
      ) : (
        <ul className="divide-y divide-border rounded-md border border-border">
          {listings.map((listing) => (
            <li key={listing.id} className="space-y-1 p-3 text-sm">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-medium">
                  {listing.variant_label || t("products.mercadolibre.productListing")}
                </span>
                <Badge variant={STATUS_VARIANT[listing.status]}>
                  {t(`products.mercadolibre.status.${listing.status}`)}
                </Badge>
              </div>
              {listing.ml_item_id && (
                <p className="text-xs text-muted-foreground">
                  {listing.permalink ? (
                    <a
                      href={listing.permalink}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1 underline underline-offset-4 hover:text-foreground"
                    >
                      {listing.ml_item_id}
                      <ExternalLink className="size-3" aria-hidden="true" />
                    </a>
                  ) : (
                    listing.ml_item_id
                  )}
                </p>
              )}
              {listing.error && <p className="text-xs text-destructive">{listing.error}</p>}
              {listing.missing.length > 0 && (
                <p className="text-xs text-destructive">
                  {t("products.mercadolibre.missing", {
                    fields: listing.missing.map((item) => item.name).join(", "),
                  })}
                </p>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
