"use client"

import { useMemo, useReducer } from "react"
import { arrayMove } from "@dnd-kit/sortable"

import type { WhatsAppFlowCategory } from "@/data/types"
import { nextFreePosition, withLayout } from "@/lib/flow-builder/layout"
import { MAX_SCREENS, isQuestionBlock } from "@/lib/flow-builder/limits"
import {
  canBeEntry,
  canRouteTo,
  dropScreenReferences,
  newScreenId,
  normalizeRoutes,
  screenPorts,
  screenTarget,
  syncBranchKeys,
  withPortTarget,
  withScreenIds,
  type RoutePort,
} from "@/lib/flow-builder/routing"
import type {
  AppointmentConfig,
  CanvasPosition,
  FlowBlock,
  FlowDefinition,
  FlowScreen,
  Target,
} from "@/lib/flow-builder/types"

export interface FlowBuilderSnapshot {
  name: string
  categories: WhatsAppFlowCategory[]
  definition: FlowDefinition
}

/** Pasos que se pueden deshacer. */
const HISTORY_LIMIT = 50
/** Cambios seguidos sobre el mismo campo dentro de este margen (tipear) son un solo paso. */
const COALESCE_MS = 1000

interface FlowBuilderState extends FlowBuilderSnapshot {
  /** Último snapshot guardado: el estado está limpio mientras coincida con él. */
  saved: FlowBuilderSnapshot
  past: FlowBuilderSnapshot[]
  future: FlowBuilderSnapshot[]
  lastEdit: { key: string; at: number } | null
}

type ScreenPatch = Partial<Pick<FlowScreen, "title" | "footer_label" | "next">>

type EditAction =
  | { type: "addScreen"; screen: FlowScreen }
  | { type: "insertScreen"; screen: FlowScreen; from: number; port: RoutePort }
  | { type: "removeScreen"; index: number }
  | { type: "setEntry"; index: number }
  | { type: "setRoute"; index: number; port: RoutePort; target: Target }
  | { type: "setPositions"; positions: Record<string, CanvasPosition> }
  | { type: "updateScreen"; index: number; patch: ScreenPatch }
  | { type: "addBlock"; screen: number; block: FlowBlock }
  | { type: "removeBlock"; screen: number; index: number }
  | { type: "moveBlock"; screen: number; from: number; to: number }
  | { type: "updateBlock"; screen: number; index: number; block: FlowBlock }
  | { type: "setAppointment"; appointment: AppointmentConfig | null }
  | { type: "setMeta"; name?: string; categories?: WhatsAppFlowCategory[] }

type FlowBuilderAction =
  | (EditAction & { at: number })
  | { type: "undo" }
  | { type: "redo" }
  | { type: "replace"; snapshot: FlowBuilderSnapshot }
  | { type: "markSaved"; snapshot: FlowBuilderSnapshot }

let uidCounter = 0

/** Id estable del cliente para arrastrar y soltar (no depende de `crypto.randomUUID`, que exige HTTPS). */
export function newUid(): string {
  uidCounter += 1
  return `${Date.now().toString(36)}-${uidCounter}-${Math.random().toString(36).slice(2, 8)}`
}

function withUids(definition: FlowDefinition): FlowDefinition {
  return withScreenIds({
    ...definition,
    screens: definition.screens.map((screen) => ({
      ...screen,
      uid: screen.uid ?? newUid(),
      blocks: screen.blocks.map((block) => ({ ...block, uid: block.uid ?? newUid() })),
    })),
  })
}

/** Forma con la que trabaja el diagrama: uids, destinos explícitos, orden de recorrido y posiciones. */
function prepare(definition: FlowDefinition): FlowDefinition {
  return withLayout(normalizeRoutes(withUids(definition)))
}

function mapScreen(definition: FlowDefinition, index: number, update: (screen: FlowScreen) => FlowScreen): FlowDefinition {
  return {
    ...definition,
    screens: definition.screens.map((screen, i) => (i === index ? update(screen) : screen)),
  }
}

/** Si la pregunta usada como motivo del turno cambia de clave o desaparece, el motivo la sigue. */
function followReasonKey(definition: FlowDefinition, previousKey: string, nextKey: string | null): FlowDefinition {
  if (!definition.appointment || definition.appointment.reason_key !== previousKey) return definition
  return { ...definition, appointment: { ...definition.appointment, reason_key: nextKey } }
}

/** Pantalla nueva con id único, destino explícito y posición. */
function placeScreen(definition: FlowDefinition, screen: FlowScreen, next: Target): FlowScreen {
  const ids = definition.screens.map((item) => item.id)
  return {
    ...screen,
    id: screen.id && !ids.includes(screen.id) ? screen.id : newScreenId(ids),
    uid: screen.uid ?? newUid(),
    next,
    position: screen.position ?? nextFreePosition(definition.screens),
  }
}

