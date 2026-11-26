"use client"

import { useEffect, useState } from "react"
import { fetchPipelineStages, getPipelines, type Pipeline, type PipelineStage } from "@/lib/api/pipeline"

export type PipelineCatalogEntry = { pipeline: Pipeline; stages: PipelineStage[] }

/**
 * Los embudos cambian poco y cada fila de una tabla puede pedirlos: se
 * comparte una sola carga por un minuto, así un admin que renombra etapas lo
 * ve sin recargar la página.
 */
const CATALOG_TTL_MS = 60_000

let cache: { promise: Promise<PipelineCatalogEntry[]>; loadedAt: number } | null = null

function loadCatalog(): Promise<PipelineCatalogEntry[]> {
  if (cache && Date.now() - cache.loadedAt < CATALOG_TTL_MS) return cache.promise

  const promise = getPipelines().then((pipelines) => Promise.all(pipelines.map(async (pipeline) => ({
    pipeline,
    stages: [...await fetchPipelineStages(pipeline.id)].sort((a, b) => a.sort_order - b.sort_order),
  }))))
  cache = { promise, loadedAt: Date.now() }
  // Un fallo no queda cacheado: el próximo intento vuelve a pedir.
  promise.catch(() => {
    if (cache?.promise === promise) cache = null
  })
  return promise
}

/** Embudos con sus etapas ordenadas; carga recién cuando `enabled` pasa a true. */
export function usePipelineCatalog(enabled: boolean) {
  const [catalog, setCatalog] = useState<PipelineCatalogEntry[] | null>(null)
  const [failed, setFailed] = useState(false)
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    if (!enabled) return
    let cancelled = false
    loadCatalog()
      .then((loaded) => {
        if (!cancelled) setCatalog(loaded)
      })
      .catch(() => {
        if (!cancelled) setFailed(true)
      })
    return () => {
      cancelled = true
    }
  }, [enabled, attempt])

  const retry = () => {
    setFailed(false)
    setAttempt((value) => value + 1)
  }

  return { catalog, failed, retry }
}

/**
 * Nombre legible de cada clave del filtro de etapas (ids como string). Con
 * varios embudos lleva el embudo delante; `none` usa `noneLabel`.
 */
export function useStageNames(keys: string[], noneLabel: string): Record<string, string> {
  const { catalog } = usePipelineCatalog(keys.length > 0)
  const multiplePipelines = (catalog?.length ?? 0) > 1
  const names: Record<string, string> = { none: noneLabel }
  catalog?.forEach(({ pipeline, stages }) => stages.forEach((stage) => {
    names[String(stage.id)] = multiplePipelines ? `${pipeline.name} · ${stage.name}` : stage.name
  }))
  return names
}
