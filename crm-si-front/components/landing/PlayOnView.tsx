"use client"

import { useEffect, useRef, useState } from "react"
import { cn } from "@/lib/utils"

interface PlayOnViewProps {
  className?: string
  children: React.ReactNode
}

/**
 * Arranca las animaciones de la escena cuando entra en pantalla (ver .landing-stage en
 * globals.css). En el celular el hilo del hero queda debajo del pliegue y, con delays contados
 * desde la carga, ya terminaba antes de que nadie lo viera.
 */
export function PlayOnView({ className, children }: PlayOnViewProps) {
  const ref = useRef<HTMLDivElement>(null)
  const [inView, setInView] = useState(false)

  useEffect(() => {
    const node = ref.current
    if (!node) return

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry.isIntersecting) return
        setInView(true)
        observer.disconnect()
      },
      { threshold: 0.35 }
    )
    observer.observe(node)
    return () => observer.disconnect()
  }, [])

  return (
    <div ref={ref} data-inview={inView || undefined} className={cn("landing-stage", className)}>
      {children}
    </div>
  )
}
