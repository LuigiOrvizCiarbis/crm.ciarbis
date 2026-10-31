"use client"

import { useEffect, useState } from "react"
import { SidebarLayout } from "@/components/SidebarLayout"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Textarea } from "@/components/ui/textarea"
import { getAuthToken } from "@/lib/api/auth-token"
import { InstagramCommentAutomations } from "@/components/InstagramCommentAutomations"
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from "@/components/ui/alert-dialog"

type InstagramComment = {
  id: number
  external_id: string
  author_username?: string | null
  text?: string | null
  status: "new" | "in_progress" | "resolved"
  visibility: "visible" | "hidden" | "deleted"
  media_id?: string | null
  ad_title?: string | null
  commented_at?: string | null
  private_reply_deadline?: string | null
  private_replied_at?: string | null
  conversation_id?: number | null
}

async function commentRequest(path: string, init?: RequestInit) {
  const response = await fetch(`/api/instagram-comments${path}`, {
    ...init,
    headers: {
      Authorization: `Bearer ${getAuthToken()}`,
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(init?.headers || {}),
    },
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(payload.message || "No se pudo completar la acción")
  return payload
}

export default function InstagramCommentsPage() {
  const [comments, setComments] = useState<InstagramComment[]>([])
  const [selected, setSelected] = useState<InstagramComment | null>(null)
  const [text, setText] = useState("")
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [confirmDelete, setConfirmDelete] = useState(false)

  const load = async () => {
    setLoading(true)
    try {
      const payload = await commentRequest("?per_page=100")
      const items: InstagramComment[] = Array.isArray(payload.data)
        ? payload.data
        : payload.data?.data || []
      setComments(items)
      setSelected((current) => current
        ? items.find((item) => item.id === current.id) || null
        : null)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "No se pudieron cargar los comentarios")
    } finally { setLoading(false) }
  }

  useEffect(() => { void load() }, [])

  const action = async (path: string, body?: object) => {
    if (!selected) return
    setError(null)
    try {
      const isDelete = path === "/delete"
      await commentRequest(`/${selected.id}${isDelete ? "" : path}`, { method: isDelete ? "DELETE" : "POST", body: body ? JSON.stringify(body) : undefined })
      setText("")
      await load()
    } catch (cause) { setError(cause instanceof Error ? cause.message : "Error en Instagram") }
  }

  return (
    <SidebarLayout>
      <div className="flex-1 overflow-y-auto p-4 md:p-6">
        <div className="mb-6"><h1 className="text-2xl font-semibold">Comentarios de Instagram</h1><p className="text-sm text-muted-foreground">Gestioná comentarios públicos, respuestas privadas y moderación.</p></div>
        {error && <div className="mb-4 rounded-md bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
        <InstagramCommentAutomations />
        <div className="grid gap-4 lg:grid-cols-[minmax(280px,380px)_1fr]">
          <Card><CardHeader><CardTitle className="text-base">Pendientes</CardTitle></CardHeader><CardContent className="space-y-2">
            {loading ? <p className="text-sm text-muted-foreground">Cargando…</p> : comments.length === 0 ? <p className="text-sm text-muted-foreground">No hay comentarios.</p> : comments.map((comment) => <button key={comment.id} onClick={() => setSelected(comment)} className={`w-full rounded-md border p-3 text-left ${selected?.id === comment.id ? "border-primary bg-primary/5" : ""}`}><div className="flex justify-between text-sm font-medium"><span>@{comment.author_username || "usuario"}</span><span className="text-xs text-muted-foreground">{comment.status}</span></div><p className="mt-1 line-clamp-2 text-sm text-muted-foreground">{comment.text || "(sin texto)"}</p></button>)}
          </CardContent></Card>
          <Card><CardHeader><CardTitle className="text-base">Detalle</CardTitle></CardHeader><CardContent>{!selected ? <p className="text-sm text-muted-foreground">Seleccioná un comentario.</p> : <div className="space-y-4"><div><p className="font-medium">@{selected.author_username || "usuario"}</p><p className="whitespace-pre-wrap text-sm">{selected.text}</p><p className="mt-2 text-xs text-muted-foreground">{selected.ad_title || (selected.media_id ? `Media ${selected.media_id}` : "Instagram")}</p></div><Textarea className="resize-none" value={text} onChange={(event) => setText(event.target.value)} placeholder="Escribí una respuesta…" /><div className="flex flex-wrap gap-2"><Button disabled={!text.trim()} onClick={() => void action("/reply", { text })}>Responder públicamente</Button><Button variant="secondary" disabled={!text.trim() || !!selected.private_replied_at} onClick={() => void action("/private-reply", { text })}>Enviar por privado</Button><Button variant="outline" onClick={() => void action(selected.visibility === "hidden" ? "/unhide" : "/hide")}>{selected.visibility === "hidden" ? "Mostrar" : "Ocultar"}</Button><Button variant="destructive" onClick={() => setConfirmDelete(true)}>Eliminar</Button></div>{selected.private_reply_deadline && <p className="text-xs text-muted-foreground">Respuesta privada disponible hasta {new Date(selected.private_reply_deadline).toLocaleString()}</p>}</div>}</CardContent></Card>
        </div>
        <AlertDialog open={confirmDelete} onOpenChange={setConfirmDelete}><AlertDialogContent><AlertDialogHeader><AlertDialogTitle>¿Eliminar comentario?</AlertDialogTitle><AlertDialogDescription>Esta acción también lo marca como resuelto en el CRM.</AlertDialogDescription></AlertDialogHeader><AlertDialogFooter><AlertDialogCancel>Cancelar</AlertDialogCancel><AlertDialogAction onClick={() => { setConfirmDelete(false); void action("/delete") }}>Eliminar</AlertDialogAction></AlertDialogFooter></AlertDialogContent></AlertDialog>
      </div>
    </SidebarLayout>
  )
}
