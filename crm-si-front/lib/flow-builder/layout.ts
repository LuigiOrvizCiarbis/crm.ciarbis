import { isQuestionBlock } from "./limits";
import { isRepeaterScreen, screenBlocks } from "./repeater";
import { branchingBlock, resolveTarget, screenTargets } from "./routing";
import type { CanvasPosition, FlowDefinition, FlowScreen } from "./types";

/**
 * Ubicación de las cajas del diagrama. Las pantallas guardan su posición en la definición; el inicio,
 * la reserva de turno y el fin se ubican solos alrededor de ellas.
 */

export const SCREEN_NODE_WIDTH = 248;
export const APPOINTMENT_NODE_WIDTH = 216;
export const APPOINTMENT_NODE_HEIGHT = 92;
export const TERMINAL_NODE_HEIGHT = 32;
const START_NODE_WIDTH = 88;
const COLUMN_GAP = 104;
const ROW_GAP = 40;
const MAX_SUMMARY_ROWS = 5;

/** Alto aproximado de una pantalla antes de medirla: alcanza para ordenar sin superponer. */
export function estimateScreenHeight(screen: FlowScreen): number {
  // Un grupo muestra sus datos (como una pantalla) y una sola salida.
  const rows = isRepeaterScreen(screen) ? screen.fields : screen.blocks;
  const block = branchingBlock(screen);
  const summaryRows = Math.min(rows.length, MAX_SUMMARY_ROWS) + (rows.length > MAX_SUMMARY_ROWS ? 1 : 0);
  const branchRows = block ? block.options.length + 1 : 0;
  return 48 + Math.max(summaryRows, 1) * 22 + branchRows * 28 + 48;
}

/** Columna de cada pantalla: el camino más largo desde la primera. Las que no tienen entrada van en la segunda. */
function screenDepths(screens: FlowScreen[]): number[] {
  const depths: number[] = screens.map((_, index) => (index === 0 ? 0 : -1));
  screens.forEach((screen, index) => {
    if (depths[index] === -1) depths[index] = 1;
    for (const target of screenTargets(screen)) {
      const resolved = resolveTarget(target, index, screens);
      if (typeof resolved === "number" && resolved > index) depths[resolved] = Math.max(depths[resolved], depths[index] + 1);
    }
  });
  return depths;
}

/**
 * Acomoda las pantallas en columnas según el recorrido, de izquierda a derecha. `heights` son los altos
 * medidos en pantalla (por `uid`); sin ellos se estiman.
 */
export function layoutScreens(screens: FlowScreen[], heights: Record<string, number> = {}): FlowScreen[] {
  const depths = screenDepths(screens);
  const columns = new Map<number, number[]>();
  depths.forEach((depth, index) => columns.set(depth, [...(columns.get(depth) ?? []), index]));

  const positions = new Map<number, CanvasPosition>();
  for (const [depth, indexes] of columns) {
    const sizes = indexes.map((index) => heights[screens[index].uid ?? ""] ?? estimateScreenHeight(screens[index]));
    const total = sizes.reduce((sum, size) => sum + size, 0) + ROW_GAP * (indexes.length - 1);
    let y = -total / 2;
    indexes.forEach((index, row) => {
      positions.set(index, { x: depth * (SCREEN_NODE_WIDTH + COLUMN_GAP), y: Math.round(y) });
      y += sizes[row] + ROW_GAP;
    });
  }
  return screens.map((screen, index) => ({ ...screen, position: positions.get(index) }));
}

/** Si a alguna pantalla le falta posición (formularios anteriores al diagrama), se acomodan todas. */
export function withLayout(definition: FlowDefinition): FlowDefinition {
  return definition.screens.every((screen) => screen.position)
    ? definition
    : { ...definition, screens: layoutScreens(definition.screens) };
}

/** Lugar para una pantalla nueva sin conexión: a la derecha de todo. */
export function nextFreePosition(screens: FlowScreen[]): CanvasPosition {
  const right = Math.max(0, ...screens.map((screen) => (screen.position?.x ?? 0) + SCREEN_NODE_WIDTH + COLUMN_GAP));
  return { x: right, y: 0 };
}

export interface TerminalPositions {
  start: CanvasPosition;
  appointment: CanvasPosition | null;
  end: CanvasPosition;
}

/** El inicio a la izquierda de la primera pantalla; la reserva y el fin a la derecha de todas, centrados. */
export function terminalPositions(screens: FlowScreen[], heights: Record<string, number>, hasAppointment: boolean): TerminalPositions {
  const boxes = screens.map((screen) => {
    const position = screen.position ?? { x: 0, y: 0 };
    const height = heights[screen.uid ?? ""] ?? estimateScreenHeight(screen);
    return { ...position, height };
  });
  const entry = boxes[0] ?? { x: 0, y: 0, height: 0 };
  const top = Math.min(...boxes.map((box) => box.y));
  const bottom = Math.max(...boxes.map((box) => box.y + box.height));
  const middle = (top + bottom) / 2;
  const right = Math.max(...boxes.map((box) => box.x + SCREEN_NODE_WIDTH)) + COLUMN_GAP;

  const appointment = hasAppointment ? { x: right, y: middle - APPOINTMENT_NODE_HEIGHT / 2 } : null;
  return {
    start: { x: entry.x - START_NODE_WIDTH - 56, y: entry.y + entry.height / 2 - TERMINAL_NODE_HEIGHT / 2 },
    appointment,
    end: { x: appointment ? right + APPOINTMENT_NODE_WIDTH + 72 : right, y: middle - TERMINAL_NODE_HEIGHT / 2 },
  };
}

/** Cantidad de preguntas de la pantalla, para los resúmenes. */
export function questionCount(screen: FlowScreen): number {
  return screenBlocks(screen).filter(isQuestionBlock).length;
}
