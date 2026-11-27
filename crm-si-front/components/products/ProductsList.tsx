"use client"

import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import {
  CircleCheck,
  CircleOff,
  Loader2,
  MoreVertical,
  Package,
  Pencil,
  Plus,
  Search,
  SearchX,
  Store,
  Trash2,
  Upload,
  X,
} from "lucide-react"
import { useTranslation } from "@/hooks/useTranslation"
import { useToast } from "@/components/Toast"
import {
  createProduct,
  deleteProduct,
  getProduct,
  getProducts,
  updateProduct,
  type Product,
  type ProductInput,
} from "@/lib/api/products"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Switch } from "@/components/ui/switch"
import { Badge } from "@/components/ui/badge"
import { Checkbox } from "@/components/ui/checkbox"
import { Skeleton } from "@/components/ui/skeleton"
import { DataTable, type DataTableColumn, type DataTableSort } from "@/components/ui/data-table"
import { DataTablePagination } from "@/components/ui/data-table-pagination"
import { CustomChoiceBadge, CustomFieldInput } from "@/components/CustomFieldInput"
import { formatCustomFieldValue, isNumericFieldType } from "@/lib/custom-field-value"
import { cn } from "@/lib/utils"
import { useProductFieldsStore } from "@/store/useProductFieldsStore"
import type { ProductField } from "@/lib/api/product-fields"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
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
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { UniversalImportDialog } from "@/components/import/universal-import-dialog"
import { DEFAULT_CURRENCY, formatCurrency } from "@/lib/currency"
import { ProductVariantsTable } from "@/components/products/ProductVariantsTable"
import { MercadoLibreProductDialog } from "@/components/products/MercadoLibreProductDialog"

interface FormState {
  name: string
  price: string
  description: string
  is_active: boolean
  custom_data: Record<string, unknown>
}

const EMPTY_FORM: FormState = {
  name: "",
  price: "",
  description: "",
  is_active: true,
  custom_data: {},
}

const PAGE_SIZE_OPTIONS = [10, 25, 50, 100]
const DEFAULT_PAGE_SIZE = 25

// Keys that map to real product columns rather than to custom_data. `name` is
// always fixed; price/description/is_active are seeded ProductField defaults but
// still live as native columns/controls.
const NATIVE_FIELD_KEYS = new Set(["name", "price", "description", "is_active"])

/**
 * Compara dos valores de un campo custom para ordenar la tabla.
 *
 * El criterio depende del tipo, igual que el orden server-side de contactos:
 * numéricos y moneda como número (si no, "9" > "10"), booleanos por
 * verdadero/falso, fechas por su ISO (que ordena igual que cronológicamente) y
 * el resto por texto con locale. Los valores vacíos van siempre al final, en
 * ambas direcciones: la ausencia de dato no es "el valor más chico".
 */
function compareCustomValues(
  a: unknown,
  b: unknown,
  type: ProductField["type"],
  dir: number,
): number {
  const isEmpty = (v: unknown) =>
    v === null || v === undefined || v === "" || (Array.isArray(v) && v.length === 0)

  const aEmpty = isEmpty(a)
  const bEmpty = isEmpty(b)
  if (aEmpty && bEmpty) return 0
  if (aEmpty) return 1
  if (bEmpty) return -1

  if (type === "number" || type === "currency") {
    const av = Number(a)
    const bv = Number(b)
    // Un valor no numérico cargado a mano no puede compararse; se trata como
    // vacío para que no altere el orden del resto.
    if (Number.isNaN(av) && Number.isNaN(bv)) return 0
    if (Number.isNaN(av)) return 1
    if (Number.isNaN(bv)) return -1
    return (av - bv) * dir
  }

  if (type === "boolean") {
    return (Number(Boolean(a)) - Number(Boolean(b))) * dir
  }

  if (type === "repeater" || type === "multi_select") {
    // Colecciones: ordenar por cantidad de elementos es lo único comparable,
    // y coincide con lo que muestra la celda.
    const av = Array.isArray(a) ? a.length : 0
    const bv = Array.isArray(b) ? b.length : 0
    return (av - bv) * dir
  }

  return String(a).localeCompare(String(b), undefined, { numeric: true }) * dir
}

