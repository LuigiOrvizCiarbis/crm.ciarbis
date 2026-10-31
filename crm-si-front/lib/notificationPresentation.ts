import type { AppNotification } from "@/lib/api/notifications"

type Translate = (key: string, params?: Record<string, string | number>) => string

/** Las claves de i18n no admiten puntos en el nombre: message.received → message_received. */
const typeKey = (notification: AppNotification) => `notifications.types.${notification.type.replace(".", "_")}`

export function describeNotification(notification: AppNotification, t: Translate): { title: string; body: string } {
  const data = notification.data ?? {}
  const key = typeKey(notification)
  const name = data.contact_name || t("notifications.unknownContact")

  switch (notification.type) {
    case "message.received":
      return {
        title: notification.count > 1
          ? t(`${key}.titleMany`, { count: notification.count, name })
          : t(`${key}.title`, { name }),
        body: data.preview || t(`${key}.attachment`),
      }
    case "conversation.assigned":
      return {
        title: t(`${key}.title`),
        body: data.actor_name ? t(`${key}.bodyBy`, { actor: data.actor_name, name }) : t(`${key}.body`, { name }),
      }
    case "conversation.unassigned":
      return { title: t(`${key}.title`), body: t(`${key}.body`, { name }) }
    case "handoff.requested":
      return { title: t(`${key}.title`), body: data.summary ? `${name}: ${data.summary}` : t(`${key}.body`, { name }) }
    case "task.assigned":
      return {
        title: t(`${key}.title`),
        body: data.actor_name ? t(`${key}.bodyBy`, { actor: data.actor_name, task: data.task_name ?? "" }) : data.task_name ?? "",
      }
    case "import.completed":
    case "import.failed":
      return { title: t(`${key}.title`), body: data.filename ?? "" }
    case "channel.token_invalid":
      return { title: t(`${key}.title`, { channel: data.channel_name ?? "" }), body: t(`${key}.body`) }
    case "trial.expiring":
      return {
        title: data.days_left === 1 ? t(`${key}.titleOne`) : t(`${key}.title`, { days: data.days_left ?? "" }),
        body: t(`${key}.body`),
      }
    case "scheduled_message.failed":
      return { title: t(`${key}.title`, { name }), body: data.error_message || t(`${key}.body`) }
    default:
      return { title: t("notifications.title"), body: "" }
  }
}

export function canUseBrowserNotifications(): boolean {
  return typeof window !== "undefined" && "Notification" in window
}

export function showBrowserNotification(
  notification: AppNotification,
  text: { title: string; body: string },
  onClick: () => void,
): void {
  if (!canUseBrowserNotifications() || Notification.permission !== "granted") return
  try {
    const browserNotification = new Notification(text.title, {
      body: text.body,
      tag: notification.group_key ?? `notification-${notification.id}`,
      icon: "/icon-192x192.png",
    })
    browserNotification.onclick = () => {
      window.focus()
      onClick()
      browserNotification.close()
    }
  } catch {
    // Algunos navegadores móviles sólo permiten notificaciones vía service worker.
  }
}

let audioContext: AudioContext | null = null
let lastSoundAt = 0
const SOUND_THROTTLE_MS = 3000

/** Chime corto sintetizado: evita servir un asset y respeta el throttle. */
export function playNotificationSound(): void {
  const now = Date.now()
  if (now - lastSoundAt < SOUND_THROTTLE_MS || typeof window === "undefined") return
  lastSoundAt = now

  try {
    const Context = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
    if (!Context) return
    audioContext ??= new Context()
    const ctx = audioContext
    void ctx.resume()

    ;[880, 1320].forEach((frequency, index) => {
      const start = ctx.currentTime + index * 0.12
      const oscillator = ctx.createOscillator()
      const gain = ctx.createGain()
      oscillator.type = "sine"
      oscillator.frequency.value = frequency
      gain.gain.setValueAtTime(0.0001, start)
      gain.gain.exponentialRampToValueAtTime(0.15, start + 0.02)
      gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.25)
      oscillator.connect(gain).connect(ctx.destination)
      oscillator.start(start)
      oscillator.stop(start + 0.26)
    })
  } catch {
    // Autoplay bloqueado hasta la primera interacción: se ignora.
  }
}
