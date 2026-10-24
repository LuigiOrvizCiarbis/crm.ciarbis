import { getAuthToken } from "./auth-token";
import { throwApiError } from "./api-error";

export interface OpportunityPayload {
  contact_id: number
  pipeline_id?: number
  conversation_id?: number | null
  pipeline_stage_id?: number | null
  assigned_to?: number | null
  title?: string
  status?: "open" | "won" | "lost" | "archived"
  source_type?: "manual" | "conversation"
  value?: number | null
  notes?: string | null
}

interface GetOpportunitiesOptions {
  contactId?: number
  conversationId?: number
  pipelineId?: number
  status?: "open" | "won" | "lost" | "archived"
}

export interface OpportunitySummary {
  total_opportunities: number
  pipeline_value: number
  weighted_value: number
  avg_value: number
  won_value: number
  won_count: number
}

function requireToken() {
  const token = getAuthToken();
  if (!token) throw new Error("No authentication token found");
  return token;
}

export async function getOpportunities(options: GetOpportunitiesOptions = {}) {
  const token = requireToken();
  const params = new URLSearchParams({ per_page: "100" });

  if (options.contactId) {
    params.set("contact_id", String(options.contactId));
  }

  if (options.conversationId) params.set("conversation_id", String(options.conversationId))
  if (options.pipelineId) params.set("pipeline_id", String(options.pipelineId))

  if (options.status) {
    params.set("status", options.status);
  }

  const response = await fetch(`/api/opportunities?${params.toString()}`, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
    cache: "no-store",
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al cargar oportunidades");
  }

  return payload.data || [];
}

export async function createOpportunity(body: OpportunityPayload) {
  const token = requireToken();

  const response = await fetch("/api/opportunities", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify(body),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al crear oportunidad");
  }

  return payload.data;
}

export async function getOpportunitySummary(pipelineId?: number): Promise<OpportunitySummary> {
  const token = requireToken();

  const query = pipelineId ? `?pipeline_id=${pipelineId}` : ""
  const response = await fetch(`/api/opportunities/summary${query}`, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
    cache: "no-store",
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al cargar resumen de pipeline");
  }

  return payload as OpportunitySummary;
}

export async function updateOpportunityStage(opportunityId: number, stageId: number, pipelineId?: number) {
  const token = requireToken();

  const response = await fetch(`/api/opportunities/${opportunityId}/stage`, {
    method: "PATCH",
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ pipeline_stage_id: stageId, ...(pipelineId ? { pipeline_id: pipelineId } : {}) }),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throwApiError(response.status, payload, "Error al actualizar etapa");
  }

  return payload.data;
}
