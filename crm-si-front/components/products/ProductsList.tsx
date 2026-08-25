"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { MoreVertical, Pencil, Plus, Search, Trash2, Upload, X } from "lucide-react"
import { useTranslation } from "@/hooks/useTranslation"
import { useToast } from "@/components/Toast"
import {
  createProduct,
  deleteProduct,
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
import { DataTable, type DataTableColumn, type DataTableSort } from "@/components/ui/data-table"
import { CustomFieldInput } from "@/components/CustomFieldInput"
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
import { ImportProductsDialog } from "@/components/products/import-products-dialog"

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

const PAGE_SIZE = 10

// Keys that map to real product columns rather than to custom_data. `name` is
// always fixed; price/description/is_active are seeded ProductField defaults but
// still live as native columns/controls.
const NATIVE_FIELD_KEYS = new Set(["name", "price", "description", "is_active"])

type TranslateFn = ReturnType<typeof useTranslation>["t"]

function formatCustomValue(raw: unknown, type: ProductField["type"], t: TranslateFn): string {
  if (raw === null || raw === undefined || raw === "") return "—"
  if (type === "repeater" && Array.isArray(raw)) return `${raw.length} ${raw.length === 1 ? "element" : "elements"}`
  if (Array.isArray(raw)) return raw.length ? raw.join(", ") : "—"
  if (type === "boolean") return raw ? t("common.yes") : t("common.no")
  return String(raw)
}

const sourceColors: Record<string, string> = {
  manual: "bg-gray-500/10 text-gray-600 border-gray-200 dark:border-gray-700",
  import: "bg-blue-500/10 text-blue-600 border-blue-200 dark:border-blue-800",
  woocommerce: "bg-purple-500/10 text-purple-600 border-purple-200 dark:border-purple-800",
}

const sourceLabels: Record<string, string> = {
  manual: "Manual",
  import: "CSV",
  woocommerce: "WooCommerce",
}

export function ProductsList() {
  const { t } = useTranslation()
  const { addToast } = useToast()

  const [products, setProducts] = useState<Product[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState("")
  const [page, setPage] = useState(1)

  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<Product | null>(null)
  const [form, setForm] = useState<FormState>(EMPTY_FORM)
  const [saving, setSaving] = useState(false)

  const [deleteTarget, setDeleteTarget] = useState<Product | null>(null)
  const [importOpen, setImportOpen] = useState(false)

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

  const load = useCallback(
    async (searchTerm?: string) => {
      setLoading(true)
      try {
        const data = await getProducts(searchTerm ? { search: searchTerm } : {})
        setProducts(data)
      } catch (error) {
        addToast({
          type: "error",
          title: t("catalog.loadError"),
          description: (error as Error).message,
        })
      } finally {
        setLoading(false)
      }
    },
    [addToast, t],
  )

  useEffect(() => {
    void load()
  }, [load])

  useEffect(() => {
    const handle = setTimeout(() => {
      setPage(1)
      void load(search.trim() || undefined)
    }, 300)
    return () => clearTimeout(handle)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

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
      custom_data: form.custom_data,
    }

    setSaving(true)
    try {
      if (editing) {
        await updateProduct(editing.id, payload)
        addToast({ type: "success", title: t("catalog.updated") })
      } else {
        await createProduct(payload)
        addToast({ type: "success", title: t("catalog.created") })
      }
      setDialogOpen(false)
      await load(search.trim() || undefined)
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
      await load(search.trim() || undefined)
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
    return [...products].sort((a, b) => {
      switch (sort.columnId) {
        case "name":
          return a.name.localeCompare(b.name) * dir
        case "price": {
          const av = a.price === null ? -Infinity : Number(a.price)
          const bv = b.price === null ? -Infinity : Number(b.price)
          return (av - bv) * dir
        }
        case "source":
          return a.source.localeCompare(b.source) * dir
        case "status":
          return (Number(a.is_active) - Number(b.is_active)) * dir
        default:
          return 0
      }
    })
  }, [products, sort])

  const totalPages = Math.max(1, Math.ceil(sortedProducts.length / PAGE_SIZE))
  const currentPage = Math.min(page, totalPages)
  const pageStart = (currentPage - 1) * PAGE_SIZE
  const paginatedProducts = sortedProducts.slice(pageStart, pageStart + PAGE_SIZE)

  const formatPrice = (price: string | null) => {
    if (price === null || price === "") return "—"
    const value = Number(price)
    if (Number.isNaN(value)) return price
    return `$${value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
  }

  const selectedProducts = products.filter((p) => selectedIds.has(p.id))
  const clearSelection = () => setSelectedIds(new Set())

  const handleBulkSetActive = async (active: boolean) => {
    if (selectedProducts.length === 0) return
    setBulkBusy(true)
    try {
      await Promise.all(selectedProducts.map((p) => updateProduct(p.id, { is_active: active })))
      addToast({
        type: "success",
        title: active ? t("catalog.bulkActivated") : t("catalog.bulkDeactivated"),
      })
      clearSelection()
      await load(search.trim() || undefined)
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
      await load(search.trim() || undefined)
    } catch (error) {
      addToast({ type: "error", title: t("catalog.deleteError"), description: (error as Error).message })
    } finally {
      setBulkBusy(false)
      setBulkDeleteOpen(false)
    }
  }

  const columns: DataTableColumn<Product>[] = [
    {
      id: "name",
      header: t("catalog.columnName"),
      sortable: true,
      cell: (product) => (
        <div>
          <div className="font-medium">{product.name}</div>
          {product.description && (
            <div className="line-clamp-1 text-sm text-muted-foreground">{product.description}</div>
          )}
        </div>
      ),
    },
    {
      id: "price",
      header: t("catalog.columnPrice"),
      sortable: true,
      width: "160px",
      className: "tabular-nums",
      cell: (product) => formatPrice(product.price),
    },
    {
      id: "source",
      header: t("catalog.columnSource"),
      sortable: true,
      width: "144px",
      cell: (product) => (
        <Badge variant="outline" className={sourceColors[product.source] || sourceColors.manual}>
          {sourceLabels[product.source] || product.source}
        </Badge>
      ),
    },
    {
      id: "status",
      header: t("catalog.columnStatus"),
      sortable: true,
      width: "128px",
      cell: (product) => (
        <Badge variant={product.is_active ? "default" : "secondary"}>
          {product.is_active ? t("catalog.active") : t("catalog.inactive")}
        </Badge>
      ),
    },
    ...customFields.map<DataTableColumn<Product>>((field) => ({
      id: `custom:${field.key}`,
      header: field.label,
      minWidth: "140px",
      cell: (product) => (
        field.type === "repeater" ? (
          <button type="button" className="text-left text-sm underline-offset-2 hover:underline" onClick={() => openEdit(product)}>
            {formatCustomValue(product.custom_data?.[field.key], field.type, t)}
          </button>
        ) : (
          <span className="text-sm">{formatCustomValue(product.custom_data?.[field.key], field.type, t)}</span>
        )
      ),
    })),
    {
      id: "actions",
      header: t("catalog.columnActions"),
      align: "right",
      width: "64px",
      cell: (product) => (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" className="h-7 w-7 text-muted-foreground hover:text-foreground">
              <MoreVertical className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onClick={() => openEdit(product)}>
              <Pencil className="mr-2 h-4 w-4" />
              {t("catalog.edit")}
            </DropdownMenuItem>
            <DropdownMenuItem
              className="text-destructive focus:text-destructive"
              onClick={() => setDeleteTarget(product)}
            >
              <Trash2 className="mr-2 h-4 w-4" />
              {t("catalog.delete")}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      ),
    },
  ]

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

      <div className="relative max-w-sm">
        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={t("catalog.searchPlaceholder")}
          className="pl-9"
        />
      </div>

      {selectedIds.size > 0 && (
        <div className="flex items-center justify-between rounded-lg border border-primary/20 bg-primary/10 px-4 py-3">
          <Badge variant="secondary" className="text-sm font-semibold">
            {t("catalog.bulkSelected", { count: selectedIds.size })}
          </Badge>
          <div className="flex items-center gap-2">
            <Button size="sm" variant="outline" disabled={bulkBusy} onClick={() => handleBulkSetActive(true)}>
              {t("catalog.activate")}
            </Button>
            <Button size="sm" variant="outline" disabled={bulkBusy} onClick={() => handleBulkSetActive(false)}>
              {t("catalog.deactivate")}
            </Button>
            <Button
              size="sm"
              variant="outline"
              className="text-destructive hover:text-destructive"
              disabled={bulkBusy}
              onClick={() => setBulkDeleteOpen(true)}
            >
              <Trash2 className="mr-1 h-3.5 w-3.5" />
              {t("catalog.delete")}
            </Button>
            <Button size="sm" variant="ghost" disabled={bulkBusy} onClick={clearSelection}>
              <X className="mr-1 h-3.5 w-3.5" />
              {t("catalog.cancel")}
            </Button>
          </div>
        </div>
      )}

      <DataTable
        columns={columns}
        data={paginatedProducts}
        getRowId={(product) => product.id}
        loading={loading}
        loadingLabel={t("catalog.loading")}
        emptyLabel={t("catalog.empty")}
        sort={sort}
        onSortChange={(next) => {
          setPage(1)
          setSort(next)
        }}
        selectable
        selectedIds={selectedIds}
        onSelectedIdsChange={setSelectedIds}
      />

      {!loading && products.length > 0 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">
            {t("catalog.paginationInfo", {
              from: pageStart + 1,
              to: pageStart + paginatedProducts.length,
              total: products.length,
            })}
          </p>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={currentPage <= 1}
            >
              {t("catalog.previous")}
            </Button>
            <span className="text-sm text-muted-foreground">
              {currentPage} / {totalPages}
            </span>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              disabled={currentPage >= totalPages}
            >
              {t("catalog.next")}
            </Button>
          </div>
        </div>
      )}

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editing ? t("catalog.editProduct") : t("catalog.newProduct")}</DialogTitle>
            <DialogDescription>{t("catalog.dialogSubtitle")}</DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-2">
            <div className="space-y-2">
              <Label htmlFor="product-name">{t("catalog.columnName")}</Label>
              <Input
                id="product-name"
                value={form.name}
                onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
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
                placeholder="0.00"
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="product-description">{t("catalog.columnDescription")}</Label>
              <Textarea
                id="product-description"
                value={form.description}
                onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
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
              />
            </div>

            {customFields.map((field) => (
              <div key={field.key} className="space-y-2">
                <Label htmlFor={`cf-${field.key}`}>
                  {field.label}
                  {field.is_required ? <span className="text-destructive"> *</span> : null}
                </Label>
                <CustomFieldInput
                  field={field}
                  value={form.custom_data[field.key]}
                  onChange={(next) =>
                    setForm((f) => ({
                      ...f,
                      custom_data: { ...f.custom_data, [field.key]: next },
                    }))
                  }
                />
              </div>
            ))}
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
            <AlertDialogAction onClick={handleDelete}>{t("catalog.delete")}</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={bulkDeleteOpen} onOpenChange={(open) => !bulkBusy && setBulkDeleteOpen(open)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("catalog.deleteConfirmTitle")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("catalog.bulkDeleteConfirmBody", { count: selectedIds.size })}
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

      <ImportProductsDialog
        open={importOpen}
        onOpenChange={setImportOpen}
        onImportComplete={() => void load(search.trim() || undefined)}
      />
    </div>
  )
}
