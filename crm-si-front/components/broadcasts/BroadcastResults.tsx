"use client"

import { useCallback, useEffect, useState } from "react"
import Link from "next/link"
import { ArrowLeft, Loader2, MessageCircle, RefreshCw, Search, XCircle } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet"
import { StatStrip, type Stat } from "@/components/ui/stat-strip"
import { DataTable, type DataTableColumn } from "@/components/ui/data-table"
import { DataTablePagination } from "@/components/ui/data-table-pagination"
import { getBroadcastRecipient, getBroadcastRecipients, getBroadcastResults } from "@/lib/api/broadcasts"
import type { BroadcastRecipientResult, BroadcastResults } from "@/lib/api/broadcasts"
import { useToast } from "@/components/Toast"
import { getPusher } from "@/lib/pusher"

const statusClass: Record<string, string> = {
  pending: "bg-muted text-muted-foreground", accepted_unconfirmed: "bg-warning/10 text-warning",
  delivered: "bg-info/10 text-info", read: "bg-success/10 text-success", failed: "bg-destructive/10 text-destructive",
}

const recipientColumns: DataTableColumn<BroadcastRecipientResult>[] = [
  { id: "contact", header: "Contacto", rowHeader: true, cell: (row) => <><p className="font-medium">{row.contact.name}</p><p className="text-xs text-muted-foreground">{row.contact.phone}</p></> },
  { id: "status", header: "Estado", cell: (row) => <Badge className={statusClass[row.status]}>{row.status_label}</Badge> },
  { id: "interaction", header: "Interacción", cell: (row) => row.interaction ? <span className="text-sm">{row.interaction.value || row.interaction.content || "Respondió"}</span> : <span className="text-sm text-muted-foreground">—</span> },
  { id: "milestone", header: "Último hito", className: "text-sm text-muted-foreground", cell: (row) => row.read_at ? "Leído" : row.delivered_at ? "Entregado" : row.sent_at ? "Aceptado" : "Pendiente" },
  { id: "failure", header: "Motivo", className: "max-w-xs truncate text-sm text-muted-foreground", cell: (row) => row.failure?.message || "—" },
]

