import { getAuthToken } from "./auth-token"
import { throwApiError } from "./api-error"

export interface MailRule { id: number; type: "allow" | "block"; value_type: "email" | "domain"; value: string }
async function request<T>(channelId: number, path = "", init: RequestInit = {}): Promise<T> { const token = getAuthToken(); if (!token) throw new Error("No authentication token found"); const response = await fetch(`/api/channels/${channelId}/mail-rules${path}`, { ...init, headers: { Authorization: `Bearer ${token}`, ...(init.body ? { "Content-Type": "application/json" } : {}) } }); const payload = await response.json().catch(() => ({})); if (!response.ok) throwApiError(response.status, payload, "No se pudieron actualizar las reglas"); return payload as T }
export const getMailRules = (channelId: number) => request<{ data: MailRule[] }>(channelId)
export const createMailRule = (channelId: number, input: Omit<MailRule, "id">) => request<{ data: MailRule }>(channelId, "", { method: "POST", body: JSON.stringify(input) })
export const deleteMailRule = (channelId: number, ruleId: number) => request<{ success: boolean }>(channelId, `/${ruleId}`, { method: "DELETE" })
