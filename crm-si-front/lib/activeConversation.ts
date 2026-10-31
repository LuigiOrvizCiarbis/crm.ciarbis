/**
 * Conversación abierta en /chats. Lo lee el listener global de notificaciones
 * para no avisar de mensajes que el usuario ya está viendo.
 */
let activeConversationId: number | null = null

export function setActiveConversationId(id: number | null): void {
  activeConversationId = id
}

export function getActiveConversationId(): number | null {
  return activeConversationId
}