export function BroadcastResults({ id }: { id: number }) {
  const { addToast } = useToast()
  const [results, setResults] = useState<BroadcastResults | null>(null)
  const [rows, setRows] = useState<BroadcastRecipientResult[]>([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [search, setSearch] = useState("")
  const [status, setStatus] = useState("")
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [selected, setSelected] = useState<{ row: BroadcastRecipientResult; history: Array<{ type: string; value: string | null; content: string | null; occurred_at: string }> } | null>(null)

  const load = useCallback(async (targetPage = page) => {
    try {
      const [summary, recipients] = await Promise.all([getBroadcastResults(id), getBroadcastRecipients(id, { page: targetPage, search, status: status || undefined })])
      setResults(summary)
      setRows(recipients.data)
      setLastPage(recipients.meta.last_page)
      setError(null)
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : "Revisá tu conexión e intentá de nuevo.")
    } finally {
      setLoading(false)
    }
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

  const refresh = async () => { setRefreshing(true); await load(page); setRefreshing(false) }
  const goToPage = (target: number) => { setPage(target); void load(target) }
  const openRecipient = async (row: BroadcastRecipientResult) => {
    try {
      const detail = await getBroadcastRecipient(id, row.id)
      setSelected({ row, history: detail.history })
    } catch (detailError) {
      addToast({ type: "error", title: detailError instanceof Error ? detailError.message : "No se pudo abrir el detalle del destinatario" })
    }
  }
  if (loading && !results) return <div className="flex items-center justify-center py-20 text-muted-foreground"><Loader2 className="mr-2 h-4 w-4 animate-spin" />Cargando resultados…</div>
  if (error && !results) return <div role="alert" className="mx-auto flex max-w-2xl flex-col items-center gap-3 px-6 py-16 text-center"><p className="font-medium text-destructive">No se pudieron cargar los resultados.</p><p className="text-sm text-muted-foreground">{error}</p><Button variant="outline" size="sm" disabled={refreshing} onClick={() => void refresh()}>{refreshing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCw className="mr-2 h-4 w-4" />}Reintentar</Button></div>
  if (!results?.results_available) return <div className="mx-auto max-w-2xl px-6 py-16"><Link href="/difusiones" className="text-sm text-muted-foreground">← Volver a Difusiones</Link><h1 className="mt-6 text-2xl font-semibold">Resultados no disponibles</h1><p className="mt-2 text-muted-foreground">Esta campaña es anterior a la activación del seguimiento detallado.</p></div>
  const summary = results.summary!
  const ofTotal = (value: number, denominator: number) => ({ value: value.toLocaleString("es-AR"), detail: `de ${denominator.toLocaleString("es-AR")}` })
  const stats: Stat[] = [
    { label: "Aceptados por Meta", ...ofTotal(summary.accepted_count, summary.audience_count) },
    { label: "Entregados", ...ofTotal(summary.delivered_count, summary.accepted_count) },
    { label: "Leídos", ...ofTotal(summary.read_count, summary.delivered_count) },
    { label: "Interactuaron", ...ofTotal(summary.interacted_count, summary.audience_count) },
    { label: "Sin confirmación", ...ofTotal(summary.unconfirmed_count, summary.accepted_count) },
    // Único ícono con significado: los fallidos piden revisión.
    { label: "Fallidos", ...ofTotal(summary.failed_count, summary.audience_count), ...(summary.failed_count > 0 ? { icon: XCircle, tone: "destructive" as const } : {}) },
  ]
  return <>
    <main className="mx-auto max-w-7xl px-4 py-6 md:px-8">
      <Link href="/difusiones" className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="h-4 w-4" />Volver a Difusiones</Link>
      <div className="mt-6 flex flex-wrap items-start justify-between gap-4"><div><p className="text-sm text-muted-foreground">Resultados de difusión</p><h1 className="text-2xl font-semibold tracking-tight">{results.campaign?.name}</h1><p className="mt-1 text-sm text-muted-foreground">{results.campaign?.template.name} · {results.campaign?.channel.name}</p></div><Button variant="outline" size="sm" disabled={refreshing} onClick={() => void refresh()}><RefreshCw className={`mr-2 h-4 w-4 ${refreshing ? "animate-spin" : ""}`} />Actualizar</Button></div>
      {error && <div role="alert" className="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"><span>No se pudieron actualizar los resultados. {error}</span><Button variant="outline" size="sm" disabled={refreshing} onClick={() => void refresh()}>Reintentar</Button></div>}
      <StatStrip stats={stats} className="mt-8" />
      <DataTable
        className="mt-8"
        columns={recipientColumns}
        data={rows}
        getRowId={(row) => String(row.id)}
        onRowClick={(row) => void openRecipient(row)}
        activeRowId={selected ? String(selected.row.id) : undefined}
        emptyLabel={search || status ? "No hay destinatarios con estos filtros." : "Esta difusión todavía no tiene destinatarios."}
        toolbar={<div className="flex flex-wrap gap-3"><div className="relative min-w-64 flex-1"><Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" /><Input className="pl-9" placeholder="Buscar contacto o teléfono" aria-label="Buscar contacto o teléfono" value={search} onChange={(event) => setSearch(event.target.value)} /></div><Select value={status || "all"} onValueChange={(value) => setStatus(value === "all" ? "" : value)}><SelectTrigger className="sm:w-52" aria-label="Filtrar por estado"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="all">Todos los estados</SelectItem><SelectItem value="pending">Pendientes</SelectItem><SelectItem value="accepted_unconfirmed">Sin confirmación</SelectItem><SelectItem value="delivered">Entregados</SelectItem><SelectItem value="read">Leídos</SelectItem><SelectItem value="failed">Fallidos</SelectItem></SelectContent></Select></div>}
        footer={<DataTablePagination page={page} pageCount={lastPage} onPageChange={goToPage} />}
      />
    </main>
    <Sheet open={selected !== null} onOpenChange={(open) => !open && setSelected(null)}><SheetContent><SheetHeader><SheetTitle>{selected?.row.contact.name}</SheetTitle><SheetDescription>{selected?.row.contact.phone}</SheetDescription></SheetHeader>{selected && <div className="space-y-5 overflow-y-auto px-4 pb-6"><div><p className="text-sm font-medium">Estado actual</p><Badge className={statusClass[selected.row.status]}>{selected.row.status_label}</Badge></div>{selected.row.failure && <div className="rounded-md border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"><p className="font-medium">Por qué no se entregó</p><p className="mt-1">{selected.row.failure.message}</p></div>}<div><p className="text-sm font-medium">Historial</p><div className="mt-2 space-y-3">{selected.history.map((item, index) => <div key={`${item.occurred_at}-${index}`} className="border-l-2 pl-3 text-sm"><p className="font-medium">{item.type === "quick_reply" ? "Respuesta rápida" : item.type === "reaction_removed" ? "Reacción eliminada" : item.type === "reaction" ? `Reacción ${item.value}` : "Respuesta"}</p>{item.content && <p className="text-muted-foreground">{item.content}</p>}<p className="text-xs text-muted-foreground">{new Date(item.occurred_at).toLocaleString("es-AR")}</p></div>)}</div></div>{selected.row.conversation_id !== null ? <Button asChild variant="outline"><Link href={`/chats?chat=${selected.row.conversation_id}`}><MessageCircle className="mr-2 h-4 w-4" />Abrir chat</Link></Button> : <p className="text-xs text-muted-foreground">Todavía no se creó la conversación con este contacto.</p>}</div>}</SheetContent></Sheet>
  </>
}
