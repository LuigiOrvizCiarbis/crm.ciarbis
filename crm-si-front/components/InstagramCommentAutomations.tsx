"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { ArrowRight, Instagram, Loader2, Pause, Pencil, Play, Plus, Zap } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { ChannelType } from "@/data/enums"
import type { Channel } from "@/data/types"
import { getChannels } from "@/lib/api/channels"
import { activateAutomation, createAutomation, getAutomations, pauseAutomation, updateAutomation, type AutomationRule } from "@/lib/api/automations"
import { useToast } from "@/components/Toast"

const TRIGGER = "instagram.comment_keyword"
const ACTION = "instagram_private_reply"

export function InstagramCommentAutomations() {
  const { addToast } = useToast()
  const [channels, setChannels] = useState<Channel[]>([])
  const [rules, setRules] = useState<AutomationRule[]>([])
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<AutomationRule | null>(null)
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)
  const [channelId, setChannelId] = useState("")
  const [name, setName] = useState("Responder palabra clave")
  const [keywords, setKeywords] = useState("")
  const [message, setMessage] = useState("")

  const instagramChannels = useMemo(() => channels.filter((channel) => Number(channel.type) === ChannelType.INSTAGRAM && channel.status === "active"), [channels])
  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [loadedChannels, loadedRules] = await Promise.all([getChannels(), getAutomations()])
      setChannels(loadedChannels)
      setRules(loadedRules.filter((rule) => rule.trigger_type === TRIGGER))
    } catch (error) {
      addToast({ type: "error", title: error instanceof Error ? error.message : "No se pudieron cargar las automatizaciones" })
    } finally { setLoading(false) }
  }, [addToast])

  useEffect(() => { void load() }, [load])

  const resetForm = () => { setEditing(null); setName("Responder palabra clave"); setChannelId(instagramChannels[0] ? String(instagramChannels[0].id) : ""); setKeywords(""); setMessage("") }
  const edit = (rule: AutomationRule) => {
    setEditing(rule); setName(rule.name); setChannelId(String(rule.trigger_config.channel_id ?? "")); setKeywords(((rule.trigger_config.keywords as string[]) ?? []).join(", ")); setMessage(String(rule.actions[0]?.config.message ?? "")); setOpen(true)
  }
  const save = async () => {
    const parsedKeywords = keywords.split(",").map((item) => item.trim()).filter(Boolean)
    if (!channelId || !name.trim() || parsedKeywords.length === 0 || !message.trim()) { addToast({ type: "error", title: "Completá canal, palabras clave y mensaje" }); return }
    setSaving(true)
    const payload = { name: name.trim(), trigger_type: TRIGGER, trigger_config: { channel_id: Number(channelId), keywords: parsedKeywords }, conditions: null, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC", actions: [{ type: ACTION as "instagram_private_reply", config: { channel_id: Number(channelId), message: message.trim() } }] }
    try {
      if (editing) {
        const updated = await updateAutomation(editing.id, payload)
        // Editar no cambia el estado: sólo revalidamos la regla si ya estaba activa.
        // Una regla pausada o borrador debe seguir sin disparar mensajes.
        if (editing.status === "active") {
          await pauseAutomation(updated.id)
          await activateAutomation(updated.id)
        }
      }
      else { const created = await createAutomation(payload); await activateAutomation(created.id) }
      addToast({ type: "success", title: editing ? "Respuesta automática actualizada" : "Respuesta automática activada" }); setOpen(false); resetForm(); await load()
    } catch (error) { addToast({ type: "error", title: error instanceof Error ? error.message : "No se pudo guardar" }) } finally { setSaving(false) }
  }
  const toggle = async (rule: AutomationRule) => {
    try { if (rule.status === "active") await pauseAutomation(rule.id); else await activateAutomation(rule.id); await load(); addToast({ type: "success", title: rule.status === "active" ? "Automatización pausada" : "Automatización activada" }) }
    catch (error) { addToast({ type: "error", title: error instanceof Error ? error.message : "No se pudo cambiar el estado" }) }
  }

  return <Card className="mb-4 overflow-hidden border-primary/20">
    <CardHeader className="flex flex-row items-start justify-between gap-4 bg-primary/[0.035]">
      <div><div className="mb-1 flex items-center gap-2"><Zap className="h-4 w-4 text-primary" /><CardTitle className="text-base">Respuestas automáticas</CardTitle></div><p className="text-sm text-muted-foreground">Cuando alguien comenta una palabra, enviá un mensaje privado dentro de la ventana de Meta.</p></div>
      <Button size="sm" onClick={() => { resetForm(); setOpen(true) }} disabled={instagramChannels.length === 0}><Plus className="mr-1 h-4 w-4" />Nueva regla</Button>
    </CardHeader>
    <CardContent className="pt-4">
      {loading ? <div className="flex items-center gap-2 text-sm text-muted-foreground"><Loader2 className="h-4 w-4 animate-spin" />Cargando reglas…</div> : rules.length === 0 ? <div className="flex items-center gap-3 rounded-md border border-dashed p-4 text-sm text-muted-foreground"><Instagram className="h-5 w-5" /><span>Creá una regla para convertir comentarios en conversaciones privadas.</span></div> : <div className="space-y-2">{rules.map((rule) => { const channel = channels.find((item) => item.id === Number(rule.trigger_config.channel_id)); return <div key={rule.id} className="flex flex-col gap-3 rounded-md border p-3 sm:flex-row sm:items-center sm:justify-between"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><span className="font-medium">{rule.name}</span><Badge variant={rule.status === "active" ? "default" : "secondary"}>{rule.status === "active" ? "Activa" : rule.status === "paused" ? "Pausada" : "Borrador"}</Badge></div><div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"><span>{channel?.name ?? "Canal"}</span><ArrowRight className="h-3 w-3" /><span>{((rule.trigger_config.keywords as string[]) ?? []).join(", ")}</span></div><p className="mt-1 line-clamp-1 text-sm text-muted-foreground">“{String(rule.actions[0]?.config.message ?? "") }”</p></div><div className="flex shrink-0 gap-1"><Button variant="outline" size="sm" onClick={() => edit(rule)}><Pencil className="mr-1 h-3.5 w-3.5" />Editar</Button><Button variant="ghost" size="sm" onClick={() => void toggle(rule)}>{rule.status === "active" ? <><Pause className="mr-1 h-3.5 w-3.5" />Pausar</> : <><Play className="mr-1 h-3.5 w-3.5" />Activar</>}</Button></div></div> })}</div>}
      {instagramChannels.length === 0 && <p className="mt-3 text-xs text-muted-foreground">Conectá una cuenta profesional de Instagram para crear reglas.</p>}
    </CardContent>
    <Dialog open={open} onOpenChange={setOpen}><DialogContent><DialogHeader><DialogTitle>{editing ? "Editar respuesta automática" : "Nueva respuesta automática"}</DialogTitle><DialogDescription>La palabra se busca como término completo, sin distinguir mayúsculas ni acentos.</DialogDescription></DialogHeader><div className="space-y-4"><label className="block space-y-1.5 text-sm font-medium">Nombre<Input value={name} onChange={(event) => setName(event.target.value)} placeholder="Ej. Enviar catálogo" /></label><label className="block space-y-1.5 text-sm font-medium">Canal<Select value={channelId} onValueChange={setChannelId}><SelectTrigger><SelectValue placeholder="Seleccioná un canal" /></SelectTrigger><SelectContent>{instagramChannels.map((channel) => <SelectItem key={channel.id} value={String(channel.id)}>{channel.name}</SelectItem>)}</SelectContent></Select></label><label className="block space-y-1.5 text-sm font-medium">Palabras clave<span className="font-normal text-muted-foreground"> (separadas por coma)</span><Input value={keywords} onChange={(event) => setKeywords(event.target.value)} placeholder="precio, catálogo, info" /></label><label className="block space-y-1.5 text-sm font-medium">Mensaje privado<Textarea className="resize-none" value={message} onChange={(event) => setMessage(event.target.value)} maxLength={1000} rows={4} placeholder="¡Gracias por comentar! Te escribimos por privado." /><span className="text-xs font-normal text-muted-foreground">{message.length}/1000</span></label><p className="rounded-md bg-muted/60 p-3 text-xs text-muted-foreground">Meta permite una respuesta privada por comentario durante 7 días. La regla queda registrada y puede pausarse cuando quieras.</p></div><DialogFooter><Button variant="outline" onClick={() => setOpen(false)}>Cancelar</Button><Button onClick={() => void save()} disabled={saving}>{saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}{editing ? "Guardar cambios" : "Crear y activar"}</Button></DialogFooter></DialogContent></Dialog>
  </Card>
}
