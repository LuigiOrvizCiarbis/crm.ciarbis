import { Fragment, type ReactNode } from "react"

import { parseBlocks, parseInline, type InlineNode, type RichTextDialect } from "@/lib/rich-text"
import { cn } from "@/lib/utils"

type RenderText = (text: string, key: string) => ReactNode

function Inline({ nodes, renderText, keyPrefix }: { nodes: InlineNode[]; renderText?: RenderText; keyPrefix: string }) {
  return nodes.map((node, index) => {
    const key = `${keyPrefix}-${index}`
    switch (node.type) {
      case "text":
        return <Fragment key={key}>{renderText ? renderText(node.text, key) : node.text}</Fragment>
      case "link":
        return (
          <a key={key} href={node.href} target="_blank" rel="noopener noreferrer nofollow" className="underline underline-offset-2">
            {node.text}
          </a>
        )
      case "code":
        return (
          <code key={key} className="font-mono text-[0.92em]">
            <Inline nodes={node.children} renderText={renderText} keyPrefix={key} />
          </code>
        )
      default: {
        const Tag = { bold: "strong", italic: "em", strike: "s" }[node.type] as "strong" | "em" | "s"
        return (
          <Tag key={key} className={node.type === "bold" ? "font-semibold" : undefined}>
            <Inline nodes={node.children} renderText={renderText} keyPrefix={key} />
          </Tag>
        )
      }
    }
  })
}

function Lines({ lines, renderText, keyPrefix }: { lines: InlineNode[][]; renderText?: RenderText; keyPrefix: string }) {
  return lines.map((line, index) => (
    <Fragment key={index}>
      {index > 0 ? <br /> : null}
      <Inline nodes={line} renderText={renderText} keyPrefix={`${keyPrefix}-${index}`} />
    </Fragment>
  ))
}

/**
 * Texto con el formato que ve el cliente.
 *
 * Por defecto arma párrafos, listas y citas en un `<div>`. Con `inline` sólo aplica negrita,
 * cursiva, tachado y código, y deja los renglones como están: sirve dentro de un `<p>` con
 * `whitespace-pre-wrap`, como el texto del chat.
 */
export function RichText({
  text,
  dialect,
  inline = false,
  renderText,
  className,
}: {
  text: string
  dialect: RichTextDialect
  inline?: boolean
  /** Transforma cada tramo de texto plano (por ejemplo, para convertir links). */
  renderText?: RenderText
  className?: string
}) {
  if (inline) return <Inline nodes={parseInline(text, dialect)} renderText={renderText} keyPrefix="rt" />

  return (
    <div className={cn("space-y-1 break-words", className)}>
      {parseBlocks(text, dialect).map((block, index) => {
        const key = `rt-${index}`
        switch (block.type) {
          case "paragraph":
            return (
              <p key={key}>
                <Lines lines={block.lines} renderText={renderText} keyPrefix={key} />
              </p>
            )
          case "quote":
            return (
              <blockquote key={key} className="border-l-2 border-current/30 pl-2 opacity-80">
                <Lines lines={block.lines} renderText={renderText} keyPrefix={key} />
              </blockquote>
            )
          case "bullets":
            return (
              <ul key={key} className="list-disc space-y-0.5 pl-5">
                {block.items.map((item, itemIndex) => (
                  <li key={itemIndex}>
                    <Inline nodes={item} renderText={renderText} keyPrefix={`${key}-${itemIndex}`} />
                  </li>
                ))}
              </ul>
            )
          case "numbers":
            return (
              <ol key={key} start={block.start} className="list-decimal space-y-0.5 pl-5">
                {block.items.map((item, itemIndex) => (
                  <li key={itemIndex}>
                    <Inline nodes={item} renderText={renderText} keyPrefix={`${key}-${itemIndex}`} />
                  </li>
                ))}
              </ol>
            )
        }
      })}
    </div>
  )
}
