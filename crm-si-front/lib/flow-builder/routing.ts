import { isQuestionBlock } from "./limits";
import { isRepeaterScreen, screenBlocks } from "./repeater";
import type { ChoiceOption, FlowBlock, FlowDefinition, FlowScreen, SingleChoiceBlock, Target } from "./types";

/**
 * Navegación entre pantallas del constructor: destinos (`Target`), ids de pantalla y alcance.
 * Mismas reglas que el backend (FlowDefinitionValidator / FlowDefinitionCompiler).
 */

export const SCREEN_ID_PATTERN = /^[A-Za-z0-9_-]{1,40}$/;
export const SCREEN_TARGET_PREFIX = "screen:";
export const DEFAULT_TARGET: Target = "following";

/** Destino resuelto: índice de una pantalla o el final (que con turno pasa a la reserva). */
export type ResolvedTarget = number | "end";

export function screenTarget(id: string): Target {
  return `${SCREEN_TARGET_PREFIX}${id}`;
}

export function targetScreenId(target: Target | undefined): string | null {
  return target?.startsWith(SCREEN_TARGET_PREFIX) ? target.slice(SCREEN_TARGET_PREFIX.length) : null;
}

/** Id corto aleatorio para una pantalla nueva, distinto de los existentes. */
export function newScreenId(taken: Iterable<string> = []): string {
  const used = new Set(taken);
  let id: string;
  do {
    id = `s_${Math.random().toString(36).slice(2, 10).padEnd(8, "0")}`;
  } while (used.has(id));
  return id;
}

/**
 * Garantiza que cada pantalla tenga un `id` válido y único. Reutiliza el `uid` del cliente
 * si cumple el formato; si no, genera uno corto.
 */
export function withScreenIds(definition: FlowDefinition): FlowDefinition {
  const used = new Set<string>();
  let changed = false;
  const screens = definition.screens.map((screen) => {
    const candidates = [screen.id, screen.uid];
    let id = candidates.find((value): value is string => !!value && SCREEN_ID_PATTERN.test(value) && !used.has(value));
    if (!id) id = newScreenId(used);
    used.add(id);
    if (id === screen.id) return screen;
    changed = true;
    return { ...screen, id };
  });
  return changed ? { ...definition, screens } : definition;
}

/** Preguntas que admiten `branches`. */
export function isBranchable(block: FlowBlock): block is SingleChoiceBlock {
  return block.type === "dropdown" || block.type === "single_choice";
}

/** Pregunta con reglas no vacías: es la que cambia el camino. */
export function hasBranches(block: FlowBlock): block is SingleChoiceBlock {
  return isBranchable(block) && Object.keys(block.branches ?? {}).length > 0;
}

/** Primera pregunta de la pantalla que cambia el camino, o null. */
export function branchingBlock(screen: FlowScreen): SingleChoiceBlock | null {
  return screenBlocks(screen).find(hasBranches) ?? null;
}

/** Resuelve un destino desde la pantalla `index`. null si apunta a una pantalla que no existe. */
export function resolveTarget(target: Target | undefined, index: number, screens: FlowScreen[]): ResolvedTarget | null {
  const value = target ?? DEFAULT_TARGET;
  if (value === "end") return "end";
  if (value === "following") return index + 1 < screens.length ? index + 1 : "end";
  const id = targetScreenId(value);
  const found = screens.findIndex((screen) => screen.id === id);
  return found === -1 ? null : found;
}

/** Un destino es válido si es el final o una pantalla estrictamente posterior. */
export function isForwardTarget(target: Target | undefined, index: number, screens: FlowScreen[]): boolean {
  const resolved = resolveTarget(target, index, screens);
  return resolved === "end" || (resolved !== null && resolved > index);
}

/** Regla de una opción. Los ids siguen al título, así que pueden coincidir con claves de `Object.prototype`. */
export function branchTarget(block: SingleChoiceBlock, optionId: string): Target | undefined {
  return block.branches && Object.hasOwn(block.branches, optionId) ? block.branches[optionId] : undefined;
}

/** Destino de cada opción de la pregunta que cambia el camino (las que no tienen regla siguen a la pantalla). */
export function optionTarget(block: SingleChoiceBlock, option: ChoiceOption, screen: FlowScreen): Target {
  return branchTarget(block, option.id) ?? screen.next ?? DEFAULT_TARGET;
}

/**
 * Destinos posibles de una pantalla. Con una pregunta que cambia el camino: el de cada opción,
 * más el `next` de la pantalla si la pregunta no es obligatoria (sin respuesta se sigue a la pantalla).
 * Sin ella, sólo el `next` de la pantalla.
 */
export function screenTargets(screen: FlowScreen): Target[] {
  const block = branchingBlock(screen);
  const next = screen.next ?? DEFAULT_TARGET;
  if (!block) return [next];
  const targets = block.options.map((option) => optionTarget(block, option, screen));
  if (!block.required) targets.push(next);
  return targets;
}

