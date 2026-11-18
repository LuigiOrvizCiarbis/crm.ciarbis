"use client"

import { useTranslation } from "@/hooks/useTranslation"
import type { ProductVariant } from "@/lib/api/products"
import { DEFAULT_CURRENCY, formatCurrency } from "@/lib/currency"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"

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

  return (
    <div className="space-y-2">
      <div>
        <p className="text-sm font-medium">{t("catalog.variants")}</p>
        <p className="text-xs text-muted-foreground">{t("catalog.variantsHint")}</p>
      </div>
      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t("catalog.variants")}</TableHead>
              <TableHead>{t("catalog.variantSku")}</TableHead>
              <TableHead className="text-right">{t("catalog.columnPrice")}</TableHead>
              <TableHead>{t("catalog.variantStock")}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {variants.map((variant) => (
              <TableRow key={variant.id}>
                <TableCell className="font-medium">
                  {Object.entries(variant.attributes)
                    .map(([name, value]) => `${name}: ${value || t("catalog.variantAnyValue")}`)
                    .join(" · ")}
                </TableCell>
                <TableCell className="whitespace-nowrap text-muted-foreground">{variant.sku ?? "—"}</TableCell>
                <TableCell className="whitespace-nowrap text-right tabular-nums">
                  {formatCurrency(variant.price, DEFAULT_CURRENCY)}
                </TableCell>
                <TableCell
                  className={`whitespace-nowrap ${variant.stock_status === "outofstock" ? "text-muted-foreground" : ""}`}
                >
                  {stock(variant)}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  )
}
