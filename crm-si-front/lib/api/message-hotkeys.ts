export type MessageHotkeyScope = "tenant" | "personal"

export interface MessageHotkey {
  id: number
  trigger: string
  content: string
  description: string | null
  scope: MessageHotkeyScope
  user_id: number | null
  created_at?: string
  updated_at?: string
}

export interface MessageHotkeyInput {
  trigger: string
  content: string
  description?: string | null
  scope: MessageHotkeyScope
}

export async function getMessageHotkeys(): Promise<MessageHotkey[]> {
  const res = await fetch("/api/message-hotkeys", {
    headers: { Accept: "application/json" },
    cache: "no-store",
  })

  if (!res.ok) return []
  const json = await res.json()
  return json.data || []
}

export async function createMessageHotkey(
  input: MessageHotkeyInput,
): Promise<{ data?: MessageHotkey; error?: string }> {
  const res = await fetch("/api/message-hotkeys", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(input),
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.data }
}

export async function updateMessageHotkey(
  id: number,
  input: Omit<MessageHotkeyInput, "scope">,
): Promise<{ data?: MessageHotkey; error?: string }> {
  const res = await fetch(`/api/message-hotkeys/${id}`, {
    method: "PUT",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(input),
  })

  const json = await res.json().catch(() => ({}))
  if (!res.ok) return { error: extractError(json) }
  return { data: json.data }
}

export async function deleteMessageHotkey(id: number): Promise<{ error?: string }> {
  const res = await fetch(`/api/message-hotkeys/${id}`, {
    method: "DELETE",
    headers: { Accept: "application/json" },
  })

  if (!res.ok) {
    const json = await res.json().catch(() => ({}))
    return { error: extractError(json) }
  }
  return {}
}

function extractError(json: any): string {
  if (json?.errors && typeof json.errors === "object") {
    const first = Object.values(json.errors)[0]
    if (Array.isArray(first) && first.length > 0) return String(first[0])
  }
  return json?.message || "Error"
}
