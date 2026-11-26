import type { ContactField, ContactFieldType } from "@/lib/api/contact-fields"

export type ContactsAudience = "all" | "clients"

/** Un filtro por campo custom; el operador lo decide el tipo del campo (ver filterKindForField). */
export type CustomFieldFilter =
  | { kind: "any"; values: string[] }
  | { kind: "contains"; text: string }
  | { kind: "boolean"; value: boolean }
  | { kind: "range"; from?: string; to?: string }

export type CustomFieldFilterKind = CustomFieldFilter["kind"]

export type CustomFieldFilters = Record<string, CustomFieldFilter>

export interface ContactFilters {
  search: string
  /** "all" = sin filtro de origen. */
  source: string
  tags: string[]
  /** Ids de etapa como string, más "none" para los contactos sin oportunidades. */
  stages: string[]
  audience: ContactsAudience
  custom: CustomFieldFilters
}

export const EMPTY_CONTACT_FILTERS: ContactFilters = {
  search: "",
  source: "all",
  tags: [],
  stages: [],
  audience: "all",
  custom: {},
}

// Archivo y Repeater no tienen un valor comparable: no se ofrecen.
const KIND_BY_TYPE: Partial<Record<ContactFieldType, CustomFieldFilterKind>> = {
  select: "any",
  multi_select: "any",
  boolean: "boolean",
  text: "contains",
  email: "contains",
  url: "contains",
  phone: "contains",
  date: "range",
  number: "range",
  currency: "range",
}

export function filterKindForField(field: ContactField): CustomFieldFilterKind | null {
  const kind = KIND_BY_TYPE[field.type] ?? null
  if (kind === "any" && !field.options?.choices?.length) return null
  return kind
}

export function isCustomFilterActive(filter: CustomFieldFilter): boolean {
  switch (filter.kind) {
    case "any":
      return filter.values.length > 0
    case "contains":
      return filter.text.trim() !== ""
    case "boolean":
      return true
    case "range":
      return Boolean(filter.from || filter.to)
  }
}

export function withCustomFilter(
  filters: CustomFieldFilters,
  key: string,
  filter: CustomFieldFilter | undefined,
): CustomFieldFilters {
  const next = { ...filters }
  if (filter && isCustomFilterActive(filter)) next[key] = filter
  else delete next[key]
  return next
}

/**
 * Descarta los filtros de campos que ya no existen o que cambiaron de tipo:
 * una URL vieja o compartida no debe dejar un chip que el backend ignora.
 */
export function pruneCustomFilters(filters: CustomFieldFilters, fields: ContactField[]): CustomFieldFilters {
  const fieldsByKey = new Map(fields.map((field) => [field.key, field]))
  const entries = Object.entries(filters).filter(([key, filter]) => {
    const field = fieldsByKey.get(key)
    return field !== undefined && filterKindForField(field) === filter.kind
  })
  return entries.length === Object.keys(filters).length ? filters : Object.fromEntries(entries)
}

export function hasActiveContactFilters(filters: ContactFilters): boolean {
  return (
    filters.search !== "" ||
    filters.source !== "all" ||
    filters.tags.length > 0 ||
    filters.stages.length > 0 ||
    filters.audience === "clients" ||
    Object.values(filters.custom).some(isCustomFilterActive)
  )
}

const FILTER_PARAM_NAMES: Record<string, true> = { search: true, source: true, tags: true, stages: true, billing: true }
const CUSTOM_PARAM = /^(custom|custom_any|custom_contains|custom_range)\[([^\]]+)\](?:\[(\w*)\])?$/

/** True para los parámetros que maneja este módulo; el resto de la URL se respeta. */
export function isContactFilterParam(name: string): boolean {
  return FILTER_PARAM_NAMES[name] === true || CUSTOM_PARAM.test(name)
}

/**
 * Serializa con las mismas keys que GET /api/contacts, así la URL de la
 * página y el fetch del listado comparten un solo formato.
 */
export function contactFiltersToParams(filters: ContactFilters): URLSearchParams {
  const params = new URLSearchParams()
  if (filters.search) params.set("search", filters.search)
  if (filters.source !== "all") params.set("source", filters.source)
  if (filters.tags.length > 0) params.set("tags", filters.tags.join(","))
  if (filters.stages.length > 0) params.set("stages", filters.stages.join(","))
  if (filters.audience === "clients") params.set("billing", "clients")

  for (const [key, filter] of Object.entries(filters.custom)) {
    if (!isCustomFilterActive(filter)) continue
    switch (filter.kind) {
      case "any":
        for (const value of filter.values) params.append(`custom_any[${key}][]`, value)
        break
      case "contains":
        params.set(`custom_contains[${key}]`, filter.text.trim())
        break
      case "boolean":
        params.set(`custom[${key}]`, String(filter.value))
        break
      case "range":
        if (filter.from) params.set(`custom_range[${key}][from]`, filter.from)
        if (filter.to) params.set(`custom_range[${key}][to]`, filter.to)
        break
    }
  }

  return params
}

export function contactFiltersFromParams(params: Pick<URLSearchParams, "get" | "forEach">): ContactFilters {
  const custom: CustomFieldFilters = {}

  params.forEach((value, name) => {
    const match = CUSTOM_PARAM.exec(name)
    if (!match || value === "") return
    const [, param, key, sub] = match
    const current = custom[key]

    if (param === "custom_any" && sub === "") {
      custom[key] = { kind: "any", values: [...(current?.kind === "any" ? current.values : []), value] }
    } else if (param === "custom_contains" && sub === undefined) {
      custom[key] = { kind: "contains", text: value }
    } else if (param === "custom" && sub === undefined && (value === "true" || value === "false")) {
      custom[key] = { kind: "boolean", value: value === "true" }
    } else if (param === "custom_range" && (sub === "from" || sub === "to")) {
      custom[key] = { ...(current?.kind === "range" ? current : { kind: "range" }), [sub]: value }
    }
  })

  return {
    search: params.get("search") ?? "",
    source: params.get("source") || "all",
    tags: (params.get("tags") ?? "").split(",").map((slug) => slug.trim()).filter(Boolean),
    stages: (params.get("stages") ?? "").split(",").map((key) => key.trim()).filter(Boolean),
    audience: params.get("billing") === "clients" ? "clients" : "all",
    custom,
  }
}
