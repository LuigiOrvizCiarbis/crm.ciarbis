"use client"

import { useEffect, useState } from "react"
import { getContactStageCounts } from "@/lib/api/contacts"
import { contactFiltersToParams, type ContactFilters } from "./contactFilters"

/**
 * Conteos por etapa para el filtro. Se piden sólo con el menú abierto y se
 * rehacen cuando cambian los otros filtros o `refreshKey` (un cambio de etapa
 * los deja viejos). Si fallan, el menú sigue funcionando sin números.
 */
export function useContactStageCounts(filters: ContactFilters, enabled: boolean, refreshKey: number) {
  const params = contactFiltersToParams({ ...filters, stages: [] }).toString()
  const requestKey = `${params}#${refreshKey}`
  const [result, setResult] = useState<{ key: string; counts: Map<string, number> | null } | null>(null)
  const fresh = result?.key === requestKey

  useEffect(() => {
    if (!enabled || fresh) return
    const controller = new AbortController()
    // Misma espera que el listado: no se cuenta en cada tecla de la búsqueda.
    const timeout = setTimeout(() => {
      getContactStageCounts(new URLSearchParams(params), controller.signal)
        .then((counts) => setResult({ key: requestKey, counts }))
        .catch(() => {
          if (!controller.signal.aborted) setResult({ key: requestKey, counts: null })
        })
    }, 300)
    return () => {
      clearTimeout(timeout)
      controller.abort()
    }
  }, [enabled, fresh, params, requestKey])

  return {
    counts: fresh ? result?.counts ?? undefined : undefined,
    loading: enabled && !fresh,
  }
}
