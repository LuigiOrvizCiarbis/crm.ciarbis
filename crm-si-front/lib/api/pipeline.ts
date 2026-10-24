import { getAuthToken } from "./auth-token";

export interface PipelineStage {
  id: number
  pipeline_id: number
  name: string
  color: string
  sort_order: number
  is_default: boolean
}

export interface Pipeline {
  id: number
  name: string
  is_default: boolean
  opportunities_count?: number
}

function authHeaders(token: string): HeadersInit {
  return {
    Authorization: `Bearer ${token}`,
    "Content-Type": "application/json",
  };
}

/** Primer error de validación o el mensaje del backend (reglas de negocio en 422). */
function apiErrorMessage(payload: { message?: string; errors?: Record<string, string[]> }, fallback: string): string {
  return Object.values(payload?.errors ?? {})[0]?.[0] ?? payload?.message ?? fallback
}

export async function getPipelines(): Promise<Pipeline[]> {
  const token = getAuthToken()
  if (!token) return []
  const res = await fetch("/api/pipelines", { headers: authHeaders(token), cache: "no-store" })
  if (!res.ok) throw new Error("No se pudieron cargar los pipelines")
  return res.json()
}

export async function createPipeline(name: string, stages?: { name: string; color: string }[]): Promise<Pipeline> {
  const token = getAuthToken()
  if (!token) throw new Error("No autenticado")
  const res = await fetch("/api/pipelines", {
    method: "POST",
    headers: authHeaders(token),
    body: JSON.stringify(stages?.length ? { name, stages } : { name }),
  })
  const payload = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(apiErrorMessage(payload, "No se pudo crear el pipeline"))
  return payload
}

export async function updatePipeline(id: number, data: { name?: string; is_default?: boolean }): Promise<Pipeline> {
  const token = getAuthToken()
  if (!token) throw new Error("No autenticado")
  const res = await fetch(`/api/pipelines/${id}`, { method: "PUT", headers: authHeaders(token), body: JSON.stringify(data) })
  const payload = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(apiErrorMessage(payload, "No se pudo actualizar el pipeline"))
  return payload
}

export async function deletePipeline(id: number): Promise<void> {
  const token = getAuthToken()
  if (!token) throw new Error("No autenticado")
  const res = await fetch(`/api/pipelines/${id}`, { method: "DELETE", headers: authHeaders(token) })
  if (!res.ok) throw new Error(apiErrorMessage(await res.json().catch(() => ({})), "No se pudo eliminar el pipeline"))
}

export async function getPipelineStages(pipelineId?: number): Promise<PipelineStage[]> {
  const token = getAuthToken();

  if (!token) return [];

  try {
    const query = pipelineId ? `?pipeline_id=${pipelineId}` : ""
    const res = await fetch(`/api/pipeline-stages${query}`, {
      headers: authHeaders(token),
    });

    if (!res.ok) return [];
    return await res.json();
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
  const token = getAuthToken();
  if (!token) throw new Error("No autenticado");

  const res = await fetch("/api/pipeline-stages", {
    method: "POST",
    headers: authHeaders(token),
    body: JSON.stringify(data),
  });

  if (!res.ok) throw new Error("No se pudo crear la etapa");
  return await res.json();
}

export async function updatePipelineStage(
  id: number,
  data: { name?: string; color?: string; sort_order?: number }
): Promise<PipelineStage> {
  const token = getAuthToken();
  if (!token) throw new Error("No autenticado");

  const res = await fetch(`/api/pipeline-stages/${id}`, {
    method: "PUT",
    headers: authHeaders(token),
    body: JSON.stringify(data),
  });

  if (!res.ok) throw new Error("No se pudo actualizar la etapa");
  return await res.json();
}

export async function deletePipelineStage(id: number): Promise<void> {
  const token = getAuthToken();
  if (!token) throw new Error("No autenticado");

  const res = await fetch(`/api/pipeline-stages/${id}`, {
    method: "DELETE",
    headers: authHeaders(token),
  });

  if (!res.ok) throw new Error(apiErrorMessage(await res.json().catch(() => ({})), "No se pudo eliminar la etapa"));
}

export async function reorderPipelineStages(
  pipelineId: number,
  stages: { id: number; sort_order: number }[]
): Promise<void> {
  const token = getAuthToken();
  if (!token) throw new Error("No autenticado");

  const res = await fetch("/api/pipeline-stages/reorder", {
    method: "POST",
    headers: authHeaders(token),
    body: JSON.stringify({ pipeline_id: pipelineId, stages }),
  });

  if (!res.ok) throw new Error("No se pudo reordenar las etapas");
}
