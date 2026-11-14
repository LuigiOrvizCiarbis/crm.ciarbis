import type { ContactField } from "@/lib/api/contact-fields";

import {
  CUSTOM_PREFILL_PREFIX,
  compatibleTargets,
  customFieldKey,
  isPrefillKnown,
  lockedOptions,
  supportsPrefill,
} from "./compat";
import { imageBytes, isSupportedImage } from "./images";
import {
  ALT_TEXT_MAX,
  APPOINTMENT_LABEL_MAX,
  CAROUSEL_IMAGES_MAX,
  FOOTER_LABEL_MAX,
  HELPER_TEXT_MAX,
  IMAGE_MAX_BYTES,
  KEY_PATTERN,
  LABEL_MAX,
  MAX_CAROUSELS,
  MAX_CAROUSELS_PER_SCREEN,
  MAX_IMAGES_PER_SCREEN,
  MAX_OPT_IN_PER_SCREEN,
  MAX_SCREENS,
  OPTION_IMAGE_MAX_BYTES,
  OPTION_TITLE_MAX,
  TEXT_MAX,
  TOTAL_IMAGE_MAX_BYTES,
  hasForbiddenKeyPrefix,
  isChoiceBlock,
  isTextBlock,
  isReservedKey,
  optionCountMax,
} from "./limits";
import { hasBranches, isForwardTarget, resolveTarget, unreachableScreens } from "./routing";
import type { FlowDefinition, QuestionBlock, Target } from "./types";

type Translate = (key: string, params?: Record<string, string | number>) => string;

/** Errores con clave de ruta (`screens.0.blocks.2.label`), igual que los 422 del backend sin el prefijo `builder_definition.`. */
export type DefinitionErrors = Record<string, string[]>;

/** Largo en caracteres, no en unidades UTF-16: es como cuenta el backend (mb_strlen). */
export function charCount(value: string | null | undefined): number {
  return Array.from(value ?? "").length;
}

/**
 * Validación del lado del cliente con las mismas reglas que FlowDefinitionValidator.
 * Lo único que no puede comprobar es si la agenda de turnos del asistente está activa.
 * Con `fields === null` (no se pudieron cargar los campos personalizados) no se juzgan
 * las referencias a campos personalizados: las valida el backend al guardar.
 */
