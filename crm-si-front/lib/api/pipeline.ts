
export interface PipelineStage {
  id: number
  pipeline_id: number
  name: string
  color: string
  sort_order: number
  is_default: boolean
  /** Probabilidad de cierre (0 a 100) para proyectar ingresos; null si no se definió. */
  win_probability: number | null
}

export interface Pipeline {
  id: number
  name: string
  is_default: boolean
  opportunities_count?: number
}

function authHeaders(): HeadersInit {
  return {
    "Content-Type": "application/json",
  };
}

/** Primer error de validación o el mensaje del backend (reglas de negocio en 422). */
function apiErrorMessage(payload: { message?: string; errors?: Record<string, string[]> }, fallback: string): string {
  return Object.values(payload?.errors ?? {})[0]?.[0] ?? payload?.message ?? fallback
}

export async function getPipelines(): Promise<Pipeline[]> {
  const res = await fetch("/api/pipelines", { headers: authHeaders(), cache: "no-store" })
  if (!res.ok) throw new Error("No se pudieron cargar los embudos")
  return res.json()
}

export async function createPipeline(name: string, stages?: { name: string; color: string }[]): Promise<Pipeline> {
  const res = await fetch("/api/pipelines", {
    method: "POST",
    headers: authHeaders(),
    body: JSON.stringify(stages?.length ? { name, stages } : { name }),
  })
  const payload = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(apiErrorMessage(payload, "No se pudo crear el embudo"))
  return payload
}

export async function updatePipeline(id: number, data: { name?: string; is_default?: boolean }): Promise<Pipeline> {
  const res = await fetch(`/api/pipelines/${id}`, { method: "PUT", headers: authHeaders(), body: JSON.stringify(data) })
  const payload = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(apiErrorMessage(payload, "No se pudo actualizar el embudo"))
  return payload
}

export async function deletePipeline(id: number): Promise<void> {
  const res = await fetch(`/api/pipelines/${id}`, { method: "DELETE", headers: authHeaders() })
  if (!res.ok) throw new Error(apiErrorMessage(await res.json().catch(() => ({})), "No se pudo eliminar el embudo"))
}

/** Igual que getPipelineStages pero lanza si falla, para las pantallas que distinguen "error" de "sin etapas". */
export async function fetchPipelineStages(pipelineId?: number): Promise<PipelineStage[]> {
  const query = pipelineId ? `?pipeline_id=${pipelineId}` : ""
  const res = await fetch(`/api/pipeline-stages${query}`, {
    headers: authHeaders(),
  });

  if (!res.ok) throw new Error(apiErrorMessage(await res.json().catch(() => ({})), "No se pudieron cargar las etapas"))
  return await res.json();
}

export async function getPipelineStages(pipelineId?: number): Promise<PipelineStage[]> {
  try {
    return await fetchPipelineStages(pipelineId);
  } catch (error) {
    console.error("Error fetching pipeline stages:", error);
    return [];
  }
}

export async function createPipelineStage(data: {
  pipeline_id: number
  name: string
  color: string
}): Promise<PipelineStage> {
  const res = await fetch("/api/pipeline-stages", {
    method: "POST",
    headers: authHeaders(),
    body: JSON.stringify(data),
  });

  if (!res.ok) throw new Error("No se pudo crear la etapa");
  return await res.json();
}

export async function updatePipelineStage(
  id: number,
  data: { name?: string; color?: string; sort_order?: number; win_probability?: number | null }
): Promise<PipelineStage> {
  const res = await fetch(`/api/pipeline-stages/${id}`, {
    method: "PUT",
    headers: authHeaders(),
    body: JSON.stringify(data),
  });

  if (!res.ok) throw new Error("No se pudo actualizar la etapa");
  return await res.json();
}

export async function deletePipelineStage(id: number): Promise<void> {
  const res = await fetch(`/api/pipeline-stages/${id}`, {
    method: "DELETE",
    headers: authHeaders(),
  });

  if (!res.ok) throw new Error(apiErrorMessage(await res.json().catch(() => ({})), "No se pudo eliminar la etapa"));
}

export async function reorderPipelineStages(
  pipelineId: number,
  stages: { id: number; sort_order: number }[]
): Promise<void> {
  const res = await fetch("/api/pipeline-stages/reorder", {
    method: "POST",
    headers: authHeaders(),
    body: JSON.stringify({ pipeline_id: pipelineId, stages }),
  });

  if (!res.ok) throw new Error("No se pudo reordenar las etapas");
}
