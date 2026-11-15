"use client"

import { useCallback, useEffect, useState } from "react"

import type { WhatsAppTemplate } from "@/data/types"
import { getManagedTemplates } from "@/lib/api/templates"

/** Plantillas del canal en cualquier estado; `reload` las vuelve a pedir (por ejemplo, al crear una). */
export function useManagedTemplates(channelId: number | null) {
  const [state, setState] = useState<{ key: string; templates: WhatsAppTemplate[]; failed: boolean } | null>(null)
  const [nonce, setNonce] = useState(0)
  const key = channelId === null ? null : `${channelId}:${nonce}`

  useEffect(() => {
    if (key === null || channelId === null) return
    let cancelled = false
    getManagedTemplates(channelId)
      .then((templates) => !cancelled && setState({ key, templates, failed: false }))
      .catch(() => !cancelled && setState({ key, templates: [], failed: true }))
    return () => {
      cancelled = true
    }
  }, [key, channelId])

  const reload = useCallback(() => setNonce((value) => value + 1), [])
  const current = state?.key === key ? state : null

  return {
    templates: current?.templates ?? null,
    loading: key !== null && current === null,
    failed: current?.failed ?? false,
    reload,
  }
}
