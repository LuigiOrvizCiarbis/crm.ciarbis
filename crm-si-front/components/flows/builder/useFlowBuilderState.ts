"use client"

import { useMemo, useReducer } from "react"
import { arrayMove } from "@dnd-kit/sortable"

import type { WhatsAppFlowCategory } from "@/data/types"
import { MAX_SCREENS, isQuestionBlock } from "@/lib/flow-builder/limits"
import type { AppointmentConfig, FlowBlock, FlowDefinition, FlowScreen } from "@/lib/flow-builder/types"

export interface FlowBuilderSnapshot {
  name: string
  categories: WhatsAppFlowCategory[]
  definition: FlowDefinition
}

interface FlowBuilderState extends FlowBuilderSnapshot {
  dirty: boolean
}

type ScreenPatch = Partial<Pick<FlowScreen, "title" | "footer_label">>

type FlowBuilderAction =
  | { type: "addScreen"; screen: FlowScreen }
  | { type: "removeScreen"; index: number }
  | { type: "moveScreen"; from: number; to: number }
  | { type: "updateScreen"; index: number; patch: ScreenPatch }
  | { type: "addBlock"; screen: number; block: FlowBlock }
  | { type: "removeBlock"; screen: number; index: number }
  | { type: "moveBlock"; screen: number; from: number; to: number }
  | { type: "updateBlock"; screen: number; index: number; block: FlowBlock }
  | { type: "setAppointment"; appointment: AppointmentConfig | null }
  | { type: "setMeta"; name?: string; categories?: WhatsAppFlowCategory[] }
  | { type: "replace"; snapshot: FlowBuilderSnapshot }
  | { type: "markSaved"; snapshot: FlowBuilderSnapshot }

let uidCounter = 0

/** Id estable del cliente para arrastrar y soltar (no depende de `crypto.randomUUID`, que exige HTTPS). */
export function newUid(): string {
  uidCounter += 1
  return `${Date.now().toString(36)}-${uidCounter}-${Math.random().toString(36).slice(2, 8)}`
}

function withUids(definition: FlowDefinition): FlowDefinition {
  return {
    ...definition,
    screens: definition.screens.map((screen) => ({
      ...screen,
      uid: screen.uid ?? newUid(),
      blocks: screen.blocks.map((block) => ({ ...block, uid: block.uid ?? newUid() })),
    })),
  }
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

function reduceDefinition(definition: FlowDefinition, action: FlowBuilderAction): FlowDefinition {
  switch (action.type) {
    case "addScreen":
      if (definition.screens.length >= MAX_SCREENS) return definition
      return { ...definition, screens: [...definition.screens, { ...action.screen, uid: action.screen.uid ?? newUid() }] }
    case "removeScreen": {
      if (definition.screens.length <= 1) return definition
      const removed = definition.screens[action.index]
      let next: FlowDefinition = {
        ...definition,
        screens: definition.screens.filter((_, i) => i !== action.index),
      }
      for (const block of removed?.blocks ?? []) {
        if (isQuestionBlock(block)) next = followReasonKey(next, block.key, null)
      }
      return next
    }
    case "moveScreen":
      return { ...definition, screens: arrayMove(definition.screens, action.from, action.to) }
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
      const next = mapScreen(definition, action.screen, (screen) => ({
        ...screen,
        blocks: screen.blocks.map((block, i) => (i === action.index ? action.block : block)),
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

function reducer(state: FlowBuilderState, action: FlowBuilderAction): FlowBuilderState {
  switch (action.type) {
    case "replace":
      return { ...action.snapshot, definition: withUids(action.snapshot.definition), dirty: false }
    case "markSaved": {
      // Sólo queda limpio si nada cambió mientras se guardaba.
      const unchanged =
        state.definition === action.snapshot.definition &&
        state.name === action.snapshot.name &&
        state.categories === action.snapshot.categories
      return unchanged ? { ...state, dirty: false } : state
    }
    case "setMeta":
      return {
        ...state,
        name: action.name ?? state.name,
        categories: action.categories ?? state.categories,
        dirty: true,
      }
    default: {
      const definition = reduceDefinition(state.definition, action)
      return definition === state.definition ? state : { ...state, definition, dirty: true }
    }
  }
}

export interface FlowBuilderActions {
  /** Agrega la pantalla al final; el llamador elige su `uid` para poder seleccionarla. */
  addScreen: (screen: FlowScreen) => void
  removeScreen: (index: number) => void
  moveScreen: (from: number, to: number) => void
  updateScreen: (index: number, patch: ScreenPatch) => void
  addBlock: (screen: number, block: FlowBlock) => void
  removeBlock: (screen: number, index: number) => void
  moveBlock: (screen: number, from: number, to: number) => void
  updateBlock: (screen: number, index: number, block: FlowBlock) => void
  setAppointment: (appointment: AppointmentConfig | null) => void
  setMeta: (meta: { name?: string; categories?: WhatsAppFlowCategory[] }) => void
  /** Carga una definición (inicial o recuperada) y deja el estado limpio. */
  replace: (snapshot: FlowBuilderSnapshot) => void
  /** Marca como guardado el snapshot enviado, si no cambió nada mientras tanto. */
  markSaved: (snapshot: FlowBuilderSnapshot) => void
}

export function useFlowBuilderState(initial: FlowBuilderSnapshot) {
  const [state, dispatch] = useReducer(reducer, initial, (snapshot) =>
    reducer({ ...snapshot, dirty: false }, { type: "replace", snapshot }),
  )

  // `dispatch` es estable: las acciones también, así los hijos no se re-renderizan de más.
  const actions = useMemo<FlowBuilderActions>(
    () => ({
      addScreen: (screen) => dispatch({ type: "addScreen", screen }),
      removeScreen: (index) => dispatch({ type: "removeScreen", index }),
      moveScreen: (from, to) => dispatch({ type: "moveScreen", from, to }),
      updateScreen: (index, patch) => dispatch({ type: "updateScreen", index, patch }),
      addBlock: (screen, block) => dispatch({ type: "addBlock", screen, block }),
      removeBlock: (screen, index) => dispatch({ type: "removeBlock", screen, index }),
      moveBlock: (screen, from, to) => dispatch({ type: "moveBlock", screen, from, to }),
      updateBlock: (screen, index, block) => dispatch({ type: "updateBlock", screen, index, block }),
      setAppointment: (appointment) => dispatch({ type: "setAppointment", appointment }),
      setMeta: (meta) => dispatch({ type: "setMeta", ...meta }),
      replace: (snapshot) => dispatch({ type: "replace", snapshot }),
      markSaved: (snapshot) => dispatch({ type: "markSaved", snapshot }),
    }),
    [],
  )

  return { state, dirty: state.dirty, actions }
}
