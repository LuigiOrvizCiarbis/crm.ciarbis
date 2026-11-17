/**
 * Formato de texto de WhatsApp y de las pantallas de un formulario.
 *
 * Son dos sintaxis distintas y el cliente ve una u otra según dónde va el texto:
 * - `whatsapp` (mensajes): *negrita*, _cursiva_, ~tachado~, `código`, ```monoespaciado```,
 *   listas con `- ` / `* ` / `1. ` y citas con `> `.
 * - `flow` (párrafo y nota de una pantalla, `markdown: true` de Meta): **negrita**, *cursiva*,
 *   ~~tachado~~, listas con `- ` / `+ ` / `1. ` y links `[texto](https://…)`.
 *
 * El parser sólo sirve para la vista previa: lo que se guarda y se envía es el texto tal cual.
 */

export type RichTextDialect = "whatsapp" | "flow"

export type InlineNode =
  | { type: "text"; text: string }
  | { type: "bold" | "italic" | "strike" | "code"; children: InlineNode[] }
  | { type: "link"; text: string; href: string }

export type BlockNode =
  | { type: "paragraph"; lines: InlineNode[][] }
  | { type: "bullets"; items: InlineNode[][] }
  | { type: "numbers"; start: number; items: InlineNode[][] }
  | { type: "quote"; lines: InlineNode[][] }

type SpanType = "bold" | "italic" | "strike" | "code"

interface Marker {
  marker: string
  type: SpanType
  /** El contenido no se vuelve a interpretar (código). */
  raw?: boolean
}

// Más largos primero: ``` antes que `, ** antes que *.
const MARKERS: Record<RichTextDialect, Marker[]> = {
  whatsapp: [
    { marker: "```", type: "code", raw: true },
    { marker: "*", type: "bold" },
    { marker: "_", type: "italic" },
    { marker: "~", type: "strike" },
    { marker: "`", type: "code", raw: true },
  ],
  flow: [
    { marker: "**", type: "bold" },
    { marker: "~~", type: "strike" },
    { marker: "*", type: "italic" },
  ],
}

const BULLET: Record<RichTextDialect, RegExp> = {
  whatsapp: /^\s*[-*•]\s+(.*)$/,
  flow: /^\s*[-+]\s+(.*)$/,
}
const NUMBERED = /^\s*(\d+)\.\s+(.*)$/
const QUOTE = /^>\s?(.*)$/
const LINK = /^\[([^\]\n]+)\]\((https:\/\/[^\s)]+)\)/

const isWordChar = (char: string | undefined) => char !== undefined && /[\p{L}\p{N}]/u.test(char)
const isSpace = (char: string | undefined) => char === undefined || /\s/.test(char)

/** Abre si lo precede un borde (inicio, espacio, signo) y lo sigue algo que no es espacio. */
const opensAt = (text: string, index: number, marker: string) =>
  !isWordChar(text[index - 1]) && !isSpace(text[index + marker.length])

/** Cierra si lo precede algo que no es espacio, no lo sigue una letra y no es el comienzo de otro marcador igual. */
const closesAt = (text: string, index: number, marker: string) =>
  !isSpace(text[index - 1]) && !isWordChar(text[index + marker.length]) && text[index + marker.length] !== marker[0]

function matchSpan(text: string, index: number, markers: Marker[]) {
  for (const { marker, type, raw } of markers) {
    if (!text.startsWith(marker, index) || !opensAt(text, index, marker)) continue
    const start = index + marker.length
    // El formato de WhatsApp no cruza renglones.
    const lineEnd = text.indexOf("\n", start) === -1 ? text.length : text.indexOf("\n", start)
    for (let end = text.indexOf(marker, start + 1); end !== -1 && end < lineEnd; end = text.indexOf(marker, end + 1)) {
      if (closesAt(text, end, marker)) return { type, raw, inner: text.slice(start, end), next: end + marker.length }
    }
  }
  return null
}

export function parseInline(text: string, dialect: RichTextDialect): InlineNode[] {
  const nodes: InlineNode[] = []
  let plain = ""
  const flush = () => {
    if (plain) nodes.push({ type: "text", text: plain })
    plain = ""
  }

  let index = 0
  while (index < text.length) {
    const link = dialect === "flow" && text[index] === "[" ? LINK.exec(text.slice(index)) : null
    if (link) {
      flush()
      nodes.push({ type: "link", text: link[1], href: link[2] })
      index += link[0].length
      continue
    }
    const span = matchSpan(text, index, MARKERS[dialect])
    if (span) {
      flush()
      nodes.push({
        type: span.type,
        children: span.raw ? [{ type: "text", text: span.inner }] : parseInline(span.inner, dialect),
      })
      index = span.next
      continue
    }
    plain += text[index]
    index += 1
  }
  flush()
  return nodes
}