export function validateDefinition(
  definition: FlowDefinition,
  fields: ContactField[] | null,
  t: Translate,
): DefinitionErrors {
  const errors: DefinitionErrors = {};
  const add = (path: string, message: string) => {
    (errors[path] ??= []).push(message);
  };
  const checkLength = (path: string, value: string | null | undefined, max: number, required = true) => {
    const length = charCount(value?.trim());
    if (required && length === 0) add(path, t("flows.builder.errors.required"));
    else if (charCount(value) > max) add(path, t("flows.builder.errors.tooLong", { max }));
  };

  const { screens, appointment } = definition;
  if (screens.length < 1 || screens.length > MAX_SCREENS) {
    add("screens", t("flows.builder.errors.screensCount", { max: MAX_SCREENS }));
  }

  const keyUses = new Map<string, string[]>();
  const questions: QuestionBlock[] = [];
  let totalImageBytes = 0;
  let carousels = 0;
  const checkImage = (path: string, src: string | null | undefined, maxBytes: number) => {
    if (!isSupportedImage(src)) {
      add(path, t("flows.builder.errors.imageMissing"));
      return;
    }
    const bytes = imageBytes(src);
    totalImageBytes += bytes;
    if (bytes > maxBytes) add(path, t("flows.builder.errors.imageTooLarge", { max: Math.round(maxBytes / 1000) }));
  };

  screens.forEach((screen, s) => {
    const screenPath = `screens.${s}`;
    checkLength(`${screenPath}.title`, screen.title, Number.POSITIVE_INFINITY);
    checkLength(`${screenPath}.footer_label`, screen.footer_label, FOOTER_LABEL_MAX);
    if (screen.blocks.length === 0) add(`${screenPath}.blocks`, t("flows.builder.errors.screenEmpty"));

    const optIns = screen.blocks.filter((block) => block.type === "opt_in").length;
    if (optIns > MAX_OPT_IN_PER_SCREEN) {
      add(`${screenPath}.blocks`, t("flows.builder.errors.optInMax", { max: MAX_OPT_IN_PER_SCREEN }));
    }
    if (screen.blocks.filter((block) => block.type === "image").length > MAX_IMAGES_PER_SCREEN) {
      add(`${screenPath}.blocks`, t("flows.builder.errors.imagesPerScreen", { max: MAX_IMAGES_PER_SCREEN }));
    }
    const screenCarousels = screen.blocks.filter((block) => block.type === "image_carousel").length;
    carousels += screenCarousels;
    if (screenCarousels > MAX_CAROUSELS_PER_SCREEN) {
      add(`${screenPath}.blocks`, t("flows.builder.errors.carouselsPerScreen", { max: MAX_CAROUSELS_PER_SCREEN }));
    }

    screen.blocks.forEach((block, b) => {
      const path = `${screenPath}.blocks.${b}`;
      if (isTextBlock(block)) {
        checkLength(`${path}.text`, block.text, TEXT_MAX[block.type]);
        return;
      }
      if (block.type === "image") {
        checkImage(`${path}.src`, block.src, IMAGE_MAX_BYTES);
        checkLength(`${path}.alt_text`, block.alt_text, ALT_TEXT_MAX, false);
        return;
      }
      if (block.type === "image_carousel") {
        if (block.images.length < 1 || block.images.length > CAROUSEL_IMAGES_MAX) {
          add(`${path}.images`, t("flows.builder.errors.carouselCount", { max: CAROUSEL_IMAGES_MAX }));
        }
        block.images.forEach((image, i) => {
          checkImage(`${path}.images.${i}.src`, image.src, IMAGE_MAX_BYTES);
          checkLength(`${path}.images.${i}.alt_text`, image.alt_text, ALT_TEXT_MAX);
        });
        return;
      }

      questions.push(block);
      keyUses.set(block.key, [...(keyUses.get(block.key) ?? []), `${path}.key`]);
      checkLength(`${path}.label`, block.label, LABEL_MAX[block.type]);

      if (!KEY_PATTERN.test(block.key)) add(`${path}.key`, t("flows.builder.errors.keyFormat"));
      else if (isReservedKey(block.key)) add(`${path}.key`, t("flows.builder.errors.keyReserved"));
      else if (hasForbiddenKeyPrefix(block.key)) add(`${path}.key`, t("flows.builder.errors.keyRequiredPrefix"));

      const requiredField = fields?.find((field) => field.key === customFieldKey(block.maps_to));
      if (requiredField?.is_required && !block.required) {
        add(`${path}.required`, t("flows.builder.errors.requiredInCrm", { field: requiredField.label }));
      }

      if ("helper_text" in block && block.helper_text) {
        checkLength(`${path}.helper_text`, block.helper_text, HELPER_TEXT_MAX, false);
      }

      if (isChoiceBlock(block)) {
        const max = optionCountMax(block);
        if (block.options.length < 1 || block.options.length > max) {
          add(`${path}.options`, t("flows.builder.errors.optionsCount", { max }));
        }
        const seenIds = new Set<string>();
        block.options.forEach((option, o) => {
          const id = option.id.trim();
          if (id === "" || seenIds.has(id)) add(`${path}.options.${o}.id`, t("flows.builder.errors.optionId"));
          seenIds.add(id);
          checkLength(`${path}.options.${o}.title`, option.title, OPTION_TITLE_MAX);
          if (option.image) {
            checkImage(`${path}.options.${o}.image`, option.image, OPTION_IMAGE_MAX_BYTES);
            checkLength(`${path}.options.${o}.alt_text`, option.alt_text, ALT_TEXT_MAX, false);
          }
        });
      }

      if ("prefill" in block && block.prefill) {
        const unknownCustom = fields === null && block.prefill.startsWith(CUSTOM_PREFILL_PREFIX);
        if (!supportsPrefill(block) || (!unknownCustom && !isPrefillKnown(block.prefill, fields ?? []))) {
          add(`${path}.prefill`, t("flows.builder.errors.prefillInvalid"));
        }
      }

      if (block.maps_to && !(fields === null && customFieldKey(block.maps_to) !== null)) {
        if (!(compatibleTargets(block, fields ?? []) as string[]).includes(block.maps_to)) {
          add(`${path}.maps_to`, t("flows.builder.errors.mapsToInvalid"));
        } else if (isChoiceBlock(block)) {
          const locked = lockedOptions(block, fields ?? []);
          const matches =
            locked === null ||
            (locked.length === block.options.length &&
              locked.every((option, i) => option.id === block.options[i].id && option.title === block.options[i].title));
          if (!matches) add(`${path}.options`, t("flows.builder.errors.optionsLocked"));
        }
      }
    });
  });

  for (const paths of keyUses.values()) {
    if (paths.length > 1) paths.forEach((path) => add(path, t("flows.builder.errors.keyDuplicate")));
  }

  if (questions.length === 0) add("screens", t("flows.builder.errors.noQuestions"));
  if (carousels > MAX_CAROUSELS) add("screens", t("flows.builder.errors.carouselsTotal", { max: MAX_CAROUSELS }));
  if (totalImageBytes > TOTAL_IMAGE_MAX_BYTES) {
    add("screens", t("flows.builder.errors.imagesTotal", { max: Math.round(TOTAL_IMAGE_MAX_BYTES / 1_000_000) }));
  }

  if (appointment) {
    checkLength("appointment.date_screen_title", appointment.date_screen_title, Number.POSITIVE_INFINITY);
    checkLength("appointment.slot_screen_title", appointment.slot_screen_title, Number.POSITIVE_INFINITY);
    checkLength("appointment.date_label", appointment.date_label, APPOINTMENT_LABEL_MAX.date_label);
    checkLength("appointment.slot_label", appointment.slot_label, APPOINTMENT_LABEL_MAX.slot_label);
    checkLength("appointment.confirm_label", appointment.confirm_label, APPOINTMENT_LABEL_MAX.confirm_label);
    if (
      appointment.reason_key !== null &&
      !questions.some((block) => block.type === "long_text" && block.key === appointment.reason_key)
    ) {
      add("appointment.reason_key", t("flows.builder.errors.reasonKey"));
    }
  }

  validateRoutes(definition, add, t);

  return errors;
}