/** Índices de las pantallas (salvo la primera) a las que no lleva ningún camino desde la primera. */
export function unreachableScreens(screens: FlowScreen[]): Set<number> {
  const reached = new Set<number>(screens.length > 0 ? [0] : []);
  const queue = [...reached];
  while (queue.length > 0) {
    const index = queue.shift() as number;
    for (const target of screenTargets(screens[index])) {
      const resolved = resolveTarget(target, index, screens);
      // Los saltos hacia atrás son un error aparte: no cuentan para llegar a una pantalla.
      if (typeof resolved === "number" && resolved > index && !reached.has(resolved)) {
        reached.add(resolved);
        queue.push(resolved);
      }
    }
  }
  const unreachable = new Set<number>();
  screens.forEach((_, index) => {
    if (!reached.has(index)) unreachable.add(index);
  });
  return unreachable;
}

/** Al borrar una pantalla, los destinos que la apuntaban pasan al final. */
export function dropScreenReferences(definition: FlowDefinition, screenId: string): FlowDefinition {
  const removed = screenTarget(screenId);
  return {
    ...definition,
    screens: definition.screens.map((screen) => {
      if (isRepeaterScreen(screen)) return screen.next === removed ? { ...screen, next: "end" as Target } : screen;
      let blocksChanged = false;
      const blocks = screen.blocks.map((block) => {
        if (!isBranchable(block) || !block.branches) return block;
        if (!Object.values(block.branches).includes(removed)) return block;
        blocksChanged = true;
        return {
          ...block,
          branches: Object.fromEntries(
            Object.entries(block.branches).map(([id, target]): [string, Target] => [id, target === removed ? "end" : target]),
          ),
        };
      });
      const nextChanged = screen.next === removed;
      if (!blocksChanged && !nextChanged) return screen;
      return { ...screen, blocks, ...(nextChanged ? { next: "end" as Target } : {}) };
    }),
  };
}

/**
 * Mantiene las reglas pegadas a sus opciones cuando cambian. Una regla sigue a su opción mientras
 * el id exista (reordenar o reemplazar las opciones por las de un campo no la mueve). Sólo con la
 * misma cantidad de opciones, un id que desaparece y otro nuevo en su posición es un renombre (el
 * id sigue al título): la regla pasa al id nuevo si ninguna otra opción lo usaba. Nunca se pisa
 * una regla existente; las de opciones que ya no están se descartan.
 */
export function syncBranchKeys(previous: FlowBlock, next: FlowBlock): FlowBlock {
  if (!isQuestionBlock(next) || !isBranchable(next) || !next.branches || !isBranchable(previous)) return next;
  if (previous.options === next.options) return next;

  const branches = next.branches;
  const previousIds = new Set(previous.options.map((option) => option.id));
  const nextIds = new Set(next.options.map((option) => option.id));
  const result: Record<string, Target> = {};
  for (const [id, target] of Object.entries(branches)) {
    if (nextIds.has(id)) result[id] = target;
  }
  if (previous.options.length === next.options.length) {
    previous.options.forEach((option, i) => {
      const target = Object.hasOwn(branches, option.id) ? branches[option.id] : undefined;
      const renamed = next.options[i].id;
      if (target === undefined || nextIds.has(option.id) || previousIds.has(renamed) || Object.hasOwn(result, renamed)) return;
      result[renamed] = target;
    });
  }
  return { ...next, branches: result };
}

/**
 * Un formulario usa el endpoint del CRM si tiene reserva de turno, más de una pantalla o
 * alguna pregunta que se guarda en el contacto. Sólo entonces necesita el cifrado del número.
 */
export function usesEndpoint(definition: FlowDefinition): boolean {
  return (
    definition.appointment !== null ||
    definition.screens.length > 1 ||
    definition.screens.some(
      (screen) => isRepeaterScreen(screen) || screen.blocks.some((block) => isQuestionBlock(block) && !!block.maps_to),
    )
  );
}

/** Salida de una pantalla en el diagrama: el botón final (`next`) o una opción de la pregunta que cambia el camino. */
export type RoutePort = "next" | `option:${string}`;

const OPTION_PORT_PREFIX = "option:";

export function optionPort(optionId: string): RoutePort {
  return `${OPTION_PORT_PREFIX}${optionId}`;
}

export function portOptionId(port: string): string | null {
  return port.startsWith(OPTION_PORT_PREFIX) ? port.slice(OPTION_PORT_PREFIX.length) : null;
}

export interface ScreenPort {
  port: RoutePort;
  target: Target;
  option?: ChoiceOption;
}

/**
 * Salidas activas de una pantalla, en el orden en que se dibujan: una por opción de la pregunta que
 * cambia el camino y, si esa pregunta es opcional o no existe, la del botón final.
 */
export function screenPorts(screen: FlowScreen): ScreenPort[] {
  const block = branchingBlock(screen);
  const next: ScreenPort = { port: "next", target: screen.next ?? DEFAULT_TARGET };
  if (!block) return [next];
  const ports: ScreenPort[] = block.options.map((option) => ({
    port: optionPort(option.id),
    target: optionTarget(block, option, screen),
    option,
  }));
  return block.required ? ports : [...ports, next];
}

