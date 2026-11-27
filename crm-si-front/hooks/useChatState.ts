import { useState } from 'react'
import { Conversation } from '@/data/types'

export const useChatState = () => {
  const [chatStates, setChatStates] = useState<Record<string, Conversation>>({
   /*  conv1: {
      id: "conv1",
      accountId: "wp1",
      contactName: "María González",
      lastMessage: "¿Está disponible el departamento?",
      timestamp: "14:30",
      stage: "nuevo",
      priority: "alta",
      assigneeId: 1,
      unread: true,
      archived: false
    },
    conv2: {
      id: "conv2", 
      accountId: "wp1", 
      contactName: "Carlos Pérez", 
      lastMessage: "Perfecto, nos vemos mañana",
      timestamp: "13:45", 
      stage: "demo", 
      priority: "hot", 
      assigneeId: "v1", 
      unread: false, 
      archived: false
    },
    conv3: {
      id: "conv3", 
      accountId: "wp2",
      contactName: "Ana Martín",
      lastMessage: "¿Tienen stock disponible?",
      timestamp: "14:15", 
      stage: "calificado", 
      priority: "media", 
      assigneeId: "v2", 
      unread: true, 
      archived: false
    }, */
  })

  const updateChatState = (chatId: number, updates: Partial<Conversation>) => {
    setChatStates((prev) => ({
      ...prev,
      [chatId]: { ...prev[chatId], id: chatId, ...updates },
    }))
  }

  const handleChangeStage = (chatId: number) => (stageId: number | undefined) => {
    updateChatState(chatId, { pipeline_stage_id: stageId })
  }

  const handleChangePriority = (chatId: number) => (priority: "baja" | "media" | "alta" | "hot") => {
    updateChatState(chatId, { priority })
  }

  const handleChangeAssignee = (chatId: number) => (assigneeId: number) => {
    updateChatState(chatId, { assigneeId: String(assigneeId) })
  }

  const handleMarkRead = (chatId: number) => () => {
    updateChatState(chatId, { unread: false })
  }

  const handleToggleArchive = (chatId: number) => () => {
    const currentState = chatStates[chatId]
    updateChatState(chatId, { archived: !currentState?.archived })
  }

  return {
    chatStates,
    handleChangeStage,
    handleChangePriority,
    handleChangeAssignee,
    handleMarkRead,
    handleToggleArchive,
  }
}