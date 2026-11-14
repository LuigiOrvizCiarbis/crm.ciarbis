import type { ContactField } from "@/lib/api/contact-fields";

import { LABEL_MAX, OPTION_COUNT_MAX } from "./limits";
import type {
  ChoiceOption,
  ContentScreen,
  FlowBlock,
  FlowDefinition,
  FlowScreen,
  QuestionBlock,
  RepeaterField,
  RepeaterFieldType,
  RepeaterScreen,
  Target,
} from "./types";

/**
 * Grupos repetibles: misma expansión que el backend (FlowDefinitionExpander). Cada fila es una
 * pantalla; las que pasan el mínimo preguntan "¿Agregar otro?". El cliente la usa para contar
 * pantallas, para la vista previa y para la copia de los subcampos del CRM.
 */

export const REPEATER_YES = "si";
export const REPEATER_NO = "no";
/** Más opciones que esto van en una lista desplegable (límite de opción única de Meta). */
const RADIO_MAX = OPTION_COUNT_MAX.single_choice;
/** El id de las filas siguientes agrega `-<n>` (hasta `-10`). */
export const GROUP_ID_MAX = 37;
export const GROUP_FIELDS_MAX = 20;
export const REPEATER_QUESTION_MAX = LABEL_MAX.single_choice;

const FORM_FIELD_TYPES: readonly RepeaterFieldType[] = ["text", "number", "currency", "date", "boolean", "select", "email", "url", "phone"];

export function isRepeaterScreen(screen: FlowScreen | undefined): screen is RepeaterScreen {
  return screen?.type === "repeater";
}

/** Bloques de una pantalla común; un grupo no tiene bloques propios. */
export function screenBlocks(screen: FlowScreen | undefined): FlowBlock[] {
  return screen && !isRepeaterScreen(screen) ? screen.blocks : [];
}

export function asksFirst(group: RepeaterScreen): boolean {
  return group.ask_first && group.min_rows === 0;
}

/** Pantallas que genera un grupo. */
export function groupScreenCount(group: RepeaterScreen): number {
  return Math.max(1, group.max_rows) + (asksFirst(group) ? 1 : 0);
}

/** Pantallas que recibe Meta una vez expandidos los grupos. */
export function expandedScreenCount(screens: FlowScreen[]): number {
  return screens.reduce((total, screen) => total + (isRepeaterScreen(screen) ? groupScreenCount(screen) : 1), 0);
}

export function rowTitle(group: RepeaterScreen, row: number): string {
  return group.row_title.replaceAll("{n}", String(row));
}

/** Etiqueta máxima de un subcampo según el bloque que genera. */
export function repeaterLabelMax(field: RepeaterField): number {
  switch (field.type) {
    case "date":
      return LABEL_MAX.date;
    case "boolean":
      return LABEL_MAX.opt_in;
    case "select":
      return (field.choices?.length ?? 0) > RADIO_MAX ? LABEL_MAX.dropdown : LABEL_MAX.single_choice;
    default:
      return LABEL_MAX.short_text;
  }
}

/** Campos repeater del CRM que se pueden usar en un grupo (todos sus subcampos activos entran en un formulario). */
export function repeaterFields(fields: ContactField[]): ContactField[] {
  return fields.filter((field) => field.type === "repeater" && activeSubfields(field).length > 0);
}

function activeSubfields(field: ContactField) {
  return (field.options?.fields ?? []).filter(
    (subfield) => subfield.is_active !== false && subfield.key && (FORM_FIELD_TYPES as readonly string[]).includes(subfield.type),
  );
}

/**
 * Copia los subcampos activos del campo. Conserva las etiquetas cortas que el administrador ya
 * había ajustado; las nuevas salen de la etiqueta del CRM recortada al límite de Meta.
 */
export function snapshotFields(field: ContactField, previous: RepeaterField[] = []): RepeaterField[] {
  return activeSubfields(field).map((subfield) => {
    const type = subfield.type as RepeaterFieldType;
    const copy: RepeaterField = {
      key: subfield.key as string,
      label: "",
      type,
      required: subfield.is_required === true,
      ...(type === "select" ? { choices: subfield.options?.choices ?? [] } : {}),
      ...(type === "currency" && subfield.options?.currency ? { currency: subfield.options.currency } : {}),
    };
    const kept = previous.find((item) => item.key === copy.key)?.label;
    return { ...copy, label: kept ?? Array.from(subfield.label).slice(0, repeaterLabelMax(copy)).join("") };
  });
}

