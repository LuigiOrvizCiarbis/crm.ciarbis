"use client"

import { useEffect, useMemo, useRef, useState, type ReactNode } from "react"
import type { LucideIcon } from "lucide-react"
import { cn } from "@/lib/utils"
import { useAuthStore } from "@/store/useAuthStore"

export interface SettingsSection<Id extends string> {
  id: Id
  label: string
  description: string
  icon: LucideIcon
  visible: boolean
}

/**
 * Scroll-spy + deep-link por hash compartido entre /configuracion y /perfil.
 * Extraído de app/configuracion/page.tsx: mismo IntersectionObserver, mismo
 * comportamiento de hash (incluye subrutas tipo #section/detalle).
 */
export function useSettingsNav<Id extends string>(sections: SettingsSection<Id>[]) {
  const scrollContainerRef = useRef<HTMLDivElement>(null)
  const [activeSection, setActiveSection] = useState<Id>(sections[0]?.id as Id)
  const hasHydrated = useAuthStore((state) => state._hasHydrated)

  const visibleSections = useMemo(() => sections.filter((section) => section.visible), [sections])
  const visibleSectionIds = visibleSections.map((section) => section.id).join(",")

  const scrollSectionIntoView = (id: Id, behavior: ScrollBehavior) => {
    const container = scrollContainerRef.current
    const section = document.getElementById(id)
    if (!container || !section) return

    const top =
      section.getBoundingClientRect().top -
      container.getBoundingClientRect().top +
      container.scrollTop
    container.scrollTo({ top, behavior })
  }

  useEffect(() => {
    if (!hasHydrated) return

    const sectionIds = visibleSectionIds.split(",").filter(Boolean) as Id[]
    if (sectionIds.length === 0) return

    // El hash puede traer subruta de detalle (#section/detalle): el primer
    // segmento identifica la sección.
    const hashSection = window.location.hash.slice(1).split("/")[0] as Id
    const initialSection = sectionIds.includes(hashSection) ? hashSection : sectionIds[0]

    setActiveSection(initialSection)
    const initialScrollFrame = sectionIds.includes(hashSection)
      ? window.requestAnimationFrame(() => {
          scrollSectionIntoView(hashSection, "auto")
        })
      : null

    const elements = sectionIds
      .map((id) => document.getElementById(id))
      .filter((element): element is HTMLElement => element !== null)

    const observer = new IntersectionObserver(
      (entries) => {
        const visibleEntry = entries
          .filter((entry) => entry.isIntersecting)
          .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0]

        if (!visibleEntry) return

        const id = visibleEntry.target.id as Id
        setActiveSection(id)
        const currentHash = window.location.hash.slice(1)
        if (currentHash !== id && !currentHash.startsWith(`${id}/`)) {
          window.history.replaceState(null, "", `#${id}`)
        }
      },
      {
        root: scrollContainerRef.current,
        rootMargin: "-12% 0px -72% 0px",
        threshold: [0, 0.1, 0.5],
      },
    )

    elements.forEach((element) => observer.observe(element))
    return () => {
      if (initialScrollFrame !== null) {
        window.cancelAnimationFrame(initialScrollFrame)
      }
      observer.disconnect()
    }
  }, [hasHydrated, visibleSectionIds])

  const navigateToSection = (id: Id) => {
    setActiveSection(id)
    window.history.replaceState(null, "", `#${id}`)
    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches
    scrollSectionIntoView(id, reduceMotion ? "auto" : "smooth")
  }

  return { scrollContainerRef, activeSection, visibleSections, navigateToSection }
}

interface SettingsTabsProps<Id extends string> {
  sections: SettingsSection<Id>[]
  activeSection: Id
  onNavigate: (id: Id) => void
  label: string
  className?: string
}

export function SettingsTabs<Id extends string>({
  sections,
  activeSection,
  onNavigate,
  label,
  className,
}: SettingsTabsProps<Id>) {
  return (
    <nav aria-label={label} className={className}>
      <div className="flex gap-6 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        {sections.map((section) => {
          const Icon = section.icon
          const isActive = activeSection === section.id

          return (
            <button
              key={section.id}
              type="button"
              onClick={() => onNavigate(section.id)}
              aria-current={isActive ? "location" : undefined}
              className={cn(
                "flex min-h-12 shrink-0 items-center gap-2 border-b-2 px-1 text-sm font-medium outline-none transition-colors focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
                isActive
                  ? "border-primary text-foreground"
                  : "border-transparent text-muted-foreground hover:border-border hover:text-foreground",
              )}
            >
              <Icon className={cn("size-4", isActive ? "text-primary" : "text-muted-foreground")} />
              {section.label}
            </button>
          )
        })}
      </div>
    </nav>
  )
}

interface SettingsSectionHeadingProps<Id extends string> {
  section: SettingsSection<Id>
  children: ReactNode
}

export function SettingsSectionHeading<Id extends string>({
  section,
  children,
}: SettingsSectionHeadingProps<Id>) {
  const Icon = section.icon

  return (
    <section id={section.id} aria-labelledby={`${section.id}-title`} className="scroll-mt-14 pt-14 first:pt-10">
      <div className="mb-7">
        <h2
          id={`${section.id}-title`}
          className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground"
        >
          <Icon className="size-3.5" aria-hidden />
          {section.label}
        </h2>
        <p className="mt-2 max-w-[68ch] text-sm leading-6 text-muted-foreground">{section.description}</p>
      </div>
      <div className="space-y-10">{children}</div>
    </section>
  )
}