/** Renglones agrupados en párrafos, listas y citas (las citas sólo existen en WhatsApp). */
export function parseBlocks(text: string, dialect: RichTextDialect): BlockNode[] {
  const blocks: BlockNode[] = []
  for (const line of text.split("\n")) {
    const last = blocks.at(-1)
    const bullet = BULLET[dialect].exec(line)
    const numbered = bullet ? null : NUMBERED.exec(line)
    const quote = dialect === "whatsapp" && !bullet && !numbered ? QUOTE.exec(line) : null

    if (bullet) {
      const item = parseInline(bullet[1], dialect)
      if (last?.type === "bullets") last.items.push(item)
      else blocks.push({ type: "bullets", items: [item] })
    } else if (numbered) {
      const item = parseInline(numbered[2], dialect)
      if (last?.type === "numbers") last.items.push(item)
      else blocks.push({ type: "numbers", start: Number(numbered[1]), items: [item] })
    } else if (quote) {
      const quoted = parseInline(quote[1], dialect)
      if (last?.type === "quote") last.lines.push(quoted)
      else blocks.push({ type: "quote", lines: [quoted] })
    } else {
      const plain = parseInline(line, dialect)
      if (last?.type === "paragraph") last.lines.push(plain)
      else blocks.push({ type: "paragraph", lines: [plain] })
    }
  }
  return blocks
}

/** El texto como se lee, sin marcas de formato (resúmenes de una línea). */
export function plainText(text: string, dialect: RichTextDialect): string {
  const flatten = (nodes: InlineNode[]): string =>
    nodes.map((node) => (node.type === "text" ? node.text : node.type === "link" ? node.text : flatten(node.children))).join("")
  return flatten(parseInline(text, dialect))
}

/** Marcadores que el editor aplica con sus botones. */
export const FORMAT_MARKERS: Record<RichTextDialect, Partial<Record<SpanType, string>>> = {
  whatsapp: { bold: "*", italic: "_", strike: "~", code: "```" },
  flow: { bold: "**", italic: "*", strike: "~~" },
}

export interface TextEdit {
  value: string
  selectionStart: number
  selectionEnd: number
}

/**
 * Envuelve la selección con un marcador, o lo saca si ya lo tiene. Los espacios de los bordes
 * quedan afuera: WhatsApp no aplica el formato si el marcador toca un espacio.
 */
export function toggleWrap(value: string, start: number, end: number, marker: string): TextEdit {
  const selected = value.slice(start, end)
  const leading = selected.length - selected.trimStart().length
  const trailing = selected.length - selected.trimEnd().length
  const from = start + leading
  const to = Math.max(from, end - trailing)
  const size = marker.length

  if (value.slice(from - size, from) === marker && value.slice(to, to + size) === marker) {
    return {
      value: value.slice(0, from - size) + value.slice(from, to) + value.slice(to + size),
      selectionStart: from - size,
      selectionEnd: to - size,
    }
  }
  return {
    value: value.slice(0, from) + marker + value.slice(from, to) + marker + value.slice(to),
    selectionStart: from + size,
    selectionEnd: to + size,
  }
}

/** Antepone un prefijo a cada renglón tocado por la selección (o lo saca si todos lo tienen). */
export function togglePrefix(value: string, start: number, end: number, prefix: (index: number) => string, pattern: RegExp): TextEdit {
  const lineStart = value.lastIndexOf("\n", start - 1) + 1
  const nextBreak = value.indexOf("\n", end)
  const lineEnd = nextBreak === -1 ? value.length : nextBreak
  const lines = value.slice(lineStart, lineEnd).split("\n")
  const remove = lines.every((line) => pattern.test(line))
  const changed = lines.map((line, index) => (remove ? line.replace(pattern, "") : prefix(index) + line)).join("\n")
  return {
    value: value.slice(0, lineStart) + changed + value.slice(lineEnd),
    selectionStart: lineStart,
    selectionEnd: lineStart + changed.length,
  }
}

/** Inserta texto (un emoji) en el cursor, reemplazando la selección. */
export function insertAt(value: string, start: number, end: number, text: string): TextEdit {
  const cursor = start + text.length
  return { value: value.slice(0, start) + text + value.slice(end), selectionStart: cursor, selectionEnd: cursor }
}