/** Si la copia del grupo sigue siendo la del campo (clave, tipo, obligatorio, opciones, mínimo). */
export function snapshotMatches(group: RepeaterScreen, field: ContactField): boolean {
  const current = snapshotFields(field);
  if (group.min_rows !== (field.options?.min_items ?? 0) || current.length !== group.fields.length) return false;
  return current.every((item, i) => {
    const copy = group.fields[i];
    return (
      copy.key === item.key &&
      copy.type === item.type &&
      copy.required === item.required &&
      JSON.stringify(copy.type === "select" ? (copy.choices ?? []) : []) === JSON.stringify(item.type === "select" ? (item.choices ?? []) : [])
    );
  });
}

export function rowKey(key: string, row: number, position: number): string {
  return `${key}_${row}_${position}`;
}

/** Bloque de pregunta que genera un subcampo (el mismo que arma el backend). */
function fieldBlock(field: RepeaterField, key: string): QuestionBlock {
  const base = { key, label: field.label, required: field.required };
  switch (field.type) {
    case "number":
      return { ...base, type: "short_text", input_type: "number" };
    case "currency":
      return { ...base, type: "short_text", input_type: "number", helper_text: field.currency ? `En ${field.currency}` : null };
    case "email":
      return { ...base, type: "short_text", input_type: "email" };
    case "phone":
      return { ...base, type: "short_text", input_type: "phone" };
    case "date":
      return { ...base, type: "date" };
    case "boolean":
      return { ...base, type: "opt_in" };
    case "select": {
      const options: ChoiceOption[] = (field.choices ?? []).map((choice) => ({ id: choice, title: choice }));
      return (field.choices?.length ?? 0) > RADIO_MAX ? { ...base, type: "dropdown", options } : { ...base, type: "single_choice", options };
    }
    default:
      return { ...base, type: "short_text", input_type: "text" };
  }
}

function yesNo(key: string, label: string, yes: Target, no: Target): QuestionBlock {
  return {
    type: "single_choice",
    key,
    label,
    required: true,
    options: [
      { id: REPEATER_YES, title: "Sí" },
      { id: REPEATER_NO, title: "No" },
    ],
    branches: { [REPEATER_YES]: yes, [REPEATER_NO]: no },
  };
}

/**
 * Pantallas de un grupo: la pregunta previa (si corresponde) y una por fila. La primera conserva
 * el id del grupo; las siguientes son `<id>-<n>`. `next` es el destino ya resuelto del grupo.
 */
export function groupScreens(group: RepeaterScreen, next: Target): ContentScreen[] {
  const ask = asksFirst(group);
  const rowId = (row: number) => (row === 1 && !ask ? group.id : `${group.id}-${row}`);
  const screens: ContentScreen[] = [];

  if (ask) {
    screens.push({
      id: group.id,
      title: group.title,
      footer_label: group.footer_label,
      blocks: [yesNo(`${group.key}_has`, group.ask_first_label, `screen:${rowId(1)}`, next)],
      next,
    });
  }

  for (let row = 1; row <= Math.max(1, group.max_rows); row++) {
    const blocks: FlowBlock[] = group.fields.map((field, i) => fieldBlock(field, rowKey(group.key, row, i + 1)));
    const last = row === group.max_rows;
    let following: Target = last ? next : `screen:${rowId(row + 1)}`;
    if (!last && row >= Math.max(group.min_rows, 1)) {
      blocks.push(yesNo(`${group.key}_${row}_more`, group.more_label, following, next));
      following = next;
    }
    screens.push({ id: rowId(row), title: rowTitle(group, row), footer_label: group.footer_label, blocks, next: following });
  }

  return screens;
}

/** Definición con los grupos convertidos en pantallas (la que valida los caminos el backend). */
export function expandDefinition(definition: FlowDefinition): FlowDefinition {
  if (!definition.screens.some(isRepeaterScreen)) return definition;
  const screens = definition.screens.flatMap((screen, index): ContentScreen[] => {
    if (!isRepeaterScreen(screen)) return [screen];
    const following = definition.screens[index + 1]?.id;
    const next: Target = !screen.next || screen.next === "following" ? (following ? `screen:${following}` : "end") : screen.next;
    return groupScreens(screen, next);
  });
  return { ...definition, screens };
}