function reduceDefinition(definition: FlowDefinition, action: EditAction): FlowDefinition {
  switch (action.type) {
    case "addScreen": {
      if (definition.screens.length >= MAX_SCREENS) return definition
      const screen = placeScreen(definition, action.screen, action.screen.next ?? "end")
      return { ...definition, screens: [...definition.screens, screen] }
    }
    case "insertScreen": {
      const from = definition.screens[action.from]
      const port = from && screenPorts(from).find((item) => item.port === action.port)
      if (definition.screens.length >= MAX_SCREENS || !from || !port) return definition
      // La pantalla nueva queda en medio del camino: hereda el destino y la salida pasa a apuntarla.
      const screen = placeScreen(definition, action.screen, port.target)
      const screens = definition.screens.map((item, i) =>
        i === action.from ? withPortTarget(item, action.port, `screen:${screen.id}`) : item,
      )
      return { ...definition, screens: [...screens, screen] }
    }
    case "removeScreen": {
      if (definition.screens.length <= 1) return definition
      const removed = definition.screens[action.index]
      let next: FlowDefinition = {
        ...definition,
        screens: definition.screens.filter((_, i) => i !== action.index),
      }
      if (removed) next = dropScreenReferences(next, removed.id)
      for (const block of removed?.blocks ?? []) {
        if (isQuestionBlock(block)) next = followReasonKey(next, block.key, null)
      }
      return next
    }
    case "setEntry":
      if (action.index <= 0 || !canBeEntry(definition.screens, action.index)) return definition
      return { ...definition, screens: arrayMove(definition.screens, action.index, 0) }
    case "setRoute": {
      const targetIndex = definition.screens.findIndex((screen) => screenTarget(screen.id) === action.target)
      if (action.target !== "end" && !canRouteTo(definition.screens, action.index, targetIndex)) return definition
      return mapScreen(definition, action.index, (screen) => withPortTarget(screen, action.port, action.target))
    }
    case "setPositions": {
      let changed = false
      const screens = definition.screens.map((screen) => {
        const position = screen.uid ? action.positions[screen.uid] : undefined
        if (!position || (position.x === screen.position?.x && position.y === screen.position?.y)) return screen
        changed = true
        return { ...screen, position }
      })
      return changed ? { ...definition, screens } : definition
    }
    case "updateScreen":
      return mapScreen(definition, action.index, (screen) => ({ ...screen, ...action.patch }))
    case "addBlock":
      return mapScreen(definition, action.screen, (screen) => ({
        ...screen,
        blocks: [...screen.blocks, { ...action.block, uid: action.block.uid ?? newUid() }],
      }))
    case "removeBlock": {
      const removed = definition.screens[action.screen]?.blocks[action.index]
      const next = mapScreen(definition, action.screen, (screen) => ({
        ...screen,
        blocks: screen.blocks.filter((_, i) => i !== action.index),
      }))
      return removed && isQuestionBlock(removed) ? followReasonKey(next, removed.key, null) : next
    }
    case "moveBlock":
      return mapScreen(definition, action.screen, (screen) => ({
        ...screen,
        blocks: arrayMove(screen.blocks, action.from, action.to),
      }))
    case "updateBlock": {
      const previous = definition.screens[action.screen]?.blocks[action.index]
      const block = previous ? syncBranchKeys(previous, action.block) : action.block
      const next = mapScreen(definition, action.screen, (screen) => ({
        ...screen,
        blocks: screen.blocks.map((current, i) => (i === action.index ? block : current)),
      }))
      if (previous && isQuestionBlock(previous) && isQuestionBlock(action.block) && previous.key !== action.block.key) {
        return followReasonKey(next, previous.key, action.block.type === "long_text" ? action.block.key : null)
      }
      return next
    }
    case "setAppointment":
      return { ...definition, appointment: action.appointment }
    default:
      return definition
  }
}

/** Clave de los cambios que se agrupan al tipear sobre un mismo campo. */
function editKey(state: FlowBuilderState, action: EditAction): string | null {
  switch (action.type) {
    case "updateScreen":
      return `screen:${state.definition.screens[action.index]?.uid}:${Object.keys(action.patch).sort().join(",")}`
    case "updateBlock":
      return `block:${action.block.uid}`
    case "setMeta":
      return action.name !== undefined ? "meta:name" : null
    case "setAppointment":
      return action.appointment && state.definition.appointment ? "appointment" : null
    case "setPositions":
      return `positions:${Object.keys(action.positions).sort().join(",")}`
    default:
      return null
  }
}

function snapshotOf(state: FlowBuilderSnapshot): FlowBuilderSnapshot {
  return { name: state.name, categories: state.categories, definition: state.definition }
}

function sameSnapshot(a: FlowBuilderSnapshot, b: FlowBuilderSnapshot): boolean {
  return a.definition === b.definition && a.name === b.name && a.categories === b.categories
}

