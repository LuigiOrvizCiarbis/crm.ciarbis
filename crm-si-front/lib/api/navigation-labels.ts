import { getAuthToken } from "./auth-token"
import type { NavigationLabels } from "@/data/navigation"

export async function updateNavigationLabels(
  labels: NavigationLabels,
): Promise<{ labels?: NavigationLabels; error?: string }> {
  const token = getAuthToken()
  if (!token) return { error: "No autenticado" }

  const response = await fetch("/api/navigation-labels", {
    method: "PUT",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({ labels }),
  })
  const payload = await response.json().catch(() => ({}))

  if (!response.ok) {
    const firstError = payload?.errors && typeof payload.errors === "object"
      ? Object.values(payload.errors).flat()[0]
      : null
    return { error: String(firstError || payload?.message || "No se pudieron guardar los nombres.") }
  }

  return { labels: payload?.data?.labels ?? {} }
}
