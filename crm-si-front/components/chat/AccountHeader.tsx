import { Button } from "@/components/ui/button"
import { ArrowLeft } from "lucide-react"
import { Channel } from "@/data/types"

interface ChannelHeaderProps {
  channel: Channel
  conversationCount?: number
  onBack: () => void
}

export function ChannelHeader({ channel, onBack }: ChannelHeaderProps) {
  return (
    <div className="p-4 border-b border-border bg-card">
      <div className="flex items-center gap-3">
        <Button variant="ghost" size="sm" onClick={onBack} aria-label="Volver a canales">
          <ArrowLeft className="w-4 h-4" aria-hidden="true" />
        </Button>
        <h3 className="font-medium">{channel.user.name}</h3>
      </div>
    </div>
  )
}