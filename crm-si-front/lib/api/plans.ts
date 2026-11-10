export interface PlanFeature {
  key: string
  /** Fallback en español definido en la DB; el front intenta primero la traducción por key. */
  label: string
}

/** Valores de la tabla comparativa: número en string, "custom" o booleano. */
export type PlanCompareValue = string | boolean

export interface Plan {
  id: number
  key: string
  name: string
  priceMonthly: number | null
  features: PlanFeature[]
  compare: Record<string, PlanCompareValue>
}

interface RawPlan {
  id: number
  key: string
  name: string
  price_monthly: string | number | null
  limits: {
    features?: PlanFeature[]
    compare?: Record<string, PlanCompareValue>
  } | null
}

function mapPlan(raw: RawPlan): Plan {
  return {
    id: raw.id,
    key: raw.key,
    name: raw.name,
    priceMonthly: raw.price_monthly === null ? null : Number(raw.price_monthly),
    features: raw.limits?.features ?? [],
    compare: raw.limits?.compare ?? {},
  }
}

export async function getPlans(): Promise<Plan[]> {
  // Catálogo público: se consulta con o sin sesión (la landing linkea a /pricing).
  const headers: Record<string, string> = { Accept: "application/json" }

  const res = await fetch("/api/plans", {
    headers,
    cache: "no-store",
  })

  if (!res.ok) {
    const json = await res.json().catch(() => ({}))
    throw new Error(json.message || "Error")
  }

  const json = await res.json()
  return (json.data || []).map(mapPlan)
}