/** La pantalla con la salida `port` apuntando a `target`. */
export function withPortTarget(screen: FlowScreen, port: RoutePort, target: Target): FlowScreen {
  const optionId = portOptionId(port);
  if (optionId === null) return { ...screen, next: target };
  const block = branchingBlock(screen);
  if (!block || isRepeaterScreen(screen)) return screen;
  return {
    ...screen,
    blocks: screen.blocks.map((item) => (item === block ? { ...block, branches: { ...block.branches, [optionId]: target } } : item)),
  };
}

/** Todos los destinos guardados en la pantalla, también los que hoy no se usan (el botón de una pregunta obligatoria). */
function storedTargets(screen: FlowScreen): Target[] {
  const targets: Target[] = [screen.next ?? DEFAULT_TARGET];
  for (const block of screenBlocks(screen)) {
    if (isBranchable(block) && block.branches) targets.push(...Object.values(block.branches));
  }
  return targets;
}

function storedSuccessors(screens: FlowScreen[], index: number): number[] {
  return storedTargets(screens[index])
    .map((target) => resolveTarget(target, index, screens))
    .filter((resolved): resolved is number => typeof resolved === "number");
}

/** Si desde la pantalla `from` se llega a `to` siguiendo los destinos guardados. */
function reaches(screens: FlowScreen[], from: number, to: number): boolean {
  const seen = new Set<number>();
  const stack = [from];
  while (stack.length > 0) {
    const index = stack.pop() as number;
    if (index === to) return true;
    if (seen.has(index)) continue;
    seen.add(index);
    stack.push(...storedSuccessors(screens, index));
  }
  return false;
}

/**
 * Si la pantalla `from` puede llevar a la pantalla `to`. Meta no admite volver atrás: no se puede
 * apuntar a la primera pantalla, a sí misma ni a una pantalla desde la que se llega a `from`.
 */
export function canRouteTo(screens: FlowScreen[], from: number, to: number): boolean {
  return to > 0 && to !== from && to < screens.length && !reaches(screens, to, from);
}

/** Si `index` puede pasar a ser la primera pantalla: ninguna otra la apunta. */
export function canBeEntry(screens: FlowScreen[], index: number): boolean {
  return screens.every((_, other) => other === index || !storedSuccessors(screens, other).includes(index));
}

function explicitTarget(target: Target | undefined, index: number, screens: FlowScreen[]): Target {
  const resolved = resolveTarget(target, index, screens);
  return resolved === null || resolved === "end" ? "end" : screenTarget(screens[resolved].id);
}

/** Reemplaza `following` (que depende del orden) por el destino concreto. Conserva la identidad si no cambia nada. */
function withExplicitRoutes(definition: FlowDefinition): FlowDefinition {
  let changed = false;
  const screens = definition.screens.map((screen, index) => {
    const next = explicitTarget(screen.next, index, definition.screens);
    if (isRepeaterScreen(screen)) {
      if (next === screen.next) return screen;
      changed = true;
      return { ...screen, next };
    }
    let blocksChanged = false;
    const blocks = screen.blocks.map((block) => {
      if (!isBranchable(block) || !block.branches) return block;
      const entries = Object.entries(block.branches);
      const explicit = entries.map(([id, target]): [string, Target] => [id, explicitTarget(target, index, definition.screens)]);
      if (explicit.every(([, target], i) => target === entries[i][1])) return block;
      blocksChanged = true;
      return { ...block, branches: Object.fromEntries(explicit) };
    });
    if (next === screen.next && !blocksChanged) return screen;
    changed = true;
    return { ...screen, next, blocks };
  });
  return changed ? { ...definition, screens } : definition;
}

/**
 * Ordena las pantallas según el recorrido: la primera queda primera y cada pantalla va después de
 * todas las que la apuntan (a igualdad, se respeta el orden actual). Así todo destino apunta hacia
 * adelante, que es lo que exige el formato de Meta.
 */
function orderScreens(definition: FlowDefinition): FlowDefinition {
  const { screens } = definition;
  if (screens.length < 3) return definition;
  const predecessors = screens.map(() => new Set<number>());
  screens.forEach((_, index) => {
    for (const successor of storedSuccessors(screens, index)) {
      if (successor !== index && successor !== 0) predecessors[successor].add(index);
    }
  });

  const order = [0];
  const placed = new Set(order);
  while (order.length < screens.length) {
    const ready = screens.findIndex((_, index) => !placed.has(index) && [...predecessors[index]].every((p) => placed.has(p)));
    // Un ciclo no debería existir; si aparece, el resto queda en su orden y el validador lo marca.
    const nextIndex = ready === -1 ? screens.findIndex((_, index) => !placed.has(index)) : ready;
    order.push(nextIndex);
    placed.add(nextIndex);
  }
  return order.every((index, position) => index === position)
    ? definition
    : { ...definition, screens: order.map((index) => screens[index]) };
}

/** Forma canónica del diagrama: destinos explícitos y pantallas en orden de recorrido. */
export function normalizeRoutes(definition: FlowDefinition): FlowDefinition {
  return orderScreens(withExplicitRoutes(definition));
}
