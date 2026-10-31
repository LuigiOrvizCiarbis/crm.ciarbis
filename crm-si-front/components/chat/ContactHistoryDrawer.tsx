"use client"

import { useState, useEffect } from "react"
import {
  Drawer,
  DrawerClose,
  DrawerContent,
  DrawerDescription,
  DrawerFooter,
  DrawerHeader,
  DrawerTitle,
} from "@/components/ui/drawer"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { ScrollArea } from "@/components/ui/scroll-area"
import { Input } from "@/components/ui/input"
import { Loader2, Search, MessageCircle, Calendar, User } from "lucide-react"
import { getAuthToken, workspaceHeaders } from "@/lib/api/auth-token"
import { format } from "date-fns"
import { es as esLocale, enUS } from "date-fns/locale"
import { useTranslation } from "@/hooks/useTranslation"

interface Message {
  id: number
  content: string
  direction: string
  sender_type: string
  delivered_at: string
  read_at: string | null
  created_at: string
}

interface ChannelHistory {
  channel_id: number
  channel_name: string
  channel_type: string
  messages: Message[]
  message_count: number
}

interface ContactHistoryData {
  contact: {
    id: number
    name: string
    phone: string | null
    email: string | null
  }
  total_conversations: number
  total_messages: number
  history_by_channel: ChannelHistory[]
}

interface ContactHistoryDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  contactId: string | number
  contactName: string
}

export function ContactHistoryDrawer({
  open,
  onOpenChange,
  contactId,
  contactName,
}: ContactHistoryDrawerProps) {
  const { t, language } = useTranslation()
  const [historyData, setHistoryData] = useState<ContactHistoryData | null>(null)
  const [loading, setLoading] = useState(false)
  const [searchQuery, setSearchQuery] = useState("")
  const [error, setError] = useState<string | null>(null)
  const dateLocale = language === "en" ? enUS : esLocale

  useEffect(() => {
    if (open && contactId) {
      fetchHistory()
    }
  }, [open, contactId])

  const fetchHistory = async () => {
    setLoading(true)
    setError(null)
    
    try {
      const token = getAuthToken();
      const response = await fetch(`/api/contacts/${contactId}/history`, {
        headers: { Authorization: `Bearer ${token}`, ...workspaceHeaders() },
      })
      
      if (!response.ok) {
        throw new Error(t("chats.historyLoadError"))
      }
      
      const data = await response.json()
      setHistoryData(data)
    } catch (err) {
      setError(err instanceof Error ? err.message : t("chats.unknownError"))
    } finally {
      setLoading(false)
    }
  }

  const getChannelIcon = (channelType: string) => {
    switch (channelType) {
      case "WHATSAPP":
        return "📱"
      case "INSTAGRAM":
        return "📸"
      case "FACEBOOK":
        return "👍"
      default:
        return "💬"
    }
  }

  const filteredHistory = historyData?.history_by_channel.map((channel) => ({
    ...channel,
    messages: channel.messages.filter((msg) =>
      msg.content.toLowerCase().includes(searchQuery.toLowerCase())
    ),
  }))

  return (
    <Drawer open={open} onOpenChange={onOpenChange} direction="right">
      <DrawerContent className="w-full max-w-2xl h-screen flex flex-col">
        <DrawerHeader className="border-b shrink-0">
          <DrawerTitle className="flex items-center gap-2">
            <User className="w-5 h-5" />
            {t("chats.contactHistoryTitle")} {contactName}
          </DrawerTitle>
          <DrawerDescription>
            {t("chats.contactHistoryDesc")}
          </DrawerDescription>
        </DrawerHeader>

        {/* Stats y búsqueda */}
        <div className="p-4 border-b space-y-3 shrink-0">
          {historyData && (
            <div className="flex gap-4 text-sm">
              <div className="flex items-center gap-2">
                <MessageCircle className="w-4 h-4 text-muted-foreground" />
                <span className="font-medium">{historyData.total_messages}</span>
                <span className="text-muted-foreground">{t("chats.historyMessages")}</span>
              </div>
              <div className="flex items-center gap-2">
                <Calendar className="w-4 h-4 text-muted-foreground" />
                <span className="font-medium">{historyData.total_conversations}</span>
                <span className="text-muted-foreground">{t("chats.historyConversations")}</span>
              </div>
            </div>
          )}
          
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
            <Input
              placeholder={t("chats.searchHistoryPlaceholder")}
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="pl-9"
            />
          </div>
        </div>

        {/* Contenido del historial */}
        <div className="flex-1 overflow-hidden">
          <ScrollArea className="h-full">
            <div className="p-4">
            {loading && (
              <div className="flex items-center justify-center py-12">
                <Loader2 className="w-8 h-8 animate-spin text-muted-foreground" />
              </div>
            )}

            {error && (
              <div className="text-center py-12">
                <p className="text-destructive">{error}</p>
                <Button onClick={fetchHistory} variant="outline" className="mt-4">
                  {t("chats.retry")}
                </Button>
              </div>
            )}

            {!loading && !error && filteredHistory && (
              <div className="space-y-6">
                {filteredHistory.map((channel) => (
                  <div key={channel.channel_id} className="space-y-3">
                    {/* Header del canal */}
                    <div className="flex items-center gap-2 sticky top-0 bg-background py-2 border-b z-10">
                      <span className="text-2xl">{getChannelIcon(channel.channel_type)}</span>
                      <div className="flex-1">
                        <h3 className="font-semibold">{channel.channel_name}</h3>
                        <p className="text-xs text-muted-foreground">
                          {channel.message_count} {t("chats.historyMessages")}
                        </p>
                      </div>
                      <Badge variant="secondary">{channel.channel_type}</Badge>
                    </div>

                    {/* Mensajes del canal */}
                    <div className="space-y-2">
                      {channel.messages.map((message) => (
                        <div
                          key={message.id}
                          className={`flex ${
                            message.direction === "OUTBOUND" ? "justify-end" : "justify-start"
                          }`}
                        >
                          <div
                            className={`max-w-[80%] rounded-lg px-3 py-2 ${
                              message.direction === "OUTBOUND"
                                ? "bg-primary text-primary-foreground"
                                : "bg-muted"
                            }`}
                          >
                            <p className="text-sm">{message.content}</p>
                            <p className="text-xs opacity-70 mt-1">
                              {format(new Date(message.delivered_at), "PPp", { locale: dateLocale })}
                            </p>
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                ))}

                {filteredHistory.every((ch) => ch.messages.length === 0) && (
                  <div className="text-center py-12 text-muted-foreground">
                    {t("chats.historyNoMatches")}
                  </div>
                )}
              </div>
            )}

            {!loading && !error && !historyData && (
              <div className="text-center py-12 text-muted-foreground">
                {t("chats.historyUnavailable")}
              </div>
            )}
            </div>
          </ScrollArea>
        </div>

        <DrawerFooter className="border-t shrink-0">
          <DrawerClose asChild>
            <Button variant="outline">{t("chats.close")}</Button>
          </DrawerClose>
        </DrawerFooter>
      </DrawerContent>
    </Drawer>
  )
}