function applyEdit(state: FlowBuilderState, action: EditAction & { at: number }): FlowBuilderState {
  let next: FlowBuilderSnapshot
  if (action.type === "setMeta") {
    next = { ...snapshotOf(state), name: action.name ?? state.name, categories: action.categories ?? state.categories }
  } else {
    const changed = reduceDefinition(state.definition, action)
    if (changed === state.definition) return state
    next = { ...snapshotOf(state), definition: normalizeRoutes(changed) }
  }

  const key = editKey(state, action)
  const coalesce = key !== null && state.lastEdit?.key === key && action.at - state.lastEdit.at < COALESCE_MS
  return {
    ...state,
    ...next,
    past: coalesce ? state.past : [...state.past, snapshotOf(state)].slice(-HISTORY_LIMIT),
    future: [],
    lastEdit: key !== null ? { key, at: action.at } : null,
  }
}

function reducer(state: FlowBuilderState, action: FlowBuilderAction): FlowBuilderState {
  switch (action.type) {
    case "replace": {
      const snapshot = { ...action.snapshot, definition: prepare(action.snapshot.definition) }
      return { ...snapshot, saved: snapshot, past: [], future: [], lastEdit: null }
    }
    case "markSaved":
      return { ...state, saved: action.snapshot }
    case "undo": {
      const previous = state.past.at(-1)
      if (!previous) return state
      return {
        ...state,
        ...previous,
        past: state.past.slice(0, -1),
        future: [snapshotOf(state), ...state.future],
        lastEdit: null,
      }
    }
    case "redo": {
      const [following, ...rest] = state.future
      if (!following) return state
      return { ...state, ...following, past: [...state.past, snapshotOf(state)], future: rest, lastEdit: null }
    }
    default:
      return applyEdit(state, action)
  }
}

export interface FlowBuilderActions {
  /** Agrega una pantalla suelta; el llamador elige su `uid` para poder seleccionarla. */
  addScreen: (screen: FlowScreen) => void
  /** Agrega una pantalla en medio del camino que sale de `from` por `port`. */
  insertScreen: (screen: FlowScreen, from: number, port: RoutePort) => void
  removeScreen: (index: number) => void
  /** La pantalla pasa a ser la primera del formulario (sólo si ninguna otra la apunta). */
  setEntry: (index: number) => void
  setRoute: (index: number, port: RoutePort, target: Target) => void
  setPositions: (positions: Record<string, CanvasPosition>) => void
  updateScreen: (index: number, patch: ScreenPatch) => void
  addBlock: (screen: number, block: FlowBlock) => void
  removeBlock: (screen: number, index: number) => void
  moveBlock: (screen: number, from: number, to: number) => void
  updateBlock: (screen: number, index: number, block: FlowBlock) => void
  setAppointment: (appointment: AppointmentConfig | null) => void
  setMeta: (meta: { name?: string; categories?: WhatsAppFlowCategory[] }) => void
  undo: () => void
  redo: () => void
  /** Carga una definición (inicial o recuperada) y deja el estado limpio. */
  replace: (snapshot: FlowBuilderSnapshot) => void
  /** Marca como guardado el snapshot enviado; si algo cambió mientras se guardaba, sigue sucio. */
  markSaved: (snapshot: FlowBuilderSnapshot) => void
}

export function useFlowBuilderState(initial: FlowBuilderSnapshot) {
  const [state, dispatch] = useReducer(reducer, initial, (snapshot) =>
    reducer({ ...snapshot, saved: snapshot, past: [], future: [], lastEdit: null }, { type: "replace", snapshot }),
  )

  // `dispatch` es estable: las acciones también, así los hijos no se re-renderizan de más.
  const actions = useMemo<FlowBuilderActions>(() => {
    const edit = (action: EditAction) => dispatch({ ...action, at: Date.now() })
    return {
      addScreen: (screen) => edit({ type: "addScreen", screen }),
      insertScreen: (screen, from, port) => edit({ type: "insertScreen", screen, from, port }),
      removeScreen: (index) => edit({ type: "removeScreen", index }),
      setEntry: (index) => edit({ type: "setEntry", index }),
      setRoute: (index, port, target) => edit({ type: "setRoute", index, port, target }),
      setPositions: (positions) => edit({ type: "setPositions", positions }),
      updateScreen: (index, patch) => edit({ type: "updateScreen", index, patch }),
      addBlock: (screen, block) => edit({ type: "addBlock", screen, block }),
      removeBlock: (screen, index) => edit({ type: "removeBlock", screen, index }),
      moveBlock: (screen, from, to) => edit({ type: "moveBlock", screen, from, to }),
      updateBlock: (screen, index, block) => edit({ type: "updateBlock", screen, index, block }),
      setAppointment: (appointment) => edit({ type: "setAppointment", appointment }),
      setMeta: (meta) => edit({ type: "setMeta", ...meta }),
      undo: () => dispatch({ type: "undo" }),
      redo: () => dispatch({ type: "redo" }),
      replace: (snapshot) => dispatch({ type: "replace", snapshot }),
      markSaved: (snapshot) => dispatch({ type: "markSaved", snapshot }),
    }
  }, [])

  return {
    state,
    dirty: !sameSnapshot(state, state.saved),
    canUndo: state.past.length > 0,
    canRedo: state.future.length > 0,
    actions,
  }
}
