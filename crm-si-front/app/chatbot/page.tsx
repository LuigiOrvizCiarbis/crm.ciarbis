import { AIChatbot } from "@/components/ai-chatbot"
import { BotAnalytics } from "@/components/bot-analytics"
import { SidebarLayout } from "@/components/SidebarLayout"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"

export default function ChatbotPage() {
  return (
    <SidebarLayout>
      <div className="p-6 space-y-6">
        <div>
          <h1 className="text-3xl font-bold">Chatbot con IA</h1>
          <p className="text-muted-foreground">Gestioná conversaciones inteligentes y calificá leads automáticamente</p>
        </div>

        <Tabs defaultValue="chat" className="space-y-4">
          <TabsList>
            <TabsTrigger value="chat">Chat en vivo</TabsTrigger>
            <TabsTrigger value="analytics">Analíticas</TabsTrigger>
            <TabsTrigger value="config">Configuración</TabsTrigger>
          </TabsList>

          <TabsContent value="chat" className="space-y-4">
            <div className="h-[calc(100vh-200px)]">
              <AIChatbot />
            </div>
          </TabsContent>

          <TabsContent value="analytics" className="space-y-4">
            <BotAnalytics />
          </TabsContent>

          <TabsContent value="config" className="space-y-4">
            <div className="text-center py-12">
              <p className="text-muted-foreground">Configuración del chatbot próximamente...</p>
            </div>
          </TabsContent>
        </Tabs>
      </div>
    </SidebarLayout>
  )
}