/**
 * Destinos de pantallas y respuestas: sólo hacia adelante, una pregunta que cambia el camino
 * por pantalla y todas las pantallas alcanzables. Mismas rutas de error que el backend.
 */
function validateRoutes(definition: FlowDefinition, add: (path: string, message: string) => void, t: Translate) {
  const { screens } = definition;
  let routeErrors = false;
  const fail = (path: string, message: string) => {
    routeErrors = true;
    add(path, message);
  };
  const checkTarget = (target: Target | undefined, index: number, path: string) => {
    const resolved = resolveTarget(target, index, screens);
    if (resolved === null) fail(path, t("flows.builder.errors.targetMissing"));
    else if (!isForwardTarget(target, index, screens)) fail(path, t("flows.builder.errors.targetBackward"));
  };

  screens.forEach((screen, s) => {
    if (screen.next !== undefined) checkTarget(screen.next, s, `screens.${s}.next`);

    let branching = 0;
    screen.blocks.forEach((block, b) => {
      if (!hasBranches(block)) return;
      const path = `screens.${s}.blocks.${b}.branches`;
      branching += 1;
      if (branching > 1) fail(path, t("flows.builder.errors.branchingPerScreen"));
      const optionIds = new Set(block.options.map((option) => option.id));
      for (const [optionId, target] of Object.entries(block.branches ?? {})) {
        if (!optionIds.has(optionId)) fail(path, t("flows.builder.errors.branchOptionMissing"));
        else checkTarget(target, s, path);
      }
    });
  });

  if (routeErrors) return;
  for (const index of unreachableScreens(screens)) {
    add(`screens.${index}`, t("flows.builder.errors.unreachable"));
  }
}
