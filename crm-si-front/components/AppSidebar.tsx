"use client"

import { useCallback, useEffect, useState } from "react"
import { CrmSidebar } from "@/components/crm-sidebar"
import { useIsMobile } from "@/hooks/use-mobile"

export function AppSidebar() {
  // Arranca abierto solo en desktop (lg+, ≥1024px), donde el sidebar es parte
  // del layout (lg:static) y no monta overlay. Por debajo de lg es un drawer
  // flotante con overlay bg-black/50, y debe arrancar CERRADO: si arrancara
  // abierto, el overlay cubría toda la pantalla de negro al cargar en anchos
  // intermedios (768–1024px) — la causa de la "pantalla negra" reportada.
  const [sidebarOpen, setSidebarOpen] = useState(() =>
    typeof window === "undefined" ? true : window.matchMedia("(min-width: 1024px)").matches,
  )
  const isMobile = useIsMobile()

  // Mantener el estado en sync con el breakpoint: al cruzar por debajo de lg el
  // sidebar pasa a ser drawer y debe cerrarse (si no, su overlay quedaría
  // cubriendo la pantalla); al cruzar a lg+ se abre como parte del layout. Esto
  // también corrige el caso en que matchMedia se reevalúa tras un diálogo nativo.
  useEffect(() => {
    const mql = window.matchMedia("(min-width: 1024px)")
    const sync = () => setSidebarOpen(mql.matches)
    mql.addEventListener("change", sync)
    return () => mql.removeEventListener("change", sync)
  }, [])

  const closeSidebar = useCallback(() => {
    setSidebarOpen(false)
  }, [])

  const toggleSidebar = useCallback(() => {
    setSidebarOpen((isOpen) => !isOpen)
  }, [])

  if (isMobile) {
    return null
  }

  return (
    <>
      {/* Overlay oscuro del drawer: solo por debajo de lg (a partir de lg el
          sidebar es lg:static, parte del layout, sin overlay). El drawer arranca
          cerrado en ese rango (ver useState abajo), así no cubre la pantalla de
          negro al cargar en anchos intermedios (768–1024px). */}
      {sidebarOpen ? (
        <div className="fixed inset-0 z-40 bg-overlay lg:hidden" onClick={closeSidebar} />
      ) : null}

      <div
        className={`
          fixed inset-y-0 left-0 z-50 h-dvh max-h-dvh shrink-0 transform transition-all duration-300 ease-in-out lg:static
          ${sidebarOpen ? "translate-x-0 w-60" : "-translate-x-full lg:translate-x-0 lg:w-20"}
        `}
      >
        <CrmSidebar isCollapsed={!sidebarOpen} onToggle={toggleSidebar} />
      </div>
    </>
  )
}
