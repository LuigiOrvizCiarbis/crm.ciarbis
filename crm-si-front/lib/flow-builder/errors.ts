import { screenBlocks } from "./repeater";
import type { DefinitionErrors } from "./validate";
import type { FlowDefinition } from "./types";

type FieldErrors = Record<string, string[]>;

/**
 * Errores agrupados por el `uid` de cada pantalla y bloque. Así siguen pegados a su
 * bloque aunque después se reordene, y se pueden descartar cuando el bloque cambia.
 * La clave interna es el resto de la ruta (`label`, `options.0.title`) o "" para el propio elemento.
 */
export interface GroupedErrors {
  global: string[];
  screens: Record<string, FieldErrors>;
  blocks: Record<string, FieldErrors>;
  appointment: FieldErrors;
}

export const EMPTY_ERRORS: GroupedErrors = { global: [], screens: {}, blocks: {}, appointment: {} };

const BLOCK_PATH = /^screens\.(\d+)\.blocks\.(\d+)(?:\.(.+))?$/;
const SCREEN_PATH = /^screens\.(\d+)(?:\.(.+))?$/;
const APPOINTMENT_PATH = /^appointment(?:\.(.+))?$/;

function push(target: FieldErrors, key: string, messages: string[]) {
  target[key] = [...(target[key] ?? []), ...messages];
}

export function groupErrors(errors: DefinitionErrors, definition: FlowDefinition): GroupedErrors {
  const grouped: GroupedErrors = { global: [], screens: {}, blocks: {}, appointment: {} };

  for (const [path, messages] of Object.entries(errors)) {
    const block = BLOCK_PATH.exec(path);
    const blockUid = block ? screenBlocks(definition.screens[Number(block[1])])[Number(block[2])]?.uid : undefined;
    if (block && blockUid) {
      push((grouped.blocks[blockUid] ??= {}), block[3] ?? "", messages);
      continue;
    }

    const screen = SCREEN_PATH.exec(path);
    const screenUid = screen ? definition.screens[Number(screen[1])]?.uid : undefined;
    if (screen && screenUid) {
      push((grouped.screens[screenUid] ??= {}), screen[2] ?? "", messages);
      continue;
    }

    const appointment = APPOINTMENT_PATH.exec(path);
    if (appointment) {
      push(grouped.appointment, appointment[1] ?? "", messages);
      continue;
    }

    grouped.global.push(...messages);
  }

  return grouped;
}

export function mergeErrors(a: GroupedErrors, b: GroupedErrors): GroupedErrors {
  const mergeRecord = (x: Record<string, FieldErrors>, y: Record<string, FieldErrors>) => {
    const result: Record<string, FieldErrors> = {};
    for (const uid of new Set([...Object.keys(x), ...Object.keys(y)])) {
      result[uid] = {};
      for (const [key, messages] of Object.entries({ ...x[uid] })) push(result[uid], key, messages);
      for (const [key, messages] of Object.entries({ ...y[uid] })) push(result[uid], key, messages);
    }
    return result;
  };
  const appointment: FieldErrors = {};
  for (const [key, messages] of Object.entries(a.appointment)) push(appointment, key, messages);
  for (const [key, messages] of Object.entries(b.appointment)) push(appointment, key, messages);

  return {
    global: [...new Set([...a.global, ...b.global])],
    screens: mergeRecord(a.screens, b.screens),
    blocks: mergeRecord(a.blocks, b.blocks),
    appointment,
  };
}

export function hasErrors(errors: GroupedErrors): boolean {
  return (
    errors.global.length > 0 ||
    Object.keys(errors.screens).length > 0 ||
    Object.keys(errors.blocks).length > 0 ||
    Object.keys(errors.appointment).length > 0
  );
}

/** Índice de la primera pantalla con errores (propios o de sus bloques), o null. */
export function firstScreenWithErrors(errors: GroupedErrors, definition: FlowDefinition): number | null {
  const index = definition.screens.findIndex(
    (screen) =>
      (screen.uid !== undefined && errors.screens[screen.uid] !== undefined) ||
      screenBlocks(screen).some((block) => block.uid !== undefined && errors.blocks[block.uid] !== undefined),
  );
  return index === -1 ? null : index;
}

/**
 * Convierte los errores 422 de Laravel (`builder_definition.screens.0.blocks.1.key`) al
 * formato del validador del cliente. Las claves de fuera de la definición (`name`, `categories`)
 * quedan como globales.
 */
export function definitionErrorsFromServer(errors: Record<string, string[]>): DefinitionErrors {
  const result: DefinitionErrors = {};
  for (const [path, messages] of Object.entries(errors)) {
    const key = path.startsWith("builder_definition.")
      ? path.slice("builder_definition.".length)
      : path === "builder_definition"
        ? "definition"
        : path;
    result[key] = [...(result[key] ?? []), ...messages];
  }
  return result;
}
