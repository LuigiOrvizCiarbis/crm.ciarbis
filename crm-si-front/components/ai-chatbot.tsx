"use client"

import type React from "react"

import { useState, useRef, useEffect } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Send, Bot, User, Calendar, Star, Phone, Mail } from "lucide-react"

interface Message {
  id: string
  content: string
  sender: "user" | "bot"
  timestamp: Date
  leadScore?: number
  suggestedActions?: string[]
}

interface LeadData {
  name?: string
  email?: string
  phone?: string
  company?: string
  interest?: string
  score: number
}

export function AIChatbot() {
  const [messages, setMessages] = useState<Message[]>([
    {
      id: "1",
      content: "¡Hola! Soy el asistente de BIMS. ¿En qué te puedo ayudar hoy?",
      sender: "bot",
      timestamp: new Date(),
    },
  ])
  const [input, setInput] = useState("")
  const [isTyping, setIsTyping] = useState(false)
  const [leadData, setLeadData] = useState<LeadData>({ score: 0 })
  const messagesEndRef = useRef<HTMLDivElement>(null)

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" })
  }

  useEffect(() => {
    scrollToBottom()
  }, [messages])

  const analyzeMessage = (message: string): { score: number; actions: string[] } => {
    const keywords = {
      high: ["comprar", "precio", "cotización", "presupuesto", "contratar", "urgente", "necesito"],
      medium: ["información", "detalles", "conocer", "saber", "interesa"],
      low: ["hola", "consulta", "pregunta"],
    }

    let score = 0
    const actions: string[] = []

    const lowerMessage = message.toLowerCase()

    if (keywords.high.some((word) => lowerMessage.includes(word))) {
      score = 80 + Math.random() * 20
      actions.push("Agendar demo", "Enviar propuesta")
    } else if (keywords.medium.some((word) => lowerMessage.includes(word))) {
      score = 40 + Math.random() * 30
      actions.push("Enviar información", "Programar seguimiento")
    } else {
      score = 10 + Math.random() * 20
      actions.push("Calificar lead")
    }

    return { score: Math.round(score), actions }
  }

  const generateBotResponse = (userMessage: string): string => {
    const lowerMessage = userMessage.toLowerCase()

    if (lowerMessage.includes("precio") || lowerMessage.includes("costo") || lowerMessage.includes("presupuesto")) {
      return "Nuestros precios varían según las necesidades específicas de tu empresa. ¿Te gustaría agendar una demo personalizada para mostrarte nuestras soluciones y preparar una cotización exacta?"
    }

    if (lowerMessage.includes("demo") || lowerMessage.includes("reunión") || lowerMessage.includes("presentación")) {
      return "Perfecto! Puedo agendar una demo personalizada para ti. ¿Cuál sería el mejor día y horario para una reunión de 30 minutos?"
    }

    if (lowerMessage.includes("funcionalidades") || lowerMessage.includes("características")) {
      return "Nuestro CRM incluye: Embudo con tablero Kanban, Omnicanalidad (WhatsApp, Instagram, Facebook), Automatización de tareas, Chatbot con IA, Panel de métricas y mucho más. ¿Hay alguna funcionalidad específica que te interese conocer?"
    }

    if (lowerMessage.includes("empresa") || lowerMessage.includes("compañía")) {
      return "Excelente! Me gustaría conocer más sobre tu empresa. ¿Podrías contarme el nombre de tu compañía y cuántos empleados tienen aproximadamente?"
    }

    const responses = [
      "Entiendo tu consulta. Nuestro CRM está diseñado específicamente para agencias como la tuya. ¿Te gustaría que te muestre cómo puede ayudarte?",
      "Perfecto! Esa es una excelente pregunta. ¿Podrías contarme un poco más sobre tu situación actual?",
      "Me alegra que estés interesado. ¿Cuál es el principal desafío que enfrentas con la gestión de tus clientes actualmente?",
    ]

    return responses[Math.floor(Math.random() * responses.length)]
  }

  const handleSend = async () => {
    if (!input.trim()) return

    const userMessage: Message = {
      id: Date.now().toString(),
      content: input,
      sender: "user",
      timestamp: new Date(),
    }

    setMessages((prev) => [...prev, userMessage])
    setInput("")
    setIsTyping(true)

    // Analizar mensaje para scoring
    const analysis = analyzeMessage(input)
    setLeadData((prev) => ({
      ...prev,
      score: Math.max(prev.score, analysis.score),
    }))

    // Simular delay de respuesta
    setTimeout(
      () => {
        const botResponse: Message = {
          id: (Date.now() + 1).toString(),
          content: generateBotResponse(input),
          sender: "bot",
          timestamp: new Date(),
          leadScore: analysis.score,
          suggestedActions: analysis.actions,
        }

        setMessages((prev) => [...prev, botResponse])
        setIsTyping(false)
      },
      1000 + Math.random() * 2000,
    )
  }

  const handleKeyPress = (e: React.KeyboardEvent) => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault()
      handleSend()
    }
  }

  const getScoreColor = (score: number) => {
    if (score >= 70) return "bg-green-500"
    if (score >= 40) return "bg-yellow-500"
    return "bg-red-500"
  }

  const getScoreLabel = (score: number) => {
    if (score >= 70) return "Lead caliente"
    if (score >= 40) return "Lead tibio"
    return "Lead frío"
  }

  return (
    <div className="flex h-full gap-4">
      {/* Chat Area */}
      <Card className="flex-1 flex flex-col">
        <CardHeader className="border-b">
          <div className="flex items-center justify-between">
            <CardTitle className="flex items-center gap-2">
              <Bot className="h-5 w-5 text-blue-500" />
              Chatbot IA de BIMS
            </CardTitle>
            {leadData.score > 0 && (
              <Badge className={`${getScoreColor(leadData.score)} text-white`}>
                {getScoreLabel(leadData.score)} ({leadData.score}%)
              </Badge>
            )}
          </div>
        </CardHeader>

        <CardContent className="flex-1 flex flex-col p-0">
          {/* Messages */}
          <div className="flex-1 overflow-y-auto p-4 space-y-4">
            {messages.map((message) => (
              <div
                key={message.id}
                className={`flex gap-3 ${message.sender === "user" ? "justify-end" : "justify-start"}`}
              >
                {message.sender === "bot" && (
                  <Avatar className="h-8 w-8">
                    <AvatarFallback className="bg-blue-500 text-white">
                      <Bot className="h-4 w-4" />
                    </AvatarFallback>
                  </Avatar>
                )}

                <div className={`max-w-[70%] ${message.sender === "user" ? "order-first" : ""}`}>
                  <div
                    className={`rounded-lg p-3 ${
                      message.sender === "user" ? "bg-blue-500 text-white ml-auto" : "bg-muted"
                    }`}
                  >
                    <p className="text-sm">{message.content}</p>
                  </div>

                  <div className="flex items-center gap-2 mt-1">
                    <span className="text-xs text-muted-foreground">{message.timestamp.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", hour12: true })}</span>
                    {message.leadScore && (
                      <Badge variant="outline" className="text-xs">
                        Score: {message.leadScore}%
                      </Badge>
                    )}
                  </div>

                  {message.suggestedActions && (
                    <div className="flex gap-1 mt-2">
                      {message.suggestedActions.map((action, index) => (
                        <Button key={index} variant="outline" size="sm" className="text-xs h-6 bg-transparent">
                          {action}
                        </Button>
                      ))}
                    </div>
                  )}
                </div>

                {message.sender === "user" && (
                  <Avatar className="h-8 w-8">
                    <AvatarFallback>
                      <User className="h-4 w-4" />
                    </AvatarFallback>
                  </Avatar>
                )}
              </div>
            ))}

            {isTyping && (
              <div className="flex gap-3">
                <Avatar className="h-8 w-8">
                  <AvatarFallback className="bg-blue-500 text-white">
                    <Bot className="h-4 w-4" />
                  </AvatarFallback>
                </Avatar>
                <div className="bg-muted rounded-lg p-3">
                  <div className="flex gap-1">
                    <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce"></div>
                    <div
                      className="w-2 h-2 bg-gray-400 rounded-full animate-bounce"
                      style={{ animationDelay: "0.1s" }}
                    ></div>
                    <div
                      className="w-2 h-2 bg-gray-400 rounded-full animate-bounce"
                      style={{ animationDelay: "0.2s" }}
                    ></div>
                  </div>
                </div>
              </div>
            )}
            <div ref={messagesEndRef} />
          </div>

          {/* Input */}
          <div className="border-t p-4">
            <div className="flex gap-2">
              <Input
                value={input}
                onChange={(e) => setInput(e.target.value)}
                onKeyPress={handleKeyPress}
                placeholder="Escribí tu mensaje..."
                className="flex-1"
              />
              <Button onClick={handleSend} disabled={!input.trim() || isTyping} aria-label="Enviar mensaje">
                <Send className="h-4 w-4" aria-hidden="true" />
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Lead Info Panel */}
      <Card className="w-80">
        <CardHeader>
          <CardTitle className="text-sm">Información del lead</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-sm text-muted-foreground">Puntaje del lead</span>
              <Badge className={`${getScoreColor(leadData.score)} text-white`}>{leadData.score}%</Badge>
            </div>
            <div className="w-full bg-gray-200 rounded-full h-2">
              <div
                className={`h-2 rounded-full transition-all duration-500 ${getScoreColor(leadData.score)}`}
                style={{ width: `${leadData.score}%` }}
              ></div>
            </div>
          </div>

          <div className="space-y-3">
            <div className="flex items-center gap-2 text-sm">
              <User className="h-4 w-4 text-muted-foreground" />
              <span>Visitante anónimo</span>
            </div>
            <div className="flex items-center gap-2 text-sm">
              <Mail className="h-4 w-4 text-muted-foreground" />
              <span className="text-muted-foreground">Email no capturado</span>
            </div>
            <div className="flex items-center gap-2 text-sm">
              <Phone className="h-4 w-4 text-muted-foreground" />
              <span className="text-muted-foreground">Teléfono no capturado</span>
            </div>
          </div>

          <div className="space-y-2">
            <h4 className="text-sm font-medium">Acciones sugeridas</h4>
            <div className="space-y-1">
              <Button variant="outline" size="sm" className="w-full justify-start bg-transparent">
                <Calendar className="h-4 w-4 mr-2" />
                Agendar Demo
              </Button>
              <Button variant="outline" size="sm" className="w-full justify-start bg-transparent">
                <Mail className="h-4 w-4 mr-2" />
                Enviar Información
              </Button>
              <Button variant="outline" size="sm" className="w-full justify-start bg-transparent">
                <Star className="h-4 w-4 mr-2" />
                Marcar como lead caliente
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
