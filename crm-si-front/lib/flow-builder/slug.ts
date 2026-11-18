import { KEY_MAX, RESERVED_KEY_PREFIX, hasForbiddenKeyPrefix, isReservedKey } from "./limits";

const FALLBACK_PREFIX = "campo_";

/**
 * Nombre interno de una pregunta a partir de su etiqueta: minúsculas, sin acentos,
 * sólo `[a-z0-9_]`, empieza con letra y no repite otra clave del formulario ni usa
 * una clave reservada del endpoint.
 */
export function keyFromLabel(label: string, existingKeys: Iterable<string>): string {
  const taken = new Set(existingKeys);

  let base = label
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .slice(0, KEY_MAX);

  // Un prefijo prohibido o reservado no se arregla con sufijos: se antepone `campo_`.
  if (!/^[a-z]/.test(base) || hasForbiddenKeyPrefix(base) || base.startsWith(RESERVED_KEY_PREFIX)) {
    base = `${FALLBACK_PREFIX}${base}`.slice(0, KEY_MAX);
  }
  base = base.replace(/_+$/, "");

  const isFree = (candidate: string) => !taken.has(candidate) && !isReservedKey(candidate);
  if (isFree(base)) return base;

  for (let n = 2; ; n++) {
    const suffix = `_${n}`;
    // Se recorta la base para que la clave con sufijo siga entrando en el máximo.
    const candidate = `${base.slice(0, KEY_MAX - suffix.length).replace(/_+$/, "")}${suffix}`;
    if (isFree(candidate)) return candidate;
  }
}
