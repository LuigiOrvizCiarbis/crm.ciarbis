"use client"

import type { LucideIcon } from "lucide-react"
import { createContext, useContext, type ReactNode } from "react"

import { cn } from "@/lib/utils"

/**
 * Rol del encabezado de un bloque según dónde se monta:
 * - `page`: el bloque es toda la página de configuración y su título es el h1.
 * - `section`: una página con varios bloques; el h1 lo pone `SettingsPage`.
 * - `block`: dentro de una sección con su propio h2 (/perfil).
 */
type SettingsHeadingLevel = "page" | "section" | "block"

interface SettingsHeadingContextValue {
  level: SettingsHeadingLevel
  /** En `page`, el h1 usa el nombre de la navegación para que ambos coincidan. */
  pageTitle?: string
}

const SettingsHeadingContext = createContext<SettingsHeadingContextValue>({ level: "block" })

export function SettingsHeadingLevelProvider({
  level,
  pageTitle,
  children,
}: SettingsHeadingContextValue & { children: ReactNode }) {
  return (
    <SettingsHeadingContext.Provider value={{ level, pageTitle }}>
      {children}
    </SettingsHeadingContext.Provider>
  )
}

const HEADING_TAG = { page: "h1", section: "h2", block: "h3" } as const

interface SettingsBlockProps {
  /** Ancla para enlazar al bloque desde otra pantalla. */
  id?: string
  title: string
  description?: string
  icon?: LucideIcon
  /** Acción principal del bloque, alineada a la derecha del encabezado. */
  action?: ReactNode
  /**
   * Ancho de la columna de contenido. `prose` para formularios y listas de
   * lectura, `full` para tablas y tableros que necesitan todo el ancho.
   */
  measure?: "prose" | "full"
  className?: string
  children: ReactNode
}

/**
 * Unidad de contenido de configuración y perfil. Sin caja: la separación se
 * resuelve con espacio y divisores, y el nivel del título sale del contexto.
 */
export function SettingsBlock({
  id,
  title,
  description,
  icon: Icon,
  action,
  measure = "full",
  className,
  children,
}: SettingsBlockProps) {
  const { level, pageTitle } = useContext(SettingsHeadingContext)
  const Heading = HEADING_TAG[level]
  const isPage = level === "page"
  const heading = isPage && pageTitle ? pageTitle : title

  return (
    <section id={id} className={cn("min-w-0", className)}>
      {/* En bloques `prose` el encabezado comparte el ancho del contenido: así la acción queda sobre él y no flotando en el borde de la página. */}
      <div
        className={cn(
          "flex flex-wrap items-start justify-between gap-x-6 gap-y-3",
          measure === "prose" && "max-w-2xl",
        )}
      >
        <div className={cn("min-w-0 flex-1 basis-72", isPage ? "space-y-1.5" : "space-y-1")}>
          <Heading
            className={cn(
              "flex items-center gap-2 font-semibold text-foreground",
              isPage ? "text-xl tracking-tight" : "text-[0.9375rem]",
            )}
          >
            {/* En la página el ícono ya está en la navegación; repetirlo junto al h1 sólo suma ruido. */}
            {Icon && !isPage && (
              <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden />
            )}
            {heading}
          </Heading>
          {description && (
            <p className="max-w-[68ch] text-pretty text-sm leading-6 text-muted-foreground">
              {description}
            </p>
          )}
        </div>
        {action && <div className="shrink-0">{action}</div>}
      </div>

      <div className={cn(isPage ? "mt-8" : "mt-5", measure === "prose" && "max-w-2xl")}>
        {children}
      </div>
    </section>
  )
}
