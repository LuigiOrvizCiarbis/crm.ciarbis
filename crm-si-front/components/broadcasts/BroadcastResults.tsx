"use client"

import { useCallback, useEffect, useState } from "react"
import Link from "next/link"
import { ArrowLeft, CheckCheck, Clock3, Loader2, MessageCircle, RefreshCw, Search, XCircle } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { getBroadcastRecipient, getBroadcastRecipients, getBroadcastResults } from "@/lib/api/broadcasts"
import type { BroadcastRecipientResult, BroadcastResults } from "@/lib/api/broadcasts"
import { getPusher } from "@/lib/pusher"

const statusClass: Record<string, string> = {
  pending: "bg-muted text-muted-foreground", accepted_unconfirmed: "bg-amber-500/10 text-amber-700",
  delivered: "bg-sky-500/10 text-sky-700", read: "bg-emerald-500/10 text-emerald-700", failed: "bg-red-500/10 text-red-700",
}

export function BroadcastResults({ id }: { id: number }) {
  const [results, setResults] = useState<BroadcastResults | null>(null)
  const [rows, setRows] = useState<BroadcastRecipientResult[]>([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [search, setSearch] = useState("")
  const [status, setStatus] = useState("")
  const [loading, setLoading] = useState(true)
  const [selected, setSelected] = useState<{ row: BroadcastRecipientResult; history: Array<{ type: string; value: string | null; content: string | null; occurred_at: string }> } | null>(null)

  const load = useCallback(async (targetPage = page) => {
    const [summary, recipients] = await Promise.all([getBroadcastResults(id), getBroadcastRecipients(id, { page: targetPage, search, status: status || undefined })])
    setResults(summary)
    setRows(recipients.data)
    setLastPage(recipients.meta.last_page)
    setLoading(false)
  }, [id, page, search, status])

  // Data fetching is the synchronization performed by this effect.
  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => { void load(1).then(() => setPage(1)) }, [id, search, status]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    const pusher = getPusher()
    const channel = pusher.subscribe(`private-broadcasts.${id}`)
    const refresh = () => void load(page)
    channel.bind("broadcast.results.updated", refresh)
    return () => { channel.unbind("broadcast.results.updated", refresh); pusher.unsubscribe(`private-broadcasts.${id}`) }
  }, [id, load, page])

  const openRecipient = async (row: BroadcastRecipientResult) => {
    const detail = await getBroadcastRecipient(id, row.id)
    setSelected({ row, history: detail.history })
  }
  if (loading && !results) return <div className="flex items-center justify-center py-20 text-muted-foreground"><Loader2 className="mr-2 h-4 w-4 animate-spin" />Cargando resultados…</div>
  if (!results?.results_available) return <div className="mx-auto max-w-2xl px-6 py-16"><Link href="/difusiones" className="text-sm text-muted-foreground">← Volver a Difusiones</Link><h1 className="mt-6 text-2xl font-semibold">Resultados no disponibles</h1><p className="mt-2 text-muted-foreground">Esta campaña es anterior a la activación del seguimiento detallado.</p></div>
  const summary = results.summary!
  const cards = [
    ["Aceptados por Meta", summary.accepted_count, summary.audience_count, CheckCheck], ["Entregados", summary.delivered_count, summary.accepted_count, CheckCheck],
    ["Leídos", summary.read_count, summary.delivered_count, CheckCheck], ["Interactuaron", summary.interacted_count, summary.audience_count, MessageCircle],
    ["Sin confirmación", summary.unconfirmed_count, summary.accepted_count, Clock3], ["Fallidos", summary.failed_count, summary.audience_count, XCircle],
  ] as const
  return <>
    <main className="mx-auto max-w-7xl px-4 py-6 md:px-8">
      <Link href="/difusiones" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="h-4 w-4" />Volver a Difusiones</Link>
      <div className="mt-6 flex flex-wrap items-start justify-between gap-4"><div><p className="text-sm text-muted-foreground">Resultados de difusión</p><h1 className="text-2xl font-semibold tracking-tight">{results.campaign?.name}</h1><p className="mt-1 text-sm text-muted-foreground">{results.campaign?.template.name} · {results.campaign?.channel.name}</p></div><Button variant="outline" size="sm" onClick={() => void load(page)}><RefreshCw className="mr-2 h-4 w-4" />Actualizar</Button></div>
      <div className="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">{cards.map(([label, value, denominator, Icon]) => <div key={label} className="rounded-lg border bg-card p-4"><div className="flex items-center justify-between text-muted-foreground"><span className="text-xs font-medium">{label}</span><Icon className="h-4 w-4" /></div><p className="mt-2 text-2xl font-semibold tabular-nums">{value.toLocaleString("es-AR")}</p><p className="text-xs text-muted-foreground">de {denominator.toLocaleString("es-AR")}</p></div>)}</div>
      <div className="mt-8 rounded-lg border"><div className="flex flex-wrap gap-3 border-b p-4"><div className="relative min-w-64 flex-1"><Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" /><Input className="pl-9" placeholder="Buscar contacto o teléfono" value={search} onChange={(event) => setSearch(event.target.value)} /></div><select className="h-9 rounded-md border bg-background px-3 text-sm" value={status} onChange={(event) => setStatus(event.target.value)}><option value="">Todos los estados</option><option value="pending">Pendientes</option><option value="accepted_unconfirmed">Sin confirmación</option><option value="delivered">Entregados</option><option value="read">Leídos</option><option value="failed">Fallidos</option></select></div><div className="overflow-x-auto"><Table><TableHeader><TableRow><TableHead>Contacto</TableHead><TableHead>Estado</TableHead><TableHead>Interacción</TableHead><TableHead>Último hito</TableHead><TableHead>Motivo</TableHead></TableRow></TableHeader><TableBody>{rows.map((row) => <TableRow key={row.id} className="cursor-pointer" onClick={() => void openRecipient(row)}><TableCell><p className="font-medium">{row.contact.name}</p><p className="text-xs text-muted-foreground">{row.contact.phone}</p></TableCell><TableCell><Badge className={statusClass[row.status]}>{row.status_label}</Badge></TableCell><TableCell>{row.interaction ? <span className="text-sm">{row.interaction.value || row.interaction.content || "Respondió"}</span> : <span className="text-sm text-muted-foreground">—</span>}</TableCell><TableCell className="text-sm text-muted-foreground">{row.read_at ? "Leído" : row.delivered_at ? "Entregado" : row.sent_at ? "Aceptado" : "Pendiente"}</TableCell><TableCell className="max-w-xs truncate text-sm text-muted-foreground">{row.failure?.message || "—"}</TableCell></TableRow>)}{rows.length === 0 && <TableRow><TableCell colSpan={5} className="h-28 text-center text-muted-foreground">No hay destinatarios con estos filtros.</TableCell></TableRow>}</TableBody></Table></div><div className="flex items-center justify-between border-t px-4 py-3 text-sm text-muted-foreground"><span>Página {page} de {lastPage}</span><div className="flex gap-2"><Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Anterior</Button><Button variant="outline" size="sm" disabled={page >= lastPage} onClick={() => setPage((value) => value + 1)}>Siguiente</Button></div></div></div>
    </main>
    <Sheet open={selected !== null} onOpenChange={(open) => !open && setSelected(null)}><SheetContent><SheetHeader><SheetTitle>{selected?.row.contact.name}</SheetTitle><SheetDescription>{selected?.row.contact.phone}</SheetDescription></SheetHeader>{selected && <div className="space-y-5 overflow-y-auto px-4 pb-6"><div><p className="text-sm font-medium">Estado actual</p><Badge className={statusClass[selected.row.status]}>{selected.row.status_label}</Badge></div>{selected.row.failure && <div className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"><p className="font-medium">Por qué no se entregó</p><p className="mt-1">{selected.row.failure.message}</p></div>}<div><p className="text-sm font-medium">Historial</p><div className="mt-2 space-y-3">{selected.history.map((item, index) => <div key={`${item.occurred_at}-${index}`} className="border-l-2 pl-3 text-sm"><p className="font-medium">{item.type === "quick_reply" ? "Respuesta rápida" : item.type === "reaction_removed" ? "Reacción eliminada" : item.type === "reaction" ? `Reacción ${item.value}` : "Respuesta"}</p>{item.content && <p className="text-muted-foreground">{item.content}</p>}<p className="text-xs text-muted-foreground">{new Date(item.occurred_at).toLocaleString("es-AR")}</p></div>)}</div></div>{selected.row.conversation_id !== null ? <Button asChild variant="outline"><Link href={`/chats?conversation=${selected.row.conversation_id}`}><MessageCircle className="mr-2 h-4 w-4" />Abrir chat</Link></Button> : <p className="text-xs text-muted-foreground">Todavía no se creó la conversación con este contacto.</p>}</div>}</SheetContent></Sheet>
  </>
}
