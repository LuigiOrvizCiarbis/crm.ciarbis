"use client"

import { useEffect, useMemo, useState } from "react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Checkbox } from "@/components/ui/checkbox"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Send, Users, Calendar, Plus, Play, Pause, BarChart3, Loader2 } from "lucide-react"
import { getContacts, type Contact } from "@/lib/api/contacts"
import { getConversations } from "@/lib/api/conversations"
import { getPipelineStages, type PipelineStage } from "@/lib/api/pipeline"
import { getTags, type Tag } from "@/lib/api/tags"
import type { Conversation } from "@/data/types"

type SourceKey = "whatsapp" | "instagram" | "facebook" | "manual"

const SOURCE_OPTIONS: { value: SourceKey; label: string }[] = [
  { value: "whatsapp", label: "WhatsApp" },
  { value: "instagram", label: "Instagram" },
  { value: "facebook", label: "Facebook" },
  { value: "manual", label: "Manual" },
]

function toggleValue<T>(values: T[], value: T, checked: boolean): T[] {
  if (checked) return values.includes(value) ? values : [...values, value]
  return values.filter((item) => item !== value)
}

export function RemarketingCampaigns() {
  const [campaignType, setCampaignType] = useState("")
  const [selectedStageIds, setSelectedStageIds] = useState<number[]>([])
  const [selectedTagSlugs, setSelectedTagSlugs] = useState<string[]>([])
  const [selectedSources, setSelectedSources] = useState<SourceKey[]>([])
  const [inactiveDays, setInactiveDays] = useState<string>("any")
  const [contacts, setContacts] = useState<Contact[]>([])
  const [conversations, setConversations] = useState<Conversation[]>([])
  const [pipelineStages, setPipelineStages] = useState<PipelineStage[]>([])
  const [tags, setTags] = useState<Tag[]>([])
  const [isLoadingAudience, setIsLoadingAudience] = useState(true)

  const campaigns = [
    {
      id: 1,
      name: "Reactivación de leads fríos",
      status: "active",
      type: "WhatsApp",
      audience: "Leads sin actividad 30+ días",
      sent: 245,
      opened: 189,
      clicked: 67,
      converted: 12,
      created: "2024-01-15",
    },
    {
      id: 2,
      name: "Follow-up Demo Perdidas",
      status: "paused",
      type: "Email",
      audience: "Etapa demo: sin respuesta",
      sent: 89,
      opened: 72,
      clicked: 23,
      converted: 5,
      created: "2024-01-10",
    },
    {
      id: 3,
      name: "Oferta especial Q1",
      status: "scheduled",
      type: "SMS",
      audience: "Clientes activos",
      sent: 0,
      opened: 0,
      clicked: 0,
      converted: 0,
      created: "2024-01-20",
    },
  ]

  const messageTemplates = [
    {
      id: "reactivation",
      name: "Reactivación personalizada",
      content:
        "Hola {nombre}, notamos que hace tiempo no hablamos. ¿Seguís interesado en {producto}? Tenemos novedades que te pueden interesar 🚀",
    },
    {
      id: "demo-followup",
      name: "Seguimiento post-demo",
      content:
        "¡Hola {nombre}! ¿Qué te pareció la demo de {producto}? ¿Tenés alguna pregunta? Estoy acá para ayudarte 💪",
    },
    {
      id: "special-offer",
      name: "Oferta especial",
      content:
        "🎉 ¡{nombre}, tenemos una oferta especial para vos! {descuento}% de descuento en {producto}. ¡Solo por tiempo limitado!",
    },
  ]

  useEffect(() => {
    let cancelled = false
    setIsLoadingAudience(true)

    Promise.all([getContacts(), getConversations(), getPipelineStages(), getTags()])
      .then(([loadedContacts, loadedConversations, loadedStages, loadedTags]) => {
        if (cancelled) return
        setContacts(loadedContacts)
        setConversations(loadedConversations)
        setPipelineStages(loadedStages)
        setTags(loadedTags)
      })
      .finally(() => {
        if (!cancelled) setIsLoadingAudience(false)
      })

    return () => {
      cancelled = true
    }
  }, [])

  const audienceContacts = useMemo(() => {
    const conversationsByContact = new Map<number, Conversation[]>()
    for (const conversation of conversations) {
      const contactId = Number(conversation.contact?.id ?? conversation.contact_id)
      if (!Number.isFinite(contactId)) continue
      conversationsByContact.set(contactId, [...(conversationsByContact.get(contactId) || []), conversation])
    }

    const selectedTags = new Set(selectedTagSlugs)
    const selectedStages = new Set(selectedStageIds)
    const selectedSourceSet = new Set(selectedSources)
    const days = inactiveDays === "any" ? null : Number(inactiveDays)
    const cutoff = days ? Date.now() - days * 24 * 60 * 60 * 1000 : null

    return contacts.filter((contact) => {
      const relatedConversations = conversationsByContact.get(contact.id) || []

      if (campaignType === "whatsapp" && !contact.phone) return false
      if (campaignType === "email" && !contact.email) return false

      if (selectedSourceSet.size > 0 && !selectedSourceSet.has(contact.source as SourceKey)) {
        return false
      }

      if (selectedStages.size > 0) {
        const hasStage = relatedConversations.some((conversation) => (
          conversation.pipeline_stage_id ? selectedStages.has(conversation.pipeline_stage_id) : false
        ))
        if (!hasStage) return false
      }

      if (selectedTags.size > 0) {
        const contactHasTag = contact.tags?.some((tag) => selectedTags.has(tag.slug)) ?? false
        const conversationHasTag = relatedConversations.some((conversation) => (
          conversation.tags?.some((tag) => selectedTags.has(tag.slug))
        ))
        if (!contactHasTag && !conversationHasTag) return false
      }

      if (cutoff) {
        const timestamps = relatedConversations
          .map((conversation) => conversation.last_message_at || conversation.created_at)
          .filter(Boolean)
          .map((value) => new Date(value as string).getTime())
          .filter(Number.isFinite)
        const lastActivity = timestamps.length > 0 ? Math.max(...timestamps) : new Date(contact.updated_at).getTime()
        if (lastActivity > cutoff) return false
      }

      return true
    })
  }, [campaignType, contacts, conversations, inactiveDays, selectedSources, selectedStageIds, selectedTagSlugs])

  const selectedFilterCount = selectedStageIds.length + selectedTagSlugs.length + selectedSources.length + (inactiveDays === "any" ? 0 : 1)

  return (
    <div className="space-y-6">
      <Tabs defaultValue="campaigns" className="w-full">
        <TabsList className="grid w-full grid-cols-3">
          <TabsTrigger value="campaigns">Campañas</TabsTrigger>
          <TabsTrigger value="create">Crear campaña</TabsTrigger>
          <TabsTrigger value="templates">Templates</TabsTrigger>
        </TabsList>

        <TabsContent value="campaigns" className="space-y-4">
          <div className="grid gap-4">
            {campaigns.map((campaign) => (
              <Card key={campaign.id}>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <div>
                    <CardTitle className="text-base">{campaign.name}</CardTitle>
                    <CardDescription>{campaign.audience}</CardDescription>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge
                      variant={
                        campaign.status === "active"
                          ? "default"
                          : campaign.status === "paused"
                            ? "secondary"
                            : "outline"
                      }
                    >
                      {campaign.status === "active"
                        ? "Activa"
                        : campaign.status === "paused"
                          ? "Pausada"
                          : "Programada"}
                    </Badge>
                    <Badge variant="outline">{campaign.type}</Badge>
                  </div>
                </CardHeader>
                <CardContent>
                  <div className="grid grid-cols-4 gap-4 text-sm">
                    <div>
                      <p className="text-muted-foreground">Enviados</p>
                      <p className="font-semibold">{campaign.sent}</p>
                    </div>
                    <div>
                      <p className="text-muted-foreground">Abiertos</p>
                      <p className="font-semibold">{campaign.opened}</p>
                    </div>
                    <div>
                      <p className="text-muted-foreground">Clicks</p>
                      <p className="font-semibold">{campaign.clicked}</p>
                    </div>
                    <div>
                      <p className="text-muted-foreground">Conversiones</p>
                      <p className="font-semibold text-success">{campaign.converted}</p>
                    </div>
                  </div>
                  <div className="flex justify-between items-center mt-4">
                    <p className="text-xs text-muted-foreground">Creada: {campaign.created}</p>
                    <div className="flex gap-2">
                      <Button size="sm" variant="outline">
                        <BarChart3 className="w-4 h-4 mr-1" />
                        Ver Métricas
                      </Button>
                      <Button size="sm" variant="outline">
                        {campaign.status === "active" ? (
                          <Pause className="w-4 h-4 mr-1" />
                        ) : (
                          <Play className="w-4 h-4 mr-1" />
                        )}
                        {campaign.status === "active" ? "Pausar" : "Activar"}
                      </Button>
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </TabsContent>

        <TabsContent value="create" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Nueva campaña de remarketing</CardTitle>
              <CardDescription>Creá mensajes masivos personalizados por etapa o etiqueta</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label htmlFor="campaign-name">Nombre de la Campaña</Label>
                  <Input id="campaign-name" placeholder="Ej: Reactivación Q1 2024" />
                </div>
                <div>
                  <Label htmlFor="campaign-type">Canal</Label>
                  <Select value={campaignType} onValueChange={setCampaignType}>
                    <SelectTrigger>
                      <SelectValue placeholder="Seleccionar canal" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="whatsapp">WhatsApp</SelectItem>
                      <SelectItem value="email">Email</SelectItem>
                      <SelectItem value="sms">SMS</SelectItem>
                      <SelectItem value="instagram">Instagram DM</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div>
                <Label>Audiencia objetivo</Label>
                <Card className="mt-2">
                  <CardContent className="space-y-5 pt-4">
                    {isLoadingAudience ? (
                      <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Loader2 className="h-4 w-4 animate-spin" />
                        Calculando audiencia
                      </div>
                    ) : (
                      <>
                        <div className="grid gap-5 lg:grid-cols-3">
                          <div className="space-y-3">
                            <div>
                              <p className="text-sm font-medium">Etapas</p>
                              <p className="text-xs text-muted-foreground">Incluye contactos con conversaciones en estas etapas.</p>
                            </div>
                            <div className="space-y-2">
                              {pipelineStages.map((stage) => (
                                <div key={stage.id} className="flex items-center gap-2">
                                  <Checkbox
                                    id={`stage-${stage.id}`}
                                    checked={selectedStageIds.includes(stage.id)}
                                    onCheckedChange={(checked) => {
                                      setSelectedStageIds((current) => toggleValue(current, stage.id, Boolean(checked)))
                                    }}
                                  />
                                  <Label htmlFor={`stage-${stage.id}`} className="cursor-pointer text-sm">
                                    {stage.name}
                                  </Label>
                                </div>
                              ))}
                            </div>
                          </div>

                          <div className="space-y-3">
                            <div>
                              <p className="text-sm font-medium">Etiquetas</p>
                              <p className="text-xs text-muted-foreground">Cruza tags de contacto y conversación.</p>
                            </div>
                            <div className="max-h-48 space-y-2 overflow-y-auto pr-1">
                              {tags.length > 0 ? tags.map((tag) => (
                                <div key={tag.id} className="flex items-center gap-2">
                                  <Checkbox
                                    id={`tag-${tag.slug}`}
                                    checked={selectedTagSlugs.includes(tag.slug)}
                                    onCheckedChange={(checked) => {
                                      setSelectedTagSlugs((current) => toggleValue(current, tag.slug, Boolean(checked)))
                                    }}
                                  />
                                  <Label htmlFor={`tag-${tag.slug}`} className="flex min-w-0 cursor-pointer items-center gap-2 text-sm">
                                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: tag.color }} />
                                    <span className="truncate">{tag.name}</span>
                                  </Label>
                                </div>
                              )) : (
                                <p className="text-sm text-muted-foreground">Todavía no hay etiquetas.</p>
                              )}
                            </div>
                          </div>

                          <div className="space-y-3">
                            <div>
                              <p className="text-sm font-medium">Fuente y actividad</p>
                              <p className="text-xs text-muted-foreground">Acota por origen y antigüedad del último contacto.</p>
                            </div>
                            <div className="space-y-2">
                              {SOURCE_OPTIONS.map((source) => (
                                <div key={source.value} className="flex items-center gap-2">
                                  <Checkbox
                                    id={`source-${source.value}`}
                                    checked={selectedSources.includes(source.value)}
                                    onCheckedChange={(checked) => {
                                      setSelectedSources((current) => toggleValue(current, source.value, Boolean(checked)))
                                    }}
                                  />
                                  <Label htmlFor={`source-${source.value}`} className="cursor-pointer text-sm">
                                    {source.label}
                                  </Label>
                                </div>
                              ))}
                            </div>
                            <div className="space-y-2 pt-2">
                              <Label>Inactividad</Label>
                              <Select value={inactiveDays} onValueChange={setInactiveDays}>
                                <SelectTrigger>
                                  <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                  <SelectItem value="any">Cualquier actividad</SelectItem>
                                  <SelectItem value="30">Sin actividad 30+ días</SelectItem>
                                  <SelectItem value="60">Sin actividad 60+ días</SelectItem>
                                  <SelectItem value="90">Sin actividad 90+ días</SelectItem>
                                </SelectContent>
                              </Select>
                            </div>
                          </div>
                        </div>

                        {selectedFilterCount > 0 && (
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                              setSelectedStageIds([])
                              setSelectedTagSlugs([])
                              setSelectedSources([])
                              setInactiveDays("any")
                            }}
                          >
                            Limpiar filtros
                          </Button>
                        )}
                      </>
                    )}
                    <div className="rounded-md bg-muted p-3">
                      <p className="text-sm text-muted-foreground">
                        <Users className="mr-1 inline h-4 w-4" />
                        Audiencia estimada:{" "}
                        <span className="font-semibold text-foreground">{audienceContacts.length}</span>{" "}
                        contactos
                      </p>
                      <p className="mt-1 text-xs text-muted-foreground">
                        Los filtros se combinan entre grupos. Dentro de cada grupo alcanza con coincidir con una opción.
                      </p>
                    </div>
                  </CardContent>
                </Card>
              </div>

              <div>
                <Label htmlFor="message-content">Mensaje</Label>
                <Textarea
                  id="message-content"
                  placeholder="Escribí tu mensaje aquí. Usá {nombre}, {empresa}, {producto} para personalizar..."
                  className="min-h-24"
                />
                <p className="text-xs text-muted-foreground mt-1">
                  Variables disponibles: {"{nombre}"}, {"{empresa}"}, {"{producto}"}, {"{descuento}"}
                </p>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label htmlFor="send-date">Fecha de envío</Label>
                  <Input id="send-date" type="datetime-local" />
                </div>
                <div>
                  <Label htmlFor="frequency">Frecuencia</Label>
                  <Select>
                    <SelectTrigger>
                      <SelectValue placeholder="Una vez" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="once">Una vez</SelectItem>
                      <SelectItem value="daily">Diario</SelectItem>
                      <SelectItem value="weekly">Semanal</SelectItem>
                      <SelectItem value="monthly">Mensual</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div className="flex gap-2">
                <Button className="flex-1">
                  <Send className="w-4 h-4 mr-2" />
                  Crear y Enviar
                </Button>
                <Button variant="outline">
                  <Calendar className="w-4 h-4 mr-2" />
                  Programar
                </Button>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="templates" className="space-y-4">
          <div className="flex justify-between items-center">
            <h3 className="text-lg font-semibold">Plantillas de mensajes</h3>
            <Button>
              <Plus className="w-4 h-4 mr-2" />
              Nuevo Template
            </Button>
          </div>

          <div className="grid gap-4">
            {messageTemplates.map((template) => (
              <Card key={template.id}>
                <CardHeader>
                  <CardTitle className="text-base">{template.name}</CardTitle>
                </CardHeader>
                <CardContent>
                  <p className="text-sm text-muted-foreground mb-4">{template.content}</p>
                  <div className="flex gap-2">
                    <Button size="sm" variant="outline">
                      Editar
                    </Button>
                    <Button size="sm" variant="outline">
                      Usar Template
                    </Button>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </TabsContent>
      </Tabs>
    </div>
  )
}
