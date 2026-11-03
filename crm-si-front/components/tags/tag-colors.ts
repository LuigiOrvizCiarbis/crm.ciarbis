/**
 * Paleta curada de etiquetas. El color se guarda como hex (el backend valida
 * #RRGGBB) y sólo se usa en borde, tinte al 10% y punto: nunca como color de
 * texto, así que cualquier tono medio se lee bien en claro y en oscuro.
 * Incluye los seis colores que se asignaban antes al crear, para que las
 * etiquetas existentes caigan dentro de la paleta.
 */
export const TAG_COLORS = [
  { key: "red", hex: "#ef4444" },
  { key: "orange", hex: "#f97316" },
  { key: "amber", hex: "#f59e0b" },
  { key: "green", hex: "#10b981" },
  { key: "teal", hex: "#14b8a6" },
  { key: "sky", hex: "#0ea5e9" },
  { key: "violet", hex: "#8b5cf6" },
  { key: "pink", hex: "#ec4899" },
  { key: "slate", hex: "#64748b" },
] as const

export type TagColorKey = (typeof TAG_COLORS)[number]["key"]

export function findTagColor(hex: string | null | undefined) {
  if (!hex) return undefined
  const normalized = hex.toLowerCase()
  return TAG_COLORS.find((color) => color.hex === normalized)
}

/** Color para una etiqueta nueva: rota por la paleta según cuántas hay. */
export function nextTagColor(existingCount: number): string {
  return TAG_COLORS[existingCount % TAG_COLORS.length].hex
}