// Lo manual y lo importado son datos propios: neutro. Lo sincronizado va en el tono informativo
// porque cambia cómo se edita (sus campos viven en la tienda). Violeta no: es de la IA.
const SOURCE_CHIP_CLASS: Record<string, string> = {
  woocommerce: "border-info/30 bg-info/10 text-info",
}
const NEUTRAL_CHIP_CLASS = "border-border bg-muted text-muted-foreground"
const SOURCE_LABEL_KEY: Record<string, string> = {
  manual: "catalog.source_manual",
  import: "catalog.source_import",
  woocommerce: "catalog.source_woocommerce",
}

export function ProductsList() {
  const { t, language } = useTranslation()
  const { addToast } = useToast()

  const [products, setProducts] = useState<Product[]>([])
  const [loading, setLoading] = useState(true)
  const [hasLoaded, setHasLoaded] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)
  const requestRef = useRef(0)
  // `search` es lo que se tipea; `query`, lo que ya se pidió al backend (sin espacios, con debounce).
  const [search, setSearch] = useState("")
  const [query, setQuery] = useState("")
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)

  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<Product | null>(null)
  const [form, setForm] = useState<FormState>(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  // Datos que trae una integración (WooCommerce): se muestran bloqueados.
  const managedFields = useMemo(() => new Set(editing?.managed_fields ?? []), [editing])

  const [deleteTarget, setDeleteTarget] = useState<Product | null>(null)
  const [importOpen, setImportOpen] = useState(false)
  const [mercadoLibreTarget, setMercadoLibreTarget] = useState<Product | null>(null)

  const [sort, setSort] = useState<DataTableSort | null>(null)
  const [selectedIds, setSelectedIds] = useState<Set<string | number>>(new Set())
  const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false)
  const [bulkBusy, setBulkBusy] = useState(false)

  const {
    fields: productFields,
    loaded: fieldsLoaded,
    fetch: fetchFields,
  } = useProductFieldsStore()

  useEffect(() => {
    if (!fieldsLoaded) {
      fetchFields().catch((error) =>
        addToast({
          type: "error",
          title: t("catalog.loadError"),
          description: (error as Error).message,
        }),
      )
    }
  }, [fieldsLoaded, fetchFields, addToast, t])

  // Fields the tenant added on top of the native columns; these are stored in
  // custom_data and rendered dynamically.
  const customFields = useMemo(
    () => productFields.filter((f) => !NATIVE_FIELD_KEYS.has(f.key)),
    [productFields],
  )

  // Un campo custom eliminado deja el sort apuntando a una columna que ya no
  // se renderiza; se descarta para no mostrar un orden que no está aplicado.
  useEffect(() => {
    if (!fieldsLoaded || !sort?.columnId.startsWith("custom:")) return
    if (customFields.some((f) => `custom:${f.key}` === sort.columnId)) return
    setSort(null)
  }, [fieldsLoaded, customFields, sort])

  const load = useCallback(async (searchTerm: string) => {
    const request = ++requestRef.current
    setLoading(true)
    try {
      const data = await getProducts(searchTerm ? { search: searchTerm } : {})
      // Una búsqueda vieja que responde tarde no pisa el resultado de la más nueva.
      if (request !== requestRef.current) return
      setProducts(data)
      setLoadError(null)
      // La selección sólo conserva lo que sigue en la lista: un id que ya no se ve entraría en
      // "Eliminar" sin que nadie lo note.
      const visibleIds = new Set<string | number>(data.map((product) => product.id))
      setSelectedIds((current) => {
        const kept = Array.from(current).filter((id) => visibleIds.has(id))
        return kept.length === current.size ? current : new Set(kept)
      })
    } catch (error) {
      if (request !== requestRef.current) return
      setLoadError((error as Error).message)
    } finally {
      if (request === requestRef.current) {
        setLoading(false)
        setHasLoaded(true)
      }
    }
  }, [])

  useEffect(() => {
    void load(query)
  }, [load, query])

  // La primera carga sale sin esperar; lo que se tipea espera a que se deje de tipear.
  useEffect(() => {
    const next = search.trim()
    if (next === query) return
    const handle = setTimeout(() => {
      setPage(1)
      setQuery(next)
    }, 300)
    return () => clearTimeout(handle)
  }, [search, query])

  const reload = () => load(query)

  const clearSearch = () => {
    setSearch("")
    setQuery("")
    setPage(1)
  }

  const openCreate = () => {
    setEditing(null)
    setForm(EMPTY_FORM)
    setDialogOpen(true)
  }

  const openEdit = (product: Product) => {
    setEditing(product)
    setForm({
      name: product.name,
      price: product.price ?? "",
      description: product.description ?? "",
      is_active: product.is_active,
      custom_data: { ...(product.custom_data ?? {}) },
    })
    setDialogOpen(true)
    // El listado no trae las variantes: se piden al abrir el detalle.
    if (product.variants_count) {
      getProduct(product.id)
        .then((full) => setEditing((current) => (current?.id === full.id ? full : current)))
        .catch((error) =>
          addToast({ type: "error", title: t("catalog.loadError"), description: (error as Error).message }),
        )
    }
  }

  const handleSave = async () => {
    if (!form.name.trim()) {
      addToast({ type: "error", title: t("catalog.nameRequired") })
      return
    }

    const payload: ProductInput = {
      name: form.name.trim(),
      price: form.price.trim() === "" ? null : Number(form.price),
      description: form.description.trim() || null,
      is_active: form.is_active,
      custom_data: Object.fromEntries(
        Object.entries(form.custom_data).filter(([key]) => !managedFields.has(key)),
      ),
    }

    setSaving(true)
    try {
      if (editing) {
        // Los datos gestionados por la integración no se reenvían.
        const editable = Object.fromEntries(
          Object.entries(payload).filter(([key]) => !managedFields.has(key)),
        ) as Partial<ProductInput>
        await updateProduct(editing.id, editable)
        addToast({ type: "success", title: t("catalog.updated") })
      } else {
        await createProduct(payload)
        addToast({ type: "success", title: t("catalog.created") })
      }
      setDialogOpen(false)
      await reload()
    } catch (error) {
      addToast({
        type: "error",
        title: t("catalog.saveError"),
        description: (error as Error).message,
      })
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteTarget) return
    try {
      await deleteProduct(deleteTarget.id)
      addToast({ type: "success", title: t("catalog.deleted") })
      await reload()
    } catch (error) {
      addToast({
        type: "error",
        title: t("catalog.deleteError"),
        description: (error as Error).message,
      })
    } finally {
      setDeleteTarget(null)
    }
  }

  const sortedProducts = useMemo(() => {
    if (!sort) return products
    const dir = sort.direction === "asc" ? 1 : -1
    const customField = sort.columnId.startsWith("custom:")
      ? customFields.find((f) => `custom:${f.key}` === sort.columnId)
      : undefined

    if (customField) {
      return [...products].sort(
        (a, b) =>
          compareCustomValues(
            a.custom_data?.[customField.key],
            b.custom_data?.[customField.key],
            customField.type,
            dir,
          ),
      )
    }

    return [...products].sort((a, b) => {
      switch (sort.columnId) {
        case "name":
          return a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: "base" }) * dir
        case "price":
          // Mismo criterio que las columnas de moneda: sin precio va al final en ambas direcciones.
          return compareCustomValues(a.price, b.price, "currency", dir)
        case "source":
          return a.source.localeCompare(b.source) * dir
        case "status":
          return (Number(a.is_active) - Number(b.is_active)) * dir
        default:
          return 0
      }
    })
  }, [products, sort, customFields])

  const totalPages = Math.max(1, Math.ceil(sortedProducts.length / pageSize))
  const currentPage = Math.min(page, totalPages)
  const pageStart = (currentPage - 1) * pageSize
  const paginatedProducts = sortedProducts.slice(pageStart, pageStart + pageSize)
  // Sólo la primera carga muestra esqueletos; al buscar o recargar, las filas quedan hasta que llega la respuesta.
  const initialLoading = loading && !hasLoaded

  const formatPrice = (price: string | null) => formatCurrency(price, DEFAULT_CURRENCY)
  const plural = (key: string, count: number) => t(`${key}_${count === 1 ? "one" : "other"}`, { count })

  const selectedProducts = products.filter((p) => selectedIds.has(p.id))
  const clearSelection = () => setSelectedIds(new Set())

  const toggleSelected = (id: number) => {
    const next = new Set(selectedIds)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    setSelectedIds(next)
  }

  const handleBulkSetActive = async (active: boolean) => {
    if (selectedProducts.length === 0) return
    // El estado de los productos de WooCommerce lo define la tienda.
    const editable = selectedProducts.filter((p) => !p.managed_fields.includes("is_active"))
    const skipped = selectedProducts.length - editable.length
    if (editable.length === 0) {
      addToast({ type: "error", title: plural("catalog.bulkSkippedManaged", skipped) })
      return
    }
    setBulkBusy(true)
    try {
      await Promise.all(editable.map((p) => updateProduct(p.id, { is_active: active })))
      addToast({
        type: "success",
        title: active ? t("catalog.bulkActivated") : t("catalog.bulkDeactivated"),
        description: skipped > 0 ? plural("catalog.bulkSkippedManaged", skipped) : undefined,
      })
      clearSelection()
      await reload()
    } catch (error) {
      addToast({ type: "error", title: t("catalog.saveError"), description: (error as Error).message })
    } finally {
      setBulkBusy(false)
    }
  }

  const handleBulkDelete = async () => {
    if (selectedProducts.length === 0) return
    setBulkBusy(true)
    try {
      await Promise.all(selectedProducts.map((p) => deleteProduct(p.id)))
      addToast({ type: "success", title: t("catalog.bulkDeleted") })
      clearSelection()
      await reload()
    } catch (error) {
      addToast({ type: "error", title: t("catalog.deleteError"), description: (error as Error).message })
    } finally {
      setBulkBusy(false)
      setBulkDeleteOpen(false)
    }
  }

  const statusBadge = (product: Product) => (
    // Estado semántico: activo en el tono de éxito, inactivo neutro.
    <Badge variant="outline" className={product.is_active ? "border-success/30 bg-success/10 text-success" : NEUTRAL_CHIP_CLASS}>
      {product.is_active ? t("catalog.active") : t("catalog.inactive")}
    </Badge>
  )

  const sourceBadge = (product: Product) => (
    <Badge variant="outline" className={SOURCE_CHIP_CLASS[product.source] ?? NEUTRAL_CHIP_CLASS}>
      {SOURCE_LABEL_KEY[product.source] ? t(SOURCE_LABEL_KEY[product.source]) : product.source}
    </Badge>
  )

  const renderCustomCell = (product: Product, field: ProductField) => {
    const raw = product.custom_data?.[field.key]
    if (field.type === "select" && raw !== null && raw !== undefined && raw !== "") {
      return <CustomChoiceBadge choice={String(raw)} color={field.options?.choice_colors?.[String(raw)]} />
    }
    const text = formatCustomFieldValue(raw, field.type, { t, language, currency: field.options?.currency })
    if (text === "—") return <span className="text-muted-foreground">—</span>
    // Texto libre: dos líneas como mucho para que una nota larga no estire la fila; el resto se lee al abrirla.
    return isNumericFieldType(field.type) ? text : <span className="line-clamp-2 w-max max-w-xs">{text}</span>
  }

  const renderActions = (product: Product, triggerClassName: string) => (
    // La fila abre la edición con un clic; el menú no. React propaga a través del portal, así que
    // este corte también cubre los ítems del menú.
    <div onClick={(event) => event.stopPropagation()}>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            className={cn("text-muted-foreground hover:text-foreground", triggerClassName)}
            aria-label={t("catalog.actionsFor", { name: product.name })}
          >
            <MoreVertical className="h-4 w-4" aria-hidden="true" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onClick={() => openEdit(product)}>
            <Pencil className="mr-2 h-4 w-4" aria-hidden="true" />
            {t("catalog.edit")}
          </DropdownMenuItem>
          <DropdownMenuItem onClick={() => setMercadoLibreTarget(product)}>
            <Store className="mr-2 h-4 w-4" aria-hidden="true" />
            {t("products.mercadolibre.menuItem")}
          </DropdownMenuItem>
          <DropdownMenuItem className="text-destructive focus:text-destructive" onClick={() => setDeleteTarget(product)}>
            <Trash2 className="mr-2 h-4 w-4" aria-hidden="true" />
            {t("catalog.delete")}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )

  const columns: DataTableColumn<Product>[] = [
    {
      id: "name",
      header: t("catalog.columnName"),
      sortable: true,
      cell: (product) => {
        const meta = [
          product.variants_count ? plural("catalog.variantsCount", product.variants_count) : null,
          product.description,
        ]
          .filter(Boolean)
          .join(" · ")
        return (
          // Ancho propio: sin él, las columnas custom aplastan el nombre a una palabra por línea.
          <div className="min-w-56 max-w-md">
            <div className="font-medium text-foreground [overflow-wrap:anywhere]">{product.name}</div>
            {meta && <div className="truncate text-xs text-muted-foreground">{meta}</div>}
          </div>
        )
      },
    },
    {
      id: "price",
      header: t("catalog.columnPrice"),
      sortable: true,
      align: "right",
      width: "144px",
      className: (product) => cn("whitespace-nowrap tabular-nums", product.price === null && "text-muted-foreground"),
      cell: (product) => formatPrice(product.price),
    },
    {
      id: "source",
      header: t("catalog.columnSource"),
      sortable: true,
      width: "136px",
      cell: sourceBadge,
    },
    {
      id: "status",
      header: t("catalog.columnStatus"),
      sortable: true,
      width: "112px",
      cell: statusBadge,
    },
    ...customFields.map<DataTableColumn<Product>>((field) => {
      const numeric = isNumericFieldType(field.type)
      return {
        id: `custom:${field.key}`,
        header: field.label,
        sortable: true,
        minWidth: "140px",
        align: numeric ? "right" : undefined,
        className: numeric ? "whitespace-nowrap tabular-nums" : undefined,
        cell: (product) => renderCustomCell(product, field),
      }
    }),
    {
      id: "actions",
      header: "",
      headerLabel: t("catalog.columnActions"),
      align: "right",
      width: "56px",
      cell: (product) => renderActions(product, "h-7 w-7"),
    },
  ]

  // Sin filas, las columnas custom no aportan nada y ensanchan la tabla hasta sacar el vacío de la vista.
  const tableColumns =
    paginatedProducts.length > 0 || initialLoading ? columns : columns.filter((column) => !column.id.startsWith("custom:"))

  const emptyState = query ? (
    <div className="mx-auto max-w-sm">
      <SearchX className="mx-auto mb-3 size-8 text-muted-foreground" aria-hidden="true" />
      <p className="font-medium text-foreground [overflow-wrap:anywhere]">{t("catalog.noResultsTitle", { query })}</p>
      <p className="mt-1 text-sm text-muted-foreground">{t("catalog.noResultsBody")}</p>
      <Button variant="outline" size="sm" className="mt-4" onClick={clearSearch}>
        {t("catalog.clearSearch")}
      </Button>
    </div>
  ) : (
    <div className="mx-auto max-w-sm">
      <Package className="mx-auto mb-3 size-8 text-muted-foreground" aria-hidden="true" />
      <p className="font-medium text-foreground">{t("catalog.emptyTitle")}</p>
      <p className="mt-1 text-sm text-muted-foreground">{t("catalog.emptyBody")}</p>
      <div className="mt-4 flex flex-wrap justify-center gap-2">
        <Button size="sm" onClick={openCreate}>
          <Plus className="h-4 w-4" aria-hidden="true" />
          {t("catalog.newProduct")}
        </Button>
        <Button variant="outline" size="sm" onClick={() => setImportOpen(true)}>
          <Upload className="h-4 w-4" aria-hidden="true" />
          {t("catalog.import")}
        </Button>
      </div>
    </div>
  )

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold">{t("catalog.title")}</h1>
          <p className="text-sm text-muted-foreground">{t("catalog.subtitle")}</p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={() => setImportOpen(true)}>
            <Upload className="mr-2 h-4 w-4" />
            {t("catalog.import")}
          </Button>
          <Button onClick={openCreate}>
            <Plus className="mr-2 h-4 w-4" />
            {t("catalog.newProduct")}
          </Button>
        </div>
      </div>

      <div className="space-y-3">
        <div className="relative max-w-sm">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t("catalog.searchPlaceholder")}
            aria-label={t("catalog.searchLabel")}
            className="pl-9 pr-9"
          />
          {loading && hasLoaded && (
            <Loader2
              className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground motion-safe:animate-spin"
              aria-label={t("catalog.loading")}
            />
          )}
        </div>

        {loadError ? (
          <div role="alert" className="rounded-lg border px-6 py-12 text-center">
            <p className="font-medium text-foreground">{t("catalog.loadError")}</p>
            <p className="mt-1 text-sm text-muted-foreground">{loadError}</p>
            <Button variant="outline" size="sm" className="mt-4" disabled={loading} onClick={() => void reload()}>
              {t("catalog.retry")}
            </Button>
          </div>
        ) : (
          <>
            {/* Lista larga: el paginado va arriba (con filas por página) y se repite abajo, como en contactos. */}
            {hasLoaded && sortedProducts.length > 0 && (
              <DataTablePagination
                page={currentPage}
                pageCount={totalPages}
                onPageChange={setPage}
                total={sortedProducts.length}
                pageSize={pageSize}
                pageSizeOptions={PAGE_SIZE_OPTIONS}
                onPageSizeChange={(size) => {
                  setPageSize(size)
                  setPage(1)
                }}
                disabled={loading}
              />
            )}

            {selectedIds.size > 0 && (
              <div className="flex flex-wrap items-center gap-1 rounded-lg border border-border bg-card px-2 py-1.5">
                <Button
                  variant="ghost"
                  size="sm"
                  className="size-8 p-0"
                  disabled={bulkBusy}
                  onClick={clearSelection}
                  aria-label={t("catalog.clearSelection")}
                  title={t("catalog.clearSelection")}
                >
                  <X className="h-4 w-4" aria-hidden="true" />
                </Button>
                <span className="px-1 text-sm font-medium tabular-nums" aria-live="polite">
                  {plural("catalog.bulkSelected", selectedIds.size)}
                </span>
                <span aria-hidden="true" className="mx-1 h-5 w-px bg-border" />
                <Button variant="ghost" size="sm" className="gap-1.5" disabled={bulkBusy} onClick={() => handleBulkSetActive(true)}>
                  <CircleCheck className="h-4 w-4" aria-hidden="true" />
                  {t("catalog.activate")}
                </Button>
                <Button variant="ghost" size="sm" className="gap-1.5" disabled={bulkBusy} onClick={() => handleBulkSetActive(false)}>
                  <CircleOff className="h-4 w-4" aria-hidden="true" />
                  {t("catalog.deactivate")}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  className="ml-auto gap-1.5 text-destructive hover:bg-destructive/10 hover:text-destructive"
                  disabled={bulkBusy}
                  onClick={() => setBulkDeleteOpen(true)}
                >
                  <Trash2 className="h-4 w-4" aria-hidden="true" />
                  {t("catalog.delete")}
                </Button>
              </div>
            )}

            {/* Teléfonos: la misma lista en filas con lo esencial; la tabla con columnas custom queda para md en adelante. */}
            <div className="md:hidden" aria-busy={loading || undefined}>
              {initialLoading ? (
                <ul className="divide-y rounded-lg border" aria-hidden="true">
                  {Array.from({ length: 5 }, (_, index) => (
                    <li key={index} className="space-y-2 px-4 py-3">
                      <Skeleton className={cn("h-4 motion-reduce:animate-none", index % 2 ? "w-1/2" : "w-3/4")} />
                      <Skeleton className="h-4 w-1/3 motion-reduce:animate-none" />
                    </li>
                  ))}
                </ul>
              ) : paginatedProducts.length === 0 ? (
                <div className="rounded-lg border px-4 py-12 text-center">{emptyState}</div>
              ) : (
                <ul className="divide-y overflow-hidden rounded-lg border">
                  {paginatedProducts.map((product) => (
                    <li
                      key={product.id}
                      className={cn("flex items-start gap-1 p-1", selectedIds.has(product.id) && "bg-primary/5")}
                    >
                      <label className="flex size-10 shrink-0 cursor-pointer items-center justify-center">
                        <Checkbox
                          checked={selectedIds.has(product.id)}
                          onCheckedChange={() => toggleSelected(product.id)}
                          aria-label={t("dataTable.selectRowNamed", { row: product.name })}
                        />
                      </label>
                      <button
                        type="button"
                        onClick={() => openEdit(product)}
                        className="min-w-0 flex-1 rounded-md py-2 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                      >
                        <span className="block text-sm font-medium text-foreground [overflow-wrap:anywhere]">{product.name}</span>
                        <span className="mt-1.5 flex flex-wrap items-center gap-2">
                          <span
                            className={cn(
                              "text-sm tabular-nums",
                              product.price === null ? "text-muted-foreground" : "text-foreground",
                            )}
                          >
                            {formatPrice(product.price)}
                          </span>
                          {statusBadge(product)}
                          {sourceBadge(product)}
                        </span>
                      </button>
                      {renderActions(product, "size-10")}
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <DataTable
              className="hidden md:block"
              columns={tableColumns}
              data={paginatedProducts}
              getRowId={(product) => product.id}
              getRowLabel={(product) => product.name}
              loading={initialLoading}
              loadingLabel={t("catalog.loading")}
              emptyLabel={emptyState}
              onRowClick={openEdit}
              activeRowId={dialogOpen ? editing?.id : null}
              sort={sort}
              onSortChange={(next) => {
                setPage(1)
                setSort(next)
              }}
              selectable
              selectedIds={selectedIds}
              onSelectedIdsChange={setSelectedIds}
            />

            {/* Al final también: es donde uno termina de leer las filas. */}
            {totalPages > 1 && (
              <DataTablePagination
                className="pt-1"
                page={currentPage}
                pageCount={totalPages}
                onPageChange={setPage}
                total={sortedProducts.length}
                pageSize={pageSize}
                disabled={loading}
              />
            )}
          </>
        )}
      </div>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editing ? t("catalog.editProduct") : t("catalog.newProduct")}</DialogTitle>
            <DialogDescription>{t("catalog.dialogSubtitle")}</DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-2">
            {editing && editing.managed_fields.length > 0 && (
              <p className="rounded-md border bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
                {t("catalog.managedByWoo")}
              </p>
            )}

            <div className="space-y-2">
              <Label htmlFor="product-name">{t("catalog.columnName")}</Label>
              <Input
                id="product-name"
                value={form.name}
                onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                disabled={managedFields.has("name")}
                placeholder={t("catalog.namePlaceholder")}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="product-price">{t("catalog.columnPrice")}</Label>
              <Input
                id="product-price"
                type="number"
                min="0"
                step="0.01"
                value={form.price}
                onChange={(e) => setForm((f) => ({ ...f, price: e.target.value }))}
                disabled={managedFields.has("price")}
                placeholder="0.00"
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="product-description">{t("catalog.columnDescription")}</Label>
              <Textarea
                id="product-description"
                value={form.description}
                onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
                disabled={managedFields.has("description")}
                placeholder={t("catalog.descriptionPlaceholder")}
                rows={4}
              />
            </div>

            <div className="flex items-center justify-between rounded-lg border p-3">
              <div>
                <Label htmlFor="product-active">{t("catalog.activeLabel")}</Label>
                <p className="text-sm text-muted-foreground">{t("catalog.activeHint")}</p>
              </div>
              <Switch
                id="product-active"
                checked={form.is_active}
                onCheckedChange={(checked) => setForm((f) => ({ ...f, is_active: checked }))}
                disabled={managedFields.has("is_active")}
              />
            </div>

            {customFields.map((field) => (
              <CustomFieldInput
                key={field.key}
                field={field}
                value={form.custom_data[field.key]}
                onChange={(next) =>
                  setForm((f) => ({
                    ...f,
                    custom_data: { ...f.custom_data, [field.key]: next },
                  }))
                }
                disabled={managedFields.has(field.key)}
              />
            ))}

            {!!editing?.variants?.length && <ProductVariantsTable variants={editing.variants} />}
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setDialogOpen(false)} disabled={saving}>
              {t("catalog.cancel")}
            </Button>
            <Button onClick={handleSave} disabled={saving}>
              {saving ? t("catalog.saving") : t("catalog.save")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={deleteTarget !== null} onOpenChange={(open) => !open && setDeleteTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("catalog.deleteConfirmTitle")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("catalog.deleteConfirmBody", { name: deleteTarget?.name ?? "" })}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("catalog.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {t("catalog.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={bulkDeleteOpen} onOpenChange={(open) => !bulkBusy && setBulkDeleteOpen(open)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("catalog.deleteConfirmTitle")}</AlertDialogTitle>
            <AlertDialogDescription>
              {plural("catalog.bulkDeleteConfirmBody", selectedIds.size)}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={bulkBusy}>{t("catalog.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault()
                void handleBulkDelete()
              }}
              disabled={bulkBusy}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {t("catalog.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <UniversalImportDialog
        open={importOpen}
        onOpenChange={setImportOpen}
        productFields={productFields}
        onImportComplete={() => void reload()}
      />

      {mercadoLibreTarget && (
        <MercadoLibreProductDialog
          productId={mercadoLibreTarget.id}
          open
          onOpenChange={(open) => !open && setMercadoLibreTarget(null)}
        />
      )}
    </div>
  )
}
