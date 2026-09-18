export type ImportFieldType = "text" | "number" | "currency" | "date" | "boolean" | "email" | "url" | "phone" | "select" | "multi_select"

export type ImportField = {
  id: string
  label: string
  type: ImportFieldType
  options?: { choices?: string[]; currency?: string }
}

export type ImportTarget = { value: string; label: string }

export type ImportResourceConfig = {
  resource: string
  title: string
  description: string
  nativeTargets: ImportTarget[]
  requireName?: boolean
  allowUpdates?: boolean
  fieldTypes?: ImportFieldType[]
}
