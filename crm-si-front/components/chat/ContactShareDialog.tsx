"use client"

import { useEffect, useState } from "react"
import { getContacts, type Contact } from "@/lib/api/contacts"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Check, Loader2, Search, UserRound } from "lucide-react"

interface ContactShareDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSend: (ids: number[]) => void | Promise<void>
  disabled?: boolean
}

export function ContactShareDialog({ open, onOpenChange, onSend, disabled = false }: ContactShareDialogProps) {
  const [contacts, setContacts] = useState<Contact[]>([])
  const [selected, setSelected] = useState<number[]>([])
  const [query, setQuery] = useState("")
  const [loading, setLoading] = useState(false)
  const [sending, setSending] = useState(false)

  useEffect(() => {
    if (!open) return
    setLoading(true)
    getContacts({ per_page: 100, search: query || undefined })
      .then(setContacts)
      .catch(() => setContacts([]))
      .finally(() => setLoading(false))
  }, [open, query])

  const toggle = (id: number) => setSelected((current) => current.includes(id)
    ? current.filter((item) => item !== id)
    : current.length < 10 ? [...current, id] : current)

  const submit = async () => {
    if (!selected.length) return
    setSending(true)
    try {
      await onSend(selected)
      setSelected([])
      setQuery("")
      onOpenChange(false)
    } finally {
      setSending(false)
    }
  }

  return <Dialog open={open} onOpenChange={onOpenChange}>
    <DialogContent className="max-w-lg">
      <DialogHeader>
        <DialogTitle>Compartir contactos</DialogTitle>
        <DialogDescription>Seleccioná hasta 10 contactos. WhatsApp los mostrará con su tarjeta nativa.</DialogDescription>
      </DialogHeader>
      <div className="relative"><Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" /><Input className="pl-9" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar por nombre o teléfono" /></div>
      <div className="max-h-72 overflow-y-auto rounded-md border">
        {loading ? <div className="flex justify-center p-8"><Loader2 className="h-5 w-5 animate-spin" /></div> : contacts.length === 0 ? <p className="p-8 text-center text-sm text-muted-foreground">No hay contactos disponibles.</p> : contacts.map((contact) => {
          const checked = selected.includes(contact.id)
          return <button key={contact.id} type="button" onClick={() => toggle(contact.id)} className={`flex w-full items-center gap-3 border-b px-3 py-2 text-left last:border-0 hover:bg-muted/60 ${checked ? "bg-primary/5" : ""}`}>
            <span className={`flex h-8 w-8 items-center justify-center rounded-full ${checked ? "bg-primary text-primary-foreground" : "bg-muted text-muted-foreground"}`}>{checked ? <Check className="h-4 w-4" /> : <UserRound className="h-4 w-4" />}</span>
            <span className="min-w-0 flex-1"><span className="block truncate text-sm font-medium">{contact.name || "Sin nombre"}</span><span className="block truncate text-xs text-muted-foreground">{contact.phone || contact.email || "Sin teléfono ni email"}</span></span>
          </button>
        })}
      </div>
      {selected.length > 0 && <div className="rounded-md bg-muted/50 p-3"><p className="mb-2 text-xs font-medium">Vista previa de la tarjeta nativa</p><div className="flex flex-wrap gap-1.5">{contacts.filter((contact) => selected.includes(contact.id)).map((contact) => <span key={contact.id} className="rounded-full border bg-background px-2 py-1 text-xs">{contact.name || contact.phone || "Sin nombre"}</span>)}</div></div>}
      <DialogFooter><span className="mr-auto text-xs text-muted-foreground">{selected.length}/10 seleccionados</span><Button variant="outline" onClick={() => onOpenChange(false)}>Cancelar</Button><Button disabled={!selected.length || disabled || sending} onClick={() => void submit()}>{sending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}Enviar</Button></DialogFooter>
    </DialogContent>
  </Dialog>
}
