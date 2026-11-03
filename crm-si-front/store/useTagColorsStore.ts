import { create } from "zustand"

interface TagColorsState {
  /** Color confirmado o en curso por id: los chips ya cargados lo leen antes que el del payload. */
  colors: Record<number, string>
  setColor: (tagId: number, color: string) => void
}

/**
 * Las etiquetas viajan embebidas en conversaciones y contactos, así que un
 * cambio de color no llega a los chips ya dibujados hasta recargar. Este store
 * propaga el cambio en la sesión actual; otros usuarios lo ven al recargar.
 */
export const useTagColorsStore = create<TagColorsState>((set) => ({
  colors: {},
  setColor: (tagId, color) => set((state) => ({ colors: { ...state.colors, [tagId]: color } })),
}))

export function useTagColor(tagId: number, fallback: string): string {
  return useTagColorsStore((state) => state.colors[tagId]) ?? fallback
}
