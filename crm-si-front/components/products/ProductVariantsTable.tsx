"use client"

import { useTranslation } from "@/hooks/useTranslation"
import type { ProductVariant } from "@/lib/api/products"
import { DEFAULT_CURRENCY, formatCurrency } from "@/lib/currency"
import { cn } from "@/lib/utils"
import { DataTable, type DataTableColumn } from "@/components/ui/data-table"

/**
 * Variantes de un producto sincronizado (talle, color, capacidad…). Solo
 * lectura: se gestionan en la tienda de origen.
 */
export function ProductVariantsTable({ variants }: { variants: ProductVariant[] }) {
  const { t } = useTranslation()

  const stock = (variant: ProductVariant) => {
    // Las extensiones de Woo pueden sumar estados sin traducción (p. ej. preorder).
    const key = `catalog.stockStatus_${variant.stock_status}`
    const translated = variant.stock_status ? t(key) : null
    const status = translated === null ? "—" : translated === key ? variant.stock_status : translated
    return variant.stock_quantity === null ? status : `${variant.stock_quantity} · ${status}`
  }

  const columns: DataTableColumn<ProductVariant>[] = [
    {
      id: "attributes",
      header: t("catalog.variants"),
      rowHeader: true,
      cell: (variant) =>
        Object.entries(variant.attributes)
          .map(([name, value]) => `${name}: ${value || t("catalog.variantAnyValue")}`)
          .join(" · "),
    },
    {
      id: "sku",
      header: t("catalog.variantSku"),
      className: "whitespace-nowrap text-muted-foreground",
      cell: (variant) => variant.sku ?? "—",
    },
    {
      id: "price",
      header: t("catalog.columnPrice"),
      align: "right",
      className: (variant) => cn("whitespace-nowrap tabular-nums", variant.price === null && "text-muted-foreground"),
      cell: (variant) => formatCurrency(variant.price, DEFAULT_CURRENCY),
    },
    {
      id: "stock",
      header: t("catalog.variantStock"),
      className: (variant) =>
        cn("whitespace-nowrap tabular-nums", variant.stock_status === "outofstock" && "text-muted-foreground"),
      cell: stock,
    },
  ]

  return (
    <div className="space-y-2">
      <div>
        <p className="text-sm font-medium">{t("catalog.variants")}</p>
        <p className="text-xs text-muted-foreground">{t("catalog.variantsHint")}</p>
      </div>
      <DataTable
        columns={columns}
        data={variants}
        getRowId={(variant) => variant.id}
        variant="plain"
        density="compact"
        maxHeight="15rem"
      />
    </div>
  )
}
