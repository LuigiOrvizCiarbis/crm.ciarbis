import { create } from "zustand"

interface ComposerStore {
  draft: string
  setDraft: (draft: string) => void
}

/**
 * Borrador del composer del chat. Vive fuera de la página para que cada tecla
 * re-renderice sólo el input (que se suscribe a `draft`) y no la lista de
 * conversaciones ni el hilo. La página escribe con `setDraft` (editar, usar
 * borrador IA, restaurar tras un error) y lee con `getState()` sin suscribirse.
 */
export const useComposerStore = create<ComposerStore>()((set) => ({
  draft: "",
  setDraft: (draft) => set({ draft }),
}))
