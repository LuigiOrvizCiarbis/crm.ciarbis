import { z, type ZodTypeAny } from "zod";

import type { ContactField, ContactFieldType } from "@/lib/api/contact-fields";

function schemaForType(type: ContactFieldType, choices: string[]): ZodTypeAny {
  switch (type) {
    case "text":
      return z.string().max(1000).nullish();
    case "number":
    // El valor de un campo moneda es el importe a secas: mismas reglas que un
    // número. La divisa vive en la definición del campo, no en el valor.
    case "currency":
      return z
        .union([z.number(), z.string().regex(/^-?\d+(\.\d+)?$/).transform(Number)])
        .nullish();
    case "date":
      return z.string().nullish();
    case "boolean":
      return z.boolean().nullish();
    case "select":
      return z.enum(choices.length > 0 ? (choices as [string, ...string[]]) : ["__none__"]).nullish();
    case "multi_select":
      return z.array(z.enum(choices.length > 0 ? (choices as [string, ...string[]]) : ["__none__"])).nullish();
    case "email":
      return z.string().email().max(255).nullish().or(z.literal(""));
    case "url":
      return z.string().url().max(2048).nullish().or(z.literal(""));
    case "phone":
      return z.string().max(50).nullish();
    case "file":
      // El valor es el id del MediaAsset subido a la app.
      return z.number().int().positive().nullish();
    case "repeater": {
      const fields = (choices as unknown as { key?: string; type?: string; options?: { choices?: string[] }; is_active?: boolean; is_required?: boolean }[])
      const shape: Record<string, ZodTypeAny> = {}
      for (const field of fields) {
        if (!field.key || field.is_active === false) continue
        const child = schemaForType((field.type ?? "text") as ContactFieldType, field.options?.choices ?? [])
        shape[field.key] = field.is_required ? child.refine((v) => v !== null && v !== undefined && v !== "", { message: "requerido" }) : child
      }
      return z.array(z.object(shape).passthrough()).nullish()
    }
  }
}

export function buildCustomDataSchema(fields: ContactField[]): z.ZodObject<Record<string, ZodTypeAny>> {
  const shape: Record<string, ZodTypeAny> = {};
  for (const field of fields) {
    const choices = field.type === "repeater" ? ((field.options?.fields ?? []) as unknown as string[]) : (field.options?.choices ?? []);
    let schema = schemaForType(field.type, choices);
    if (field.is_required) {
      schema = schema.refine(
        (v) => v !== null && v !== undefined && v !== "" && (!Array.isArray(v) || v.length > 0),
        { message: `${field.label} es requerido` },
      );
    }
    shape[field.key] = schema;
  }
  return z.object(shape);
}
